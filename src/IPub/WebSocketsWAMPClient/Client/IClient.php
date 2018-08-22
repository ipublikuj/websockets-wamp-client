<?php
/**
 * IClient.php
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

use React\EventLoop;

use IPub\WebSocketsWAMPClient\Exceptions;

interface IClient
{
	/**
	 * Connect client to server
	 *
	 * @return void
	 *
	 * @throws Exceptions\ConnectionException
	 */
	public function connect() : void;

	/**
	 * Disconnect from server
	 *
	 * @return void
	 */
	public function disconnect() : void;

	/**
	 * @return bool
	 */
	public function isConnected() : bool;

	/**
	 * @param string $topicUri
	 * @param string $event
	 * @param array $exclude
	 * @param array $eligible
	 *
	 * @return void
	 */
	public function publish(string $topicUri, string $event, array $exclude = [], array $eligible = []) : void;

	/**
	 * @param string $topicUri
	 *
	 * @return void
	 */
	public function subscribe(string $topicUri) : void;

	/**
	 * @param string $topicUri
	 *
	 * @return void
	 */
	public function unsubscribe(string $topicUri) : void;

	/**
	 * @param string $processUri
	 * @param array $args
	 * @param callable $successCallback
	 * @param callable $errorCallback
	 *
	 * @return void
	 */
	public function call(string $processUri, array $args, callable $successCallback = NULL, callable $errorCallback = NULL) : void;

	/**
	 * @param EventLoop\LoopInterface $loop
	 *
	 * @return void
	 */
	public function setLoop(EventLoop\LoopInterface $loop) : void;

	/**
	 * @return EventLoop\LoopInterface
	 */
	public function getLoop() : EventLoop\LoopInterface;
}
