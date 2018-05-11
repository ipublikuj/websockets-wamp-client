<?php
/**
 * Client.php
 *
 * @copyright      More in license.md
 * @license        https://www.ipublikuj.eu
 * @author         Adam Kadlec <adam.kadlec@ipublikuj.eu>
 * @package        iPublikuj:WebSocketsWAMPClient!
 * @subpackage     Client
 * @since          1.0.0
 *
 * @date           11.05.18
 */

declare(strict_types = 1);

namespace IPub\WebSocketsWAMPClient\Client;

use Nette;
use Nette\Utils;

use React\EventLoop;
use React\Stream;

use IPub\WebSocketsWAMPClient\Exceptions;

/**
 * @method void onOpen(array $data)
 * @method void onEvent(string $topic, string $event)
 */
class Client implements IClient
{
	/**
	 * Implement nette smart magic
	 */
	use Nette\SmartObject;

	const VERSION = '0.1.0';

	const TOKEN_LENGTH = 16;

	const TYPE_ID_WELCOME = 0;
	const TYPE_ID_PREFIX = 1;
	const TYPE_ID_CALL = 2;
	const TYPE_ID_CALL_RESULT = 3;
	const TYPE_ID_ERROR = 4;
	const TYPE_ID_SUBSCRIBE = 5;
	const TYPE_ID_UNSUBSCRIBE = 6;
	const TYPE_ID_PUBLISH = 7;
	const TYPE_ID_EVENT = 8;

	/**
	 * @var \Closure
	 */
	public $onOpen = [];

	/**
	 * @var \Closure
	 */
	public $onEvent = [];

	/**
	 * @var string
	 */
	private $key;

	/**
	 * @var EventLoop\LoopInterface
	 */
	private $loop;

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
	 * @var Stream\DuplexResourceStream
	 */
	private $stream;

	/**
	 * @var bool
	 */
	private $connected = FALSE;

	/**
	 * @var array
	 */
	private $callbacks = [];

	/**
	 * @param EventLoop\LoopInterface $eventLoop
	 * @param string $host
	 * @param int $port
	 * @param string $path
	 * @param string|NULL $origin
	 */
	function __construct(EventLoop\LoopInterface $eventLoop, string $host = '127.0.0.1', int $port = 8080, string $path = '/', ?string $origin = NULL)
	{
		$this->setLoop($eventLoop);
		$this->setHost($host);
		$this->setPort($port);
		$this->setPath($path);
		$this->setOrigin($origin);
		$this->setKey($this->generateToken(self::TOKEN_LENGTH));
	}

	/**
	 * Disconnect on destruct
	 */
	function __destruct()
	{
		$this->disconnect();
	}

