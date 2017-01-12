<?php

// --------
// SETTINGS
// --------
$version	= 70014; // tell the node what 'version' of software our node is running
$testnet	= false;
$node		= array('46.19.137.74', 8333); // node you want to connect to (8333=mainnet, 18333=testnet)
$local		= array('127.0.0.1', 8880); // our ip and port

list($node_ip, $node_port) = $node;
list($local_ip, $local_port) = $local;

// ---------
// Functions
// ---------

// General Bitcoin Functions

function swapEndian($data) {
    return implode('', array_reverse(str_split($data, 2)));
}

function ascii2hex($ascii) {
	$hex = '';
	for ($i = 0; $i < strlen($ascii); $i++) {
		$byte = strtoupper(dechex(ord($ascii{$i})));
		$byte = str_repeat('0', 2 - strlen($byte)).$byte;
		$hex .= $byte;
	}
	return $hex;
}

function fieldSize1($field, $bytes = 1) {
	$length = $bytes * 2;
	$result = str_pad($field, $length, '0', STR_PAD_LEFT);
	return $result;
}

function byteSpaces($bytes) { // add spaces between bytes
	$bytes = implode(str_split(strtoupper($bytes), 2), ' ');
	return $bytes;
}

function socketerror() {
	$error = socket_strerror(socket_last_error());
	echo $error.PHP_EOL;
}


// Message Helper Functions 
function timestamp($time) { // convert timestamp to network byte order
	$time = dechex($time);
	$time = fieldSize1($time, 8);
	$time = swapEndian($time);
	return byteSpaces($time);
}

function networkaddress($ip, $port = '8333') { // convert ip address to network byte order
	$services = '01 00 00 00 00 00 00 00'; // 1 = NODE_NETWORK
	
	$ipv6_prefix = '00 00 00 00 00 00 00 00 00 00 FF FF';
	
	$ip = explode('.', $ip);
	$ip = array_map("dechex", $ip);
	$ip = array_map("fieldSize1", $ip);
	$ip = array_map("strtoupper", $ip);
	$ip = implode($ip, ' ');
	
	$port = dechex($port); // for some fucking reason this is big-endian
	$port = byteSpaces($port);
	
	return "$services $ipv6_prefix $ip $port";
}

function checksum($string) { // create checksum of message payloads for message headers
	$string = hex2bin($string);
	$hash = hash('sha256', hash('sha256', $string, true));
	$checksum = substr($hash, 0, 8);
	return byteSpaces($checksum);
}

// Message Functions
function makeMessage($command, $payload, $testnet = false) {

	// Header
	$magicbytes = $testnet ? '0B 11 09 07' : 'F9 BE B4 D9';
	$command = str_pad(ascii2hex($command), 24, '0', STR_PAD_RIGHT); // e.g. 76 65 72 73 69 6F 6E 00 00 00 00 00
	$payload_size = bytespaces(swapEndian(fieldSize1(dechex(strlen($payload) / 2), 4)));
	$checksum = checksum($payload);

	$header_array = [
		'magicbytes'	=> $magicbytes,
		'command'		=> $command,
		'payload_size'	=> $payload_size,
		'checksum'		=> $checksum,
	];

	$header = str_replace(' ', '', implode($header_array));
	// echo 'Header: '; print_r($header_array);
	
	return $header.$payload;

}

function makeVersionPayload($version, $node_ip, $node_port, $local_ip, $local_port) {
	
	// settings
	$services = '0D 00 00 00 00 00 00 00'; // (1 = NODE_NETORK), (D = what I've got from my 0.13.1 node)
	$user_agent = '00';
	$start_height = 0;
	
	// prepare
	$version = bytespaces(swapEndian(fieldSize1(dechex($version), 4)));
	$timestamp = timestamp(time()); // 73 43 c9 57 00 00 00 00
	$recv = networkaddress($node_ip, $node_port);
	$from = networkaddress($local_ip, $local_port);
	$nonce = bytespaces(swapEndian(fieldSize1(dechex(1), 8)));
	$start_height = bytespaces(swapEndian(fieldSize1(dechex($start_height), 4)));

	$version_array = [ // hexadecimal, network byte order
		'version'   	=> $version,		// 4 bytes (60002)
		'services'  	=> $services,		// 8 bytes
		'timestamp' 	=> $timestamp,		// 8 bytes
		'addr_recv' 	=> $recv,			// 26 bytes (8 + 16 + 2) 
		'addr_from' 	=> $from,			// 26 bytes (8 + 16 + 2)
		'nonce'			=> $nonce,			// 8 bytes
		'user_agent'	=> $user_agent,		// varint
		'start_height'	=> $start_height	// 4 bytes
	];
	
	$version_payload = str_replace(' ', '', implode($version_array));
	
	return $version_payload;

}

function commandName($data) { // http://www.asciitohex.com/
	if     ($data == '76657273696f6e0000000000') { $command = 'version'; }
	elseif ($data == '76657261636b000000000000') { $command = 'verack'; }
	elseif ($data == '70696e670000000000000000') { $command = 'ping'; }
	elseif ($data == '616464720000000000000000') { $command = 'addr'; }
	elseif ($data == '676574686561646572730000') { $command = 'getheaders'; }
	elseif ($data == '696e76000000000000000000') { $command = 'inv'; }
	elseif ($data == '676574646174610000000000') { $command = 'getdata'; }
	elseif ($data == '747800000000000000000000') { $command = 'tx'; }
	elseif ($data == '626c6f636b00000000000000') { $command = 'block'; }
	else { $command = $data; }
	
	return $command;
}


// -----------------
// 1. SOCKET CONNECT
// -----------------

// i. Create Version Message (needs to be sent to node you want to connect to)
$payload = makeVersionPayload($version, $node_ip, $node_port, $local_ip, $local_port);
$message = makeMessage('version', $payload, $testnet);
$message_size = strlen($message) / 2; // the size of the message (in bytes) being sent

// ii. Connect to socket and send version message
echo "Connecting to $node_ip...\n";
$socket = socket_create(AF_INET, SOCK_STREAM, 6); socketerror(); // IPv4, TCP uses this type, TCP protocol
socket_connect($socket, $node_ip, $node_port);
echo "Sending version message...\n\n";
socket_send($socket, hex2bin($message), $message_size, 0); // don't forget to send message in binary

// iii. Keep receiving data 
while (true) {

	// read whats written to the socket 1 byte at a time
	while (socket_recv($socket, $byte, 1, MSG_DONTWAIT)) {
		$byte = bin2hex($byte);
		echo $byte;
	
		// tiny sleep to prevent looping insanely fast and using up 100% CPU power on one core
		usleep(5000); // 1/1000th of a second
	
	}

}



/* Resources
	- https://wiki.bitcoin.com/w/Network
	- https://en.bitcoin.it/wiki/Protocol_documentation
	- https://coinlogic.wordpress.com/2014/03/09/the-bitcoin-protocol-4-network-messages-1-version/
*/
