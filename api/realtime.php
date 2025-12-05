<?php
/**
 * Real-time Data API Endpoints
 * 
 * Replaces Socket.IO events with AJAX polling endpoints
 * 
 * GET /api/realtime.php?action=status
 * GET /api/realtime.php?action=balance
 * GET /api/realtime.php?action=trades
 * GET /api/realtime.php?action=updates
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
use App\Middleware\AuthMiddleware;
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

    // Require authentication
    try {
        $user = AuthMiddleware::requireAuth();
        $userId = $user['id'];
    } catch (Exception $e) {
        Response::error('Authentication required', 401);
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Only GET requests for polling
    if ($method !== 'GET') {
        Response::error("Method {$method} not allowed. This endpoint only accepts GET requests for polling", 405);
    }
    
    // Route requests
    if (empty($action)) {
        Response::error('Action parameter is required. Valid actions: status, balance, trades, updates, notifications', 400);
    }
    
    switch ($action) {
        case 'status':
            handleGetStatus($userId);
            break;
        
        case 'balance':
            handleGetBalance($userId);
            break;
        
        case 'trades':
            handleGetTrades($userId);
            break;
        
        case 'updates':
            handleGetUpdates($userId);
            break;
        
        case 'notifications':
            handleGetNotifications($userId);
            break;
        
        default:
            Response::error('Invalid action. Valid actions: status, balance, trades, updates, notifications', 400);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/realtime.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Get trading status (replaces trading:status event)
 */
function handleGetStatus(int $userId)
{
    try {
        $helper = new DatabaseHelper();
        $db = Database::getInstance();
        
        // Get user settings
        $settings = $helper->getUserSettings($userId);
        
        // Get active trading session
        $activeSession = $helper->getActiveTradingSession($userId);
        
        // Get trade statistics
        $stats = $helper->getUserTradeStats($userId);
        
        // Ensure stats has default values
        $stats = array_merge([
            'total_trades' => 0,
            'won_trades' => 0,
            'lost_trades' => 0,
            'total_profit' => 0,
            'total_loss' => 0,
            'net_profit' => 0,
        ], $stats ?: []);
        
        // Calculate win rate
        $winRate = 0;
        if ($stats['total_trades'] > 0) {
            $winRate = ($stats['won_trades'] / $stats['total_trades']) * 100;
        }
        
        Response::success([
            'isActive' => $settings ? (bool)$settings['is_bot_active'] : false,
            'settings' => $settings ? [
                'stake' => (float)$settings['stake'],
                'target' => (float)$settings['target'],
                'stopLimit' => (float)$settings['stop_limit'],
                'isBotActive' => (bool)$settings['is_bot_active'],
                'dailyProfit' => (float)$settings['daily_profit'],
                'dailyLoss' => (float)$settings['daily_loss'],
            ] : null,
            'sessionInfo' => $activeSession ? [
                'id' => $activeSession['id'],
                'session_id' => $activeSession['session_id'],
                'state' => $activeSession['state'],
                'totalTrades' => (int)$activeSession['total_trades'],
                'successfulTrades' => (int)$activeSession['successful_trades'],
                'failedTrades' => (int)$activeSession['failed_trades'],
                'dailyProfit' => (float)$activeSession['daily_profit'],
                'dailyLoss' => (float)$activeSession['daily_loss'],
            ] : null,
            'stats' => [
                'totalTrades' => (int)($stats['total_trades'] ?? 0),
                'wonTrades' => (int)($stats['won_trades'] ?? 0),
                'lostTrades' => (int)($stats['lost_trades'] ?? 0),
                'winRate' => number_format($winRate, 2),
                'totalProfit' => number_format((float)($stats['total_profit'] ?? 0), 2),
                'totalLoss' => number_format((float)($stats['total_loss'] ?? 0), 2),
                'netProfit' => number_format((float)($stats['total_profit'] ?? 0) - (float)($stats['total_loss'] ?? 0), 2),
            ],
            'timestamp' => date('c'),
        ]);
        
    } catch (Exception $e) {
        error_log('Get status error: ' . $e->getMessage());
        Response::error('Failed to fetch trading status', 500);
    }
}

/**
 * Get account balance (replaces trading:balance event)
 */
