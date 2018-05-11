<?php
/**
 * IFormatter.php
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

/**
 * WebSocketsWAMPClient server output formater interface
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Logger
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
interface IFormatter
{
	/**
	 * @param string $message
	 *
	 * @return void
	 */
	function error(string $message);

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	function warning(string $message);
	/**
	 * @param string $message
	 *
	 * @return void
	 */
	function note(string $message);

	/**
	 * @param string $message
	 *
	 * @return void
	 */
	function caution(string $message);
}