	/**
	 * {@inheritdoc}
	 */
	public function setLoop(EventLoop\LoopInterface $loop) : void
	{
		$this->loop = $loop;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getLoop() : EventLoop\LoopInterface
	{
		return $this->loop;
	}

	/**
	 * {@inheritdoc}
	 */
	public function connect() : void
	{
		$resource = @stream_socket_client('tcp://' . $this->getHost() . ':' . $this->getPort());

		if (!$resource) {
			throw new Exceptions\ConnectionException('Opening socket failed.');
		}

		$this->stream = new Stream\DuplexResourceStream($resource, $this->getLoop());

		$this->stream->on('data', function ($chunk) : void {
			$data = $this->parseChunk($chunk);

			$this->parseData($data);
		});

		$this->stream->on('close', function () : void {
			echo '[CLOSED]' . PHP_EOL;
		});

		$this->stream->write($this->createHeader());
	}

	/**
	 * {@inheritdoc}
	 */
	public function disconnect() : void
	{
		$this->connected = FALSE;

		if ($this->stream instanceof Stream\DuplexStreamInterface) {
			$this->stream->close();
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function isConnected() : bool
	{
		return $this->connected;
	}

	/**
	 * {@inheritdoc}
	 */
	public function publish(string $topicUri, string $event, array $exclude = [], array $eligible = []) : void
	{
		$this->sendData([
			self::TYPE_ID_PUBLISH,
			$topicUri,
			$event,
			$exclude,
			$eligible
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function subscribe(string $topicUri) : void
	{
		$this->sendData([
			self::TYPE_ID_SUBSCRIBE,
			$topicUri
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function unsubscribe(string $topicUri) : void
	{
		$this->sendData([
			self::TYPE_ID_UNSUBSCRIBE,
			$topicUri
		]);
	}

	/**
	 * {@inheritdoc}
	 */
	public function call(string $processUri, array $args, callable $callback = NULL) : void
	{
		$callId = $this->generateAlphaNumToken(16);
		$this->callbacks[$callId] = $callback;

		$data = [
			self::TYPE_ID_CALL,
			$callId,
			$processUri,
		];

		$data = array_merge($data, $args);

		$this->sendData($data);
	}

	/**
	 * @param mixed $data
	 * @param array $header
	 *
	 * @return void
	 */
	private function receiveData($data, array $header) : void
	{
		if (!$this->isConnected()) {
			$this->disconnect();

			return;
		}

		if (isset($data[0])) {
			switch ($data[0]) {
				case self::TYPE_ID_WELCOME:
					$this->onOpen($data);
					break;

				case self::TYPE_ID_CALL_RESULT:
					if (isset($data[1])) {
						$id = $data[1];

						if (isset($this->callbacks[$id])) {
							$callback = $this->callbacks[$id];
							$callback((isset($data[2]) ? $data[2] : []));
						}
					}
					break;

				case self::TYPE_ID_EVENT:
					if (isset($data[1]) && isset($data[2])) {
						$this->onEvent($data[1], $data[2]);
					}
					break;
			}
		}
	}

	/**
	 * @param array $data
	 * @param string $type
	 * @param bool $masked
	 *
	 * @return void
	 *
	 * @throws Utils\JsonException
	 */
	private function sendData(array $data, string $type = 'text', bool $masked = TRUE) : void
	{
		if (!$this->isConnected()) {
			$this->disconnect();

			return;
		}

		$this->stream->write($this->hybi10Encode(Utils\Json::encode($data), $type, $masked));
	}

	/**
	 * Parse received data
	 *
	 * @param $response
	 *
	 * @return void
	 *
	 * @throws Utils\JsonException
	 */
	private function parseData($response) : void
	{
		if (!$this->connected && isset($response['Sec-Websocket-Accept'])) {
			if (base64_encode(pack('H*', sha1($this->key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))) === $response['Sec-Websocket-Accept']) {
				$this->connected = TRUE;
			}
		}

		if ($this->connected && !empty($response['content'])) {
			$content = trim($response['content']);

			if (preg_match('/(\[.*\])/', $content, $match)) {
				$content = Utils\Json::decode($match[1], Utils\Json::FORCE_ARRAY);

				if (is_array($content)) {
					unset($response['status']);
					unset($response['content']);

					$this->receiveData($content, $response);
				}
			}
		}
	}

	/**
	 * Create header for web socket client
	 *
	 * @return string
	 */
	private function createHeader() : string
	{
		$host = $this->getHost();

		if ($host === '127.0.0.1' || $host === '0.0.0.0') {
			$host = 'localhost';
		}

		$origin = $this->getOrigin() ? $this->getOrigin() : 'null';

		return
			"GET {$this->getPath()} HTTP/1.1" . "\r\n" .
			"Origin: {$origin}" . "\r\n" .
			"Host: {$host}:{$this->getPort()}" . "\r\n" .
			"Sec-WebSocket-Key: {$this->getKey()}" . "\r\n" .
			"User-Agent: IPubWebSocketClient/" . self::VERSION . "\r\n" .
			"Upgrade: websocket" . "\r\n" .
			"Connection: Upgrade" . "\r\n" .
			"Sec-WebSocket-Protocol: wamp" . "\r\n" .
			"Sec-WebSocket-Version: 13" . "\r\n" . "\r\n";
	}

	/**
	 * Parse raw incoming data
	 *
	 * @param string $header
	 *
	 * @return mixed[]
	 */
	private function parseChunk(string $header) : array
	{
		$parsed = [];

		$content = '';

		$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));

		foreach ($fields as $field) {
			if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
				$match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function ($matches) {
					return strtoupper($matches[0]);
				}, strtolower(trim($match[1])));

				if (isset($parsed[$match[1]])) {
					$parsed[$match[1]] = [$parsed[$match[1]], $match[2]];

				} else {
					$parsed[$match[1]] = trim($match[2]);
				}

			} elseif (preg_match('!HTTP/1\.\d (\d)* .!', $field)) {
				$parsed['status'] = $field;

			} else {
				$content .= $field . "\r\n";
			}
		}

		$parsed['content'] = $content;

		return $parsed;
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

	/**
	 * Generate token
	 *
	 * @param int $length
	 *
	 * @return string
	 */
	private function generateAlphaNumToken(int $length) : string
	{
		$characters = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

		srand((float) microtime() * 1000000);

		$token = '';

		do {
			shuffle($characters);
			$token .= $characters[mt_rand(0, (count($characters) - 1))];
		} while (strlen($token) < $length);

		return $token;
	}

	/**
	 * @param int $port
	 *
	 * @return void
	 */
	public function setPort(int $port) : void
	{
		$this->port = $port;
	}

	/**
	 * @return int
	 */
	public function getPort() : int
	{
		return $this->port;
	}

	/**
	 * @param string $host
	 *
	 * @return void
	 */
	public function setHost(string $host) : void
	{
		$this->host = $host;
	}

	/**
	 * @return string
	 */
	public function getHost() : string
	{
		return $this->host;
	}

	/**
	 * @param string|NULL $origin
	 *
	 * @return void
	 */
	public function setOrigin(?string $origin = NULL) : void
	{
		if ($origin !== NULL) {
			$this->origin = $origin;

		} else {
			$this->origin = NULL;
		}
	}

	/**
	 * @return string|NULL
	 */
	public function getOrigin() : ?string
	{
		return $this->origin;
	}

	/**
	 * @param string $key
	 *
	 * @return void
	 */
	public function setKey(string $key) : void
	{
		$this->key = $key;
	}

	/**
	 * @return string
	 */
	public function getKey() : string
	{
		return $this->key;
	}

	/**
	 * @param string $path
	 *
	 * @return void
	 */
	public function setPath(string $path) : void
	{
		$this->path = $path;
	}

	/**
	 * @return string
	 */
	public function getPath()
	{
		return $this->path;
	}

	/**
	 * @param string $payload
	 * @param string $type
	 * @param bool $masked
	 *
	 * @return bool|string
	 */
	private function hybi10Encode(string $payload, string $type = 'text', bool $masked = TRUE)
	{
		$frameHead = [];

		$payloadLength = strlen($payload);

		switch ($type) {
			case 'text':
				// First byte indicates FIN, Text-Frame (10000001):
				$frameHead[0] = 129;
				break;

			case 'close':
				// First byte indicates FIN, Close Frame(10001000):
				$frameHead[0] = 136;
				break;

			case 'ping':
				// First byte indicates FIN, Ping frame (10001001):
				$frameHead[0] = 137;
				break;

			case 'pong':
				// First byte indicates FIN, Pong frame (10001010):
				$frameHead[0] = 138;
				break;
		}

		// Set mask and payload length (using 1, 3 or 9 bytes)
		if ($payloadLength > 65535) {
			$payloadLengthBin = str_split(sprintf('%064b', $payloadLength), 8);
			$frameHead[1] = ($masked === TRUE) ? 255 : 127;

			for ($i = 0; $i < 8; $i++) {
				$frameHead[$i + 2] = bindec($payloadLengthBin[$i]);
			}

			// Most significant bit MUST be 0 (close connection if frame too big)
			if ($frameHead[2] > 127) {
				$this->close(1004);

				return FALSE;
			}

		} elseif ($payloadLength > 125) {
			$payloadLengthBin = str_split(sprintf('%016b', $payloadLength), 8);

			$frameHead[1] = ($masked === TRUE) ? 254 : 126;
			$frameHead[2] = bindec($payloadLengthBin[0]);
			$frameHead[3] = bindec($payloadLengthBin[1]);

		} else {
			$frameHead[1] = ($masked === TRUE) ? $payloadLength + 128 : $payloadLength;
		}

		// Convert frame-head to string:
		foreach (array_keys($frameHead) as $i) {
			$frameHead[$i] = chr($frameHead[$i]);
		}

		if ($masked === TRUE) {
			// Generate a random mask:
			$mask = [];

			for ($i = 0; $i < 4; $i++) {
				$mask[$i] = chr(rand(0, 255));
			}

			$frameHead = array_merge($frameHead, $mask);
		}

		$frame = implode('', $frameHead);

		// Append payload to frame:
		for ($i = 0; $i < $payloadLength; $i++) {
			$frame .= ($masked === TRUE) ? $payload[$i] ^ $mask[$i % 4] : $payload[$i];
		}

		return $frame;
	}

	/**
	 * @param $data
	 *
	 * @return string|NULL
	 */
	private function hybi10Decode($data) : ?string
	{
		if (empty($data)) {
			return NULL;
		}

		$bytes = $data;
		$decodedData = '';
		$secondByte = sprintf('%08b', ord($bytes[1]));
		$masked = ($secondByte[0] == '1') ? TRUE : FALSE;
		$dataLength = ($masked === TRUE) ? ord($bytes[1]) & 127 : ord($bytes[1]);

		if ($masked === TRUE) {
			if ($dataLength === 126) {
				$mask = substr($bytes, 4, 4);
				$coded_data = substr($bytes, 8);

			} elseif ($dataLength === 127) {
				$mask = substr($bytes, 10, 4);
				$coded_data = substr($bytes, 14);

			} else {
				$mask = substr($bytes, 2, 4);
				$coded_data = substr($bytes, 6);
			}

			for ($i = 0; $i < strlen($coded_data); $i++) {
				$decodedData .= $coded_data[$i] ^ $mask[$i % 4];
			}

		} else {
			if ($dataLength === 126) {
				$decodedData = substr($bytes, 4);

			} elseif ($dataLength === 127) {
				$decodedData = substr($bytes, 10);

			} else {
				$decodedData = substr($bytes, 2);
			}
		}

		return $decodedData;
	}
}
