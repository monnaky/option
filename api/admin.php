<?php
/**
 * Admin API Endpoints
 * 
 * GET /api/admin/users
 */

// CRITICAL: Suppress ALL errors FIRST
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Load autoloader BEFORE use statements
require_once __DIR__ . '/../app/autoload.php';

// use statements MUST be at top level, immediately after require
use App\Config\Database;
use App\Utils\DatabaseHelper;
use App\Services\SignalService;
use App\Services\TradingBotService;
use App\Middleware\AuthMiddleware;
use App\Middleware\AdminMiddleware;
use App\Utils\Response;

// Start output buffering AFTER use statements
@ob_start();
@ob_clean(); // Clean any previous output

// Set JSON header AFTER output buffering starts
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Wrap everything in try-catch to prevent any errors from leaking
try {

    // Start session
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Require admin authentication
    try {
        $admin = AdminMiddleware::requireAdmin(true);
    } catch (Exception $e) {
        // AdminMiddleware::requireAdmin() already sends JSON response and exits
        exit;
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Route requests
    switch ($method) {
        case 'GET':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid GET actions: users, stats, signals, trades, system', 400);
            } elseif ($action === 'users') {
                handleGetUsers();
            } elseif ($action === 'stats') {
                handleGetStats();
            } elseif ($action === 'signals') {
                handleGetSignals();
            } elseif ($action === 'trades') {
                handleGetTrades();
            } elseif ($action === 'system') {
                handleGetSystem();
            } else {
                Response::error('Invalid action. Valid GET actions: users, stats, signals, trades, system', 400);
            }
            break;
        
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid POST actions: user-suspend, user-activate, user-delete, cron-activate, broadcast-signal, test-signal', 400);
            } elseif ($action === 'user-suspend') {
                handleSuspendUser();
            } elseif ($action === 'user-activate') {
                handleActivateUser();
            } elseif ($action === 'user-delete') {
                handleDeleteUser();
            } elseif ($action === 'cron-activate') {
                handleCronActivate();
            } elseif ($action === 'broadcast-signal') {
                handleBroadcastSignal();
            } elseif ($action === 'test-signal') {
                handleTestSignal();
            } else {
                Response::error('Invalid action. Valid POST actions: user-suspend, user-activate, user-delete, cron-activate, broadcast-signal, test-signal', 400);
            }
            break;
        
        default:
            Response::error("Method {$method} not allowed. Use GET or POST with action parameter", 405);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/admin.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Handle get all users (admin)
 */
function handleGetUsers()
{
    try {
        $db = Database::getInstance();
        
        // Get pagination parameters
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        $search = $_GET['search'] ?? '';
        
        $offset = ($page - 1) * $limit;
        
        // Build query
        $sql = "SELECT id, email, is_active, created_at FROM users";
        $params = [];
        
        if (!empty($search)) {
            $sql .= " WHERE email LIKE :search";
            $params['search'] = "%{$search}%";
        }
        
        $sql .= " ORDER BY created_at DESC LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
        
        // Get users
        $users = $db->query($sql, $params);
        
        // Get total count
        $countSql = "SELECT COUNT(*) as total FROM users";
        if (!empty($search)) {
            $countSql .= " WHERE email LIKE :search";
        }
        $total = (int)$db->queryValue($countSql, $params);
        
        // Get settings and stats for each user
        $helper = new DatabaseHelper();
        $usersWithStats = [];
        
        foreach ($users as $user) {
            $userId = $user['id'];
            
            // Get settings
            $settings = $helper->getUserSettings($userId);
            
            // Get trade stats
            $stats = $helper->getUserTradeStats($userId);
            
            // Calculate win rate
            $winRate = 0;
            if ($stats['total_trades'] > 0) {
                $winRate = ($stats['won_trades'] / $stats['total_trades']) * 100;
            }
            
            // Check if has API token
            $userRecord = $db->queryOne(
                "SELECT encrypted_api_token FROM users WHERE id = :id",
                ['id' => $userId]
            );
            
            $usersWithStats[] = [
                'id' => $userId,
                'email' => $user['email'],
                'is_active' => (bool)$user['is_active'],
                'has_api_token' => !empty($userRecord['encrypted_api_token']),
                'created_at' => $user['created_at'],
                'settings' => $settings ? [
                    'stake' => (float)$settings['stake'],
                    'target' => (float)$settings['target'],
                    'stop_limit' => (float)$settings['stop_limit'],
                    'is_bot_active' => (bool)$settings['is_bot_active'],
                    'daily_profit' => (float)$settings['daily_profit'],
                    'daily_loss' => (float)$settings['daily_loss'],
                ] : null,
                'stats' => [
                    'total_trades' => (int)$stats['total_trades'],
                    'won_trades' => (int)$stats['won_trades'],
                    'win_rate' => number_format($winRate, 2),
                    'total_profit' => number_format((float)$stats['total_profit'], 2),
                    'total_loss' => number_format((float)$stats['total_loss'], 2),
                    'net_profit' => number_format((float)$stats['total_profit'] - (float)$stats['total_loss'], 2),
                ],
            ];
        }
        
        // Return response
        Response::success([
            'users' => $usersWithStats,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
            ],
        ]);
        
    } catch (Exception $e) {
        error_log('Get users error: ' . $e->getMessage());
        Response::error('Failed to fetch users', 500);
    }
}

