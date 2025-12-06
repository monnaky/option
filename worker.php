<?php

/**
 * Queue Worker
 * 
 * Runs continuously to process jobs from the database queue.
 * Usage: php worker.php [queue_name]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/bootstrap.php';

use App\Services\QueueService;

// Set unlimited execution time
set_time_limit(0);

// Get queue name from argument or default
$queueName = $argv[1] ?? 'default';

$queue = QueueService::getInstance();

echo "Worker started. Listening on queue: {$queueName}\n";

$isRunning = true;

// Handle termination signals (if PCNTL is available)
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () use (&$isRunning) {
        echo "Shutting down...\n";
        $isRunning = false;
    });
    pcntl_signal(SIGINT, function () use (&$isRunning) {
        echo "Shutting down...\n";
        $isRunning = false;
    });
}

while ($isRunning) {
    try {
        // Pop next job
        $job = $queue->pop($queueName);
        
        if ($job) {
            $jobId = $job['id'];
            $payload = $job['payload'];
            $jobClass = $payload['job'] ?? null;
            $data = $payload['data'] ?? [];
            
            echo "[" . date('Y-m-d H:i:s') . "] Processing job {$jobId}: {$jobClass}\n";
            
            try {
                if (!$jobClass || !class_exists($jobClass)) {
                    throw new Exception("Job class not found: {$jobClass}");
                }
                
                // Instantiate and execute job
                // Jobs should implement a handle($data) method
                $instance = new $jobClass();
                
                if (!method_exists($instance, 'handle')) {
                    throw new Exception("Job class {$jobClass} does not have a handle() method");
                }
                
                $instance->handle($data);
                
                // Job done
                $queue->delete($jobId);
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} processed successfully\n";
                
            } catch (Exception $e) {
                echo "[" . date('Y-m-d H:i:s') . "] Job {$jobId} failed: " . $e->getMessage() . "\n";
                
                // Retry or fail
                if ($job['attempts'] < 3) {
                    $queue->release($jobId, 30); // Retry in 30s
                    echo "Job released for retry (attempt {$job['attempts']})\n";
                } else {
                    $queue->fail($jobId, $e->getMessage());
                    echo "Job failed permanently\n";
                }
            }
        } else {
            // No jobs, sleep for a bit
            sleep(1);
        }
        
        // Optional: Check for memory leaks / restart periodically
        if (memory_get_usage() > 128 * 1024 * 1024) { // 128MB
            echo "Memory limit reached, restarting...\n";
            $isRunning = false;
        }
        
    } catch (Exception $e) {
        echo "Worker error: " . $e->getMessage() . "\n";
        sleep(5);
    }
}
