<?php
/**
 * URL-based Signal Watcher
 *
 * Polls a remote getSignal.txt endpoint over HTTP instead of the local filesystem.
 * When content is detected, it forwards the signal to SignalService and clears
 * the upstream file via a clear endpoint.
 */

set_time_limit(0);
ignore_user_abort(true);

$scriptDir = __DIR__;
$appRoot = dirname($scriptDir);

$LOG_DIR = $appRoot . '/logs';
$LOG_FILE = $LOG_DIR . '/url_signal_watcher.log';
$ERROR_LOG_FILE = $LOG_DIR . '/url_signal_watcher_error.log';
$REMOTE_SIGNAL_URL = getenv('REMOTE_SIGNAL_URL') ?: 'https://vtmoption.com/getSignal.txt';
$REMOTE_CLEAR_URL = getenv('REMOTE_SIGNAL_CLEAR_URL') ?: 'https://vtmoption.com/signals.php?action=clear';
$POLL_INTERVAL = (int)(getenv('REMOTE_SIGNAL_POLL_INTERVAL') ?: 2);
$HTTP_TIMEOUT = (int)(getenv('REMOTE_SIGNAL_HTTP_TIMEOUT') ?: 10);

// Clamp poll interval between 1 and 5 seconds
if ($POLL_INTERVAL < 1) {
    $POLL_INTERVAL = 1;
} elseif ($POLL_INTERVAL > 5) {
    $POLL_INTERVAL = 5;
}

if (!is_dir($LOG_DIR)) {
    @mkdir($LOG_DIR, 0755, true);
}

function logMessage($level, $message)
{
    global $LOG_FILE;
    $timestamp = date('Y-m-d H:i:s');
    $entry = "[$timestamp] [$level] $message" . PHP_EOL;
    @file_put_contents($LOG_FILE, $entry, FILE_APPEND | LOCK_EX);
    if (PHP_SAPI === 'cli') {
        fwrite(STDOUT, $entry);
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

if (!is_readable($configPath) || !is_readable($bootstrapPath)) {
    logMessage('ERROR', 'Missing config/bootstrap files for URL watcher');
    exit(1);
}

require_once $configPath;
require_once $bootstrapPath;

use App\Services\SignalService;

vtm_signal_ensure_paths();
$localMirror = vtm_signal_public_path();
logMessage('INFO', "Signal storage initialized. Local mirror at {$localMirror}");

$signalService = SignalService::getInstance();

$lastHash = null;
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

logMessage('INFO', "URL watcher online. Source={$REMOTE_SIGNAL_URL}, clear={$REMOTE_CLEAR_URL}, interval={$POLL_INTERVAL}s");

while (!$shutdown) {
    if (function_exists('pcntl_signal_dispatch')) {
        pcntl_signal_dispatch();
    }

    $fetch = fetchRemoteSignal($REMOTE_SIGNAL_URL, $HTTP_TIMEOUT);

    if (!$fetch['success']) {
        logMessage('WARN', "Fetch failed: {$fetch['error']}. HTTP={$fetch['http_code']} duration={$fetch['duration_ms']}ms");
        sleep($POLL_INTERVAL);
        continue;
    }

    $body = $fetch['body'];
    $trimmed = trim($body);
    $hash = $trimmed === '' ? 'EMPTY' : hash('sha256', $trimmed);

    logMessage(
        'DEBUG',
        sprintf(
            'Poll ok: code=%s len=%s last_mod=%s etag=%s hash=%s',
            $fetch['http_code'],
            $fetch['length'],
            $fetch['last_modified'] ?? 'n/a',
            $fetch['etag'] ?? 'n/a',
            $hash
        )
    );

    if ($trimmed === '') {
        $lastHash = 'EMPTY';
        sleep($POLL_INTERVAL);
        continue;
    }

    if ($hash === $lastHash) {
        sleep($POLL_INTERVAL);
        continue;
    }

    $parsed = parseSignalLine($trimmed);
    if (!$parsed['success']) {
        logMessage('ERROR', "Parse failure: {$parsed['error']} raw=\"{$trimmed}\"");
        sleep($POLL_INTERVAL);
        continue;
    }

    try {
        $result = $signalService->receiveSignal([
            'type' => $parsed['type'],
            'asset' => $parsed['asset'],
            'rawText' => $parsed['raw_text'],
            'source' => 'remote_file',
            'timestamp' => $parsed['timestamp'],
        ]);

        $isDuplicate = isset($result['error']) && stripos($result['error'], 'duplicate') !== false;

        if (!empty($result['success']) || $isDuplicate) {
            logMessage(
                'INFO',
                sprintf(
                    $isDuplicate
                        ? 'Signal duplicate acknowledged: type=%s asset=%s signal_id=%s users=%s'
                        : 'Signal processed: type=%s asset=%s signal_id=%s users=%s',
                    $parsed['type'],
                    $parsed['asset'],
                    $result['signal_id'] ?? 'n/a',
                    $result['execution']['total_users'] ?? 'n/a'
                )
            );
            $lastHash = $hash;
            clearRemoteSignal($REMOTE_CLEAR_URL, $HTTP_TIMEOUT);
        } else {
            logMessage('ERROR', 'SignalService returned failure: ' . json_encode($result));
        }
    } catch (Throwable $e) {
        logMessage('ERROR', 'SignalService exception: ' . $e->getMessage());
    }

    sleep($POLL_INTERVAL);
}

logMessage('INFO', 'URL watcher shutting down');
exit(0);

/**
 * Fetch remote signal file via HTTP.
 */
function fetchRemoteSignal(string $url, int $timeout): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FILETIME => true,
        CURLOPT_HEADER => true,
    ]);

    $start = microtime(true);
    $response = curl_exec($ch);
    $duration = round((microtime(true) - $start) * 1000, 2);
    $error = curl_error($ch);
    $errno = curl_errno($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $filetime = curl_getinfo($ch, CURLINFO_FILETIME);
    $length = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headersRaw = $headerSize ? substr($response, 0, $headerSize) : '';
    $body = $headerSize ? substr($response, $headerSize) : $response;

    curl_close($ch);

    if ($errno !== 0) {
        return [
            'success' => false,
            'error' => $error ?: 'Unknown cURL error',
            'http_code' => $httpCode,
            'duration_ms' => $duration,
        ];
    }

    if ($httpCode !== 200) {
        return [
            'success' => false,
            'error' => "HTTP $httpCode",
            'http_code' => $httpCode,
            'duration_ms' => $duration,
        ];
    }

    return [
        'success' => true,
        'body' => $body,
        'http_code' => $httpCode,
        'duration_ms' => $duration,
        'length' => $length,
        'last_modified' => $filetime > 0 ? gmdate('c', $filetime) : null,
        'etag' => extractHeaderValue($headersRaw, 'ETag'),
    ];
}

