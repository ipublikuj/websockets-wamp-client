<?php
/**
 * Configuration.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Client
 * @since          1.0.0
 *
 * @date           24.05.18
 */

declare(strict_types = 1);

namespace IPub\WebSocketsWAMPClient\Client;

use Nette;

/**
 * Web sockets client configuration container
 *
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Client
 *
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 */
final class Configuration
{
	/**
	 * Implement nette smart magic
	 */
	use Nette\SmartObject;

	private const TOKEN_LENGTH = 16;

	/**
	 * @var string
	 */
	private $host;

	/**
	 * @var int
	 */
	private $port;

	/**
	 * @var string
	 */
	private $origin;

	/**
	 * @var string
	 */
	private $path;

	/**
	 * @var string
	 */
	private $key;

	/**
	 * @param string $host
	 * @param int $port
	 * @param string $path
	 * @param string|NULL $origin
	 */
	public function __construct(string $host = '127.0.0.1', int $port = 8080, string $path = '/', ?string $origin = NULL)
	{
		$this->host = $host;
		$this->port = $port;
		$this->path = $path;
		$this->key = $this->generateToken(self::TOKEN_LENGTH);
	}

	/**
	 * @return int
	 */
	public function getPort() : int
	{
		return $this->port;
	}

	/**
	 * @return string
	 */
	public function getHost() : string
	{
		return $this->host;
	}

	/**
	 * @return string|NULL
	 */
	public function getOrigin() : ?string
	{
		return $this->origin;
	}

	/**
	 * @return string
	 */
	public function getKey() : string
	{
		return $this->key;
	}

	/**
	 * @return string
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 * Generate token
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	private function generateToken(int $length) : string
	{
		$characters = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ!"§$%&/()=[]{}';

		$useChars = [];

		// Select some random chars:
		for ($i = 0; $i < $length; $i++) {
			$useChars[] = $characters[mt_rand(0, strlen($characters) - 1)];
		}

		// Add numbers
		array_push($useChars, rand(0, 9), rand(0, 9), rand(0, 9));
		shuffle($useChars);

		$randomString = trim(implode('', $useChars));
		$randomString = substr($randomString, 0, self::TOKEN_LENGTH);

		return base64_encode($randomString);
	}
}
