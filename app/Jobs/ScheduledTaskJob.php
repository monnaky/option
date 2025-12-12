<?php

namespace App\Jobs;

use App\Services\TradingBotService;
use Exception;

class ScheduledTaskJob
{
    /**
     * Execute the scheduled task
     * This job handles contract monitoring and other scheduled trading tasks
     * 
     * @param array $data Job data containing task information
     */
    public function handle(array $data): void
    {
        $taskType = $data['task_type'] ?? 'contract_monitor';
        
        echo "Executing scheduled task: {$taskType}\n";
        
        try {
            switch ($taskType) {
                case 'contract_monitor':
                    $this->handleContractMonitor();
                    break;
                
                case 'cleanup_old_trades':
                    $this->handleCleanupOldTrades();
                    break;
                
                case 'update_statistics':
                    $this->handleUpdateStatistics();
                    break;
                
                default:
                    throw new Exception("Unknown task type: {$taskType}");
            }
            
            echo "Scheduled task '{$taskType}' completed successfully\n";
            
        } catch (Exception $e) {
            error_log("ScheduledTaskJob failed for task '{$taskType}': " . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Handle contract monitoring task
     */
    private function handleContractMonitor(): void
    {
        $tradingBot = TradingBotService::getInstance();
        $tradingBot->processContractResults();
    }
    
    /**
     * Handle cleanup of old trades
     */
    private function handleCleanupOldTrades(): void
    {
        // Clean up trades older than 30 days
        $db = \App\Config\Database::getInstance();
        
        $result = $db->execute(
            "DELETE FROM trades 
             WHERE status IN ('cancelled', 'error') 
             AND created_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
        );
        
        echo "Cleaned up old trades: {$result} records deleted\n";
    }
    
    /**
     * Handle statistics update
     */
    private function handleUpdateStatistics(): void
    {
        // Update daily statistics
        $db = \App\Config\Database::getInstance();
        
        // Reset daily limits at midnight
        $db->execute(
            "UPDATE settings 
             SET daily_profit = 0, 
                 daily_loss = 0, 
                 daily_trades = 0 
             WHERE DATE(updated_at) < CURDATE()"
        );
        
        echo "Daily statistics updated\n";
    }
}
