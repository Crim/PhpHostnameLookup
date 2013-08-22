<?php

/*
 *                                  1  1  1  1  1  1
      0  1  2  3  4  5  6  7  8  9  0  1  2  3  4  5
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |                      ID                       |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |QR|   Opcode  |AA|TC|RD|RA|   Z    |   RCODE   |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |                    QDCOUNT                    |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |                    ANCOUNT                    |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |                    NSCOUNT                    |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+
    |                    ARCOUNT                    |
    +--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+--+


ID              A 16 bit identifier assigned by the program that
                generates any kind of query.  This identifier is copied
                the corresponding reply and can be used by the requester
                to match up replies to outstanding queries.

QR              A one bit field that specifies whether this message is a
                query (0), or a response (1).

OPCODE          A four bit field that specifies kind of query in this
                message.  This value is set by the originator of a query
                and copied into the response.  The values are:

                0               a standard query (QUERY)

                1               an inverse query (IQUERY)

                2               a server status request (STATUS)

                3-15            reserved for future use

AA              Authoritative Answer - this bit is valid in responses,
                and specifies that the responding name server is an
                authority for the domain name in question section.

                Note that the contents of the answer section may have
                multiple owner names because of aliases.  The AA bit
                corresponds to the name which matches the query name, or
                the first owner name in the answer section.

TC              TrunCation - specifies that this message was truncated
                due to length greater than that permitted on the
                transmission channel.

RD              Recursion Desired - this bit may be set in a query and
                is copied into the response.  If RD is set, it directs
                the name server to pursue the query recursively.
                Recursive query support is optional.

RA              Recursion Available - this be is set or cleared in a
                response, and denotes whether recursive query support is
                available in the name server.

Z               Reserved for future use.  Must be zero in all queries
                and responses.

RCODE           Response code - this 4 bit field is set as part of
                responses.  The values have the following
                interpretation:

                0               No error condition

                1               Format error - The name server was
                                unable to interpret the query.

                2               Server failure - The name server was
                                unable to process this query due to a
                                problem with the name server.

                3               Name Error - Meaningful only for
                                responses from an authoritative name
                                server, this code signifies that the
                                domain name referenced in the query does
                                not exist.

                4               Not Implemented - The name server does
                                not support the requested kind of query.

                5               Refused - The name server refuses to
                                perform the specified operation for
                                policy reasons.  For example, a name
                                server may not wish to provide the
                                information to the particular requester,
                                or a name server may not wish to perform
                                a particular operation (e.g., zone
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

	public function __construct($nameserver, $port = 53, $timeout = 500000)
	{
		$this->nameserver = $nameserver;
		$this->timeout = $timeout;
		$this->port = $port;
	}

	public function resolveIpAddress($ipAddr)
	{
		// Open socket
		$this->openSocket();

		// Send request
		$bytesWritten = $this->sendRequest($ipAddr);

		// Read response
		$response = $this->readFromSocket(1000);

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

	protected function parseResponse($response, $requestSize)
	{
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

	protected function readFromSocket($n)
	{
		if ($this->debug) {
			$startTime = microtime(true);
		}

		$response = socket_read($this->socket, 1000);

		if ($this->debug) {
			echo "ReadFromSocketTime:".(microtime(true)-$startTime)."\n";
			$startTime = microtime(true);
		}

		return $response;
	}

	protected function writeToSocket($data)
	{
		$len = strlen($data);

		while (true) {
			if (is_null($this->socket)) {
				throw new \Exception('Socket was null!');
			}
			$sent = socket_write($this->socket, $data, $len);
			if ($sent === false) {
				throw new \Exception ("Error sending data");
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


$dns = new DnsClient('8.8.8.8', 53);
