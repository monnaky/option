<?php
/**
 * Trading API Endpoints
 * 
 * GET /api/trading/stats
 * POST /api/trading/place-trade
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
use App\Services\TradingBotService;
use App\Middleware\AuthMiddleware;
use App\Utils\Validator;
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
    } catch (Exception $e) {
        Response::error('Authentication required', 401);
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Route requests
    switch ($method) {
        case 'GET':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid GET actions: stats, balance, history', 400);
            } elseif ($action === 'stats') {
                handleGetStats($user['id']);
            } elseif ($action === 'balance') {
                handleGetBalance($user['id']);
            } elseif ($action === 'history') {
                handleGetHistory($user['id']);
            } else {
                Response::error('Invalid action. Valid GET actions: stats, balance, history', 400);
            }
            break;
        
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid POST actions: start, stop, place-trade, update-settings', 400);
            } elseif ($action === 'start') {
                handleStartTrading($user['id']);
            } elseif ($action === 'stop') {
                handleStopTrading($user['id']);
            } elseif ($action === 'place-trade') {
                handlePlaceTrade($user['id']);
            } elseif ($action === 'update-settings') {
                handleUpdateSettings($user['id']);
            } else {
                Response::error('Invalid action. Valid POST actions: start, stop, place-trade, update-settings', 400);
            }
            break;
        
        default:
            Response::error("Method {$method} not allowed. Use GET or POST with action parameter", 405);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/trading.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Handle get trading statistics
 */
function handleGetStats(int $userId)
{
    try {
        $helper = new DatabaseHelper();
        
        // Get user settings
        $settings = $helper->getUserSettings($userId);
        
        // Get trade statistics
        $stats = $helper->getUserTradeStats($userId);
        
        // Get active trading session
        $activeSession = $helper->getActiveTradingSession($userId);
        
        // Calculate win rate
        $winRate = 0;
        if ($stats['total_trades'] > 0) {
            $winRate = ($stats['won_trades'] / $stats['total_trades']) * 100;
        }
        
        // Return response
        Response::success([
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
                'lost_trades' => (int)$stats['lost_trades'],
                'win_rate' => number_format($winRate, 2),
                'total_profit' => number_format((float)$stats['total_profit'], 2),
                'total_loss' => number_format((float)$stats['total_loss'], 2),
                'net_profit' => number_format((float)$stats['total_profit'] - (float)$stats['total_loss'], 2),
            ],
            'active_session' => $activeSession ? [
                'id' => $activeSession['id'],
                'session_id' => $activeSession['session_id'],
                'state' => $activeSession['state'],
                'total_trades' => (int)$activeSession['total_trades'],
                'successful_trades' => (int)$activeSession['successful_trades'],
                'failed_trades' => (int)$activeSession['failed_trades'],
                'daily_profit' => (float)$activeSession['daily_profit'],
                'daily_loss' => (float)$activeSession['daily_loss'],
            ] : null,
        ]);
        
    } catch (Exception $e) {
        error_log('Get stats error: ' . $e->getMessage());
        Response::error('Failed to fetch trading statistics', 500);
    }
}

/**
 * Handle place trade
 */
