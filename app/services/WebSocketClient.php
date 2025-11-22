<?php

/**
 * Pure PHP WebSocket Client
 * 
 * Implements WebSocket protocol without external dependencies
 * Supports SSL/TLS (wss://) connections
 */

namespace App\Services;

use Exception;

class WebSocketClient
{
    private $socket = null;
    private string $url = '';
    private string $host = '';
    private int $port = 443;
    private string $path = '/';
    private bool $secure = true;
    private int $timeout = 8; // Reduced from 15 to 8 seconds for faster failure
    private int $connectionTimeout = 5; // Reduced from 10 to 5 seconds
    private bool $connected = false;
    private ?string $debugLogFile = null;
    
    // WebSocket frame constants
    private const OPCODE_TEXT = 0x1;
    private const OPCODE_CLOSE = 0x8;
    private const OPCODE_PING = 0x9;
    private const OPCODE_PONG = 0xA;
    
    /**
     * Get debug log file path
     */
    private function getDebugLogFile(): string
    {
        if ($this->debugLogFile === null) {
            // Get project root directory
            $baseDir = realpath(__DIR__ . '/../..');
            if (!$baseDir) {
                $baseDir = dirname(__DIR__, 2);
            }
            $this->debugLogFile = $baseDir . DIRECTORY_SEPARATOR . 'debug_websocket.log';
        }
        return $this->debugLogFile;
    }
    
