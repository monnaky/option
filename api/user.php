<?php
/**
 * User API Endpoints
 * 
 * POST /api/user/save-token
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
use App\Services\EncryptionService;
use App\Services\DerivAPI;
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
    } catch (Exception $e) {
        Response::error('Authentication required', 401);
    }

    // Get request method and action
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    // Route requests
    switch ($method) {
        case 'POST':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid actions: save-token, test-connection, disconnect-token', 400);
            } elseif ($action === 'save-token') {
                handleSaveToken($user['id']);
            } elseif ($action === 'test-connection') {
                handleTestConnection($user['id']);
            } elseif ($action === 'disconnect-token') {
                handleDisconnectToken($user['id']);
            } else {
                Response::error('Invalid action. Valid actions: save-token, test-connection, disconnect-token', 400);
            }
            break;
        
        case 'GET':
            if (empty($action)) {
                Response::error('Action parameter is required. Valid actions: test-connection', 400);
            } elseif ($action === 'test-connection') {
                handleTestConnection($user['id']);
            } else {
                Response::error('Invalid action. Valid actions: test-connection', 400);
            }
            break;
        
        default:
            Response::error("Method {$method} not allowed. Use GET or POST with action parameter", 405);
    }
    
} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/user.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error', 500);
}

/**
 * Handle save API token
 */
function handleSaveToken(int $userId)
{
    try {
        // Get request data
        $data = json_decode(file_get_contents('php://input'), true);
        
        if (!$data) {
            Response::error('Invalid request data', 400);
        }
        
        $apiToken = $data['apiToken'] ?? '';
        
        // Validate input
        if (empty($apiToken)) {
            Response::error('API token is required', 400);
        }
        
        // Validate token with Deriv API
        $derivAPI = null;
        try {
            @error_log("Starting Deriv API validation for user {$userId}");
            @error_log("API token length: " . strlen($apiToken));
            
            $derivAPI = new DerivAPI($apiToken);
            
            // Test connection by authorizing (this validates the token)
            try {
                @error_log("Calling authorize() to validate token");
                $authData = $derivAPI->authorize();
                
                @error_log("Authorization successful. LoginID: " . ($authData['loginid'] ?? 'N/A'));
                
                // Verify token is valid by checking if we got account info
                if (empty($authData['loginid'])) {
                    $derivAPI->close();
                    @error_log("Authorization succeeded but loginid is empty");
                    Response::error('Invalid API token response. Please check your token and try again.', 400);
                }
                
                // Get account info for response
                $accountInfo = [
                    'loginid' => $authData['loginid'] ?? '',
                    'currency' => $authData['currency'] ?? 'USD',
                    'country' => $authData['country'] ?? '',
                ];
                
            } catch (Exception $apiError) {
                if ($derivAPI) {
                    $derivAPI->close();
                }
                @error_log('Deriv API authorization error: ' . $apiError->getMessage());
                @error_log('Error type: ' . get_class($apiError));
                throw $apiError;
            }
            
            // Close connection after validation
            if ($derivAPI) {
                $derivAPI->close();
            }
            
            @error_log("Deriv API validation completed successfully");
            
        } catch (Exception $e) {
            if ($derivAPI) {
                try {
                    $derivAPI->close();
                } catch (Exception $closeError) {
                    // Ignore close errors
                }
            }
            @error_log('Deriv API validation error: ' . $e->getMessage());
            @error_log('Error trace: ' . $e->getTraceAsString());
            Response::error('Failed to validate API token with Deriv: ' . $e->getMessage(), 400);
        }
        
        // Encrypt API token
        try {
            $encryptedToken = EncryptionService::encrypt($apiToken);
        } catch (Exception $e) {
            @error_log('Encryption error: ' . $e->getMessage());
            Response::error('Failed to encrypt API token: ' . $e->getMessage(), 500);
        }
        
        // Update user
        try {
            $db = Database::getInstance();
            $db->update('users', [
                'encrypted_api_token' => $encryptedToken,
                'api_token_created_at' => date('Y-m-d H:i:s'),
                'api_token_last_used' => date('Y-m-d H:i:s'),
            ], ['id' => $userId]);
        } catch (Exception $e) {
            @error_log('Database update error: ' . $e->getMessage());
            Response::error('Failed to save API token to database', 500);
        }
        
        // Return response
        Response::success([
            'encrypted' => true,
            'validated' => true,
        ], 'API token validated, encrypted and saved successfully');
        
    } catch (Exception $e) {
        @error_log('Save token error: ' . $e->getMessage());
        Response::error('Failed to save API token: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle test Deriv API connection
 */
function handleTestConnection(int $userId)
{
    try {
        // Get user's stored API token
        $db = Database::getInstance();
        $user = $db->queryOne(
            "SELECT encrypted_api_token FROM users WHERE id = :id",
            ['id' => $userId]
        );
        
        if (!$user || empty($user['encrypted_api_token'])) {
            Response::error('No API token found. Please connect your Deriv API token first.', 400);
        }
        
        // Decrypt token
        try {
            $apiToken = EncryptionService::decrypt($user['encrypted_api_token']);
        } catch (Exception $e) {
            @error_log('Decryption error: ' . $e->getMessage());
            Response::error('Failed to decrypt API token: ' . $e->getMessage(), 500);
        }
        
        // Test connection with Deriv API
        $derivAPI = null;
        try {
            $derivAPI = new DerivAPI($apiToken);
            
            // Get account info
            try {
                $accountInfo = $derivAPI->getAccountInfo();
            } catch (Exception $e) {
                $derivAPI->close();
                throw new Exception('Failed to get account info: ' . $e->getMessage());
            }
            
            // Get balance
            try {
                $balance = $derivAPI->getBalance();
            } catch (Exception $e) {
                $derivAPI->close();
                throw new Exception('Failed to get balance: ' . $e->getMessage());
            }
            
            // Close connection
            $derivAPI->close();
            
            Response::success([
                'connected' => true,
                'balance' => (float)$balance,
                'account_info' => $accountInfo ? [
                    'loginid' => $accountInfo['loginid'] ?? null,
                    'currency' => $accountInfo['currency'] ?? null,
                    'country' => $accountInfo['country'] ?? null,
                ] : null,
            ], 'Successfully connected to Deriv API');
            
        } catch (Exception $e) {
            // Ensure connection is closed on error
            if ($derivAPI) {
                try {
                    $derivAPI->close();
                } catch (Exception $closeError) {
                    // Ignore close errors
                }
            }
            @error_log('Deriv API connection test error: ' . $e->getMessage());
            Response::error('Failed to connect to Deriv API: ' . $e->getMessage(), 400);
        }
        
    } catch (Exception $e) {
        @error_log('Test connection error: ' . $e->getMessage());
        Response::error('Failed to test connection: ' . $e->getMessage(), 500);
    }
}

/**
 * Handle disconnect API token
 */
function handleDisconnectToken(int $userId)
{
    try {
        $db = Database::getInstance();
        
        // Clear the encrypted API token
        $db->update('users', [
            'encrypted_api_token' => null,
            'api_token_created_at' => null,
            'api_token_last_used' => null,
        ], ['id' => $userId]);
        
        Response::success([], 'API token disconnected successfully');
        
    } catch (Exception $e) {
        @error_log('Disconnect token error: ' . $e->getMessage());
        Response::error('Failed to disconnect API token: ' . $e->getMessage(), 500);
    }
}