/**
 * Handle get admin statistics
 */
function handleGetStats()
{
    try {
        $db = Database::getInstance();
        $helper = new DatabaseHelper();
        
        // Total users
        $totalUsers = (int)$db->queryValue("SELECT COUNT(*) FROM users");
        $activeUsers = (int)$db->queryValue("SELECT COUNT(*) FROM users WHERE is_active = 1");
        
        // Users with active trading
        $activeTrading = (int)$db->queryValue(
            "SELECT COUNT(DISTINCT u.id) 
             FROM users u
             INNER JOIN settings s ON u.id = s.user_id
             INNER JOIN trading_sessions ts ON u.id = ts.user_id
             WHERE s.is_bot_active = 1 AND ts.state = 'active'"
        );
        
        // Total trades
        $totalTrades = (int)$db->queryValue("SELECT COUNT(*) FROM trades");
        $pendingTrades = (int)$db->queryValue("SELECT COUNT(*) FROM trades WHERE status = 'pending'");
        $wonTrades = (int)$db->queryValue("SELECT COUNT(*) FROM trades WHERE status = 'won'");
        $lostTrades = (int)$db->queryValue("SELECT COUNT(*) FROM trades WHERE status = 'lost'");
        
        // Total profit/loss
        $totalProfit = (float)$db->queryValue("SELECT COALESCE(SUM(profit), 0) FROM trades WHERE profit > 0") ?? 0;
        $totalLoss = (float)$db->queryValue("SELECT COALESCE(SUM(ABS(profit)), 0) FROM trades WHERE profit < 0") ?? 0;
        $netProfit = $totalProfit - $totalLoss;
        
        // Active trading sessions
        $activeSessions = (int)$db->queryValue(
            "SELECT COUNT(*) FROM trading_sessions WHERE state = 'active'"
        );
        
        // Signals
        $signalService = SignalService::getInstance();
        $signalStats = $signalService->getSignalStatistics();
        
        // Recent activity (last 24 hours)
        $recentTrades = (int)$db->queryValue(
            "SELECT COUNT(*) FROM trades WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        $recentSignals = (int)$db->queryValue(
            "SELECT COUNT(*) FROM signals WHERE timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        Response::success([
            'users' => [
                'total' => $totalUsers,
                'active' => $activeUsers,
                'active_trading' => $activeTrading,
            ],
            'trades' => [
                'total' => $totalTrades,
                'pending' => $pendingTrades,
                'won' => $wonTrades,
                'lost' => $lostTrades,
                'win_rate' => $totalTrades > 0 ? round(($wonTrades / $totalTrades) * 100, 2) : 0,
            ],
            'profit' => [
                'total_profit' => number_format($totalProfit, 2),
                'total_loss' => number_format($totalLoss, 2),
                'net_profit' => number_format($netProfit, 2),
            ],
            'sessions' => [
                'active' => $activeSessions,
            ],
            'signals' => $signalStats,
            'recent_activity' => [
                'trades_24h' => $recentTrades,
                'signals_24h' => $recentSignals,
            ],
        ]);
        
    } catch (Exception $e) {
        error_log('Get admin stats error: ' . $e->getMessage());
        Response::error('Failed to fetch statistics', 500);
    }
}

/**
 * Handle get signals (admin view)
 */