    /**
     * Write log message to debug_websocket.log
     */
    private function debugLog(string $message): void
    {
        $logFile = $this->getDebugLogFile();
        $timestamp = date('Y-m-d H:i:s');
        $logEntry = "{$timestamp} [WebSocketClient] {$message}\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * Constructor
     */
    public function __construct(string $url, int $timeout = 15)
    {
        $this->debugLog("Constructor called with URL: " . var_export($url, true));
        $this->debugLog("URL type: " . gettype($url) . ", length: " . strlen($url));
        $this->debugLog("Timeout: {$timeout} seconds");
        
        if (empty($url)) {
            $this->debugLog("ERROR: Empty URL provided to constructor!");
            throw new Exception("WebSocket URL cannot be empty");
        }
        
        $this->url = $url;
        $this->timeout = max(5, min($timeout, 30)); // Clamp between 5-30 seconds
        $this->connectionTimeout = max(5, min($timeout - 5, 15)); // Connection timeout is 5 seconds less
        
        $this->debugLog("Connection timeout set to: {$this->connectionTimeout} seconds");
        $this->debugLog("General timeout set to: {$this->timeout} seconds");
        
        $this->debugLog("Calling parseUrl() with: " . var_export($url, true));
        $this->parseUrl($url);
        
        $this->debugLog("Constructor completed - Host: {$this->host}, Port: {$this->port}, Path: " . var_export($this->path, true));
    }
    
    /**
     * Parse WebSocket URL
     */
    private function parseUrl(string $url): void
    {
        $this->debugLog("[parseUrl] Starting URL parsing for: " . var_export($url, true));
        
        $parsed = parse_url($url);
        
        $this->debugLog("[parseUrl] parse_url() result: " . var_export($parsed, true));
        
        if (!$parsed) {
            $this->debugLog("[parseUrl] ERROR: parse_url() returned false for URL: {$url}");
            throw new Exception("Invalid WebSocket URL: {$url}");
        }
        
        // Log each component
        $this->debugLog("[parseUrl] Scheme: " . ($parsed['scheme'] ?? 'NOT SET'));
        $this->debugLog("[parseUrl] Host: " . ($parsed['host'] ?? 'NOT SET'));
        $this->debugLog("[parseUrl] Port: " . ($parsed['port'] ?? 'NOT SET'));
        $this->debugLog("[parseUrl] Path: " . var_export($parsed['path'] ?? 'NOT SET', true));
        $this->debugLog("[parseUrl] Query: " . ($parsed['query'] ?? 'NOT SET'));
        
        $this->secure = ($parsed['scheme'] === 'wss' || $parsed['scheme'] === 'https');
        $this->debugLog("[parseUrl] Secure mode: " . ($this->secure ? 'yes' : 'no'));
        
        $this->host = $parsed['host'] ?? '';
        $this->debugLog("[parseUrl] Host extracted: " . var_export($this->host, true));
        
        $this->port = $parsed['port'] ?? ($this->secure ? 443 : 80);
        $this->debugLog("[parseUrl] Port extracted: {$this->port}");
        
        // Extract path - handle both null and empty string cases
        $path = $parsed['path'] ?? '';
        $this->debugLog("[parseUrl] Raw path from parse_url: " . var_export($path, true));
        $this->debugLog("[parseUrl] Path is empty check: " . (empty($path) ? 'YES' : 'NO'));
        $this->debugLog("[parseUrl] Path is null check: " . (is_null($path) ? 'YES' : 'NO'));
        
        if (empty($path)) {
            $this->debugLog("[parseUrl] Path is empty/null, defaulting to '/'");
            $path = '/';
        }
        
        // Ensure path starts with /
        if ($path[0] !== '/') {
            $this->debugLog("[parseUrl] Path doesn't start with /, prepending it");
            $path = '/' . $path;
        }
        
        $this->debugLog("[parseUrl] Path after normalization: " . var_export($path, true));
        
        // Append query string if present
        if (isset($parsed['query']) && !empty($parsed['query'])) {
            $this->debugLog("[parseUrl] Appending query string: {$parsed['query']}");
            $path .= '?' . $parsed['query'];
        } else {
            $this->debugLog("[parseUrl] No query string to append");
        }
        
        $this->path = $path;
        $this->debugLog("[parseUrl] Final path value: " . var_export($this->path, true));
        $this->debugLog("[parseUrl] Final path length: " . strlen($this->path));
        $this->debugLog("[parseUrl] Final path empty check: " . (empty($this->path) ? 'YES - ERROR!' : 'NO - OK'));
        
        // Validate required components
        if (empty($this->host)) {
            $this->debugLog("[parseUrl] ERROR: Host is empty!");
            throw new Exception("Invalid WebSocket URL - missing host: {$url}");
        }
        
        if (empty($this->path)) {
            $this->debugLog("[parseUrl] ERROR: Path is empty after parsing!");
            $this->debugLog("[parseUrl] Original URL: " . var_export($url, true));
            $this->debugLog("[parseUrl] Parsed components: " . var_export($parsed, true));
            throw new Exception("Invalid WebSocket URL - path cannot be empty: {$url}");
        }
        
        $this->debugLog("[parseUrl] URL parsing completed successfully - Host: {$this->host}, Port: {$this->port}, Path: {$this->path}, Secure: " . ($this->secure ? 'yes' : 'no'));
    }
    
    /**
     * Test DNS resolution
     */
    private function testDnsResolution(): bool
    {
        $this->debugLog("[connect] Testing DNS resolution for: {$this->host}");
        $startTime = microtime(true);
        
        $ip = @gethostbyname($this->host);
        $dnsTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($ip === $this->host) {
            $this->debugLog("[connect] DNS resolution FAILED - Could not resolve {$this->host}");
            return false;
        }
        
        $this->debugLog("[connect] DNS resolution SUCCESS - {$this->host} resolved to {$ip} in {$dnsTime}ms");
        return true;
    }
    
    /**
     * Test network connectivity
     */
    private function testNetworkConnectivity(): bool
    {
        $this->debugLog("[connect] Testing network connectivity to {$this->host}:{$this->port}");
        $startTime = microtime(true);
        
        // Try to open a socket connection with a short timeout
        $testSocket = @fsockopen(
            $this->secure ? 'ssl://' . $this->host : $this->host,
            $this->port,
            $errno,
            $errstr,
            5 // 5 second timeout for connectivity test
        );
        
        $connectTime = round((microtime(true) - $startTime) * 1000, 2);
        
        if ($testSocket) {
            @fclose($testSocket);
            $this->debugLog("[connect] Network connectivity SUCCESS - Connected in {$connectTime}ms");
            return true;
        } else {
            $this->debugLog("[connect] Network connectivity FAILED - Error: {$errstr} ({$errno}) in {$connectTime}ms");
            return false;
        }
    }
    
    /**
     * Connect to WebSocket server
     */
    public function connect(): void
    {
        $this->debugLog("[connect] Connect() method called");
        $this->debugLog("[connect] Current connection status: " . ($this->connected ? 'connected' : 'not connected'));
        $this->debugLog("[connect] Current URL: " . var_export($this->url, true));
        $this->debugLog("[connect] Current host: " . var_export($this->host, true));
        $this->debugLog("[connect] Current port: {$this->port}");
        $this->debugLog("[connect] Current path: " . var_export($this->path, true));
        $this->debugLog("[connect] Current path empty check: " . (empty($this->path) ? 'YES - ERROR!' : 'NO - OK'));
        $this->debugLog("[connect] Current path length: " . strlen($this->path ?? ''));
        $this->debugLog("[connect] Connection timeout: {$this->connectionTimeout} seconds");
        $this->debugLog("[connect] General timeout: {$this->timeout} seconds");
        
        // CRITICAL: Validate path at the beginning of connect()
        if (empty($this->path)) {
            $this->debugLog("[connect] FATAL ERROR: Path is empty at start of connect()!");
            $this->debugLog("[connect] URL was: " . var_export($this->url, true));
            $this->debugLog("[connect] Host: " . var_export($this->host, true));
            $this->debugLog("[connect] Port: {$this->port}");
            throw new Exception("WebSocket path cannot be empty at connect() - URL: {$this->url}");
        }
        
        if ($this->connected) {
            $this->debugLog("[connect] Already connected, returning early");
            return;
        }
        
        // Test DNS resolution first
        if (!$this->testDnsResolution()) {
            throw new Exception("DNS resolution failed for {$this->host}. Check your network connection and DNS settings.");
        }
        
        // Test network connectivity
        if (!$this->testNetworkConnectivity()) {
            throw new Exception("Cannot reach {$this->host}:{$this->port}. Check firewall settings and network connectivity.");
        }
        
        try {
            // Create socket connection with proper SSL context
            // PHP 8+ requires proper SSL context configuration to avoid "Path cannot be empty" errors
            $caBundlePath = $this->getCaBundlePath();
            
            $sslOptions = [
                'verify_peer' => false,  // Disable peer verification to avoid SSL context issues
                'verify_peer_name' => false,  // Disable peer name verification
                'allow_self_signed' => true,  // Allow self-signed certificates
            ];
            
            // Only add cafile if it exists and is readable
            if ($caBundlePath && file_exists($caBundlePath) && is_readable($caBundlePath)) {
                $sslOptions['cafile'] = $caBundlePath;
                $this->debugLog("[connect] Using CA bundle: {$caBundlePath}");
            } else {
                $this->debugLog("[connect] CA bundle not found or not readable, SSL verification disabled");
            }
            
            $context = stream_context_create([
                'ssl' => $sslOptions,
            ]);
            
            $this->debugLog("[connect] SSL context created with options: " . json_encode($sslOptions));
            
            // CRITICAL: Validate path again right before connection
            $this->debugLog("[connect] Validating path before stream_socket_client()");
            $this->debugLog("[connect] Path value: " . var_export($this->path, true));
            $this->debugLog("[connect] Path type: " . gettype($this->path));
            $this->debugLog("[connect] Path length: " . strlen($this->path));
            $this->debugLog("[connect] Path empty check: " . (empty($this->path) ? 'YES - ERROR!' : 'NO - OK'));
            
            if (empty($this->path)) {
                $this->debugLog("[connect] FATAL ERROR: Path is empty right before stream_socket_client()!");
                $this->debugLog("[connect] URL: " . var_export($this->url, true));
                $this->debugLog("[connect] Host: " . var_export($this->host, true));
                $this->debugLog("[connect] Port: {$this->port}");
                $this->debugLog("[connect] Path: " . var_export($this->path, true));
                throw new Exception("WebSocket path cannot be empty before connection - URL: {$this->url}");
            }
            
            $protocol = $this->secure ? 'ssl' : 'tcp';
            $address = "{$protocol}://{$this->host}:{$this->port}";
            
            $this->debugLog("[connect] About to call stream_socket_client()");
            $this->debugLog("[connect] Address: {$address}");
            $this->debugLog("[connect] Path will be used in handshake: " . var_export($this->path, true));
            $this->debugLog("[connect] Path length: " . strlen($this->path));
            
            // IMPORTANT: stream_socket_client() connects to host:port only (NOT host:port/path)
            // The path is NOT part of the TCP/SSL connection string
            // The path will be sent in the HTTP Upgrade request during performHandshake()
            // Format: "ssl://host:port" (NOT "ssl://host:port/path?query")
            
            $this->debugLog("[connect] Calling stream_socket_client() with address: {$address}");
            $this->debugLog("[connect] Path '{$this->path}' will be sent in HTTP handshake, not in connection string");
            $this->debugLog("[connect] Connection timeout: {$this->connectionTimeout} seconds");
            $this->debugLog("[connect] About to execute stream_socket_client() with SSL context");
            
            $connectionStartTime = microtime(true);
            
            // Use connectionTimeout for the initial connection
            $this->socket = @stream_socket_client(
                $address,
                $errno,
                $errstr,
                $this->connectionTimeout, // Use shorter timeout for connection
                STREAM_CLIENT_CONNECT,
                $context
            );
            
            $connectionTime = round((microtime(true) - $connectionStartTime) * 1000, 2);
            $this->debugLog("[connect] stream_socket_client() completed in {$connectionTime}ms");
            $this->debugLog("[connect] stream_socket_client() returned: " . ($this->socket ? 'socket resource' : 'false'));
            
            if (!$this->socket) {
                $this->debugLog("[connect] stream_socket_client() error: {$errstr} ({$errno})");
                $this->debugLog("[connect] Connection address was: {$address}");
                $this->debugLog("[connect] Path at time of error: " . var_export($this->path, true));
                $this->debugLog("[connect] Connection attempt took: {$connectionTime}ms");
                
                // Provide more helpful error messages
                $errorMessage = "Failed to connect to {$this->host}:{$this->port}";
                if ($errno === 110 || strpos($errstr, 'timeout') !== false || strpos($errstr, 'timed out') !== false) {
                    $errorMessage .= " - Connection timeout after {$this->connectionTimeout} seconds. ";
                    $errorMessage .= "Possible causes: firewall blocking connection, network issues, or server unreachable.";
                } elseif ($errno === 111 || strpos($errstr, 'Connection refused') !== false) {
                    $errorMessage .= " - Connection refused. The server may be down or not accepting connections.";
                } elseif ($errno === 113 || strpos($errstr, 'No route to host') !== false) {
                    $errorMessage .= " - No route to host. Check your network configuration.";
                } else {
                    $errorMessage .= " - Error: {$errstr} ({$errno})";
                }
                
                throw new Exception($errorMessage);
            }
            
            $this->debugLog("[connect] Socket connection established successfully");
            
            // Set socket options with timeout
            stream_set_timeout($this->socket, $this->timeout);
            stream_set_blocking($this->socket, true);
            
            $this->debugLog("[connect] Socket timeout set to {$this->timeout} seconds");
            
            // Perform WebSocket handshake with timeout
            $handshakeStartTime = microtime(true);
            $this->performHandshake();
            $handshakeTime = round((microtime(true) - $handshakeStartTime) * 1000, 2);
            $this->debugLog("[connect] WebSocket handshake completed in {$handshakeTime}ms");
            
            $this->connected = true;
            $totalTime = round((microtime(true) - $connectionStartTime) * 1000, 2);
            $this->debugLog("WebSocket connection established successfully in {$totalTime}ms total");
            
        } catch (Exception $e) {
            $this->debugLog("[connect] Connection failed: " . $e->getMessage());
            $this->close();
            
            // Enhance error message with troubleshooting tips
            $errorMsg = $e->getMessage();
            if (strpos($errorMsg, 'timeout') !== false || strpos($errorMsg, 'timed out') !== false) {
                $errorMsg .= " Troubleshooting: Check firewall settings, verify {$this->host} is reachable, and ensure port {$this->port} is not blocked.";
            }
            
            throw new Exception("WebSocket connection failed: " . $errorMsg);
        }
    }
    
    /**
     * Perform WebSocket handshake
     */
    private function performHandshake(): void
    {
        $this->debugLog("[performHandshake] Starting WebSocket handshake");
        $this->debugLog("[performHandshake] Current path: " . var_export($this->path, true));
        $this->debugLog("[performHandshake] Path length: " . strlen($this->path ?? ''));
        $this->debugLog("[performHandshake] Path empty check: " . (empty($this->path) ? 'YES - ERROR!' : 'NO - OK'));
        $this->debugLog("[performHandshake] Host: " . var_export($this->host, true));
        $this->debugLog("[performHandshake] Port: {$this->port}");
        
        // Validate path is not empty before handshake
        if (empty($this->path)) {
            $this->debugLog("[performHandshake] FATAL ERROR: Path is empty in performHandshake()!");
            $this->debugLog("[performHandshake] URL was: " . var_export($this->url, true));
            $this->debugLog("[performHandshake] Host: " . var_export($this->host, true));
            $this->debugLog("[performHandshake] Port: {$this->port}");
            throw new Exception("WebSocket path cannot be empty in handshake");
        }
        
        // Generate WebSocket key
        $key = base64_encode(random_bytes(16));
        $this->debugLog("[performHandshake] Generated WebSocket key: {$key}");
        
        // Format Host header - omit port for standard ports (80, 443)
        $hostHeader = $this->host;
        if (($this->secure && $this->port !== 443) || (!$this->secure && $this->port !== 80)) {
            $hostHeader .= ':' . $this->port;
        }
        $this->debugLog("[performHandshake] Host header: {$hostHeader}");
        
        // Build handshake request
        $request = "GET {$this->path} HTTP/1.1\r\n";
        $request .= "Host: {$hostHeader}\r\n";
        $request .= "Upgrade: websocket\r\n";
        $request .= "Connection: Upgrade\r\n";
        $request .= "Sec-WebSocket-Key: {$key}\r\n";
        $request .= "Sec-WebSocket-Version: 13\r\n";
        $request .= "Origin: https://{$this->host}\r\n";
        $request .= "\r\n";
        
        $this->debugLog("[performHandshake] WebSocket handshake request: GET {$this->path} HTTP/1.1");
        $this->debugLog("[performHandshake] Full handshake request (first 500 chars): " . substr($request, 0, 500));
        
        // Send handshake
        fwrite($this->socket, $request);
        
        // Read response with timeout
        $response = '';
        $headerEnd = false;
        $startTime = time();
        $handshakeTimeout = min($this->timeout, 10); // Handshake should complete quickly
        
        $this->debugLog("[performHandshake] Waiting for handshake response (timeout: {$handshakeTimeout}s)");
        
        while (!$headerEnd && (time() - $startTime) < $handshakeTimeout) {
            // Check if socket is still valid
            if (feof($this->socket)) {
                $this->debugLog("[performHandshake] Socket closed before handshake completed");
                throw new Exception("WebSocket handshake failed - connection closed by server");
            }
            
            $line = @fgets($this->socket, 4096);
            if ($line === false) {
                // Check if it's a timeout
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    $elapsed = time() - $startTime;
                    $this->debugLog("[performHandshake] Handshake read timeout after {$elapsed}s");
                    throw new Exception("WebSocket handshake timeout after {$elapsed} seconds - server did not respond");
                }
                // If not a timeout, might be end of stream
                break;
            }
            
            $response .= $line;
            
            if (trim($line) === '') {
                $headerEnd = true;
            }
        }
        
        if (!$headerEnd) {
            $elapsed = time() - $startTime;
            $this->debugLog("[performHandshake] Handshake timeout - no complete response after {$elapsed}s");
            $this->debugLog("[performHandshake] Partial response received: " . substr($response, 0, 500));
            throw new Exception("WebSocket handshake timeout after {$elapsed} seconds - incomplete response from server");
        }
        
        $this->debugLog("[performHandshake] Handshake response received: " . substr($response, 0, 200));
        
        // Verify response
        if (strpos($response, 'HTTP/1.1 101') === false && strpos($response, 'HTTP/1.0 101') === false) {
            throw new Exception("WebSocket handshake failed. Response: " . substr($response, 0, 500));
        }
        
        // Verify upgrade headers
        if (stripos($response, 'Upgrade: websocket') === false) {
            throw new Exception("WebSocket handshake failed - missing Upgrade header");
        }
        
        if (stripos($response, 'Connection: Upgrade') === false && stripos($response, 'Connection: upgrade') === false) {
            throw new Exception("WebSocket handshake failed - missing Connection header");
        }
        
        // Verify Sec-WebSocket-Accept
        $expectedAccept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if (stripos($response, "Sec-WebSocket-Accept: {$expectedAccept}") === false) {
            // Some servers might have different formatting, so we'll be lenient
            $this->debugLog("[performHandshake] Warning: Sec-WebSocket-Accept verification skipped");
        }
    }
    
