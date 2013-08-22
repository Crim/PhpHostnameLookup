<?php

/**
 * Class DnsClient
 * Quick and dirty UDP Dns IP => Hostname lookups
 * with timeout
 */

class DnsClient
{
	// Configuration
	protected $nameserver = null;
	protected $port = 53;
	protected $timeout = 1000;

	// Internals
	protected $socket = null;
	protected $debug = true;

	/**
	 * @param String $nameserver
	 * @param int $port
	 * @param int $timeout in microseconds
	 */
	public function __construct($nameserver, $port = 53, $timeout = 500000)
	{
		$this->nameserver = $nameserver;
		$this->timeout = $timeout;
		$this->port = $port;
	}

	/**
	 * resolveIpAddress
	 *
	 * @param String $ipAddr
	 * @return string
	 */
	public function resolveIpAddress($ipAddr)
	{
		// Open socket
		$this->openSocket();

		// Send request
		$bytesWritten = $this->sendRequest($ipAddr);

		try {
			// Read response
			$response = $this->readFromSocket(1000);
		} catch (UnexpectedValueException $e) {
			// Catch failed to read exception
			$this->closeSocket();
			return $ipAddr;
		}

		// parse response
		$returnValue = $this->parseResponse($response, $bytesWritten);

		// Close socket
		$this->closeSocket();

		// If false
		if (!$returnValue) {
			// No hostname
			return $ipAddr;
		}
		return $returnValue;
	}

	/**
	 * parseResponse
	 *
	 * Swiped this code from king dot macro at gmail dot com
	 * from the comments on http://www.php.net/gethostbyaddr
	 *
	 * @param String $response
	 * @param $requestSize
	 * @return bool|string
	 */
	protected function parseResponse($response, $requestSize)
	{
		// If empty or false response
		if (!$response) {
			return false;
		}

		$type = @unpack("s", substr($response, $requestSize + 2));
		if ($type[1] != 0x0C00) {
			return false;
		}

		// set up our variables
		$host = "";
		$len = 0;

		// set our pointer at the beginning of the hostname
		// uses the request size from earlier rather than work it out
		$position = $requestSize + 12;

		// reconstruct hostname
		do {
			// get segment size
			$len = unpack("c", substr($response, $position));
			// null terminated string, so length 0 = finished
			if ($len[1] == 0) {
				// return the hostname, without the trailing .
				return substr($host, 0, strlen($host) - 1);
			}

			// add segment to our host
			$host .= substr($response, $position + 1, $len[1]) . ".";

			// move pointer on to the next segment
			$position += $len[1] + 1;
		} while ($len != 0);
		return false;
	}

	/**
	 * sendRequest
	 *
	 * @param String $ip
	 * @return int - number of bytes written to the socket
	 */
	protected function sendRequest($ip)
	{
		if ($this->debug) {
			$startTime = microtime(true);
		}
		// Need txt id, 16 bits, or 2 bytes
		$id = rand(10,99);
		$headerBits = 0x0000 | 0x0100;
		$header = pack('nnnnn', $headerBits, 1, 0, 0, 0);

		// explode ip
		$bits = explode('.', $ip);
		$ipData = '';
		for ($x=3; $x>=0; $x--)
		{
			// needs a byte to indicate the length of each segment of the request
			$len = strlen($bits[$x]);
			$ipData.= pack('C', $len).$bits[$x];
		}

		// footer
		$footer = pack('C', 7).'in-addr'.pack('C','4').'arpa'."\0\0\x0C\0\1";

		// Build message and send
		$bytesWritten = $this->writeToSocket($id.$header.$ipData.$footer);

		if ($this->debug) {
			echo "WriteToSocketTime:".(microtime(true)-$startTime)."\n";
		}

		return $bytesWritten;
	}

	/**
	 * readFromSocket
	 *
	 * @param $n - number of bytes to read
	 * @return string
	 */
	protected function readFromSocket($n)
	{
		if ($this->debug) {
			$startTime = microtime(true);
		}

		// Read from socket
		$response = socket_read($this->socket, 1000);

		// Validate response
		if ($response === false) {
			throw new UnexpectedValueException('Failed to read from socket Err#'.socket_last_error($this->socket).' - '.socket_strerror(socket_last_error($this->socket)));
		}

		if ($this->debug) {
			echo "ReadFromSocketTime:".(microtime(true)-$startTime)."\n";
		}

		return $response;
	}

	/**
	 * writeToSocket
	 *
	 * @param String $data
	 * @return int - number of bytes written to the socket
	 * @throws Exception
	 */
	protected function writeToSocket($data)
	{
		$len = strlen($data);

		while (true) {
			if (is_null($this->socket)) {
				throw new Exception('Socket was null!');
			}
			$sent = socket_write($this->socket, $data, $len);
			if ($sent === false) {
				throw new Exception ("Error sending data");
			}
			// Check if the entire message has been sented
			if ($sent < $len) {
				// If not sent the entire message.
				// Get the part of the message that has not yet been sented as message
				$data = substr($data, $sent);
				// Get the length of the not sented part
				$len -= $sent;
			} else {
				break;
			}
		}
		return $sent;
	}

	/**
	 * openSocket
	 *
	 * @return resource
	 * @throws Exception
	 */
	protected function openSocket()
	{
		if ($this->debug) {
			$startTime = microtime(true);
		}

		// Create and setup socket
		$this->socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
		if (!$this->socket) {
			throw new Exception('Unable to create socket!');
		}

		// Set options
		socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 0, 'usec' => $this->timeout));
		socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 0, 'usec' => $this->timeout));
		$v = socket_connect($this->socket, $this->nameserver, $this->port);

		// Handle error
		if (!$v || !$this->socket) {
			throw new Exception('Unable to connect to '.$this->nameserver.' - Err#'.socket_last_error($this->socket).' - '.socket_strerror(socket_last_error($this->socket)));
		}

		if ($this->debug) {
			echo "OpenSocketTime:".(microtime(true)-$startTime)."\n";
			$startTime = microtime(true);
		}

		return $this->socket;
	}

	/**
	 * closeSocket
	 */
	protected function closeSocket()
	{
		if ($this->debug) {
			$starTime = microtime(true);
		}

		if ($this->socket) {
			socket_close($this->socket);
			$this->socket = null;
		}

		if ($this->debug) {
			echo "CloseSocketTime:".(microtime(true)-$starTime)."\n";
		}
	}
}