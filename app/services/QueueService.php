<?php

namespace App\Services;

use App\Config\Database;
use Exception;

class QueueService
{
    private static ?QueueService $instance = null;
    private Database $db;
    
    // Configuration
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY = 60; // seconds
    
    private function __construct()
    {
        $this->db = Database::getInstance();
    }
    
    public static function getInstance(): QueueService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Push a job onto the queue
     * 
     * @param string $jobClass Class name of the job to execute
     * @param array $data Data to pass to the job
     * @param string $queue Queue name (default: 'default')
     * @param int $delay Delay in seconds (default: 0)
     */
    public function push(string $jobClass, array $data = [], string $queue = 'default', int $delay = 0): int
    {
        $payload = json_encode([
            'job' => $jobClass,
            'data' => $data,
        ]);
        
        $availableAt = time() + $delay;
        $createdAt = time();
        
        $sql = "INSERT INTO jobs (queue, payload, attempts, available_at, created_at) 
                VALUES (:queue, :payload, 0, :available_at, :created_at)";
                
        $this->db->execute($sql, [
            'queue' => $queue,
            'payload' => $payload,
            'available_at' => $availableAt,
            'created_at' => $createdAt,
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Pop the next job from the queue
     * 
     * @param string $queue Queue name
     * @return array|null Job data or null if queue is empty
     */
    public function pop(string $queue = 'default'): ?array
    {
        // Start transaction to ensure atomic operation
        $this->db->beginTransaction();
        
        try {
            // Find next available job
            // We use FOR UPDATE SKIP LOCKED if available (MySQL 8.0+), or just simple locking
            // Since we want broad compatibility, we'll use a simple update-based reservation approach
            // which is robust enough for this scale.
            
            $now = time();
            
            // 1. Find a candidate job
            $sql = "SELECT id, payload, attempts FROM jobs 
                    WHERE queue = :queue 
                    AND reserved_at IS NULL 
                    AND available_at <= :now 
                    ORDER BY id ASC 
                    LIMIT 1 
                    FOR UPDATE";
                    
            $job = $this->db->queryOne($sql, [
                'queue' => $queue,
                'now' => $now,
            ]);
            
            if (!$job) {
                $this->db->commit();
                return null;
            }
            
            // 2. Reserve the job
            $updateSql = "UPDATE jobs SET reserved_at = :now, attempts = attempts + 1 WHERE id = :id";
            $this->db->execute($updateSql, [
                'now' => $now,
                'id' => $job['id'],
            ]);
            
            $this->db->commit();
            
            return [
                'id' => $job['id'],
                'payload' => json_decode($job['payload'], true),
                'attempts' => $job['attempts'] + 1,
            ];
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Queue pop error: " . $e->getMessage());
            return null;
        }
    }
    
    /**
     * Delete a job (mark as done)
     */
    public function delete(int $jobId): void
    {
        $this->db->execute("DELETE FROM jobs WHERE id = :id", ['id' => $jobId]);
    }
    
    /**
     * Release a job back to the queue (retry later)
     */
    public function release(int $jobId, int $delay = self::RETRY_DELAY): void
    {
        $availableAt = time() + $delay;
        
        $this->db->execute(
            "UPDATE jobs SET reserved_at = NULL, available_at = :available_at WHERE id = :id",
            [
                'available_at' => $availableAt,
                'id' => $jobId,
            ]
        );
    }
    
    /**
     * Mark job as failed (final failure)
     */
    public function fail(int $jobId, string $error): void
    {
        // For now, we just delete it, but in a real system we might move it to a failed_jobs table
        error_log("Job {$jobId} failed permanently: {$error}");
        $this->delete($jobId);
    }
}