/**
 * Clear remote signal file via HTTP.
 */
function clearRemoteSignal(string $url, int $timeout): void
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($error || $httpCode !== 200) {
        logMessage('WARN', "Failed to clear remote signal (code=$httpCode error={$error}). Response: {$response}");
    } else {
        logMessage('INFO', 'Remote signal cleared successfully');
    }
}

/**
 * Parse MT5 signal CSV line.
 */
function parseSignalLine(string $line): array
{
    $parts = array_map('trim', explode(',', $line));
    if (count($parts) < 3) {
        return [
            'success' => false,
            'error' => 'Expected at least 3 comma-separated values (asset,message,timestamp)',
        ];
    }

    [$asset, $message, $timestamp] = [$parts[0], $parts[1], $parts[2]];

    if ($asset === '') {
        return [
            'success' => false,
            'error' => 'Asset is empty',
        ];
    }

    $messageLower = strtolower($message);
    $type = null;
    $buyKeywords = ['buy', 'call', 'rise', 'long', 'up', 'bull'];
    $sellKeywords = ['sell', 'put', 'fall', 'short', 'down', 'bear'];

    foreach ($buyKeywords as $keyword) {
        if ($keyword !== '' && strpos($messageLower, $keyword) !== false) {
            $type = 'RISE';
            break;
        }
    }

    if ($type === null) {
        foreach ($sellKeywords as $keyword) {
            if ($keyword !== '' && strpos($messageLower, $keyword) !== false) {
                $type = 'FALL';
                break;
            }
        }
    }

    if ($type === null) {
        return [
            'success' => false,
            'error' => 'Unable to determine RISE/FALL from message',
        ];
    }

    return [
        'success' => true,
        'asset' => $asset,
        'type' => $type,
        'timestamp' => $timestamp,
        'raw_text' => $line,
    ];
}

/**
 * Extract a header value from raw header string.
 */
function extractHeaderValue(string $headers, string $name): ?string
{
    foreach (explode("\n", $headers) as $headerLine) {
        if (stripos($headerLine, $name . ':') === 0) {
            return trim(substr($headerLine, strlen($name) + 1));
        }
    }
    return null;
}