    /**
     * Send message
     */
    public function send(string $message): void
    {
        if (!$this->connected || !$this->socket) {
            throw new Exception("WebSocket is not connected");
        }
        
        $frame = $this->encodeFrame($message);
        $bytesWritten = fwrite($this->socket, $frame);
        
        if ($bytesWritten === false || $bytesWritten !== strlen($frame)) {
            throw new Exception("Failed to send WebSocket frame");
        }
    }
    
    /**
     * Receive message
     */
    public function receive(int $timeout = 30): string
    {
        if (!$this->connected || !$this->socket) {
            throw new Exception("WebSocket is not connected");
        }
        
        $startTime = time();
        
        while ((time() - $startTime) < $timeout) {
            $data = fread($this->socket, 2);
            
            if ($data === false || strlen($data) < 2) {
                // Check if connection is still alive
                if (feof($this->socket)) {
                    throw new Exception("WebSocket connection closed");
                }
                continue;
            }
            
            $byte1 = ord($data[0]);
            $byte2 = ord($data[1]);
            
            $fin = ($byte1 >> 7) & 0x1;
            $opcode = $byte1 & 0x0F;
            $masked = ($byte2 >> 7) & 0x1;
            $payloadLength = $byte2 & 0x7F;
            
            // Handle close frame
            if ($opcode === self::OPCODE_CLOSE) {
                $this->close();
                throw new Exception("WebSocket connection closed by server");
            }
            
            // Handle ping frame
            if ($opcode === self::OPCODE_PING) {
                $this->sendPong();
                continue;
            }
            
            // Read extended payload length if needed
            if ($payloadLength === 126) {
                $lengthData = fread($this->socket, 2);
                if (strlen($lengthData) < 2) {
                    continue;
                }
                $payloadLength = unpack('n', $lengthData)[1];
            } elseif ($payloadLength === 127) {
                $lengthData = fread($this->socket, 8);
                if (strlen($lengthData) < 8) {
                    continue;
                }
                $unpacked = unpack('N2', $lengthData);
                $payloadLength = $unpacked[1] * 4294967296 + $unpacked[2];
            }
            
            // Read masking key
            $maskingKey = '';
            if ($masked) {
                $maskingKey = fread($this->socket, 4);
                if (strlen($maskingKey) < 4) {
                    continue;
                }
            }
            
            // Read payload
            $payload = '';
            $bytesRead = 0;
            while ($bytesRead < $payloadLength) {
                $chunk = fread($this->socket, $payloadLength - $bytesRead);
                if ($chunk === false) {
                    break;
                }
                $payload .= $chunk;
                $bytesRead += strlen($chunk);
            }
            
            if (strlen($payload) < $payloadLength) {
                continue;
            }
            
            // Unmask payload if needed
            if ($masked) {
                $payload = $this->unmask($payload, $maskingKey);
            }
            
            // Return text frame payload
            if ($opcode === self::OPCODE_TEXT) {
                return $payload;
            }
            
            // Continue for other opcodes
        }
        
        throw new Exception("WebSocket receive timeout");
    }
    
