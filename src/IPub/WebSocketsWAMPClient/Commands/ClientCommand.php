<?php
/**
 * ClientCommand.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Commands
 * @since          1.0.0
 *
 * @date           11.05.18
 */

declare(strict_types = 1);

namespace IPub\WebSocketsWAMPClient\Commands;

use Symfony\Component\Console;
use Symfony\Component\Console\Input;
use Symfony\Component\Console\Style;
use Symfony\Component\Console\Output;

use Psr\Log;

use IPub\WebSocketsWAMPClient\Client;
use IPub\WebSocketsWAMPClient\Exceptions;
use IPub\WebSocketsWAMPClient\Logger;

/**
 * MQTT client command
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Commands
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
class ClientCommand extends Console\Command\Command
{
	/**
	 * @var Client\IClient
	 */
	private $client;

	/**
	 * @var Log\LoggerInterface|Log\NullLogger|NULL
	 */
	private $logger;

	/**
	 * @param Client\IClient $client
	 * @param Log\LoggerInterface|NULL $logger
	 * @param string|NULL $name
	 */
	public function __construct(
		Client\IClient $client,
		Log\LoggerInterface $logger = NULL,
		string $name = NULL
	) {
		parent::__construct($name);

		$this->client = $client;
		$this->logger = $logger === NULL ? new Log\NullLogger : $logger;
	}

	/**
	 * @return void
	 */
	protected function configure()
	{
		$this
			->setName('ipub:wampclient:start')
			->setDescription('Start web sockets WAMP client.');
	}

	/**
	 * {@inheritdoc}
	 */
	protected function execute(Input\InputInterface $input, Output\OutputInterface $output)
	{
		$io = new Style\SymfonyStyle($input, $output);

		$io->text([
			'',
			'+-------------+',
			'| WAMP client |',
			'+-------------+',
			'',
		]);

		if ($this->logger instanceof Logger\Console) {
			$this->logger->setFormatter(new Logger\Formatter\Symfony($io));
		}

		try {
			$this->client->connect();

		} catch (Exceptions\ConnectionException $ex) {
			$this->client->getLoop()->stop();
		}

		$this->client->getLoop()->run();
	}
}
