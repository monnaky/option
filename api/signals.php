<?php
/**
 * Signals API Endpoints
 * 
 * POST /api/signals/execute
 */

// CRITICAL: Suppress ALL errors FIRST
@error_reporting(0);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Load autoloader BEFORE use statements
require_once __DIR__ . '/../app/autoload.php';

// use statements MUST be at top level, immediately after require
use App\Services\SignalService;
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

    // Get request method and action EARLY so we can decide about sessions
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Only start a PHP session for endpoints that actually need it.
    // The public signal receive endpoint (action=receive) is stateless and
    // should not send session cookies to keep response headers small.
    if ($action !== 'receive' && session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Route requests
    switch ($method) {
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid POST actions: receive, execute', 400);
            } elseif ($action === 'receive') {
                // Public endpoint for signal providers (no auth required)
                handleReceiveSignal();
            } elseif ($action === 'execute') {
                // Authenticated endpoint for manual signal execution
                try {
                    $user = AuthMiddleware::requireAuth();
                    handleExecuteSignal($user['id']);
                } catch (Exception $e) {
                    Response::error('Authentication required', 401);
                }
            } else {
                Response::error('Invalid action. Valid POST actions: receive, execute', 400);
            }
            break;
        
        case 'GET':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid GET actions: get, history, stats, health', 400);
            } elseif ($action === 'health') {
                // Simple health check – no auth required
                handleSignalHealth();
            } elseif ($action === 'get') {
                // Public endpoint to get signal details
                handleGetSignal();
            } else {
                // Authenticated endpoints
                try {
                    $user = AuthMiddleware::requireAuth();
                    if ($action === 'history') {
                        handleGetHistory($user['id']);
                    } elseif ($action === 'stats') {
                        handleGetStats($user['id']);
                    } else {
                        Response::error('Invalid action. Valid GET actions: get, history, stats, health', 400);
                    }
                } catch (Exception $e) {
                    Response::error('Authentication required', 401);
                }
            }
            break;

        
        default:
            Response::error("Method {$method} not allowed. Use GET or POST with action parameter", 405);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/signals.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Handle execute signal (for authenticated users)
 */
function handleExecuteSignal(int $userId)
{
    try {
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $signalType = $data['signalType'] ?? $data['type'] ?? '';
        $asset = $data['asset'] ?? null;
        
        // Validate input
        if (empty($signalType)) {
            Response::error('Signal type is required', 400);
        }
        
        if (!in_array(strtoupper($signalType), ['RISE', 'FALL'])) {
            Response::error('Signal type must be RISE or FALL', 400);
        }
        
        // Use SignalService to process signal
        $signalService = SignalService::getInstance();
        $result = $signalService->receiveSignal([
            'type' => strtoupper($signalType),
            'asset' => $asset,
            'source' => 'manual',
        ]);
        
        Response::success($result, 'Signal executed successfully');
        
    } catch (Exception $e) {
        error_log('Execute signal error: ' . $e->getMessage());
        Response::error($e->getMessage(), 400);
    }
}

/**
 * Handle receive signal (public endpoint for signal providers)
 * Requires API key authentication via X-API-Key header
 */
