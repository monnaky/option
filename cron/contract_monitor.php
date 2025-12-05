<?php

/**
 * Contract Monitor Cron Job
 * 
 * This script should be run every 10-15 seconds via cron:
 * */10 * * * * /usr/bin/php /path/to/cron/contract_monitor.php
 * 
 * Or for shared hosting, set up in cPanel cron jobs (minimum 1 minute)
 * 
 * Note: For faster monitoring, you may need to run this more frequently
 * or use a different approach (queue system, background workers)
 */

// Set script execution time limit
set_time_limit(60); // 1 minute max

// Load application
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\TradingBotService;

try {
    $tradingBot = TradingBotService::getInstance();
    
    // Process contract results
    $tradingBot->processContractResults();
    
    error_log("Contract monitor cron job completed successfully");
    
} catch (Exception $e) {
    error_log("Contract monitor cron job error: " . $e->getMessage());
    exit(1);
}

exit(0);