function handlePlaceTrade(int $userId)
{
    try {
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $asset = $data['asset'] ?? '';
        $direction = $data['direction'] ?? '';
        $stake = $data['stake'] ?? 0;
        
        // Validate input
        $errors = Validator::required($data, ['asset', 'direction']);
        
        if (!empty($errors)) {
            Response::validationError($errors);
        }
        
        if (!in_array(strtoupper($direction), ['RISE', 'FALL'])) {
            Response::error('Direction must be RISE or FALL', 400);
        }
        
        if (!Validator::numeric($stake, 1)) {
            Response::error('Stake must be a number greater than 0', 400);
        }
        
        // Get user settings
        $helper = new DatabaseHelper();
        $settings = $helper->getUserSettings($userId);
        
        if (!$settings) {
            Response::error('User settings not found', 404);
        }
        
        // Check if user has API token
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT encrypted_api_token FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$user || empty($user['encrypted_api_token'])) {
            Response::error('Please connect your Deriv API token first', 400);
        }
        
        // Execute trade via TradingBotService
        // Note: Manual trades are not supported - use bot instead
        Response::error('Manual trades not supported. Please use the trading bot.', 400);
        
        // Legacy code (not used):
        // Generate unique trade ID
        $tradeId = 'TRADE_' . uniqid();
        
        // Create trade record
        $tradeRecordId = $helper->createTrade([
            'user_id' => $userId,
            'trade_id' => $tradeId,
            'asset' => $asset,
            'direction' => strtoupper($direction),
            'stake' => (float)$stake,
            'status' => 'pending',
        ]);
        
        // Return response
        Response::success([
            'trade_id' => $tradeId,
            'status' => 'pending',
            'message' => 'Trade placed successfully',
        ], 'Trade placed successfully', 201);
        
    } catch (Exception $e) {
        error_log('Place trade error: ' . $e->getMessage());
        Response::error('Failed to place trade', 500);
    }
}

/**
 * Handle start trading bot
 */
function handleStartTrading(int $userId)
{
    try {
        $tradingBot = TradingBotService::getInstance();
        $result = $tradingBot->startTrading($userId);
        
        Response::success($result, 'Trading bot started successfully');
        
    } catch (Exception $e) {
        error_log('Start trading error: ' . $e->getMessage());
        Response::error($e->getMessage(), 400);
    }
}

/**
 * Handle stop trading bot
 */
function handleStopTrading(int $userId)
{
    try {
        $tradingBot = TradingBotService::getInstance();
        $result = $tradingBot->stopTrading($userId);
        
        Response::success($result, 'Trading bot stopped successfully');
        
    } catch (Exception $e) {
        error_log('Stop trading error: ' . $e->getMessage());
        Response::error($e->getMessage(), 400);
    }
}

/**
 * Handle get account balance
 */
