<?php
/**
 * Symfony.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Logger
 * @since          1.0.0
 *
 * @date           11.05.18
 */

declare(strict_types = 1);

namespace IPub\WebSocketsWAMPClient\Logger\Formatter;

use Symfony\Component\Console;

/**
 * WebSocketsWAMPClient server symfony console output formater
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Logger
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
final class Symfony implements IFormatter
{
	/**
	 * @var Console\Style\SymfonyStyle
	 */
	private $output;

	/**
	 * @param Console\Style\SymfonyStyle $output
	 */
	public function __construct(Console\Style\SymfonyStyle $output)
	{
		$this->output = $output;
	}

	/**
	 * {@inheritdoc}
	 */
	public function error(string $message)
	{
		$this->output->error($message);
	}

	/**
	 * {@inheritdoc}
	 */
	public function warning(string $message)
	{
		$this->output->warning($message);
	}

	/**
	 * {@inheritdoc}
	 */
	public function note(string $message)
	{
		$this->output->note($message);
	}

	/**
	 * {@inheritdoc}
	 */
	public function caution(string $message)
	{
		$this->output->caution($message);
	}
}
