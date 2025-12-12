<?php

require_once __DIR__ . '/app/autoload.php';
require __DIR__ . '/app/services/WebSocketClient.php';
require __DIR__ . '/app/services/DerivAPI.php';

use App\Services\WebSocketClient;
use App\Services\DerivAPI;

class WebSocketKeepAliveTest {
    private $wsClient;
    private $derivApi;
    private $testDuration = 300; // 5 minutes test
    private $startTime;
    private $lastPingTime = 0;
    private $isRunning = true;
    private $pingInterval = 30; // Check every 30 seconds for pings
    private $wsUrl = 'wss://ws.derivws.com/websockets/v3?app_id=1089';
    
    public function __construct() {
        $this->startTime = time();
        $this->setupErrorHandling();
    }
    
    private function setupErrorHandling() {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        set_error_handler([$this, 'errorHandler']);
        set_exception_handler([$this, 'exceptionHandler']);
        
        // Handle CTRL+C to clean up properly
        if (function_exists('pcntl_signal')) {
            declare(ticks=1);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
        }
    }
    
    public function shutdown() {
        $this->isRunning = false;
        $this->log("Shutting down test...");
        $this->cleanup();
        exit(0);
    }
    
    public function errorHandler($errno, $errstr, $errfile, $errline) {
        $this->log("ERROR [$errno] $errstr in $errfile on line $errline");
        return true;
    }
    
    public function exceptionHandler($exception) {
        $this->log("EXCEPTION: " . $exception->getMessage());
        $this->log($exception->getTraceAsString());
    }
    
    private function log($message) {
        $elapsed = time() - $this->startTime;
        $timestamp = date('Y-m-d H:i:s');
        echo "[$timestamp] [{$elapsed}s] $message" . PHP_EOL;
    }
    
    private function connectWebSocket() {
        $this->log("Connecting to WebSocket at {$this->wsUrl}");
        $this->wsClient = new WebSocketClient($this->wsUrl, 30);
        $this->wsClient->connect();
        $this->log("WebSocket connected successfully");
    }
    
    private function testKeepAlive() {
        $this->log("Starting keep-alive test for " . $this->testDuration . " seconds");
        $lastCheck = time();
        
        while ($this->isRunning && (time() - $this->startTime) < $this->testDuration) {
            $currentTime = time();
            $elapsed = $currentTime - $this->startTime;
            
            // Check connection status
            if (!$this->wsClient->isConnected()) {
                $this->log("WebSocket disconnected! Attempting to reconnect...");
                $this->connectWebSocket();
            }
            
            // Send a test message every minute to trigger keep-alive
            if (($currentTime - $lastCheck) >= 60) {
                $this->log("Sending test message...");
                try {
                    $this->wsClient->send(json_encode([
                        'ping' => 1
                    ]));
                    $this->log("Test message sent successfully");
                } catch (Exception $e) {
                    $this->log("Failed to send test message: " . $e->getMessage());
                }
                $lastCheck = $currentTime;
            }
            
            // Log status every 30 seconds
            if (($currentTime - $this->lastPingTime) >= $this->pingInterval) {
                $this->log("Connection active for {$elapsed} seconds");
                $this->lastPingTime = $currentTime;
            }
            
            // Small sleep to prevent high CPU usage
            sleep(1);
        }
    }
    
    private function cleanup() {
        $this->log("Cleaning up resources...");
        if ($this->wsClient && $this->wsClient->isConnected()) {
            $this->wsClient->disconnect();
            $this->log("WebSocket connection closed");
        }
    }
    
    public function run() {
        try {
            $this->log("=== Starting WebSocket Keep-Alive Test ===");
            $this->log("Test will run for " . $this->testDuration . " seconds");
            
            // Connect to WebSocket
            $this->connectWebSocket();
            
            // Run the keep-alive test
            $this->testKeepAlive();
            
        } catch (Exception $e) {
            $this->log("Test failed: " . $e->getMessage());
            $this->log($e->getTraceAsString());
        } finally {
            $this->cleanup();
            $this->log("=== Test completed ===");
        }
    }
}

// Run the test if executed directly
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($argv[0])) {
    $test = new WebSocketKeepAliveTest();
    $test->run();
}