function handleGetBalance(int $userId)
{
    try {
        // Use TradingBotService to get balance from Deriv API
        $tradingBot = \App\Services\TradingBotService::getInstance();
        $balance = $tradingBot->getAccountBalance($userId);
        
        // Check if user has API token
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT encrypted_api_token FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        $hasToken = !empty($user['encrypted_api_token']);
        
        Response::success([
            'balance' => (float)$balance,
            'currency' => 'USD',
            'hasToken' => $hasToken,
            'timestamp' => date('c'),
        ]);
        
    } catch (Exception $e) {
        @error_log('Get balance error: ' . $e->getMessage());
        
        // Return 0 balance on error instead of failing completely
        Response::success([
            'balance' => 0.00,
            'currency' => 'USD',
            'hasToken' => false,
            'error' => $e->getMessage(),
            'timestamp' => date('c'),
        ]);
    }
}

/**
 * Get recent trades (replaces trading:trade-started and trading:trade-result events)
 */
function handleGetTrades(int $userId)
{
    try {
        $helper = new DatabaseHelper();
        
        // Get recent trades (last 10)
        $trades = $helper->getUserTrades($userId, [
            'limit' => 10,
        ]);
        
        // Get pending trades (live trades)
        $pendingTrades = $helper->getUserTrades($userId, [
            'status' => 'pending',
            'limit' => 10,
        ]);
        
        // Format trades
        $formattedTrades = array_map(function($trade) {
            return [
                'id' => $trade['id'],
                'trade_id' => $trade['trade_id'],
                'asset' => $trade['asset'],
                'direction' => $trade['direction'],
                'stake' => (float)$trade['stake'],
                'profit' => (float)$trade['profit'],
                'status' => $trade['status'],
                'timestamp' => $trade['timestamp'],
            ];
        }, $trades);
        
        $formattedPending = array_map(function($trade) {
            return [
                'id' => $trade['id'],
                'trade_id' => $trade['trade_id'],
                'asset' => $trade['asset'],
                'direction' => $trade['direction'],
                'stake' => (float)$trade['stake'],
                'profit' => (float)$trade['profit'],
                'status' => $trade['status'],
                'timestamp' => $trade['timestamp'],
            ];
        }, $pendingTrades);
        
        Response::success([
            'trades' => $formattedTrades,
            'liveTrades' => $formattedPending,
            'timestamp' => date('c'),
        ]);
        
    } catch (Exception $e) {
        error_log('Get trades error: ' . $e->getMessage());
        Response::error('Failed to fetch trades', 500);
    }
}

/**
 * Get comprehensive updates (replaces trading:update event)
 * This combines status, balance, and recent trades
 */