function handleGetSignals()
{
    try {
        $signalService = SignalService::getInstance();
        
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $signals = $signalService->getSignalHistory($limit, $offset);
        
        Response::success([
            'signals' => $signals,
            'total' => count($signals),
        ]);
        
    } catch (Exception $e) {
        error_log('Get admin signals error: ' . $e->getMessage());
        Response::error('Failed to fetch signals', 500);
    }
}

/**
 * Handle get all trades (admin view)
 */
function handleGetTrades()
{
    try {
        $db = Database::getInstance();
        
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = ($page - 1) * $limit;
        
        $sql = "
            SELECT t.*, u.email as user_email
            FROM trades t
            INNER JOIN users u ON t.user_id = u.id
            ORDER BY t.timestamp DESC
            LIMIT :limit OFFSET :offset
        ";
        
        $trades = $db->query($sql, [
            'limit' => $limit,
            'offset' => $offset,
        ]);
        
        $total = (int)$db->queryValue("SELECT COUNT(*) FROM trades");
        
        // Format trades
        $formattedTrades = array_map(function($trade) {
            return [
                'id' => (int)$trade['id'],
                'user_id' => (int)$trade['user_id'],
                'user_email' => $trade['user_email'],
                'trade_id' => $trade['trade_id'],
                'asset' => $trade['asset'],
                'direction' => $trade['direction'],
                'stake' => (float)$trade['stake'],
                'profit' => (float)$trade['profit'],
                'status' => $trade['status'],
                'timestamp' => $trade['timestamp'],
            ];
        }, $trades);
        
        Response::success([
            'trades' => $formattedTrades,
            'pagination' => [
                'page' => $page,
                'limit' => $limit,
                'total' => $total,
                'pages' => ceil($total / $limit),
            ],
        ]);
        
    } catch (Exception $e) {
        error_log('Get admin trades error: ' . $e->getMessage());
        Response::error('Failed to fetch trades', 500);
    }
}

/**
 * Handle get system information
 */
function handleGetSystem()
{
    try {
        $db = Database::getInstance();
        
        // Database status
        $dbStatus = 'online';
        try {
            $db->queryValue("SELECT 1");
        } catch (Exception $e) {
            $dbStatus = 'offline';
        }
        
        // PHP version
        $phpVersion = PHP_VERSION;
        
        // Server info
        $serverInfo = [
            'php_version' => $phpVersion,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            'server_time' => date('Y-m-d H:i:s'),
        ];
        
        // Active cron jobs (check if recent activity exists)
        $recentCronActivity = $db->queryValue(
            "SELECT COUNT(*) FROM signals WHERE timestamp > DATE_SUB(NOW(), INTERVAL 5 MINUTE)"
        ) > 0;
        
        Response::success([
            'database' => [
                'status' => $dbStatus,
            ],
            'server' => $serverInfo,
            'cron' => [
                'active' => $recentCronActivity,
            ],
        ]);
        
    } catch (Exception $e) {
        error_log('Get system info error: ' . $e->getMessage());
        Response::error('Failed to fetch system information', 500);
    }
}

/**
 * Handle suspend user
 */
function handleSuspendUser()
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($data['user_id'] ?? 0);
        
        if ($userId <= 0) {
            Response::error('Invalid user ID', 400);
        }
        
        $db = Database::getInstance();
        $db->execute(
            "UPDATE users SET is_active = 0 WHERE id = :id",
            ['id' => $userId]
        );
        
        // Stop trading for user
        $tradingBot = TradingBotService::getInstance();
        try {
            $tradingBot->stopTrading($userId);
        } catch (Exception $e) {
            // Ignore if not trading
        }
        
        Response::success(['message' => 'User suspended successfully']);
        
    } catch (Exception $e) {
        error_log('Suspend user error: ' . $e->getMessage());
        Response::error('Failed to suspend user', 500);
    }
}

/**
 * Handle activate user
 */
function handleActivateUser()
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($data['user_id'] ?? 0);
        
        if ($userId <= 0) {
            Response::error('Invalid user ID', 400);
        }
        
        $db = Database::getInstance();
        $db->execute(
            "UPDATE users SET is_active = 1 WHERE id = :id",
            ['id' => $userId]
        );
        
        Response::success(['message' => 'User activated successfully']);
        
    } catch (Exception $e) {
        error_log('Activate user error: ' . $e->getMessage());
        Response::error('Failed to activate user', 500);
    }
}

