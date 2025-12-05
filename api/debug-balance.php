<?php
/**
 * Comprehensive Balance Debug Endpoint
 * 
 * GET /api/debug-balance.php?userId=1
 * 
 * This endpoint provides comprehensive debugging information for the balance flow:
 * 1. Database token check
 * 2. Token decryption test
 * 3. DerivAPI connection test
 * 4. Balance API test
 * 5. Raw responses at each step
 */

// CRITICAL: Suppress ALL errors FIRST
@error_reporting(E_ALL);
@ini_set('display_errors', '0');
@ini_set('display_startup_errors', '0');
@ini_set('log_errors', '1');

// Load autoloader BEFORE use statements
require_once __DIR__ . '/../app/autoload.php';

// use statements MUST be at top level, immediately after require
use App\Config\Database;
use App\Services\EncryptionService;
use App\Services\DerivAPI;
use App\Services\TradingBotService;
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

    // Allow override userId for admin debugging (optional)
    $debugUserId = $_GET['userId'] ?? $userId;
    if ($debugUserId != $userId && !isset($user['is_admin'])) {
        $debugUserId = $userId; // Only allow if admin
    }

    $debugInfo = [
        'timestamp' => date('Y-m-d H:i:s'),
        'userId' => (int)$debugUserId,
        'steps' => [],
        'errors' => [],
        'warnings' => [],
    ];

    // STEP 1: Check Database for Token
    $debugInfo['steps']['1_database_check'] = [];
    try {
        $db = Database::getInstance();
        $userRecord = $db->queryOne(
            "SELECT id, email, encrypted_api_token, api_token_created_at, api_token_last_used 
             FROM users WHERE id = :id",
            ['id' => $debugUserId]
        );
        
        if (!$userRecord) {
            $debugInfo['errors'][] = "User {$debugUserId} not found in database";
            $debugInfo['steps']['1_database_check']['status'] = 'FAILED';
            $debugInfo['steps']['1_database_check']['error'] = 'User not found';
        } else {
            $debugInfo['steps']['1_database_check']['status'] = 'SUCCESS';
            $debugInfo['steps']['1_database_check']['user_found'] = true;
            $debugInfo['steps']['1_database_check']['email'] = $userRecord['email'];
            $debugInfo['steps']['1_database_check']['has_encrypted_token'] = !empty($userRecord['encrypted_api_token']);
            $debugInfo['steps']['1_database_check']['token_length'] = $userRecord['encrypted_api_token'] ? strlen($userRecord['encrypted_api_token']) : 0;
            $debugInfo['steps']['1_database_check']['token_created_at'] = $userRecord['api_token_created_at'] ?? null;
            $debugInfo['steps']['1_database_check']['token_last_used'] = $userRecord['api_token_last_used'] ?? null;
            $debugInfo['steps']['1_database_check']['token_preview'] = $userRecord['encrypted_api_token'] ? substr($userRecord['encrypted_api_token'], 0, 50) . '...' : null;
            
            if (empty($userRecord['encrypted_api_token'])) {
                $debugInfo['warnings'][] = "User has no encrypted_api_token in database";
                $debugInfo['steps']['1_database_check']['warning'] = 'No token stored';
            }
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "Database check failed: " . $e->getMessage();
        $debugInfo['steps']['1_database_check']['status'] = 'ERROR';
        $debugInfo['steps']['1_database_check']['error'] = $e->getMessage();
        $debugInfo['steps']['1_database_check']['trace'] = $e->getTraceAsString();
    }

    // STEP 2: Test Token Decryption
    $debugInfo['steps']['2_token_decryption'] = [];
    $decryptedToken = null;
    try {
        if (empty($userRecord['encrypted_api_token'])) {
            $debugInfo['steps']['2_token_decryption']['status'] = 'SKIPPED';
            $debugInfo['steps']['2_token_decryption']['reason'] = 'No encrypted token to decrypt';
        } else {
            $encryptedToken = $userRecord['encrypted_api_token'];
            
            // Check encryption format
            $isValidFormat = EncryptionService::isValidFormat($encryptedToken);
            $debugInfo['steps']['2_token_decryption']['encryption_format_valid'] = $isValidFormat;
            
            if (!$isValidFormat) {
                $debugInfo['errors'][] = "Encrypted token format is invalid";
                $debugInfo['steps']['2_token_decryption']['status'] = 'FAILED';
                $debugInfo['steps']['2_token_decryption']['error'] = 'Invalid encryption format';
            } else {
                // Try to decrypt
                try {
                    $decryptedToken = EncryptionService::decrypt($encryptedToken);
                    $debugInfo['steps']['2_token_decryption']['status'] = 'SUCCESS';
                    $debugInfo['steps']['2_token_decryption']['decrypted'] = true;
                    $debugInfo['steps']['2_token_decryption']['decrypted_length'] = strlen($decryptedToken);
                    $debugInfo['steps']['2_token_decryption']['token_preview'] = substr($decryptedToken, 0, 20) . '...' . substr($decryptedToken, -10);
                } catch (Exception $decryptError) {
                    $debugInfo['errors'][] = "Token decryption failed: " . $decryptError->getMessage();
                    $debugInfo['steps']['2_token_decryption']['status'] = 'FAILED';
                    $debugInfo['steps']['2_token_decryption']['error'] = $decryptError->getMessage();
                    $debugInfo['steps']['2_token_decryption']['trace'] = $decryptError->getTraceAsString();
                }
            }
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "Decryption test failed: " . $e->getMessage();
        $debugInfo['steps']['2_token_decryption']['status'] = 'ERROR';
        $debugInfo['steps']['2_token_decryption']['error'] = $e->getMessage();
    }

    // STEP 3: Test DerivAPI Connection (using public methods only)
    $debugInfo['steps']['3_derivapi_connection'] = [];
    $derivApi = null;
    try {
        if (!$decryptedToken) {
            $debugInfo['steps']['3_derivapi_connection']['status'] = 'SKIPPED';
            $debugInfo['steps']['3_derivapi_connection']['reason'] = 'No decrypted token available';
        } else {
            $debugInfo['steps']['3_derivapi_connection']['creating_instance'] = true;
            $derivApi = new DerivAPI($decryptedToken);
            $debugInfo['steps']['3_derivapi_connection']['instance_created'] = true;
            
            // Test connection using public method (isConnected will check status)
            // Note: Connection is established automatically when authorize() or getBalance() is called
            try {
                $debugInfo['steps']['3_derivapi_connection']['checking_connection_status'] = true;
                $isConnectedBefore = $derivApi->isConnected();
                $debugInfo['steps']['3_derivapi_connection']['initial_connection_status'] = $isConnectedBefore ? 'CONNECTED' : 'NOT CONNECTED';
                $debugInfo['steps']['3_derivapi_connection']['note'] = 'Connection will be established when authorize() or getBalance() is called';
                $debugInfo['steps']['3_derivapi_connection']['status'] = 'SUCCESS';
            } catch (Exception $connError) {
                $debugInfo['errors'][] = "DerivAPI connection check failed: " . $connError->getMessage();
                $debugInfo['steps']['3_derivapi_connection']['status'] = 'FAILED';
                $debugInfo['steps']['3_derivapi_connection']['error'] = $connError->getMessage();
                $debugInfo['steps']['3_derivapi_connection']['trace'] = $connError->getTraceAsString();
            }
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "DerivAPI creation failed: " . $e->getMessage();
        $debugInfo['steps']['3_derivapi_connection']['status'] = 'ERROR';
        $debugInfo['steps']['3_derivapi_connection']['error'] = $e->getMessage();
    }

    // STEP 4: Test Authorization (this will establish connection automatically)
    $debugInfo['steps']['4_authorization'] = [];
    $authData = null;
    try {
        if (!$derivApi) {
            $debugInfo['steps']['4_authorization']['status'] = 'SKIPPED';
            $debugInfo['steps']['4_authorization']['reason'] = 'DerivAPI instance not created';
        } else {
            try {
                $debugInfo['steps']['4_authorization']['calling_authorize'] = true;
                $debugInfo['steps']['4_authorization']['note'] = 'authorize() will automatically establish connection if needed';
                
                // Call authorize() - this is a public method that will handle connection internally
                $authData = $derivApi->authorize();
                
                // Check connection status after authorize
                $isConnectedAfter = $derivApi->isConnected();
                $debugInfo['steps']['4_authorization']['connection_established'] = $isConnectedAfter;
                
                $debugInfo['steps']['4_authorization']['status'] = 'SUCCESS';
                $debugInfo['steps']['4_authorization']['authorized'] = true;
                $debugInfo['steps']['4_authorization']['loginid'] = $authData['loginid'] ?? 'N/A';
                $debugInfo['steps']['4_authorization']['currency'] = $authData['currency'] ?? 'N/A';
                $debugInfo['steps']['4_authorization']['balance_from_auth'] = $authData['balance'] ?? 0;
                $debugInfo['steps']['4_authorization']['raw_response'] = $authData;
            } catch (Exception $authError) {
                $debugInfo['errors'][] = "Authorization failed: " . $authError->getMessage();
                $debugInfo['steps']['4_authorization']['status'] = 'FAILED';
                $debugInfo['steps']['4_authorization']['error'] = $authError->getMessage();
                $debugInfo['steps']['4_authorization']['trace'] = $authError->getTraceAsString();
            }
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "Authorization test failed: " . $e->getMessage();
        $debugInfo['steps']['4_authorization']['status'] = 'ERROR';
        $debugInfo['steps']['4_authorization']['error'] = $e->getMessage();
    }

    // STEP 5: Test getBalance() directly (this will establish connection if needed)
    $debugInfo['steps']['5_getbalance_direct'] = [];
    $directBalance = null;
    try {
        if (!$derivApi) {
            $debugInfo['steps']['5_getbalance_direct']['status'] = 'SKIPPED';
            $debugInfo['steps']['5_getbalance_direct']['reason'] = 'DerivAPI instance not created';
        } else {
            try {
                $debugInfo['steps']['5_getbalance_direct']['calling_getbalance'] = true;
                $debugInfo['steps']['5_getbalance_direct']['note'] = 'getBalance() will automatically establish connection if needed';
                
                // Call getBalance() - this is a public method that will handle connection internally
                $directBalance = $derivApi->getBalance();
                
                // Check connection status after getBalance
                $isConnectedAfter = $derivApi->isConnected();
                $debugInfo['steps']['5_getbalance_direct']['connection_status'] = $isConnectedAfter ? 'CONNECTED' : 'NOT CONNECTED';
                
                $debugInfo['steps']['5_getbalance_direct']['status'] = 'SUCCESS';
                $debugInfo['steps']['5_getbalance_direct']['balance'] = $directBalance;
                $debugInfo['steps']['5_getbalance_direct']['balance_type'] = gettype($directBalance);
            } catch (Exception $balanceError) {
                $debugInfo['errors'][] = "getBalance() failed: " . $balanceError->getMessage();
                $debugInfo['steps']['5_getbalance_direct']['status'] = 'FAILED';
                $debugInfo['steps']['5_getbalance_direct']['error'] = $balanceError->getMessage();
                $debugInfo['steps']['5_getbalance_direct']['trace'] = $balanceError->getTraceAsString();
            }
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "Direct balance test failed: " . $e->getMessage();
        $debugInfo['steps']['5_getbalance_direct']['status'] = 'ERROR';
        $debugInfo['steps']['5_getbalance_direct']['error'] = $e->getMessage();
    }

    // STEP 6: Test TradingBotService::getAccountBalance()
    $debugInfo['steps']['6_tradingbot_service'] = [];
    $serviceBalance = null;
    try {
        $tradingBot = TradingBotService::getInstance();
        $debugInfo['steps']['6_tradingbot_service']['service_instance_created'] = true;
        
        try {
            $debugInfo['steps']['6_tradingbot_service']['calling_getaccountbalance'] = true;
            $serviceBalance = $tradingBot->getAccountBalance($debugUserId);
            $debugInfo['steps']['6_tradingbot_service']['status'] = 'SUCCESS';
            $debugInfo['steps']['6_tradingbot_service']['balance'] = $serviceBalance;
        } catch (Exception $serviceError) {
            $debugInfo['errors'][] = "TradingBotService::getAccountBalance() failed: " . $serviceError->getMessage();
            $debugInfo['steps']['6_tradingbot_service']['status'] = 'FAILED';
            $debugInfo['steps']['6_tradingbot_service']['error'] = $serviceError->getMessage();
            $debugInfo['steps']['6_tradingbot_service']['trace'] = $serviceError->getTraceAsString();
        }
    } catch (Exception $e) {
        $debugInfo['errors'][] = "TradingBotService test failed: " . $e->getMessage();
        $debugInfo['steps']['6_tradingbot_service']['status'] = 'ERROR';
        $debugInfo['steps']['6_tradingbot_service']['error'] = $e->getMessage();
    }

    // STEP 7: Test API Endpoint
    $debugInfo['steps']['7_api_endpoint'] = [];
    try {
        // Simulate the API endpoint call
        $debugInfo['steps']['7_api_endpoint']['endpoint'] = '/api/trading.php?action=balance';
        $debugInfo['steps']['7_api_endpoint']['note'] = 'This would be the actual API response';
    } catch (Exception $e) {
        $debugInfo['errors'][] = "API endpoint test failed: " . $e->getMessage();
        $debugInfo['steps']['7_api_endpoint']['status'] = 'ERROR';
        $debugInfo['steps']['7_api_endpoint']['error'] = $e->getMessage();
    }

    // Cleanup
    if ($derivApi) {
        try {
            $derivApi->close();
        } catch (Exception $e) {
            // Ignore cleanup errors
        }
    }

    // Summary
    $debugInfo['summary'] = [
        'total_steps' => count($debugInfo['steps']),
        'successful_steps' => count(array_filter($debugInfo['steps'], function($step) {
            return isset($step['status']) && $step['status'] === 'SUCCESS';
        })),
        'failed_steps' => count(array_filter($debugInfo['steps'], function($step) {
            return isset($step['status']) && in_array($step['status'], ['FAILED', 'ERROR']);
        })),
        'total_errors' => count($debugInfo['errors']),
        'total_warnings' => count($debugInfo['warnings']),
        'final_balance' => $serviceBalance ?? $directBalance ?? ($authData['balance'] ?? null),
    ];

    // Return comprehensive debug info
    Response::success($debugInfo, 'Balance debug information retrieved');

} catch (Throwable $e) {
    // Clean any output before sending error
    @ob_clean();
    
    // Catch ANY error (including fatal errors, parse errors, etc.)
    @error_log('Fatal error in api/debug-balance.php: ' . $e->getMessage());
    @error_log('Stack trace: ' . $e->getTraceAsString());
    
    // Ensure only JSON is output
    Response::error('Internal server error: ' . $e->getMessage(), 500);
}

