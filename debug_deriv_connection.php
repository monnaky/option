<?php
// Strict reproduction of WebSocketClient.php connection logic
$host = 'ws.derivws.com';
$port = 443;
$path = '/websockets/v3?app_id=105326'; // Using default app_id from .env

$protocol = 'ssl';
$address = "{$protocol}://{$host}:{$port}";

echo "1. Connecting to {$address} using stream_socket_client...\n";

// Mimic the exact SSL options from WebSocketClient.php
$sslOptions = [
    'verify_peer' => false,
    'verify_peer_name' => false,
    'allow_self_signed' => true,
];

$context = stream_context_create([
    'ssl' => $sslOptions,
]);

$timeout = 5;
$socket = @stream_socket_client(
    $address,
    $errno,
    $errstr,
    $timeout,
    STREAM_CLIENT_CONNECT,
    $context
);

if (!$socket) {
    echo "ERROR: stream_socket_client failed: $errstr ($errno)\n";
    exit(1);
}

echo "SUCCESS: Socket connected.\n";

// Set timeout as per WebSocketClient
stream_set_timeout($socket, 8);
stream_set_blocking($socket, true);

// 2. Perform Handshake (Mimic performHandshake)
echo "2. Sending Handshake...\n";

$key = base64_encode(openssl_random_pseudo_bytes(16));
$hostHeader = $host; // HTTPS default port

$request = "GET {$path} HTTP/1.1\r\n";
$request .= "Host: {$hostHeader}\r\n";
$request .= "Upgrade: websocket\r\n";
$request .= "Connection: Upgrade\r\n";
$request .= "Sec-WebSocket-Key: {$key}\r\n";
$request .= "Sec-WebSocket-Version: 13\r\n";
$request .= "Origin: https://{$host}\r\n";
$request .= "\r\n";

// This is where the app failed
echo "   Writing " . strlen($request) . " bytes to socket...\n";
$written = fwrite($socket, $request);

if ($written === false) {
    echo "ERROR: fwrite failed! (This matches the app error)\n";
    $info = stream_get_meta_data($socket);
    print_r($info);
    exit(1);
} else {
    echo "SUCCESS: fwrite wrote $written bytes.\n";
}

// 3. Read Response
echo "3. Reading Response...\n";
$response = fread($socket, 2048);
if ($response === false) {
    echo "ERROR: fread failed.\n";
} else {
    echo "--- RESPONSE ---\n";
    echo substr($response, 0, 500) . "...\n";
}

fclose($socket);
?>
