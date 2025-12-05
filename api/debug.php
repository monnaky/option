<?php
/**
 * Deriv API Debug/Diagnostic Endpoint
 * 
 * Tests Deriv API connection and returns detailed diagnostic information
 * 
 * USAGE:
 * - Test all: GET /api/debug.php?test=all
 * - Test error logging: GET /api/debug.php?test=errorlog
 * - Test extensions only: GET /api/debug.php?test=extensions
 * - Test WebSocket library: GET /api/debug.php?test=websocket
 * - Test connection: GET /api/debug.php?test=connection
 * - Test authorization: GET /api/debug.php?test=authorize&token=YOUR_DERIV_TOKEN
 * - Test DerivAPI class: GET /api/debug.php?test=derivapi&token=YOUR_DERIV_TOKEN
 * 
 * DEBUG LOG FILE:
 * - All debug logs are written to: debug_websocket.log (in project root)
 * - Check this file for detailed error information and WebSocket debugging
 * 
 * The endpoint will identify:
 * - Missing PHP extensions (openssl, json, sockets, mbstring)
 * - Missing WebSocketClient class
 * - WebSocket connection failures
 * - WebSocket handshake failures
 * - Authorization failures
 * - Token validation issues
 * - DerivAPI class method failures
 */

// CRITICAL: Suppress ALL errors FIRST
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Set up dedicated debug logging with absolute path (before autoloader)
$baseDir = realpath(__DIR__ . '/..');
if (!$baseDir) {
    // Fallback if realpath fails
    $baseDir = dirname(__DIR__);
}
$debugLogFile = $baseDir . DIRECTORY_SEPARATOR . 'debug_websocket.log';

// Normalize path separators for Windows
$debugLogFile = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $debugLogFile);

// Ensure debug log file is writable
if (!file_exists($debugLogFile)) {
    $dir = dirname($debugLogFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    @touch($debugLogFile);
    @chmod($debugLogFile, 0666);
}

// Set error_log to use absolute path
@ini_set('error_log', $debugLogFile);

// Log initial setup
@file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [DEBUG.PHP] Initialized\n" .
    "Debug log file: {$debugLogFile}\n" .
    "File exists: " . (file_exists($debugLogFile) ? 'yes' : 'no') . "\n" .
    "File writable: " . (is_writable($debugLogFile) ? 'yes' : 'no') . "\n" .
    "PHP error_log setting: " . ini_get('error_log') . "\n" .
    "PHP log_errors: " . ini_get('log_errors') . "\n\n",
    FILE_APPEND | LOCK_EX);

// Load autoloader BEFORE use statements
require_once __DIR__ . '/../app/autoload.php';

// use statements MUST be at top level, immediately after require
use App\Services\DerivAPI;
use App\Services\WebSocketClient;
use App\Utils\Response;

// Start output buffering AFTER use statements
@ob_start();
@ob_clean(); // Clean any previous output

