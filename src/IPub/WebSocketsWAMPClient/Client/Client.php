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
 * @method void onConnect()
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

	const TYPE_ID_WELCOME = 0;
	const TYPE_ID_PREFIX = 1;
	const TYPE_ID_CALL = 2;
	const TYPE_ID_CALL_RESULT = 3;
	const TYPE_ID_CALL_ERROR = 4;
	const TYPE_ID_SUBSCRIBE = 5;
	const TYPE_ID_UNSUBSCRIBE = 6;
	const TYPE_ID_PUBLISH = 7;
	const TYPE_ID_EVENT = 8;

	/**
	 * @var \Closure
	 */
	public $onConnect = [];

	/**
	 * @var \Closure
	 */
	public $onOpen = [];

	/**
	 * @var \Closure
	 */
	public $onEvent = [];

	/**
	 * @var EventLoop\LoopInterface
	 */
	private $loop;

	/**
	 * @var Configuration
	 */
	private $configuration;

	/**
	 * @var Stream\DuplexResourceStream
	 */
	private $stream;

	/**
	 * @var bool
	 */
	private $connected = FALSE;

	/**
	 * @var callable[]
	 */
	private $sucessCallbacks = [];

	/**
	 * @var callable[]
	 */
	private $errorCallbacks = [];

	/**
	 * @param EventLoop\LoopInterface $loop
	 * @param Configuration $configuration
	 */
	function __construct(EventLoop\LoopInterface $loop, Configuration $configuration)
	{
		$this->loop = $loop;
		$this->configuration = $configuration;
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
		$resource = @stream_socket_client('tcp://' . $this->configuration->getHost() . ':' . $this->configuration->getPort());

		if (!$resource) {
			throw new Exceptions\ConnectionException('Opening socket failed.');
		}

		$this->stream = new Stream\DuplexResourceStream($resource, $this->getLoop());

		$this->stream->on('data', function ($chunk) : void {
			$data = $this->parseChunk($chunk);

			$this->parseData($data);
		});

		$this->stream->on('close', function () : void {
			// When connection is closed, stop loop & end running script
			$this->loop->stop();
		});

		$this->stream->write($this->createHeader());

		$this->onConnect();
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
	public function call(string $processUri, array $args, callable $successCallback = NULL, callable $errorCallback = NULL) : void
	{
		$callId = $this->generateAlphaNumToken(16);

		$this->sucessCallbacks[$callId] = $successCallback;
		$this->errorCallbacks[$callId] = $errorCallback;

		$data = [
			self::TYPE_ID_CALL,
			$callId,
			$processUri,
			$args,
		];

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

						if (isset($this->sucessCallbacks[$id])) {
							$callback = $this->sucessCallbacks[$id];
							$callback(
								Utils\ArrayHash::from(isset($data[2]) ? (is_array($data[2]) ? $data[2] : [$data[2]]) : [])
							);

							unset($this->sucessCallbacks[$id]);
						}
					}
					break;

				case self::TYPE_ID_CALL_ERROR:
					if (isset($data[1])) {
						$id = $data[1];

						if (isset($this->errorCallbacks[$id])) {
							$callback = $this->errorCallbacks[$id];
							$callback(
								// Topic
								(isset($data[2]) ? (string) $data[2] : NULL),

								// Error exception message
								(isset($data[3]) ? (string) $data[3] : NULL),

								// Additional error data
								Utils\ArrayHash::from(isset($data[4]) ? (is_array($data[4]) ? $data[4] : [$data[4]]) : [])
							);

							unset($this->errorCallbacks[$id]);
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
	 * @param array $response
	 *
	 * @return void
	 *
	 * @throws Utils\JsonException
	 */
	private function parseData(array $response) : void
	{
		if (!$this->connected && isset($response['Sec-Websocket-Accept'])) {
			if (base64_encode(pack('H*', sha1($this->configuration->getKey() . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11'))) === $response['Sec-Websocket-Accept']) {
				$this->connected = TRUE;
			}
		}

		if ($this->connected && !empty($response['content'])) {
			$content = str_replace("\r\n", '', trim($response['content']));

			if (preg_match('/(\[[^\]]+\])/', $content, $match)) {
				try {
					$parsedContent = Utils\Json::decode($match[1], Utils\Json::FORCE_ARRAY);

				} catch (Utils\JsonException $ex) {
					$parsedContent = NULL;
				}

				if (is_array($parsedContent)) {
					unset($response['status']);
					unset($response['content']);

					$this->receiveData($parsedContent, $response);
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
		$host = $this->configuration->getHost();

		if ($host === '127.0.0.1' || $host === '0.0.0.0') {
			$host = 'localhost';
		}

		$origin = $this->configuration->getOrigin() ? $this->configuration->getOrigin() : 'null';

		return
			"GET {$this->configuration->getPath()} HTTP/1.1" . "\r\n" .
			"Origin: {$origin}" . "\r\n" .
			"Host: {$host}:{$this->configuration->getPort()}" . "\r\n" .
			"Sec-WebSocket-Key: {$this->configuration->getKey()}" . "\r\n" .
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
	private function generateAlphaNumToken(int $length) : string
	{
		$characters = str_split('abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789');

		srand((int) microtime() * 1000000);

		$token = '';

		do {
			shuffle($characters);
			$token .= $characters[mt_rand(0, (count($characters) - 1))];
		} while (strlen($token) < $length);

		return $token;
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
