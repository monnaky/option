<?php
/**
 * Cleanup Error Sessions
 * 
 * Fixes sessions stuck in error state with end_time IS NULL
 */

require_once __DIR__ . '/app/autoload.php';

use App\Config\Database;

$db = Database::getInstance();

echo "=== Cleanup Error Sessions ===\n\n";

// Find error sessions with no end_time
$errorSessions = $db->query("
    SELECT ts.id, ts.user_id, u.email, ts.state, ts.start_time
    FROM trading_sessions ts
    INNER JOIN users u ON ts.user_id = u.id
    WHERE ts.state IN ('error', 'initializing', 'stopping')
    AND ts.end_time IS NULL
    ORDER BY ts.start_time DESC
");

if (empty($errorSessions)) {
    echo "No error sessions to clean up\n";
    exit(0);
}

echo "Found " . count($errorSessions) . " error session(s) to clean up:\n\n";

foreach ($errorSessions as $session) {
    echo "Session {$session['id']} - User {$session['user_id']} ({$session['email']})\n";
    echo "  State: {$session['state']}\n";
    echo "  Started: {$session['start_time']}\n";
    
    // Set end_time to now
    $db->execute(
        "UPDATE trading_sessions SET end_time = NOW() WHERE id = :id",
        ['id' => $session['id']]
    );
    
    echo "  ✓ Cleaned up (set end_time)\n\n";
}

// Fix User 2 (bot active but no session)
echo "Checking for users with bot_active but no session...\n";
$orphanedBots = $db->query("
    SELECT u.id, u.email, s.is_bot_active
    FROM users u
    INNER JOIN settings s ON u.id = s.user_id
    LEFT JOIN trading_sessions ts ON u.id = ts.user_id AND ts.state = 'active' AND ts.end_time IS NULL
    WHERE s.is_bot_active = 1
    AND ts.id IS NULL
");

if (!empty($orphanedBots)) {
    echo "Found " . count($orphanedBots) . " user(s) with bot_active but no session:\n\n";
    
    foreach ($orphanedBots as $user) {
        echo "User {$user['id']} ({$user['email']})\n";
        
        // Set bot_active to 0
        $db->execute(
            "UPDATE settings SET is_bot_active = 0 WHERE user_id = :user_id",
            ['user_id' => $user['id']]
        );
        
        echo "  ✓ Set is_bot_active = 0\n\n";
    }
}

echo "=== Cleanup Complete ===\n";