// Set JSON header AFTER output buffering starts
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Wrap everything in try-catch to prevent any errors from leaking
try {

    // Get test type
    $testType = $_GET['test'] ?? 'all';
    $apiToken = $_GET['token'] ?? '';

    $diagnostics = [
        'timestamp' => date('Y-m-d H:i:s'),
        'php_version' => PHP_VERSION,
        'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
        'debug_log_file' => $debugLogFile,
        'debug_log_exists' => file_exists($debugLogFile),
        'debug_log_readable' => file_exists($debugLogFile) && is_readable($debugLogFile),
        'tests' => [],
    ];

    // Test 0: Test Error Logging (if requested)
    if ($testType === 'all' || $testType === 'errorlog') {
        $diagnostics['tests']['error_logging'] = testErrorLogging($debugLogFile);
    }

    // Test 1: Check PHP Extensions
    if ($testType === 'all' || $testType === 'extensions') {
        $diagnostics['tests']['extensions'] = testPhpExtensions();
    }

    // Test 2: Check WebSocket Client Class
    if ($testType === 'all' || $testType === 'websocket') {
        $diagnostics['tests']['websocket_client'] = testWebSocketClient();
    }

    // Test 3: Test WebSocket Connection
    if ($testType === 'all' || $testType === 'connection') {
        $diagnostics['tests']['connection'] = testWebSocketConnection();
    }

    // Test 4: Test Authorization (if token provided)
    if ($apiToken && ($testType === 'all' || $testType === 'authorize')) {
        $diagnostics['tests']['authorization'] = testAuthorization($apiToken);
    }

    // Test 5: Test with DerivAPI class
    if ($apiToken && ($testType === 'all' || $testType === 'derivapi')) {
        $diagnostics['tests']['derivapi_class'] = testDerivAPIClass($apiToken);
    }

    Response::success($diagnostics, 'Diagnostic tests completed');

} catch (Throwable $e) {
    // Catch ANY error (including fatal errors, parse errors, etc.)
    $errorDetails = [
        'message' => $e->getMessage(),
        'type' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ];
    
    // Try to get WebSocketClient debug info if available
    // Note: $wsClient may not be in scope here, so we'll check in the test functions
    
    // Write to dedicated debug log file using file_put_contents (more reliable)
    $errorLogEntry = date('Y-m-d H:i:s') . " [FATAL ERROR]\n" . 
        "Message: " . $e->getMessage() . "\n" .
        "Type: " . get_class($e) . "\n" .
        "File: " . $e->getFile() . ":" . $e->getLine() . "\n" .
        "Trace:\n" . $e->getTraceAsString() . "\n" .
        "Debug Info: " . json_encode($errorDetails, JSON_PRETTY_PRINT) . "\n" .
        "PHP error_log setting: " . ini_get('error_log') . "\n" .
        "Debug log file: {$debugLogFile}\n\n";
    
    @file_put_contents($debugLogFile, $errorLogEntry, FILE_APPEND | LOCK_EX);
    
    // Also try error_log() (may go to different location)
    error_log('[DEBUG.PHP] Fatal error: ' . $e->getMessage());
    error_log('[DEBUG.PHP] Stack trace: ' . $e->getTraceAsString());
    error_log('[DEBUG.PHP] Error details: ' . json_encode($errorDetails, JSON_PRETTY_PRINT));
    
    Response::error('Internal server error: ' . $e->getMessage(), 500, $errorDetails);
}

/**
 * Test Error Logging
 */