function handleGetUpdates(int $userId)
{
    try {
        $helper = new DatabaseHelper();
        $db = Database::getInstance();
        
        // Get settings
        $settings = $helper->getUserSettings($userId);
        
        // Get active session
        $activeSession = $helper->getActiveTradingSession($userId);
        
        // Get recent trades
        $recentTrades = $helper->getUserTrades($userId, [
            'limit' => 5,
        ]);
        
        // Get pending trades
        $pendingTrades = $helper->getUserTrades($userId, [
            'status' => 'pending',
            'limit' => 5,
        ]);
        
        // Get balance (placeholder)
        $balance = 0.00;
        
        // Check for new trades since last poll
        $lastUpdate = $_GET['lastUpdate'] ?? null;
        $newTrades = [];
        
        if ($lastUpdate) {
            $lastUpdateTime = date('Y-m-d H:i:s', strtotime($lastUpdate));
            $newTradesQuery = $db->query(
                "SELECT * FROM trades 
                 WHERE user_id = :user_id 
                 AND timestamp > :last_update 
                 ORDER BY timestamp DESC 
                 LIMIT 10",
                [
                    'user_id' => $userId,
                    'last_update' => $lastUpdateTime,
                ]
            );
            
            $newTrades = array_map(function($trade) {
                return [
                    'id' => $trade['id'],
                    'trade_id' => $trade['trade_id'],
                    'asset' => $trade['asset'],
                    'direction' => $trade['direction'],
                    'stake' => (float)$trade['stake'],
                    'profit' => (float)$trade['profit'],
                    'status' => $trade['status'],
                    'timestamp' => $trade['timestamp'],
                    'isNew' => true,
                ];
            }, $newTradesQuery);
        }
        
        Response::success([
            'settings' => $settings ? [
                'stake' => (float)$settings['stake'],
                'target' => (float)$settings['target'],
                'stopLimit' => (float)$settings['stop_limit'],
                'isBotActive' => (bool)$settings['is_bot_active'],
                'dailyProfit' => (float)$settings['daily_profit'],
                'dailyLoss' => (float)$settings['daily_loss'],
            ] : null,
            'session' => $activeSession ? [
                'id' => $activeSession['id'],
                'state' => $activeSession['state'],
                'totalTrades' => (int)$activeSession['total_trades'],
                'successfulTrades' => (int)$activeSession['successful_trades'],
                'failedTrades' => (int)$activeSession['failed_trades'],
                'dailyProfit' => (float)$activeSession['daily_profit'],
                'dailyLoss' => (float)$activeSession['daily_loss'],
            ] : null,
            'balance' => $balance,
            'recentTrades' => array_map(function($trade) {
                return [
                    'id' => $trade['id'],
                    'asset' => $trade['asset'],
                    'direction' => $trade['direction'],
                    'stake' => (float)$trade['stake'],
                    'profit' => (float)$trade['profit'],
                    'status' => $trade['status'],
                    'timestamp' => $trade['timestamp'],
                ];
            }, $recentTrades),
            'liveTrades' => array_map(function($trade) {
                return [
                    'id' => $trade['id'],
                    'asset' => $trade['asset'],
                    'direction' => $trade['direction'],
                    'stake' => (float)$trade['stake'],
                    'profit' => (float)$trade['profit'],
                    'status' => $trade['status'],
                    'timestamp' => $trade['timestamp'],
                ];
            }, $pendingTrades),
            'newTrades' => $newTrades,
            'timestamp' => date('c'),
        ]);
        
    } catch (Exception $e) {
        error_log('Get updates error: ' . $e->getMessage());
        Response::error('Failed to fetch updates', 500);
    }
}

/**
 * Get notifications (replaces trading:target-reached, trading:stop-limit-reached, trading:error)
 */
function handleGetNotifications(int $userId)
{
    try {
        $db = Database::getInstance();
        
        // Get recent trades that might have notifications
        $recentTrades = $db->query(
            "SELECT * FROM trades 
             WHERE user_id = :user_id 
             AND timestamp > DATE_SUB(NOW(), INTERVAL 1 MINUTE)
             ORDER BY timestamp DESC 
             LIMIT 5",
            ['user_id' => $userId]
        );
        
        $notifications = [];
        
        // Check for target/stop limit reached
        $settings = (new DatabaseHelper())->getUserSettings($userId);
        if ($settings) {
            $netProfit = (float)$settings['daily_profit'] - (float)$settings['daily_loss'];
            
            if ($settings['is_bot_active'] && (float)$settings['daily_profit'] >= (float)$settings['target']) {
                $notifications[] = [
                    'type' => 'success',
                    'message' => "Daily profit target of $" . number_format($settings['target'], 2) . " reached!",
                    'timestamp' => date('c'),
                ];
            }
            
            if ($settings['is_bot_active'] && (float)$settings['daily_loss'] >= (float)$settings['stop_limit']) {
                $notifications[] = [
                    'type' => 'warning',
                    'message' => "Daily loss limit of $" . number_format($settings['stop_limit'], 2) . " reached!",
                    'timestamp' => date('c'),
                ];
            }
        }
        
        // Check for new trade results
        foreach ($recentTrades as $trade) {
            if ($trade['status'] === 'won' || $trade['status'] === 'lost') {
                $notifications[] = [
                    'type' => $trade['status'] === 'won' ? 'success' : 'error',
                    'message' => "Trade {$trade['status']}: " . 
                                ($trade['profit'] > 0 ? '+' : '') . 
                                "$" . number_format($trade['profit'], 2),
                    'timestamp' => $trade['timestamp'],
                ];
            }
        }
        
        Response::success([
            'notifications' => $notifications,
            'timestamp' => date('c'),
        ]);
        
    } catch (Exception $e) {
        error_log('Get notifications error: ' . $e->getMessage());
        Response::error('Failed to fetch notifications', 500);
    }
}