function handleReceiveSignal()
{
    try {
        // ========================================================================
        // API KEY AUTHENTICATION
        // ========================================================================
        
        // Get expected API key from environment
        $expectedApiKey = getenv('SIGNAL_API_KEY');
        
        // Check if API key is configured in environment
        if (empty($expectedApiKey)) {
            error_log('SIGNAL_API_KEY environment variable is not set');
            Response::error('API key authentication is not properly configured', 500);
        }
        
        // Get API key from request header
        $providedApiKey = $_SERVER['HTTP_X_API_KEY'] ?? 
                         $_SERVER['X-API-Key'] ?? 
                         $_GET['api_key'] ?? 
                         $_POST['api_key'] ?? 
                         null;
        
        // Check if API key was provided
        if (empty($providedApiKey)) {
            error_log('Signal reception attempt without API key');
            Response::error('API key is required. Please provide X-API-Key header.', 401);
        }
        
        // Validate API key using secure comparison (prevents timing attacks)
        if (!hash_equals($expectedApiKey, $providedApiKey)) {
            error_log('Signal reception attempt with invalid API key');
            Response::error('Invalid API key', 401);
        }
        
        // API key validated successfully
        error_log('Signal reception request authenticated successfully');
        
        // ========================================================================
        // SIGNAL DATA PROCESSING
        // ========================================================================
        
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            // Try form data
            $data = $_POST;
        }
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        // Extract signal data (support multiple formats)
        $signalType = $data['type'] ?? $data['signalType'] ?? $data['signal'] ?? '';
        $asset = $data['asset'] ?? $data['symbol'] ?? null;
        $rawText = $data['rawText'] ?? $data['raw_text'] ?? $data['text'] ?? null;
        
        // If raw text provided, try to parse it
        if ($rawText && empty($signalType)) {
            // Simple parsing: "RISE R_10" or "FALL"
            $parts = explode(' ', trim($rawText));
            $signalType = strtoupper($parts[0] ?? '');
            $asset = $parts[1] ?? null;
        }
        
        // Validate
        if (empty($signalType)) {
            Response::error('Signal type is required', 400);
        }
        
        if (!in_array(strtoupper($signalType), ['RISE', 'FALL'])) {
            Response::error('Signal type must be RISE or FALL', 400);
        }
        
        // Use SignalService to process signal
        $signalService = SignalService::getInstance();
        $result = $signalService->receiveSignal([
            'type' => strtoupper($signalType),
            'asset' => $asset,
            'rawText' => $rawText ?? ($asset ? "{$signalType} {$asset}" : $signalType),
            'source' => 'api',
        ]);
        
        Response::success($result, 'Signal received and processed successfully');
        
    } catch (Exception $e) {
        error_log('Receive signal error: ' . $e->getMessage());
        Response::error($e->getMessage(), 400);
    }
}

/**
 * Handle get signal history
 */
function handleGetHistory(int $userId)
{
    try {
        $limit = (int)($_GET['limit'] ?? 50);
        $offset = (int)($_GET['offset'] ?? 0);
        
        $signalService = SignalService::getInstance();
        $history = $signalService->getSignalHistory($limit, $offset);
        
        Response::success([
            'signals' => $history,
            'total' => count($history),
        ]);
        
    } catch (Exception $e) {
        error_log('Get signal history error: ' . $e->getMessage());
        Response::error('Failed to fetch signal history', 500);
    }
}

/**
 * Handle get signal statistics
 */
function handleGetStats(int $userId)
{
    try {
        $dateFrom = $_GET['date_from'] ?? null;
        $dateTo = $_GET['date_to'] ?? null;
        
        $signalService = SignalService::getInstance();
        $stats = $signalService->getSignalStatistics($dateFrom, $dateTo);
        
        Response::success($stats);
        
    } catch (Exception $e) {
        error_log('Get signal stats error: ' . $e->getMessage());
        Response::error('Failed to fetch signal statistics', 500);
    }
}

/**
 * Simple health check for Signal API (no auth required)
 */
function handleSignalHealth()
{
    try {
        // Just return a static healthy response
        Response::success(['status' => 'healthy', 'message' => 'Signal API is reachable']);
    } catch (Exception $e) {
        error_log('Signal health check error: ' . $e->getMessage());
        Response::error('Health check failed', 500);
    }
}


/**
 * Handle get single signal
 */
function handleGetSignal()
{
    try {
        $signalId = (int)($_GET['id'] ?? 0);
        
        if ($signalId <= 0) {
            Response::error('Signal ID is required', 400);
        }
        
        $signalService = SignalService::getInstance();
        $signal = $signalService->getSignalById($signalId);
        
        if (!$signal) {
            Response::error('Signal not found', 404);
        }
        
        Response::success($signal);
        
    } catch (Exception $e) {
        error_log('Get signal error: ' . $e->getMessage());
        Response::error('Failed to fetch signal', 500);
    }
}