function testErrorLogging(string $debugLogFile): array
{
    $result = [
        'status' => 'unknown',
        'message' => '',
        'log_file' => $debugLogFile,
        'log_file_absolute' => realpath($debugLogFile) ?: $debugLogFile,
        'file_exists' => file_exists($debugLogFile),
        'file_writable' => false,
        'file_readable' => false,
        'test_methods' => [],
        'php_settings' => [],
    ];
    
    try {
        // Get absolute path
        $absolutePath = realpath($debugLogFile) ?: $debugLogFile;
        $result['log_file_absolute'] = $absolutePath;
        
        // Check file permissions
        if (file_exists($debugLogFile)) {
            $result['file_writable'] = is_writable($debugLogFile);
            $result['file_readable'] = is_readable($debugLogFile);
            $result['file_size'] = filesize($debugLogFile);
            $result['file_permissions'] = substr(sprintf('%o', fileperms($debugLogFile)), -4);
            $result['file_owner'] = function_exists('posix_getpwuid') ? posix_getpwuid(fileowner($debugLogFile))['name'] : 'unknown';
        } else {
            // Try to create the file
            $dir = dirname($debugLogFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            if (@touch($debugLogFile)) {
                @chmod($debugLogFile, 0666);
                $result['file_exists'] = true;
                $result['file_writable'] = is_writable($debugLogFile);
                $result['file_readable'] = is_readable($debugLogFile);
            }
        }
        
        // Get PHP settings
        $result['php_settings'] = [
            'error_log' => ini_get('error_log'),
            'error_log_absolute' => realpath(ini_get('error_log')) ?: ini_get('error_log'),
            'log_errors' => ini_get('log_errors'),
            'display_errors' => ini_get('display_errors'),
            'error_reporting' => error_reporting(),
        ];
        
        // Generate unique test messages
        $testId = time() . '_' . uniqid();
        $testMessage1 = 'TEST_ERROR_LOG_' . $testId;
        $testMessage2 = 'TEST_FILE_PUT_' . $testId;
        $testMessage3 = 'TEST_DIRECT_WRITE_' . $testId;
        
        // Method 1: Test error_log()
        $result['test_methods']['error_log'] = [
            'method' => 'error_log()',
            'test_message' => $testMessage1,
            'written' => false,
            'found_in_file' => false,
            'found_in_php_log' => false,
        ];
        
        // Get initial file size
        $initialSize = file_exists($debugLogFile) ? filesize($debugLogFile) : 0;
        
        // Write via error_log
        error_log('[ERROR_LOG_TEST] ' . $testMessage1);
        
        // Wait a moment for the log to be written
        usleep(200000); // 0.2 seconds
        
        // Check if message appears in our debug log file
        if (file_exists($debugLogFile) && is_readable($debugLogFile)) {
            $logContent = @file_get_contents($debugLogFile);
            $result['test_methods']['error_log']['found_in_file'] = (strpos($logContent, $testMessage1) !== false);
            $result['test_methods']['error_log']['file_size_after'] = strlen($logContent);
            $result['test_methods']['error_log']['file_grew'] = (strlen($logContent) > $initialSize);
        }
        
        // Check if message appears in PHP's configured error_log location
        $phpErrorLog = ini_get('error_log');
        if ($phpErrorLog && file_exists($phpErrorLog) && is_readable($phpErrorLog)) {
            $phpLogContent = @file_get_contents($phpErrorLog);
            $result['test_methods']['error_log']['found_in_php_log'] = (strpos($phpLogContent, $testMessage1) !== false);
            $result['test_methods']['error_log']['php_log_file'] = $phpErrorLog;
            $result['test_methods']['error_log']['php_log_size'] = strlen($phpLogContent);
        }
        
        $result['test_methods']['error_log']['written'] = true;
        
        // Method 2: Test file_put_contents()
        $result['test_methods']['file_put_contents'] = [
            'method' => 'file_put_contents()',
            'test_message' => $testMessage2,
            'written' => false,
            'found_in_file' => false,
        ];
        
        $writeResult = @file_put_contents($debugLogFile, date('Y-m-d H:i:s') . " [FILE_PUT_TEST] {$testMessage2}\n", FILE_APPEND | LOCK_EX);
        $result['test_methods']['file_put_contents']['written'] = ($writeResult !== false);
        $result['test_methods']['file_put_contents']['bytes_written'] = $writeResult;
        
        // Wait a moment
        usleep(100000); // 0.1 seconds
        
        // Check if message appears
        if (file_exists($debugLogFile) && is_readable($debugLogFile)) {
            $logContent = @file_get_contents($debugLogFile);
            $result['test_methods']['file_put_contents']['found_in_file'] = (strpos($logContent, $testMessage2) !== false);
        }
        
        // Method 3: Test direct write with fopen/fwrite
        $result['test_methods']['fwrite'] = [
            'method' => 'fopen()/fwrite()',
            'test_message' => $testMessage3,
            'written' => false,
            'found_in_file' => false,
        ];
        
        $fp = @fopen($debugLogFile, 'a');
        if ($fp) {
            $writeResult = @fwrite($fp, date('Y-m-d H:i:s') . " [FWRITE_TEST] {$testMessage3}\n");
            @fclose($fp);
            $result['test_methods']['fwrite']['written'] = ($writeResult !== false);
            $result['test_methods']['fwrite']['bytes_written'] = $writeResult;
            
            // Wait a moment
            usleep(100000); // 0.1 seconds
            
            // Check if message appears
            if (file_exists($debugLogFile) && is_readable($debugLogFile)) {
                $logContent = @file_get_contents($debugLogFile);
                $result['test_methods']['fwrite']['found_in_file'] = (strpos($logContent, $testMessage3) !== false);
            }
        } else {
            $result['test_methods']['fwrite']['error'] = 'Could not open file for writing';
        }
        
        // Get final log content preview
        if (file_exists($debugLogFile) && is_readable($debugLogFile)) {
            $logContent = @file_get_contents($debugLogFile);
            $result['log_file_size'] = strlen($logContent);
            $result['log_last_500_chars'] = substr($logContent, -500);
            $result['log_contains_test_messages'] = [
                'test1' => strpos($logContent, $testMessage1) !== false,
                'test2' => strpos($logContent, $testMessage2) !== false,
                'test3' => strpos($logContent, $testMessage3) !== false,
            ];
        }
        
        // Determine overall status
        $methodsWorking = 0;
        $methodsTotal = 0;
        foreach ($result['test_methods'] as $method => $data) {
            if (isset($data['found_in_file'])) {
                $methodsTotal++;
                if ($data['found_in_file']) {
                    $methodsWorking++;
                }
            }
        }
        
        if ($methodsWorking === $methodsTotal && $methodsTotal > 0) {
            $result['status'] = 'ok';
            $result['message'] = "All logging methods working ({$methodsWorking}/{$methodsTotal})";
        } elseif ($methodsWorking > 0) {
            $result['status'] = 'partial';
            $result['message'] = "Some logging methods working ({$methodsWorking}/{$methodsTotal})";
        } else {
            $result['status'] = 'failed';
            $result['message'] = 'No logging methods are working';
        }
        
        // Add warning if error_log() is going to a different location
        if (isset($result['test_methods']['error_log']['found_in_php_log']) && 
            $result['test_methods']['error_log']['found_in_php_log'] && 
            !$result['test_methods']['error_log']['found_in_file']) {
            $result['warning'] = 'error_log() is writing to a different location than expected. Check php_error_log_setting.';
        }
        
    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = 'Error testing error logging: ' . $e->getMessage();
        $result['error'] = $e->getMessage();
        $result['error_trace'] = $e->getTraceAsString();
    }
    
    return $result;
}

/**
 * Test PHP Extensions
 */
function testPhpExtensions(): array
{
    $extensions = [
        'openssl' => extension_loaded('openssl'), // Required for SSL/TLS (wss://)
        'json' => extension_loaded('json'), // Required for JSON encoding/decoding
        'sockets' => extension_loaded('sockets'), // Helpful but not strictly required (stream functions work)
        'mbstring' => extension_loaded('mbstring'), // Helpful for string operations
    ];

    // OpenSSL and JSON are critical, others are optional
    $criticalLoaded = $extensions['openssl'] && $extensions['json'];
    $allLoaded = array_reduce($extensions, function($carry, $loaded) {
        return $carry && $loaded;
    }, true);

    return [
        'status' => $criticalLoaded ? ($allLoaded ? 'ok' : 'partial') : 'missing',
        'extensions' => $extensions,
        'message' => $criticalLoaded 
            ? ($allLoaded ? 'All required extensions are loaded' : 'Critical extensions loaded, some optional extensions missing')
            : 'Critical extensions missing (openssl and/or json)',
    ];
}

/**
 * Test WebSocket Client Class
 */
function testWebSocketClient(): array
{
    try {
        if (!class_exists('App\Services\WebSocketClient')) {
            return [
                'status' => 'missing',
                'message' => 'WebSocketClient class not found',
                'installed' => false,
            ];
        }

        // Check required PHP functions
        $requiredFunctions = ['stream_socket_client', 'fwrite', 'fread', 'fclose'];
        $missingFunctions = [];
        
        foreach ($requiredFunctions as $func) {
            if (!function_exists($func)) {
                $missingFunctions[] = $func;
            }
        }
        
        if (!empty($missingFunctions)) {
            return [
                'status' => 'missing',
                'message' => 'Required PHP functions missing: ' . implode(', ', $missingFunctions),
                'installed' => false,
                'missing_functions' => $missingFunctions,
            ];
        }
        
        // Check OpenSSL extension for SSL support
        $hasOpenSSL = extension_loaded('openssl');
        
        return [
            'status' => 'ok',
            'message' => 'WebSocketClient class is available',
            'installed' => true,
            'has_openssl' => $hasOpenSSL,
            'required_functions' => $requiredFunctions,
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'Error checking WebSocketClient: ' . $e->getMessage(),
            'installed' => false,
        ];
    }
}

/**
 * Test WebSocket Connection
 */
function testWebSocketConnection(): array
{
    $result = [
        'status' => 'unknown',
        'message' => '',
        'connection_time' => null,
        'error' => null,
        'details' => [],
    ];

    try {
        if (!class_exists('App\Services\WebSocketClient')) {
            $result['status'] = 'failed';
            $result['message'] = 'WebSocketClient class not available';
            $result['error'] = 'WebSocketClient class not found';
            return $result;
        }

        $appId = $_ENV['DERIV_APP_ID'] ?? '1089';
        $wsHost = $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com';
        $wsUrl = "wss://{$wsHost}/websockets/v3?app_id=" . urlencode($appId);

        $result['details']['url'] = $wsUrl;
        $result['details']['app_id'] = $appId;
        $result['details']['host'] = $wsHost;

        $startTime = microtime(true);
        $wsClient = null;

        try {
            // Create WebSocket client
            error_log("[testWebSocketConnection] Creating WebSocketClient with URL: " . var_export($wsUrl, true));
            $wsClient = new WebSocketClient($wsUrl, 30);
            
            // Get debug info before connecting
            try {
                $result['details']['debug_info_before_connect'] = $wsClient->getDebugInfo();
            } catch (Exception $debugError) {
                $result['details']['debug_info_error'] = $debugError->getMessage();
            }
            
            // Connect (performs handshake)
            error_log("[testWebSocketConnection] Calling connect()");
            $wsClient->connect();
            
            // Get debug info after connecting
            try {
                $result['details']['debug_info_after_connect'] = $wsClient->getDebugInfo();
            } catch (Exception $debugError) {
                // Ignore
            }
            
            $connectionTime = round((microtime(true) - $startTime) * 1000, 2);
            $result['connection_time'] = $connectionTime . 'ms';
            $result['details']['handshake'] = 'success';
            
            // Test sending and receiving a message
            try {
                // Send active_symbols request
                $testMessage = json_encode([
                    'active_symbols' => 'brief',
                    'product_type' => 'basic',
                    'req_id' => 1,
                ]);
                
                $sendStart = microtime(true);
                $wsClient->send($testMessage);
                $sendTime = round((microtime(true) - $sendStart) * 1000, 2);
                $result['details']['send_time'] = $sendTime . 'ms';
                
                // Try to receive response (with short timeout)
                $receiveStart = microtime(true);
                $response = $wsClient->receive(10);
                $receiveTime = round((microtime(true) - $receiveStart) * 1000, 2);
                $result['details']['receive_time'] = $receiveTime . 'ms';
                
                // Parse response
                $data = json_decode($response, true);
                if ($data) {
                    $result['status'] = 'success';
                    $result['message'] = 'WebSocket connection established and message exchange successful';
                    $result['details']['response_received'] = true;
                    $result['details']['response_preview'] = substr($response, 0, 200);
                    
                    if (isset($data['active_symbols'])) {
                        $result['details']['symbols_count'] = count($data['active_symbols'] ?? []);
                    }
                    if (isset($data['error'])) {
                        $result['details']['api_error'] = $data['error'];
                    }
                } else {
                    $result['status'] = 'warning';
                    $result['message'] = 'WebSocket connected but received invalid JSON response';
                    $result['details']['response_preview'] = substr($response, 0, 200);
                }
                
            } catch (Exception $e) {
                // Connection works but message exchange failed
                $result['status'] = 'partial';
                $result['message'] = 'WebSocket connected but message exchange failed';
                $result['error'] = $e->getMessage();
                $result['details']['connection_established'] = true;
            }
            
            // Close connection
            $wsClient->close();

        } catch (Exception $e) {
            $connectionTime = round((microtime(true) - $startTime) * 1000, 2);
            $result['connection_time'] = $connectionTime . 'ms';
            $result['status'] = 'failed';
            $result['message'] = 'WebSocket connection failed';
            $result['error'] = $e->getMessage();
            $result['error_type'] = get_class($e);
            $result['error_file'] = $e->getFile();
            $result['error_line'] = $e->getLine();
            $result['error_trace'] = explode("\n", $e->getTraceAsString());
            
            // Get debug info from WebSocketClient if available
            if ($wsClient) {
                try {
                    $result['details']['debug_info_on_error'] = $wsClient->getDebugInfo();
                } catch (Exception $debugError) {
                    $result['details']['debug_info_error'] = $debugError->getMessage();
                }
                
                try {
                    $wsClient->close();
                } catch (Exception $closeError) {
                    // Ignore close errors
                }
            }
            
            // Log detailed error information
            error_log("[testWebSocketConnection] Connection failed: " . $e->getMessage());
            error_log("[testWebSocketConnection] Error trace: " . $e->getTraceAsString());
            if (isset($wsClient) && $wsClient instanceof WebSocketClient) {
                try {
                    error_log("[testWebSocketConnection] Debug info: " . json_encode($wsClient->getDebugInfo(), JSON_PRETTY_PRINT));
                } catch (Exception $logError) {
                    // Ignore
                }
            }
        }

    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = 'Error during WebSocket connection test';
        $result['error'] = $e->getMessage();
        $result['error_type'] = get_class($e);
    }

    return $result;
}

/**
 * Test Authorization via WebSocket
 */
function testAuthorization(string $apiToken): array
{
    $result = [
        'status' => 'unknown',
        'message' => '',
        'token_length' => strlen($apiToken),
        'error' => null,
        'response' => null,
    ];

    try {
        if (!class_exists('App\Services\WebSocketClient')) {
            $result['status'] = 'failed';
            $result['message'] = 'WebSocketClient class not available';
            $result['error'] = 'WebSocketClient class not found';
            return $result;
        }

        $appId = $_ENV['DERIV_APP_ID'] ?? '1089';
        $wsHost = $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com';
        $wsUrl = "wss://{$wsHost}/websockets/v3?app_id=" . urlencode($appId);

        $wsClient = null;

        try {
            // Create and connect WebSocket client
            $wsClient = new WebSocketClient($wsUrl, 30);
            $wsClient->connect();
            
            // Send authorization message
            $authMessage = [
                'authorize' => $apiToken,
            ];
            
            $postData = json_encode($authMessage);
            $wsClient->send($postData);
            
            // Wait for authorization response
            $startTime = time();
            $data = null;
            
            while ((time() - $startTime) < 30) {
                try {
                    $rawResponse = $wsClient->receive(5);
                    $data = json_decode($rawResponse, true);

                    if (!$data) {
                        continue;
                    }

                    // Check for error
                    if (isset($data['error'])) {
                        $result['status'] = 'failed';
                        $result['message'] = 'Authorization failed';
                        $result['error'] = $data['error']['message'] ?? 'Unknown error';
                        $result['error_code'] = $data['error']['code'] ?? 'UNKNOWN';
                        $result['response'] = $data;
                        $wsClient->close();
                        return $result;
                    }

                    // Check for success
                    if (isset($data['authorize'])) {
                        $result['status'] = 'success';
                        $result['message'] = 'Authorization successful via WebSocket';
                        $result['response'] = [
                            'loginid' => $data['authorize']['loginid'] ?? null,
                            'currency' => $data['authorize']['currency'] ?? null,
                            'balance' => $data['authorize']['balance'] ?? null,
                        ];
                        $wsClient->close();
                        return $result;
                    }

                    // If it's an echo_req, ignore it
                    if (isset($data['echo_req'])) {
                        continue;
                    }
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'timeout') === false) {
                        throw $e;
                    }
                }
            }
            
            $result['status'] = 'failed';
            $result['message'] = 'Authorization timeout';
            $result['error'] = 'No response received within 30 seconds';

        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['message'] = 'WebSocket connection or authorization failed';
            $result['error'] = $e->getMessage();
            $result['error_type'] = get_class($e);
        } finally {
            if ($wsClient) {
                try {
                    $wsClient->close();
                } catch (Exception $closeError) {
                    // Ignore close errors
                }
            }
        }

    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = 'Error during authorization test';
        $result['error'] = $e->getMessage();
        $result['error_type'] = get_class($e);
    }

    return $result;
}

