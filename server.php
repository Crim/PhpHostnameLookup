<?php

// create unix udp socket
$socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);

$socket = stream_socket_server("udp://127.0.0.1:1053", $errno, $errstr, STREAM_SERVER_BIND);
if (!$socket) {
	die("$errstr ($errno)");
}

do {
	$pkt = stream_socket_recvfrom($socket, 1, 0, $peer);
	echo "Connection from $peer\n";
	sleep(1);
	stream_socket_sendto($socket, $msg, 0, $peer);
} while ($pkt !== false);