/**
 * Handle delete user
 */
function handleDeleteUser()
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $userId = (int)($data['user_id'] ?? 0);
        
        if ($userId <= 0) {
            Response::error('Invalid user ID', 400);
        }
        
        // Stop trading first
        $tradingBot = TradingBotService::getInstance();
        try {
            $tradingBot->stopTrading($userId);
        } catch (Exception $e) {
            // Ignore if not trading
        }
        
        $db = Database::getInstance();
        
        // Delete user data (cascade should handle related records)
        $db->execute("DELETE FROM users WHERE id = :id", ['id' => $userId]);
        
        Response::success(['message' => 'User deleted successfully']);
        
    } catch (Exception $e) {
        error_log('Delete user error: ' . $e->getMessage());
        Response::error('Failed to delete user', 500);
    }
}

/**
 * Handle cron activation (manual trigger)
 */
function handleCronActivate()
{
    try {
        $results = [];
        
        // Step 1: Check if url_signal_watcher.php is running
        error_log('[CronActivate] Checking if url_signal_watcher.php is running...');
        
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        $watcherRunning = false;
        
        if ($isWindows) {
            // Windows: Use tasklist to check for php process running url_signal_watcher.php
            $output = shell_exec('tasklist /FI "IMAGENAME eq php.exe" /FO CSV 2>&1');
            if ($output && stripos($output, 'php.exe') !== false) {
                // Check if any php process has url_signal_watcher in command line
                $processes = shell_exec('wmic process where "name=\'php.exe\'" get commandline 2>&1');
                if ($processes && stripos($processes, 'url_signal_watcher') !== false) {
                    $watcherRunning = true;
                }
            }
        } else {
            // Linux/Unix: Use ps to check for process
            $output = shell_exec('ps aux | grep url_signal_watcher.php | grep -v grep 2>&1');
            $watcherRunning = !empty(trim($output));
        }
        
        error_log('[CronActivate] url_signal_watcher.php running: ' . ($watcherRunning ? 'YES' : 'NO'));
        $results['signal_watcher'] = [
            'running' => $watcherRunning,
            'action' => $watcherRunning ? 'already_running' : 'starting',
        ];
        
        // Step 2: Start url_signal_watcher.php if not running
        if (!$watcherRunning) {
            error_log('[CronActivate] Starting url_signal_watcher.php...');
            
            $projectRoot = dirname(__DIR__);
            $watcherPath = $projectRoot . '/cron/url_signal_watcher.php';
            $logPath = $projectRoot . '/logs/url_signal_watcher.log';
            
            if (!file_exists($watcherPath)) {
                error_log('[CronActivate] ERROR: url_signal_watcher.php not found at: ' . $watcherPath);
                $results['signal_watcher']['error'] = 'Watcher script not found';
            } else {
                // Ensure logs directory exists
                $logsDir = dirname($logPath);
                if (!is_dir($logsDir)) {
                    @mkdir($logsDir, 0755, true);
                }
                
                if ($isWindows) {
                    // Windows: Start process in background using start command
                    $command = 'start /B php "' . $watcherPath . '" >> "' . $logPath . '" 2>&1';
                    pclose(popen($command, 'r'));
                } else {
                    // Linux/Unix: Start process in background with nohup
                    $command = "nohup php '{$watcherPath}' >> '{$logPath}' 2>&1 &";
                    exec($command);
                }
                
                error_log('[CronActivate] Started url_signal_watcher.php with command: ' . $command);
                $results['signal_watcher']['started'] = true;
                $results['signal_watcher']['command'] = $command;
                
                // Wait a moment and check if it started
                sleep(1);
                
                if ($isWindows) {
                    $processes = shell_exec('wmic process where "name=\'php.exe\'" get commandline 2>&1');
                    $started = ($processes && stripos($processes, 'url_signal_watcher') !== false);
                } else {
                    $output = shell_exec('ps aux | grep url_signal_watcher.php | grep -v grep 2>&1');
                    $started = !empty(trim($output));
                }
                
                $results['signal_watcher']['verified'] = $started;
                error_log('[CronActivate] Verification: watcher is ' . ($started ? 'RUNNING' : 'NOT RUNNING'));
            }
        }
        
        // Step 3: Trigger trading loop
        error_log('[CronActivate] Triggering trading loop...');
        try {
            $tradingBot = TradingBotService::getInstance();
            $tradingBot->processTradingLoop();
            $tradingBot->processContractResults();
            $results['trading_loop'] = ['status' => 'executed'];
            error_log('[CronActivate] Trading loop executed successfully');
        } catch (Exception $e) {
            $results['trading_loop'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            error_log('[CronActivate] Trading loop error: ' . $e->getMessage());
        }
        
        // Step 4: Trigger signal processing
        error_log('[CronActivate] Processing unprocessed signals...');
        try {
            $signalService = SignalService::getInstance();
            $signalResult = $signalService->processUnprocessedSignals(10);
            $results['signal_processing'] = [
                'status' => 'executed',
                'processed' => $signalResult['processed'] ?? 0,
            ];
            error_log('[CronActivate] Processed ' . ($signalResult['processed'] ?? 0) . ' signals');
        } catch (Exception $e) {
            $results['signal_processing'] = [
                'status' => 'error',
                'error' => $e->getMessage(),
            ];
            error_log('[CronActivate] Signal processing error: ' . $e->getMessage());
        }
        
        error_log('[CronActivate] Complete. Results: ' . json_encode($results));
        
        Response::success([
            'message' => 'Cron jobs activated successfully',
            'details' => $results,
        ]);
        
    } catch (Exception $e) {
        error_log('Cron activation error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        Response::error('Failed to activate cron jobs: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle broadcast signal (manual signal for all users)
 */
function handleBroadcastSignal()
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $type = $data['type'] ?? '';
        $asset = $data['asset'] ?? null;
        
        if (empty($type)) {
            Response::error('Signal type is required (RISE or FALL)', 400);
        }
        
        $signalService = SignalService::getInstance();
        $result = $signalService->receiveSignal([
            'type' => $type,
            'asset' => $asset,
            'source' => 'admin_broadcast',
            'rawText' => ($asset ? "{$asset},{$type}" : $type),
        ]);
        
        Response::success([
            'message' => 'Signal broadcast successfully',
            'result' => $result,
        ]);
        
    } catch (Exception $e) {
        error_log('Broadcast signal error: ' . $e->getMessage());
        Response::error('Failed to broadcast signal: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle test signal (for debugging)
 */
function handleTestSignal()
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $type = $data['type'] ?? 'RISE';
        $asset = $data['asset'] ?? 'R_100';
        
        error_log("=== TEST SIGNAL START ===");
        error_log("Type: {$type}, Asset: {$asset}");
        
        // Step 1: Check active users
        $db = Database::getInstance();
        $activeUsers = $db->query("
            SELECT u.id, u.email, 
                   s.is_bot_active,
                   ts.state,
                   CASE 
                       WHEN u.encrypted_api_token IS NULL THEN 'NO_TOKEN'
                       WHEN u.encrypted_api_token = '' THEN 'EMPTY_TOKEN'
                       ELSE 'HAS_TOKEN'
                   END as token_status
            FROM users u
            LEFT JOIN settings s ON u.id = s.user_id
            LEFT JOIN trading_sessions ts ON u.id = ts.user_id AND ts.end_time IS NULL
            WHERE u.is_active = 1
        ");
        
        error_log("Found " . count($activeUsers) . " active users");
        foreach ($activeUsers as $user) {
            error_log("  User {$user['id']} ({$user['email']}): bot_active={$user['is_bot_active']}, session={$user['state']}, token={$user['token_status']}");
        }
        
        // Step 2: Send signal
        $signalService = SignalService::getInstance();
        $result = $signalService->receiveSignal([
            'type' => $type,
            'asset' => $asset,
            'source' => 'admin_test',
            'rawText' => "{$asset},{$type}",
        ]);
        
        error_log("Signal result: " . json_encode($result));
        error_log("=== TEST SIGNAL END ===");
        
        Response::success([
            'message' => 'Test signal sent',
            'active_users' => count($activeUsers),
            'users_detail' => $activeUsers,
            'signal_result' => $result,
        ]);
        
    } catch (Exception $e) {
        error_log('Test signal error: ' . $e->getMessage());
        error_log('Stack trace: ' . $e->getTraceAsString());
        Response::error('Failed to send test signal: ' . $e->getMessage(), 500);
    }
}
