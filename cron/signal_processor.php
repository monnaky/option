<?php

/**
 * Signal Processor Cron Job
 * 
 * This script processes unprocessed signals from the queue
 * Should be run every minute via cron:
 * * * * * * /usr/bin/php /path/to/cron/signal_processor.php
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes max

// Load application
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\SignalService;

try {
    $signalService = SignalService::getInstance();
    
    // Process unprocessed signals (up to 10 at a time)
    $result = $signalService->processUnprocessedSignals(10);
    
    if ($result['processed'] > 0) {
        error_log("Signal processor: Processed {$result['processed']} signals");
    }
    
    // Cleanup old signals (older than 30 days) - run once per day
    // Check if it's the first run of the day (hour = 0, minute = 0)
    $currentHour = (int)date('H');
    $currentMinute = (int)date('i');
    
    if ($currentHour === 0 && $currentMinute === 0) {
        $deleted = $signalService->cleanupOldSignals(30);
        if ($deleted > 0) {
            error_log("Signal processor: Cleaned up {$deleted} old signals");
        }
    }
    
} catch (Exception $e) {
    error_log("Signal processor cron job error: " . $e->getMessage());
    exit(1);
}

exit(0);

