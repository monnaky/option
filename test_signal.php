<?php
/**
 * Send Test Signal
 * 
 * Sends a test signal and shows detailed execution results
 */

require_once __DIR__ . '/app/autoload.php';

use App\Services\SignalService;

echo "=== Sending Test Signal ===\n\n";

$signalService = SignalService::getInstance();

// Send a test RISE signal
$signalData = [
    'type' => 'RISE',
    'asset' => null, // Auto-select
    'source' => 'manual',
    'rawText' => 'TEST SIGNAL - Manual test from check script',
];

echo "Signal Data:\n";
echo json_encode($signalData, JSON_PRETTY_PRINT) . "\n\n";

try {
    echo "Sending signal...\n";
    $result = $signalService->receiveSignal($signalData);
    
    echo "\n✓ Signal sent successfully!\n\n";
    echo "Result:\n";
    echo json_encode($result, JSON_PRETTY_PRINT) . "\n\n";
    
    if (isset($result['signal_id'])) {
        echo "Signal ID: {$result['signal_id']}\n";
        
        if (isset($result['execution'])) {
            $exec = $result['execution'];
            echo "\nExecution Summary:\n";
            echo "  Total Users: {$exec['total_users']}\n";
            echo "  Valid Users: " . ($exec['valid_users'] ?? $exec['total_users']) . "\n";
            echo "  Successful: {$exec['successful']}\n";
            echo "  Failed: {$exec['failed']}\n";
            
            if (isset($exec['results']) && !empty($exec['results'])) {
                echo "\nDetailed Results:\n";
                foreach ($exec['results'] as $idx => $userResult) {
                    $userId = $userResult['user_id'];
                    $success = $userResult['success'] ? '✓' : '✗';
                    echo "  User {$userId}: {$success}";
                    
                    if (!$userResult['success']) {
                        echo " - Error: " . ($userResult['error'] ?? 'Unknown');
                    } else {
                        echo " - Trade ID: " . ($userResult['trade_id'] ?? 'N/A');
                    }
                    echo "\n";
                }
            }
            
            if (isset($exec['skipped']) && !empty($exec['skipped'])) {
                echo "\nSkipped Users:\n";
                foreach ($exec['skipped'] as $skipped) {
                    echo "  User {$skipped['user_id']} ({$skipped['email']}): {$skipped['reason']}\n";
                }
            }
        }
    }
    
} catch (Exception $e) {
    echo "\n✗ Signal failed!\n\n";
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace:\n" . $e->getTraceAsString() . "\n";
}

echo "\n=== Test Complete ===\n";
