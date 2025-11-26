<?php
/**
 * Stable File-based Signal Watcher (PHP Polling Version)
 *
 * This is a crash-resistant replacement for file_signal_watcher.sh
 * Uses simple file polling instead of inotify for better Docker compatibility.
 *
 * Features:
 * - No external dependencies (no inotify-tools needed)
 * - Proper signal handling (SIGTERM, SIGINT)
 * - Automatic recovery from errors
 * - Docker-container friendly
 * - Continuous operation without termination loops
 *
 * Usage:
 *   php cron/file_signal_watcher.php
 *
 * Recommended systemd service (example):
 *   [Unit]
 *   Description=VTM Option File Signal Watcher (PHP)
 *   After=network.target
 *
 *   [Service]
 *   Type=simple
 *   WorkingDirectory=/app
 *   ExecStart=/usr/bin/php /app/cron/file_signal_watcher.php
 *   Restart=always
 *   RestartSec=5
 *   User=www-data
 *
 *   [Install]
 *   WantedBy=multi-user.target
 */

// Prevent script timeout
set_time_limit(0);
ignore_user_abort(true);

// Load application bootstrap
$scriptDir = __DIR__;
$appRoot = dirname($scriptDir);

require_once $appRoot . '/config.php';
require_once $appRoot . '/app/bootstrap.php';

// Configuration
$SIGNAL_FILE = $appRoot . '/getSignal.txt';
$PROCESSOR = $scriptDir . '/file_signal_processor.php';
$LOG_DIR = $appRoot . '/logs';
$LOG_FILE = $LOG_DIR . '/file_signal_watcher.log';
$POLL_INTERVAL = 1; // Check every 1 second (adjustable)
$MAX_ERRORS = 10; // Max consecutive errors before backoff
$ERROR_BACKOFF = 5; // Seconds to wait after max errors

// Ensure log directory exists
if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0755, true);
}

// Logging function
function logMessage($level, $message) {
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents($LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);
    // Also output to stderr for Docker logs
    if (php_sapi_name() === 'cli') {
        fwrite(STDERR, $logEntry);
    }
}

// Signal handler for graceful shutdown
$shutdown = false;
function signalHandler($signo) {
    global $shutdown;
    $signals = [
        SIGTERM => 'SIGTERM',
        SIGINT => 'SIGINT',
        SIGHUP => 'SIGHUP'
    ];
    $signalName = $signals[$signo] ?? "Signal $signo";
    logMessage('INFO', "Received $signalName, initiating graceful shutdown...");
    $shutdown = true;
}

// Register signal handlers (only on Unix-like systems)
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, 'signalHandler');
    pcntl_signal(SIGINT, 'signalHandler');
    pcntl_signal(SIGHUP, 'signalHandler');
    pcntl_async_signals(true);
}

// Validate processor script exists
if (!file_exists($PROCESSOR)) {
    logMessage('ERROR', "Processor script not found: $PROCESSOR");
    exit(1);
}

// Create signal file if it doesn't exist
if (!file_exists($SIGNAL_FILE)) {
    logMessage('INFO', "Signal file not found; creating empty file at $SIGNAL_FILE");
    @touch($SIGNAL_FILE);
    if (!file_exists($SIGNAL_FILE)) {
        logMessage('ERROR', "Unable to create signal file at $SIGNAL_FILE");
        exit(1);
    }
}

logMessage('INFO', "Starting stable file signal watcher on $SIGNAL_FILE");
logMessage('INFO', "Poll interval: {$POLL_INTERVAL}s, Processor: $PROCESSOR");

// Track file modification time for change detection
$lastModified = @filemtime($SIGNAL_FILE);
$lastSize = @filesize($SIGNAL_FILE);
$consecutiveErrors = 0;
$lastProcessedContent = '';

// Main polling loop
while (!$shutdown) {
    try {
        // Handle pending signals
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
        
        if ($shutdown) {
            logMessage('INFO', 'Shutdown flag set, exiting gracefully');
            break;
        }
        
        // Check if file exists
        if (!file_exists($SIGNAL_FILE)) {
            // File was deleted, reset tracking
            $lastModified = 0;
            $lastSize = 0;
            sleep($POLL_INTERVAL);
            continue;
        }
        
        // Get current file stats
        $currentModified = @filemtime($SIGNAL_FILE);
        $currentSize = @filesize($SIGNAL_FILE);
        
        // Check if file has changed
        $fileChanged = false;
        if ($currentModified !== false && $currentSize !== false) {
            if ($currentModified !== $lastModified || $currentSize !== $lastSize) {
                $fileChanged = true;
            }
        }
        
        // If file changed, read content and check if it's actually new
        if ($fileChanged) {
            $content = @file_get_contents($SIGNAL_FILE);
            
            if ($content !== false) {
                $content = trim($content);
                
                // Only process if content is different from last processed
                if ($content !== $lastProcessedContent && $content !== '') {
                    logMessage('INFO', "File change detected (modified: $currentModified, size: $currentSize)");
                    
                    // Execute processor
                    $startTime = microtime(true);
                    $output = [];
                    $returnCode = 0;
                    
                    // Use exec to capture output and return code
                    exec("php " . escapeshellarg($PROCESSOR) . " 2>&1", $output, $returnCode);
                    
                    $duration = round((microtime(true) - $startTime) * 1000, 2);
                    
                    if ($returnCode === 0) {
                        logMessage('INFO', "Processor completed successfully (exit=$returnCode, {$duration}ms)");
                        $lastProcessedContent = $content;
                        $consecutiveErrors = 0; // Reset error counter on success
                    } else {
                        $errorMsg = implode("\n", $output);
                        logMessage('ERROR', "Processor failed (exit=$returnCode, {$duration}ms): $errorMsg");
                        $consecutiveErrors++;
                        
                        // If too many errors, back off
                        if ($consecutiveErrors >= $MAX_ERRORS) {
                            logMessage('WARN', "Too many consecutive errors ($consecutiveErrors), backing off for {$ERROR_BACKOFF}s");
                            sleep($ERROR_BACKOFF);
                            $consecutiveErrors = 0; // Reset after backoff
                        }
                    }
                }
                
                // Update tracking even if content was empty (file was cleared)
                if ($content === '') {
                    $lastProcessedContent = '';
                }
            } else {
                logMessage('WARN', "Unable to read signal file (may be locked)");
                $consecutiveErrors++;
            }
            
            // Update tracking
            $lastModified = $currentModified;
            $lastSize = $currentSize;
        }
        
        // Reset error counter if we're running normally
        if ($consecutiveErrors > 0 && $fileChanged) {
            // Error counter will be reset above on successful processing
        }
        
    } catch (Throwable $e) {
        $consecutiveErrors++;
        logMessage('ERROR', "Exception in watcher loop: " . $e->getMessage() . " (Line: " . $e->getLine() . ")");
        
        if ($consecutiveErrors >= $MAX_ERRORS) {
            logMessage('WARN', "Too many consecutive errors ($consecutiveErrors), backing off for {$ERROR_BACKOFF}s");
            sleep($ERROR_BACKOFF);
            $consecutiveErrors = 0;
        }
    }
    
    // Sleep before next poll
    sleep($POLL_INTERVAL);
}

logMessage('INFO', 'File signal watcher stopped gracefully');
exit(0);

