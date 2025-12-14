<?php
/**
 * Verify Trading Setup
 * 
 * Diagnostic script to check if trading system is properly configured
 */

require_once __DIR__ . '/app/autoload.php';

use App\Config\Database;

$db = Database::getInstance();

echo "=== VTM Option Trading Setup Verification ===\n\n";

// Check 1: Active users with bot enabled
echo "1. Active Users:\n";
$activeUsers = $db->query("
    SELECT u.id, u.email, s.is_bot_active, u.encrypted_api_token IS NOT NULL as has_token
    FROM users u
    LEFT JOIN settings s ON u.id = s.user_id
    WHERE u.is_active = 1
");

if (empty($activeUsers)) {
    echo "   No active users found\n";
} else {
    foreach ($activeUsers as $user) {
        $status = $user['is_bot_active'] ? '✓ Bot Active' : '✗ Bot Inactive';
        $token = $user['has_token'] ? '✓ Has Token' : '✗ No Token';
        echo "   User {$user['id']} ({$user['email']}): {$status}, {$token}\n";
    }
}
echo "\n";

// Check 2: Active trading sessions
echo "2. Active Trading Sessions:\n";
$activeSessions = $db->query("
    SELECT ts.id, ts.user_id, u.email, ts.state, ts.start_time, ts.end_time
    FROM trading_sessions ts
    INNER JOIN users u ON ts.user_id = u.id
    WHERE ts.end_time IS NULL
    ORDER BY ts.start_time DESC
");

if (empty($activeSessions)) {
    echo "   No active sessions found\n";
} else {
    foreach ($activeSessions as $session) {
        $endTime = $session['end_time'] ?? 'NULL';
        echo "   Session {$session['id']} - User {$session['user_id']} ({$session['email']}): {$session['state']} since {$session['start_time']} (end: {$endTime})\n";
    }
}
echo "\n";

// Check 3: Recent signals
echo "3. Recent Signals (last 5):\n";
$recentSignals = $db->query("
    SELECT id, signal_type, source, processed, total_users, successful_executions, failed_executions, timestamp
    FROM signals
    ORDER BY timestamp DESC
    LIMIT 5
");

if (empty($recentSignals)) {
    echo "   No signals found\n";
} else {
    foreach ($recentSignals as $signal) {
        $status = $signal['processed'] ? "✓ Processed" : "✗ Pending";
        echo "   Signal {$signal['id']} ({$signal['signal_type']} from {$signal['source']}): {$status}, Users: {$signal['total_users']}, Success: {$signal['successful_executions']}, Failed: {$signal['failed_executions']} - {$signal['timestamp']}\n";
    }
}
echo "\n";

// Check 4: Eligible users for signal execution
echo "4. Users Eligible for Signal Execution:\n";
$eligibleUsers = $db->query("
    SELECT DISTINCT u.id, u.email
    FROM users u
    INNER JOIN settings s ON u.id = s.user_id
    INNER JOIN trading_sessions ts ON u.id = ts.user_id
    WHERE u.is_active = 1
    AND s.is_bot_active = 1
    AND u.encrypted_api_token IS NOT NULL
    AND u.encrypted_api_token != ''
    AND ts.state = 'active'
    AND ts.end_time IS NULL
");

if (empty($eligibleUsers)) {
    echo "   ✗ No users eligible - signals will not execute trades!\n";
    echo "   \n";
    echo "   To fix:\n";
    echo "   1. Login to the dashboard\n";
    echo "   2. Connect your Deriv API token in Profile settings\n";
    echo "   3. Click 'START TRADING' button\n";
    echo "   4. Run this script again to verify\n";
} else {
    echo "   ✓ " . count($eligibleUsers) . " user(s) eligible:\n";
    foreach ($eligibleUsers as $user) {
        echo "     - User {$user['id']} ({$user['email']})\n";
    }
}
echo "\n";

// Check 5: Recent trades
echo "5. Recent Trades (last 5):\n";
$recentTrades = $db->query("
    SELECT t.id, t.user_id, u.email, t.asset, t.direction, t.stake, t.status, t.profit, t.timestamp
    FROM trades t
    INNER JOIN users u ON t.user_id = u.id
    ORDER BY t.timestamp DESC
    LIMIT 5
");

if (empty($recentTrades)) {
    echo "   No trades found\n";
} else {
    foreach ($recentTrades as $trade) {
        $profitStr = $trade['profit'] > 0 ? "+\${$trade['profit']}" : "\${$trade['profit']}";
        echo "   Trade {$trade['id']} - User {$trade['user_id']} ({$trade['email']}): {$trade['asset']} {$trade['direction']} \${$trade['stake']} - {$trade['status']} ({$profitStr}) - {$trade['timestamp']}\n";
    }
}
echo "\n";

// Check 6: System health
echo "6. System Health:\n";
$issues = [];

// Check if any user has bot active but no active session
$orphanedBots = $db->query("
    SELECT u.id, u.email
    FROM users u
    INNER JOIN settings s ON u.id = s.user_id
    LEFT JOIN trading_sessions ts ON u.id = ts.user_id AND ts.state = 'active' AND ts.end_time IS NULL
    WHERE u.is_active = 1
    AND s.is_bot_active = 1
    AND ts.id IS NULL
");

if (!empty($orphanedBots)) {
    $issues[] = "Found " . count($orphanedBots) . " user(s) with bot_active=1 but no active session";
    foreach ($orphanedBots as $user) {
        echo "   ⚠️  User {$user['id']} ({$user['email']}): Bot marked active but no session\n";
    }
}

// Check if any session is stuck in initializing
$stuckSessions = $db->query("
    SELECT ts.id, ts.user_id, u.email, ts.start_time
    FROM trading_sessions ts
    INNER JOIN users u ON ts.user_id = u.id
    WHERE ts.state = 'initializing'
    AND ts.start_time < DATE_SUB(NOW(), INTERVAL 10 SECOND)
");

if (!empty($stuckSessions)) {
    $issues[] = "Found " . count($stuckSessions) . " session(s) stuck in initializing state";
    foreach ($stuckSessions as $session) {
        echo "   ⚠️  Session {$session['id']} - User {$session['user_id']} ({$session['email']}): Stuck since {$session['start_time']}\n";
    }
}

if (empty($issues)) {
    echo "   ✓ No issues detected\n";
}
echo "\n";

echo "=== Verification Complete ===\n";
echo "\n";

// Summary
$totalUsers = count($activeUsers);
$totalEligible = count($eligibleUsers);
$totalSignals = count($recentSignals);
$totalTrades = count($recentTrades);

echo "Summary:\n";
echo "  - Active Users: {$totalUsers}\n";
echo "  - Eligible for Trading: {$totalEligible}\n";
echo "  - Recent Signals: {$totalSignals}\n";
echo "  - Recent Trades: {$totalTrades}\n";

if ($totalEligible > 0) {
    echo "\n✓ System is ready for signal-based trading!\n";
} else {
    echo "\n✗ System is NOT ready - no eligible users for trading\n";
}