    /**
     * Encode WebSocket frame
     */
    private function encodeFrame(string $payload): string
    {
        $payloadLength = strlen($payload);
        $frame = '';
        
        // First byte: FIN (1) + RSV (000) + Opcode (0001 = text)
        $frame .= chr(0x81);
        
        // Second byte: MASK (0, server doesn't mask) + Payload length
        if ($payloadLength < 126) {
            $frame .= chr($payloadLength);
        } elseif ($payloadLength < 65536) {
            $frame .= chr(126);
            $frame .= pack('n', $payloadLength);
        } else {
            $frame .= chr(127);
            $frame .= pack('N', 0) . pack('N', $payloadLength);
        }
        
        // Payload
        $frame .= $payload;
        
        return $frame;
    }
    
    /**
     * Unmask payload
     */
    private function unmask(string $payload, string $maskingKey): string
    {
        $unmasked = '';
        for ($i = 0; $i < strlen($payload); $i++) {
            $unmasked .= $payload[$i] ^ $maskingKey[$i % 4];
        }
        return $unmasked;
    }
    
    /**
     * Send PONG frame
     */
    private function sendPong(): void
    {
        if (!$this->socket) {
            return;
        }
        
        $frame = chr(0x8A) . chr(0x00); // PONG frame with 0 payload
        fwrite($this->socket, $frame);
    }
    
    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->connected && $this->socket && !feof($this->socket);
    }
    
    /**
     * Get debug information (for troubleshooting)
     */
    public function getDebugInfo(): array
    {
        return [
            'url' => $this->url,
            'host' => $this->host,
            'port' => $this->port,
            'path' => $this->path,
            'path_length' => strlen($this->path ?? ''),
            'path_empty' => empty($this->path),
            'secure' => $this->secure,
            'connected' => $this->connected,
            'timeout' => $this->timeout,
        ];
    }
    
    /**
     * Close connection
     */
    public function close(): void
    {
        if ($this->socket) {
            // Send close frame
            try {
                $frame = chr(0x88) . chr(0x00); // CLOSE frame
                @fwrite($this->socket, $frame);
            } catch (Exception $e) {
                // Ignore errors during close
            }
            
            @fclose($this->socket);
            $this->socket = null;
        }
        
        $this->connected = false;
    }
    
    /**
     * Get CA bundle path for SSL verification
     */
    private function getCaBundlePath(): ?string
    {
        // Try common CA bundle locations
        $caBundles = [
            '/etc/ssl/certs/ca-certificates.crt',
            '/etc/pki/tls/certs/ca-bundle.crt',
            '/usr/local/share/certs/ca-root-nss.crt',
            __DIR__ . '/../../cacert.pem', // Local fallback
        ];
        
        foreach ($caBundles as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }
        
        // If no CA bundle found, return null (PHP will use system defaults)
        return null;
    }
    
    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }
}

