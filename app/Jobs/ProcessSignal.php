<?php

namespace App\Jobs;

use App\Services\SignalService;
use Exception;

class ProcessSignal
{
    /**
     * Execute the job
     * 
     * @param array $data Job data containing signal information
     */
    public function handle(array $data): void
    {
        $signalId = $data['signal_id'] ?? null;
        $signalType = $data['signal_type'] ?? null;
        $asset = $data['asset'] ?? null;
        
        if (!$signalId || !$signalType) {
            throw new Exception("Invalid signal data: missing ID or type");
        }
        
        echo "Processing Signal #{$signalId} ({$signalType} on " . ($asset ?? 'ALL') . ")...\n";
        
        $signalService = SignalService::getInstance();
        
        // Execute the signal for all users
        // Note: In a larger system, we might fan-out here (create ExecuteTrade jobs for each user)
        // But for now, we'll keep the logic centralized in SignalService but run it in the worker
        $result = $signalService->executeSignalForAllUsers($signalId, $signalType, $asset);
        
        echo "Signal #{$signalId} processed. Success: {$result['successful']}, Failed: {$result['failed']}\n";
    }
}
