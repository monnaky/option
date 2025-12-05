<?php

/**
 * Trading Loop Cron Job
 * 
 * This script should be run every minute via cron:
 * * * * * * /usr/bin/php /path/to/cron/trading_loop.php
 * 
 * Or for shared hosting, set up in cPanel cron jobs
 */

// Set script execution time limit
set_time_limit(300); // 5 minutes max

// Load application
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\TradingBotService;

try {
    $tradingBot = TradingBotService::getInstance();
    
    // Process trading loop for all active users
    $tradingBot->processTradingLoop();
    
    // Cleanup stale sessions
    $tradingBot->cleanupStaleSessions();
    
    // Perform health check
    $tradingBot->performHealthCheck();
    
    error_log("Trading loop cron job completed successfully");
    
} catch (Exception $e) {
    error_log("Trading loop cron job error: " . $e->getMessage());
    exit(1);
}

exit(0);