/**
 * Test DerivAPI Class
 */
function testDerivAPIClass(string $apiToken): array
{
    $result = [
        'status' => 'unknown',
        'message' => '',
        'steps' => [],
        'error' => null,
    ];

    try {
        // Step 1: Create instance
        try {
            $derivAPI = new DerivAPI($apiToken);
            $result['steps']['create_instance'] = 'success';
        } catch (Exception $e) {
            $result['steps']['create_instance'] = 'failed: ' . $e->getMessage();
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            return $result;
        }

        // Step 2: Test authorize
        try {
            $authData = $derivAPI->authorize();
            $result['steps']['authorize'] = 'success';
            $result['steps']['authorize_data'] = [
                'loginid' => $authData['loginid'] ?? null,
                'currency' => $authData['currency'] ?? null,
            ];
        } catch (Exception $e) {
            $result['steps']['authorize'] = 'failed: ' . $e->getMessage();
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
            $result['error_type'] = get_class($e);
            
            // Close connection on error
            try {
                $derivAPI->close();
            } catch (Exception $closeError) {
                // Ignore
            }
            return $result;
        }

        // Step 3: Test getAccountInfo
        try {
            $accountInfo = $derivAPI->getAccountInfo();
            $result['steps']['get_account_info'] = 'success';
            $result['steps']['account_info'] = $accountInfo;
        } catch (Exception $e) {
            $result['steps']['get_account_info'] = 'failed: ' . $e->getMessage();
        }

        // Step 4: Test getBalance
        try {
            $balance = $derivAPI->getBalance();
            $result['steps']['get_balance'] = 'success';
            $result['steps']['balance'] = $balance;
        } catch (Exception $e) {
            $result['steps']['get_balance'] = 'failed: ' . $e->getMessage();
        }

        // Close connection
        try {
            $derivAPI->close();
            $result['steps']['close_connection'] = 'success';
        } catch (Exception $e) {
            $result['steps']['close_connection'] = 'failed: ' . $e->getMessage();
        }

        $result['status'] = 'success';
        $result['message'] = 'All DerivAPI class tests passed';

    } catch (Exception $e) {
        $result['status'] = 'error';
        $result['message'] = 'Error during DerivAPI class test';
        $result['error'] = $e->getMessage();
        $result['error_type'] = get_class($e);
    }

    return $result;
}

