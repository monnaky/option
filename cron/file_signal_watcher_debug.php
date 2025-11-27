<?php
/**
 * DEBUG VERSION - Stable File-based Signal Watcher (PHP Polling Version)
 *
 * Adds verbose logging for every polling cycle (mtime/size/inode/content hash)
 * and forces the processor to run each pass so we can see what the watcher
 * actually observes.
 *
 * Usage:
 *   php cron/file_signal_watcher_debug.php
 */

set_time_limit(0);
ignore_user_abort(true);

$scriptDir = __DIR__;
$appRoot = dirname($scriptDir);

$SIGNAL_FILE = $appRoot . '/getSignal.txt';
$PROCESSOR = $scriptDir . '/file_signal_processor.php';
$LOG_DIR = $appRoot . '/logs';
$LOG_FILE = $LOG_DIR . '/file_signal_watcher_debug.log';
$ERROR_LOG_FILE = $LOG_DIR . '/file_signal_watcher_debug_error.log';
$POLL_INTERVAL = 1; // seconds
$FORCE_PROCESS = true; // execute processor every poll cycle

if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0755, true);
}

function logMessage($level, $message)
{
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents($LOG_FILE, $logEntry, FILE_APPEND | LOCK_EX);

    // Mirror to STDOUT for real-time visibility
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $logEntry);
        fflush(STDOUT);
    }
}

ini_set('log_errors', '1');
ini_set('display_errors', '1');
ini_set('error_log', $ERROR_LOG_FILE);

set_error_handler(function ($severity, $message, $file, $line) {
    $level = in_array($severity, [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)
        ? 'ERROR'
        : 'WARN';
    logMessage($level, "PHP error: $message in $file on line $line");
    return false;
});

set_exception_handler(function ($exception) {
    logMessage('ERROR', 'Uncaught exception: ' . $exception->getMessage() . ' in ' .
        $exception->getFile() . ' on line ' . $exception->getLine());
});

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error !== null) {
        logMessage('ERROR', 'Fatal shutdown error: ' . $error['message'] .
            ' in ' . $error['file'] . ' on line ' . $error['line']);
    }
});

$configPath = $appRoot . '/config.php';
$bootstrapPath = $appRoot . '/app/bootstrap.php';

if (!is_readable($configPath)) {
    logMessage('ERROR', "Config file missing or unreadable: $configPath");
    exit(1);
}

if (!is_readable($bootstrapPath)) {
    logMessage('ERROR', "Bootstrap file missing or unreadable: $bootstrapPath");
    exit(1);
}

require_once $configPath;
require_once $bootstrapPath;

$shutdown = false;
if (function_exists('pcntl_signal')) {
    pcntl_signal(SIGTERM, function () use (&$shutdown) {
        logMessage('INFO', 'Received SIGTERM, shutting down...');
        $shutdown = true;
    });
    pcntl_signal(SIGINT, function () use (&$shutdown) {
        logMessage('INFO', 'Received SIGINT, shutting down...');
        $shutdown = true;
    });
    pcntl_async_signals(true);
}

if (!file_exists($PROCESSOR)) {
    logMessage('ERROR', "Processor script not found: $PROCESSOR");
    exit(1);
}

if (!file_exists($SIGNAL_FILE)) {
    logMessage('WARN', "Signal file missing - creating empty file at $SIGNAL_FILE");
    @touch($SIGNAL_FILE);
}

logMessage('INFO', "DEBUG watcher online. File: $SIGNAL_FILE");
logMessage('INFO', "Processor: $PROCESSOR | Poll Interval: {$POLL_INTERVAL}s | Force Process: " . ($FORCE_PROCESS ? 'ON' : 'OFF'));

$cycle = 0;

while (!$shutdown) {
    $cycle++;

    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    clearstatcache(true, $SIGNAL_FILE);
    $exists = file_exists($SIGNAL_FILE);
    $mtime = $exists ? @filemtime($SIGNAL_FILE) : 'n/a';
    $size = $exists ? @filesize($SIGNAL_FILE) : 'n/a';
    $inode = $exists ? @fileinode($SIGNAL_FILE) : 'n/a';

    logMessage('DEBUG', "Cycle #$cycle | exists=" . ($exists ? 'yes' : 'no') . " | mtime=$mtime | size=$size | inode=$inode");

    if (!$exists) {
        logMessage('WARN', 'Signal file missing – sleeping');
        sleep($POLL_INTERVAL);
        continue;
    }

    $content = @file_get_contents($SIGNAL_FILE);
    if ($content === false) {
        logMessage('ERROR', 'Unable to read signal file (maybe locked). Will retry.');
        sleep($POLL_INTERVAL);
        continue;
    }

    $trimmed = trim($content);
    $hash = $trimmed === '' ? 'EMPTY' : hash('sha256', $trimmed);
    $preview = substr($trimmed, 0, 120);

    logMessage('DEBUG', "Content hash=$hash | preview=\"" . $preview . "\"");

    $shouldProcess = $FORCE_PROCESS || $trimmed !== '';

    if ($shouldProcess) {
        $start = microtime(true);
        $output = [];
        $code = 0;
        $phpBinary = PHP_BINARY ?: 'php';
        exec(escapeshellarg($phpBinary) . ' ' . escapeshellarg($PROCESSOR) . " 2>&1", $output, $code);
        $duration = round((microtime(true) - $start) * 1000, 2);
        $joinedOutput = implode("\n", $output);

        if ($code === 0) {
            logMessage('INFO', "Processor OK (exit=$code, {$duration}ms)");
        } else {
            logMessage('ERROR', "Processor FAILED (exit=$code, {$duration}ms)\n$joinedOutput");
        }
    } else {
        logMessage('DEBUG', 'Skipping processor run (empty content)');
    }

    sleep($POLL_INTERVAL);
}

logMessage('INFO', 'Debug watcher shutting down.');
exit(0);

