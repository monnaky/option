<?php
/**
 * Check Signal Execution Logs
 * 
 * Shows recent signal execution attempts and why they failed
 */

require_once __DIR__ . '/app/autoload.php';

use App\Config\Database;

$db = Database::getInstance();

echo "=== Signal Execution Analysis ===\n\n";

// Get recent signals with details
$signals = $db->query("
    SELECT id, signal_type, asset, source, processed, 
           total_users, successful_executions, failed_executions, 
           timestamp, processed_at
    FROM signals
    ORDER BY timestamp DESC
    LIMIT 10
");

echo "Recent Signals:\n";
echo str_repeat("-", 100) . "\n";

foreach ($signals as $signal) {
    $status = $signal['processed'] ? "✓ Processed" : "✗ Pending";
    $success = $signal['successful_executions'];
    $failed = $signal['failed_executions'];
    $total = $signal['total_users'];
    
    echo "Signal #{$signal['id']} - {$signal['signal_type']} ({$signal['source']})\n";
    echo "  Time: {$signal['timestamp']}\n";
    echo "  Status: {$status}\n";
    echo "  Users: {$total} total, {$success} success, {$failed} failed\n";
    
    if ($signal['processed_at']) {
        echo "  Processed: {$signal['processed_at']}\n";
    }
    
    echo "\n";
}

echo "\n" . str_repeat("=", 100) . "\n\n";

// Check trade_execution.log if it exists
$logFile = __DIR__ . '/logs/trade_execution.log';
if (file_exists($logFile)) {
    echo "Recent Trade Execution Log (last 50 lines):\n";
    echo str_repeat("-", 100) . "\n";
    
    $lines = file($logFile);
    $recentLines = array_slice($lines, -50);
    
    foreach ($recentLines as $line) {
        echo $line;
    }
} else {
    echo "Trade execution log not found at: {$logFile}\n";
}

echo "\n" . str_repeat("=", 100) . "\n\n";

// Check for users who should be eligible but aren't
echo "Eligibility Check:\n";
echo str_repeat("-", 100) . "\n";

$users = $db->query("
    SELECT u.id, u.email, u.is_active,
           s.is_bot_active,
           u.encrypted_api_token IS NOT NULL as has_token,
           ts.id as session_id, ts.state as session_state, ts.end_time
    FROM users u
    LEFT JOIN settings s ON u.id = s.user_id
    LEFT JOIN trading_sessions ts ON u.id = ts.user_id AND ts.end_time IS NULL
    WHERE u.is_active = 1
    ORDER BY u.id
");

foreach ($users as $user) {
    $eligible = $user['is_active'] && 
                $user['is_bot_active'] && 
                $user['has_token'] && 
                $user['session_id'] && 
                $user['session_state'] === 'active';
    
    $status = $eligible ? "✓ ELIGIBLE" : "✗ NOT ELIGIBLE";
    
    echo "\nUser {$user['id']} ({$user['email']}): {$status}\n";
    echo "  - Active: " . ($user['is_active'] ? "✓" : "✗") . "\n";
    echo "  - Bot Active: " . ($user['is_bot_active'] ? "✓" : "✗") . "\n";
    echo "  - Has Token: " . ($user['has_token'] ? "✓" : "✗") . "\n";
    echo "  - Session: " . ($user['session_id'] ? "#{$user['session_id']} ({$user['session_state']})" : "None") . "\n";
    
    if (!$eligible) {
        echo "  Reason: ";
        if (!$user['is_active']) echo "User not active. ";
        if (!$user['is_bot_active']) echo "Bot not active. ";
        if (!$user['has_token']) echo "No API token. ";
        if (!$user['session_id']) echo "No session. ";
        if ($user['session_id'] && $user['session_state'] !== 'active') echo "Session not active ({$user['session_state']}). ";
        echo "\n";
    }
}

echo "\n" . str_repeat("=", 100) . "\n";
