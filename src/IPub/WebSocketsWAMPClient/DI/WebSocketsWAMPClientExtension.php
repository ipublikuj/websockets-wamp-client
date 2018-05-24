<?php
/**
 * WebSocketsWAMPClientExtension.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     DI
 * @since          1.0.0
 *
 * @date           11.05.18
 */

declare(strict_types = 1);

namespace IPub\WebSocketsWAMPClient\DI;

use Nette;
use Nette\DI;

use Kdyby\Console;

use React;

use Psr\Log;

use IPub\WebSocketsWAMPClient\Client;
use IPub\WebSocketsWAMPClient\Commands;
use IPub\WebSocketsWAMPClient\Logger;

/**
 * Web sockets WAMP client extension container
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     DI
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 *
 * @method DI\ContainerBuilder getContainerBuilder()
 * @method array getConfig(array $default)
 * @method string prefix($id)
 */
final class WebSocketsWAMPClientExtension extends DI\CompilerExtension
{
	/**
	 * @var array
	 */
	private $defaults = [
		'server' => [
			'host'    => '127.0.0.1',
			'port'    => 8080,
			'path'    => '/',
			'origin'  => NULL,
			'dns'     => [
				'enable'  => TRUE,
				'address' => '8.8.8.8',
			],
			'secured' => [
				'enable'      => FALSE,
				'sslSettings' => [],
			],
		],
		'loop'   => NULL,
	];

	/**
	 * {@inheritdoc}
	 */
	public function loadConfiguration() : void
	{
		parent::loadConfiguration();

		/** @var DI\ContainerBuilder $builder */
		$builder = $this->getContainerBuilder();
		/** @var array $configuration */
		$configuration = $this->getConfig($this->defaults);

		if ($configuration['loop'] === NULL) {
			$loop = $builder->addDefinition($this->prefix('client.loop'))
				->setType(React\EventLoop\LoopInterface::class)
				->setFactory('React\EventLoop\Factory::create')
				->setAutowired(FALSE);

		} else {
			$loop = $builder->getDefinition(ltrim($configuration['loop'], '@'));
		}

		if ($builder->findByType(Log\LoggerInterface::class) === []) {
			$builder->addDefinition($this->prefix('server.logger'))
				->setType(Logger\Console::class);
		}

		$builder->addDefinition($this->prefix('client.configuration'))
			->setType(Client\Configuration::class)
			->setArguments([
				'host'   => (string) $configuration['server']['host'],
				'port'   => (int) $configuration['server']['port'],
				'path'   => (string) $configuration['server']['path'],
				'origin' => $configuration['server']['origin'],
			]);

		$builder->addDefinition($this->prefix('client.client'))
			->setType(Client\Client::class)
			->setArguments([
				'loop' => $loop,
			]);

		// Define all console commands
		$commands = [
			'client' => Commands\ClientCommand::class,
		];

		foreach ($commands as $name => $cmd) {
			$builder->addDefinition($this->prefix('commands' . lcfirst($name)))
				->setType($cmd)
				->addTag(Console\DI\ConsoleExtension::TAG_COMMAND);
		}
	}

	/**
	 * @param Nette\Configurator $config
	 * @param string $extensionName
	 *
	 * @return void
	 */
	public static function register(Nette\Configurator $config, string $extensionName = 'wampClient') : void
	{
		$config->onCompile[] = function (Nette\Configurator $config, DI\Compiler $compiler) use ($extensionName) {
			$compiler->addExtension($extensionName, new WebSocketsWAMPClientExtension);
		};
	}
}
