<?php
/**
 * File-based Signal Bridge Cron Job
 *
 * Bridges the simple file-based signal connection (getSignal.txt)
 * with the full trading system via SignalService.
 *
 * Expected file content format (single line):
 *   ASSET,SIGNAL_TYPE_MESSAGE,TIMESTAMP
 *
 * Example:
 *   XRPUSD,Buy Message from MT5,1764039334
 *
 * Mapping:
 *   "Buy"  -> "RISE"
 *   "Sell" -> "FALL"
 *
 * Usage (cron, every 5–10 seconds):
 *   * * * * * /usr/bin/php /path/to/cron/file_signal_processor.php
 */

// Reasonable safety limit; script should be very fast
set_time_limit(30);

// Load application/bootstrap (same pattern as other cron jobs)
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SignalService;

vtm_signal_ensure_paths();

// Path to the signal file written by the MT5 bridge (now in /tmp by default)
$signalFile = vtm_signal_primary_path();
if (!is_writable($signalFile)) {
    error_log('file_signal_processor: Signal file is not writable: ' . $signalFile);
}

// If file does not exist, nothing to do
if (!file_exists($signalFile)) {
    exit(0);
}

// Read file content
$rawContent = @file_get_contents($signalFile);

// If we can't read or it's empty/whitespace, nothing to process
if ($rawContent === false) {
    error_log('file_signal_processor: Failed to read getSignal.txt');
    exit(1);
}

$rawContent = trim($rawContent);
if ($rawContent === '') {
    // No pending signal
    exit(0);
}

// In case there are multiple lines, use the last non-empty line as the active signal
$lines = preg_split('/\r\n|\r|\n/', $rawContent);
$lines = array_filter($lines, 'strlen');
$signalLine = trim(end($lines));

// Normalize encoding/BOM/control characters (handles UTF-16/Windows issues)
if (function_exists('vtm_signal_normalize_encoding')) {
    $signalLine = vtm_signal_normalize_encoding($signalLine);
}

// Expected format: ASSET,SIGNAL_TYPE_MESSAGE,TIMESTAMP
$parts = explode(',', $signalLine);
if (count($parts) < 3) {
    error_log('file_signal_processor: Invalid signal format in getSignal.txt: ' . $signalLine);
    // Do NOT clear file so it can be inspected/fixed
    exit(1);
}

$asset     = trim($parts[0]);
$message   = trim($parts[1]);
$timestamp = trim($parts[2]);

// Basic validation of asset
if ($asset === '') {
    error_log('file_signal_processor: Empty asset in signal line: ' . $signalLine);
    exit(1);
}

// Determine RISE/FALL from the message segment
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
    error_log('file_signal_processor: Unable to determine signal type from message: ' . $message);
    // Do NOT clear file so issue can be investigated
    exit(1);
}

try {
    $signalService = SignalService::getInstance();

    // Build signal payload for the core SignalService
    $payload = [
        'type'    => $type,
        'asset'   => $asset,
        'rawText' => $signalLine,
        'source'  => 'file',
        // Optional: pass timestamp if needed in future
        'timestamp' => $timestamp,
    ];

    $result = $signalService->receiveSignal($payload);

    if (!empty($result['success'])) {
        // On successful processing for all active users, clear the file
        if (@file_put_contents($signalFile, '') === false) {
            error_log('file_signal_processor: Processed signal but failed to clear getSignal.txt');
            exit(1);
        }

        error_log(
            sprintf(
                'file_signal_processor: Signal processed successfully: type=%s asset=%s signal_id=%s',
                $type,
                $asset,
                $result['signal_id'] ?? 'n/a'
            )
        );
    } else {
        error_log(
            'file_signal_processor: SignalService did not report success: ' .
            json_encode($result)
        );
        // Do not clear file so we can retry / inspect
        exit(1);
    }
} catch (Throwable $e) {
    error_log('file_signal_processor: Exception while processing signal: ' . $e->getMessage());
    exit(1);
}

exit(0);


