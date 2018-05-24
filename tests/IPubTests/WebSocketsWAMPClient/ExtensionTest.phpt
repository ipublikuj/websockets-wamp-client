<?php
/**
 * Test: IPub\WebSocketsWAMPClient\Extension
 * @testCase
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Tests
 * @since          1.0.0
 *
 * @date           24.05.18
 */

declare(strict_types = 1);

namespace IPubTests\WebSocketsWAMPClient;

use Nette;

use Tester;
use Tester\Assert;

use React;

use IPub\WebSocketsWAMPClient;

require __DIR__ . '/../bootstrap.php';

/**
 * Registering WAMP client extension tests
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Tests
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
class ExtensionTest extends Tester\TestCase
{
	public function testFunctional() : void
	{
		$dic = $this->createContainer();

		Assert::true($dic->getService('client.loop') instanceof React\EventLoop\LoopInterface::class);
		Assert::true($dic->getService('client.configuration') instanceof WebSocketsWAMPClient\Client\Configuration::class);
		Assert::true($dic->getService('client.client') instanceof WebSocketsWAMPClient\Client\IClient);
	}

	/**
	 * @return Nette\DI\Container
	 */
	protected function createContainer() : Nette\DI\Container
	{
		$rootDir = __DIR__ . '/../../';

		$config = new Nette\Configurator();
		$config->setTempDirectory(TEMP_DIR);

		$config->addParameters(['container' => ['class' => 'SystemContainer_' . md5((string) time())]]);
		$config->addParameters(['appDir' => $rootDir, 'wwwDir' => $rootDir]);

		$config->addConfig(__DIR__ . '/files/config.neon');

		WebSocketsWAMPClient\DI\WebSocketsWAMPClientExtension::register($config);

		return $config->createContainer();
	}
}

\run(new ExtensionTest());