function handleGetBalance(int $userId)
{
    $debugLog = [];
    $debugLog[] = "=== handleGetBalance START for user {$userId} ===";
    $debugLog[] = "Timestamp: " . date('Y-m-d H:i:s');
    
    try {
        // Check if user has API token first
        $db = Database::getInstance();
        $debugLog[] = "Step 1: Querying database for user {$userId}";
        
        $user = $db->queryOne(
            "SELECT id, email, encrypted_api_token, api_token_created_at, api_token_last_used FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        $debugLog[] = "Step 1 Result: User " . ($user ? "FOUND" : "NOT FOUND");
        if ($user) {
            $debugLog[] = "  - Email: " . ($user['email'] ?? 'N/A');
            $debugLog[] = "  - Has encrypted_token: " . (!empty($user['encrypted_api_token']) ? 'YES' : 'NO');
            $debugLog[] = "  - Token length: " . (isset($user['encrypted_api_token']) ? strlen($user['encrypted_api_token']) : 0);
            $debugLog[] = "  - Token created: " . ($user['api_token_created_at'] ?? 'N/A');
            $debugLog[] = "  - Token last used: " . ($user['api_token_last_used'] ?? 'N/A');
        }
        
        if (!$user || empty($user['encrypted_api_token'])) {
            $debugLog[] = "Step 2: No API token found - returning 0 balance";
            @error_log("[handleGetBalance] " . implode("\n", $debugLog));
            
            Response::success([
                'balance' => 0.00,
                'currency' => 'USD',
                'hasToken' => false,
                'message' => 'Please connect your Deriv API token to view balance',
                'debug' => [
                    'user_found' => (bool)$user,
                    'has_token' => false,
                ],
            ]);
            return;
        }
        
        // Get balance from Deriv API
        $debugLog[] = "Step 3: Calling TradingBotService->getAccountBalance({$userId})";
        $tradingBot = TradingBotService::getInstance();
        $debugLog[] = "Step 3: TradingBotService instance obtained";
        
        $balance = $tradingBot->getAccountBalance($userId);
        $debugLog[] = "Step 4: Balance retrieved from service: " . $balance;
        $debugLog[] = "Step 4: Balance type: " . gettype($balance);
        $debugLog[] = "Step 4: Balance value: " . var_export($balance, true);
        
        $response = [
            'balance' => (float)$balance,
            'currency' => 'USD',
            'hasToken' => true,
            'debug' => [
                'user_found' => true,
                'has_token' => true,
                'balance_retrieved' => true,
                'balance_value' => $balance,
            ],
        ];
        
        $debugLog[] = "Step 5: Preparing response: " . json_encode($response);
        $debugLog[] = "=== handleGetBalance SUCCESS ===";
        @error_log("[handleGetBalance] " . implode("\n", $debugLog));
        
        Response::success($response);
        
    } catch (Exception $e) {
        $debugLog[] = "EXCEPTION CAUGHT: " . $e->getMessage();
        $debugLog[] = "Exception type: " . get_class($e);
        $debugLog[] = "Stack trace: " . $e->getTraceAsString();
        $debugLog[] = "=== handleGetBalance FAILED ===";
        @error_log("[handleGetBalance] " . implode("\n", $debugLog));
        
        // Return structured error instead of masking with 0.00
        // This allows frontend to show proper error messages
        $errorType = 'unknown';
        $errorMessage = $e->getMessage();
        
        // Determine error type for better handling
        if (strpos($errorMessage, 'token') !== false || strpos($errorMessage, 'API token') !== false) {
            $errorType = 'no_token';
        } elseif (strpos($errorMessage, 'connection') !== false || strpos($errorMessage, 'WebSocket') !== false) {
            $errorType = 'connection_error';
        } elseif (strpos($errorMessage, 'timeout') !== false) {
            $errorType = 'timeout';
        } elseif (strpos($errorMessage, 'decrypt') !== false) {
            $errorType = 'decryption_error';
        }
        
        Response::success([
            'balance' => 0.00,
            'currency' => 'USD',
            'hasToken' => false,
            'error' => true,
            'errorType' => $errorType,
            'errorMessage' => $errorMessage,
            'debug' => [
                'exception' => true,
                'error_message' => $errorMessage,
                'error_type' => get_class($e),
            ],
        ]);
    }
}

/**
 * Handle get trade history
 */
function handleGetHistory(int $userId)
{
    try {
        $helper = new DatabaseHelper();
        
        // Get recent trades
        $trades = $helper->getUserTrades($userId, [
            'limit' => 50,
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
        
        Response::success([
            'trades' => $formattedTrades,
        ]);
        
    } catch (Exception $e) {
        error_log('Get history error: ' . $e->getMessage());
        Response::error('Failed to fetch trade history', 500);
    }
}

/**
 * Handle update trading settings
 */
function handleUpdateSettings(int $userId)
{
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $stake = $data['stake'] ?? null;
        $target = $data['target'] ?? null;
        $stopLimit = $data['stopLimit'] ?? null;
        
        // Validate
        if ($stake !== null && !Validator::numeric($stake, 0.01)) {
            Response::error('Stake must be a number greater than 0.01', 400);
        }
        
        if ($target !== null && !Validator::numeric($target, 0)) {
            Response::error('Target must be a number greater than or equal to 0', 400);
        }
        
        if ($stopLimit !== null && !Validator::numeric($stopLimit, 0)) {
            Response::error('Stop limit must be a number greater than or equal to 0', 400);
        }
        
        // Build update data
        $updateData = [];
        if ($stake !== null) $updateData['stake'] = (float)$stake;
        if ($target !== null) $updateData['target'] = (float)$target;
        if ($stopLimit !== null) $updateData['stop_limit'] = (float)$stopLimit;
        
        if (empty($updateData)) {
            Response::error('No settings to update', 400);
        }
        
        // Update settings
        $helper = new DatabaseHelper();
        $success = $helper->updateUserSettings($userId, $updateData);
        
        if (!$success) {
            Response::error('Failed to update settings', 500);
        }
        
        Response::success([
            'updated' => array_keys($updateData),
        ], 'Settings updated successfully');
        
    } catch (Exception $e) {
        error_log('Update settings error: ' . $e->getMessage());
        Response::error('Failed to update settings', 500);
    }
}

