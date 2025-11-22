<?php

/**
 * Deriv API Service
 * 
 * PHP implementation of Deriv API using pure PHP WebSocket client
 * No external dependencies - uses built-in PHP stream functions
 * Maintains WebSocket connection for real-time API communication
 */

namespace App\Services;

use Exception;
use App\Services\WebSocketClient;

class DerivAPI
{
    private string $apiToken;
    private string $appId;
    private string $wsUrl;
    private string $wsHost;
    private ?WebSocketClient $wsClient = null;
    private int $requestId = 1;
    private bool $isAuthorized = false;
    private ?string $userId = null;
    private ?array $authData = null;
    
    // Configuration constants (optimized for speed)
    private const HTTP_TIMEOUT = 8; // Reduced from 15 to 8 seconds for faster failure detection
    private const CONNECTION_TIMEOUT = 5; // Reduced from 10 to 5 seconds
    private const MAX_RETRY_ATTEMPTS = 2; // Reduced from 3
    private const RETRY_DELAY = 1; // Reduced from 2 seconds
    private const DERIV_WS_DOMAIN = 'ws.derivws.com';
    
    // Rate limiting
    private const RATE_LIMIT_WINDOW = 60; // seconds
    private const RATE_LIMIT_MAX_CALLS = 60;
    private array $rateLimitCalls = [];
    private int $rateLimitResetTime = 0;
    
    /**
     * Constructor
     */
    public function __construct(string $apiToken, ?string $appId = null, ?string $userId = null)
    {
        $this->apiToken = $apiToken;
        $this->userId = $userId;
        
        // Get app ID from environment or use provided
        $this->appId = $appId ?? ($_ENV['DERIV_APP_ID'] ?? '1089');
        
        // Get WebSocket host from environment or use default
        $this->wsHost = $_ENV['DERIV_WS_HOST'] ?? 'ws.derivws.com';
        
        // Build WebSocket URL
        $this->wsUrl = "wss://{$this->wsHost}/websockets/v3?app_id={$this->appId}";
        
        error_log("[DerivAPI] Constructor - Building WebSocket URL");
        error_log("[DerivAPI] wsHost: " . var_export($this->wsHost, true));
        error_log("[DerivAPI] appId: " . var_export($this->appId, true));
        error_log("[DerivAPI] Final wsUrl: " . var_export($this->wsUrl, true));
        error_log("[DerivAPI] wsUrl length: " . strlen($this->wsUrl));
        error_log("[DerivAPI] wsUrl empty check: " . (empty($this->wsUrl) ? 'YES - ERROR!' : 'NO - OK'));
        error_log("DerivAPI initialized: {$this->wsUrl}, App ID: {$this->appId}");
    }
    
    /**
     * Check rate limit
     */
    private function checkRateLimit(): bool
    {
        if (!$this->userId) {
            return true; // No rate limit if no user ID
        }
        
        $now = time();
        
        // Reset if window expired
        if ($now > $this->rateLimitResetTime) {
            $this->rateLimitCalls = [];
            $this->rateLimitResetTime = $now + self::RATE_LIMIT_WINDOW;
        }
        
        // Remove old calls
        $this->rateLimitCalls = array_filter($this->rateLimitCalls, function($time) use ($now) {
            return ($now - $time) < self::RATE_LIMIT_WINDOW;
        });
        
        // Check limit
        if (count($this->rateLimitCalls) >= self::RATE_LIMIT_MAX_CALLS) {
            return false; // Rate limit exceeded
        }
        
        // Record this call
        $this->rateLimitCalls[] = $now;
        
        return true;
    }
    
    /**
     * Ensure WebSocket connection is established
     */
    private function ensureConnection(): void
    {
        if ($this->wsClient && $this->wsClient->isConnected()) {
            return;
        }
        
        // Close existing connection if any
        if ($this->wsClient) {
            try {
                $this->wsClient->close();
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            $this->wsClient = null;
            $this->isAuthorized = false;
        }
        
        // Create new WebSocket connection
        try {
            error_log("[DerivAPI::ensureConnection] About to create WebSocketClient");
            error_log("[DerivAPI::ensureConnection] URL to pass: " . var_export($this->wsUrl, true));
            error_log("[DerivAPI::ensureConnection] URL type: " . gettype($this->wsUrl));
            error_log("[DerivAPI::ensureConnection] URL length: " . strlen($this->wsUrl));
            error_log("[DerivAPI::ensureConnection] URL empty check: " . (empty($this->wsUrl) ? 'YES - ERROR!' : 'NO - OK'));
            
            if (empty($this->wsUrl)) {
                error_log("[DerivAPI::ensureConnection] FATAL ERROR: wsUrl is empty before creating WebSocketClient!");
                throw new Exception("WebSocket URL is empty in DerivAPI");
            }
            
            $this->wsClient = new WebSocketClient($this->wsUrl, self::HTTP_TIMEOUT);
            error_log("[DerivAPI::ensureConnection] WebSocketClient created successfully, calling connect()");
            error_log("[DerivAPI::ensureConnection] Connection timeout: " . self::HTTP_TIMEOUT . " seconds");
            
            $connectionStartTime = microtime(true);
            $this->wsClient->connect();
            $connectionTime = round((microtime(true) - $connectionStartTime) * 1000, 2);
            error_log("WebSocket connection established to Deriv API in {$connectionTime}ms");
        } catch (Exception $e) {
            error_log("[DerivAPI::ensureConnection] ERROR: WebSocket connection failed");
            error_log("[DerivAPI::ensureConnection] Exception message: " . $e->getMessage());
            error_log("[DerivAPI::ensureConnection] Exception trace: " . $e->getTraceAsString());
            error_log("WebSocket connection failed: " . $e->getMessage());
            throw new Exception("Failed to connect to Deriv API: " . $e->getMessage());
        }
    }
    
    /**
     * Send WebSocket request and wait for response
     */
    private function sendRequest(string $method, array $request): array
    {
        // Check rate limit
        if (!$this->checkRateLimit()) {
            throw new Exception('Rate limit exceeded. Please wait before making more requests.');
        }
        
        // Ensure we're authorized (unless this is an authorize request)
        if ($method !== 'authorize' && !$this->isAuthorized) {
            error_log("Not authorized, calling authorize() first");
            $this->authorize();
        }
        
        // Ensure WebSocket connection
        $this->ensureConnection();
        
        if (!$this->wsClient || !$this->wsClient->isConnected()) {
            throw new Exception('WebSocket is not connected');
        }
        
        $requestId = $this->requestId++;
        
        // Build request message - Deriv API expects method name as key
        // Format: { "method_name": params, "req_id": 123 }
        $requestMessage = [
            $method => $request,
            'req_id' => $requestId,
        ];
        
        // Send request
        $postData = json_encode($requestMessage);
        error_log("Sending WebSocket request: {$method} (req_id: {$requestId})");
        
        try {
            $this->wsClient->send($postData);
        } catch (Exception $e) {
            $this->isAuthorized = false;
            throw new Exception("Failed to send WebSocket message: " . $e->getMessage());
        }
        
        // Wait for response with retry logic
        $lastError = null;
        for ($attempt = 1; $attempt <= self::MAX_RETRY_ATTEMPTS; $attempt++) {
            try {
                $response = $this->wsClient->receive(self::HTTP_TIMEOUT);
                $data = json_decode($response, true);
                
                if (!$data) {
                    error_log("Invalid JSON response: " . substr($response, 0, 200));
                    continue;
                }
                
                // Check if this is the response we're waiting for
                if (isset($data['req_id']) && $data['req_id'] == $requestId) {
                    // Check for error
                    if (isset($data['error'])) {
                        $errorMsg = $data['error']['message'] ?? 'Deriv API error';
                        $errorCode = $data['error']['code'] ?? 'UNKNOWN';
                        throw new Exception("Deriv API error: {$errorMsg} ({$errorCode})");
                    }
                    
                    // Remove req_id from response
                    unset($data['req_id']);
                    return $data;
                }
                
                // If it's an echo_req, ignore it
                if (isset($data['echo_req'])) {
                    continue;
                }
                
                // If no req_id match, might be a different response
                // For authorize, we might get response without req_id
                if ($method === 'authorize' && isset($data['authorize'])) {
                    return $data;
                }
                
            } catch (Exception $e) {
                $lastError = $e;
                error_log("WebSocket receive attempt {$attempt} failed: " . $e->getMessage());
                
                // If connection lost, try to reconnect
                if (strpos($e->getMessage(), 'connection') !== false || strpos($e->getMessage(), 'closed') !== false) {
                    $this->isAuthorized = false;
                    $this->ensureConnection();
                }
                
                if ($attempt < self::MAX_RETRY_ATTEMPTS) {
                    sleep(self::RETRY_DELAY * $attempt);
                }
            }
        }
        
        throw new Exception(
            "Failed to get response from Deriv API after " . self::MAX_RETRY_ATTEMPTS . " attempts. " .
            "Last error: " . ($lastError ? $lastError->getMessage() : 'Unknown error')
        );
    }
    
    /**
     * Authorize API token and get account details
     */
    public function authorize(): array
    {
        $debugLog = [];
        $debugLog[] = "=== DerivAPI::authorize START ===";
        $debugLog[] = "Timestamp: " . date('Y-m-d H:i:s');
        $debugLog[] = "Token length: " . strlen($this->apiToken);
        $debugLog[] = "Token preview: " . substr($this->apiToken, 0, 20) . '...' . substr($this->apiToken, -10);
        
        try {
            // Build authorization request - app_id is in URL, not body
            $authMessage = [
                'authorize' => $this->apiToken,
            ];
            $debugLog[] = "Step 1: Built auth message";
            $debugLog[] = "  - Message structure: " . json_encode(array_keys($authMessage));
            
            // Ensure WebSocket connection
            $debugLog[] = "Step 2: Ensuring WebSocket connection";
            $this->ensureConnection();
            $debugLog[] = "  - Connection ensured";
            
            if (!$this->wsClient || !$this->wsClient->isConnected()) {
                $debugLog[] = "  - ERROR: WebSocket not connected";
                $debugLog[] = "=== DerivAPI::authorize FAILED ===";
                @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
                throw new Exception('WebSocket is not connected');
            }
            $debugLog[] = "  - WebSocket is connected";
            
            // Send authorization request directly
            $postData = json_encode($authMessage);
            $debugLog[] = "Step 3: Sending authorization request";
            $debugLog[] = "  - Request data length: " . strlen($postData);
            $debugLog[] = "  - Request preview: " . substr($postData, 0, 100) . '...';
            
            $this->wsClient->send($postData);
            $debugLog[] = "  - Request sent successfully";
            
            // Wait for authorization response
            $debugLog[] = "Step 4: Waiting for authorization response";
            $startTime = time();
            $data = null;
            $responseCount = 0;
            
            while ((time() - $startTime) < self::HTTP_TIMEOUT) {
                try {
                    $debugLog[] = "  - Receiving response (attempt " . ($responseCount + 1) . ")";
                    $response = $this->wsClient->receive(5);
                    $responseCount++;
                    $debugLog[] = "  - Response received, length: " . strlen($response);
                    $debugLog[] = "  - Response preview: " . substr($response, 0, 200);
                    
                    $data = json_decode($response, true);
                    
                    if (!$data) {
                        $debugLog[] = "  - Invalid JSON, continuing...";
                        continue;
                    }
                    
                    $debugLog[] = "  - JSON decoded successfully";
                    $debugLog[] = "  - Response keys: " . implode(', ', array_keys($data));
                    
                    // Check for error
                    if (isset($data['error'])) {
                        $errorMsg = $data['error']['message'] ?? 'Authorization failed';
                        $errorCode = $data['error']['code'] ?? 'UNKNOWN';
                        $debugLog[] = "  - ERROR in response: {$errorMsg} ({$errorCode})";
                        $debugLog[] = "  - Full error: " . json_encode($data['error']);
                        $debugLog[] = "=== DerivAPI::authorize FAILED ===";
                        @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
                        throw new Exception("Authorization failed: {$errorMsg} ({$errorCode})");
                    }
                    
                    // Check for authorization response
                    if (isset($data['authorize'])) {
                        $debugLog[] = "  - Authorization response found!";
                        $debugLog[] = "  - Authorize data keys: " . implode(', ', array_keys($data['authorize']));
                        break;
                    }
                    
                    // If it's an echo_req, ignore it
                    if (isset($data['echo_req'])) {
                        $debugLog[] = "  - Echo request, ignoring";
                        continue;
                    }
                    
                    $debugLog[] = "  - Unexpected response type, continuing...";
                    
                } catch (Exception $e) {
                    if (strpos($e->getMessage(), 'timeout') === false) {
                        $debugLog[] = "  - Exception (non-timeout): " . $e->getMessage();
                        $debugLog[] = "=== DerivAPI::authorize FAILED ===";
                        @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
                        throw $e;
                    }
                    $debugLog[] = "  - Timeout, continuing...";
                }
            }
            
            if (!$data || !isset($data['authorize'])) {
                $debugLog[] = "  - ERROR: No valid authorization response received";
                $debugLog[] = "  - Data received: " . ($data ? json_encode($data) : 'null');
                $debugLog[] = "=== DerivAPI::authorize FAILED ===";
                @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
                throw new Exception("Authorization timeout or invalid response");
            }
            
            $authData = $data['authorize'];
            $this->isAuthorized = true;
            $this->authData = $authData;
            
            $debugLog[] = "Step 5: Authorization successful";
            $debugLog[] = "  - LoginID: " . ($authData['loginid'] ?? 'N/A');
            $debugLog[] = "  - Balance: " . ($authData['balance'] ?? 'N/A');
            $debugLog[] = "  - Currency: " . ($authData['currency'] ?? 'N/A');
            $debugLog[] = "  - Full auth data: " . json_encode($authData);
            $debugLog[] = "=== DerivAPI::authorize SUCCESS ===";
            @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
            
            return [
                'account_list' => $authData['account_list'] ?? [],
                'balance' => (float)($authData['balance'] ?? 0),
                'currency' => $authData['currency'] ?? 'USD',
                'loginid' => $authData['loginid'] ?? '',
                'country' => $authData['country'] ?? '',
                'email' => $authData['email'] ?? '',
                'fullname' => $authData['fullname'] ?? '',
                'scopes' => $authData['scopes'] ?? [],
            ];
            
        } catch (Exception $e) {
            $debugLog[] = "EXCEPTION CAUGHT: " . $e->getMessage();
            $debugLog[] = "Exception type: " . get_class($e);
            $debugLog[] = "Stack trace: " . $e->getTraceAsString();
            $debugLog[] = "=== DerivAPI::authorize EXCEPTION ===";
            @error_log("[DerivAPI::authorize] " . implode("\n", $debugLog));
            
            $this->isAuthorized = false;
            $this->authData = null;
            throw $e;
        }
    }
    
    /**
     * Get account information
     */
    public function getAccountInfo(): array
    {
        try {
            error_log("Getting account info");
            
            // Use authorize to get account info (it returns account details)
            // If already authorized, we still call authorize() to get fresh data
            $authData = $this->authorize();
            
            error_log("Account info retrieved. LoginID: " . ($authData['loginid'] ?? 'N/A'));
            
            return [
                'loginid' => $authData['loginid'] ?? '',
                'currency' => $authData['currency'] ?? 'USD',
                'country' => $authData['country'] ?? '',
                'email' => $authData['email'] ?? '',
                'fullname' => $authData['fullname'] ?? '',
                'scopes' => $authData['scopes'] ?? [],
            ];
            
        } catch (Exception $e) {
            error_log('Get account info error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            throw $e;
        }
    }
    
    /**
     * Get account balance
     */
    public function getBalance(): float
    {
        $debugLog = [];
        $debugLog[] = "=== DerivAPI::getBalance START ===";
        $debugLog[] = "Timestamp: " . date('Y-m-d H:i:s');
        
        try {
            // If we have cached auth data with balance, use it
            $debugLog[] = "Step 1: Checking cached auth data";
            if ($this->authData && isset($this->authData['balance'])) {
                $cachedBalance = (float)$this->authData['balance'];
                $debugLog[] = "  - Using cached balance: " . $cachedBalance;
                $debugLog[] = "=== DerivAPI::getBalance SUCCESS (cached) ===";
                @error_log("[DerivAPI::getBalance] " . implode("\n", $debugLog));
                return $cachedBalance;
            }
            $debugLog[] = "  - No cached balance available";
            
            // Ensure connection is established before making requests
            $debugLog[] = "Step 2: Ensuring connection";
            $this->ensureConnection();
            $debugLog[] = "  - Connection ensured";
            $debugLog[] = "  - isConnected(): " . ($this->isConnected() ? 'true' : 'false');
            $debugLog[] = "  - isAuthorized: " . ($this->isAuthorized ? 'true' : 'false');
            
            // Try to get balance via account endpoint
            $debugLog[] = "Step 3: Trying account endpoint";
            try {
                $requestParams = ['account' => 1];
                $debugLog[] = "  - Request params: " . json_encode($requestParams);
                $debugLog[] = "  - Sending account request";
                
                $response = $this->sendRequest('account', $requestParams);
                
                $debugLog[] = "  - Response received";
                $debugLog[] = "  - Response keys: " . implode(', ', array_keys($response));
                $debugLog[] = "  - Full response: " . json_encode($response);
                
                if (isset($response['account']['balance'])) {
                    $balance = (float)$response['account']['balance'];
                    $debugLog[] = "  - Balance extracted: " . $balance;
                    $debugLog[] = "=== DerivAPI::getBalance SUCCESS (account endpoint) ===";
                    @error_log("[DerivAPI::getBalance] " . implode("\n", $debugLog));
                    return $balance;
                } else {
                    $debugLog[] = "  - No balance in account response";
                    $debugLog[] = "  - Response structure: " . json_encode($response);
                }
            } catch (Exception $e) {
                $debugLog[] = "  - Account endpoint FAILED: " . $e->getMessage();
                $debugLog[] = "  - Error type: " . get_class($e);
                $debugLog[] = "  - Error trace: " . substr($e->getTraceAsString(), 0, 500);
            }
            
            // Fallback: get fresh data via authorize
            $debugLog[] = "Step 4: Falling back to authorize()";
            $authData = $this->authorize();
            $debugLog[] = "  - authorize() completed";
            $debugLog[] = "  - Auth data keys: " . implode(', ', array_keys($authData));
            $debugLog[] = "  - Auth data balance: " . ($authData['balance'] ?? 'NOT SET');
            
            $balance = (float)($authData['balance'] ?? 0);
            $debugLog[] = "  - Final balance: " . $balance;
            $debugLog[] = "=== DerivAPI::getBalance SUCCESS (authorize fallback) ===";
            @error_log("[DerivAPI::getBalance] " . implode("\n", $debugLog));
            
            return $balance;
            
        } catch (Exception $e) {
            $debugLog[] = "EXCEPTION CAUGHT: " . $e->getMessage();
            $debugLog[] = "Exception type: " . get_class($e);
            $debugLog[] = "Stack trace: " . $e->getTraceAsString();
            $debugLog[] = "=== DerivAPI::getBalance FAILED ===";
            @error_log("[DerivAPI::getBalance] " . implode("\n", $debugLog));
            throw $e;
        }
    }
    
    /**
     * Get available assets for trading
     */
    public function getAvailableAssets(): array
    {
        try {
            $response = $this->sendRequest('active_symbols', [
                'active_symbols' => 'brief',
                'product_type' => 'basic',
            ]);
            
            if (isset($response['active_symbols']) && is_array($response['active_symbols'])) {
                return array_map(function($symbol) {
                    return $symbol['symbol'] ?? '';
                }, $response['active_symbols']);
            }
            
            // Return default assets on error
            return ['R_10', 'R_25', 'R_50', 'R_100', 'R_75'];
            
        } catch (Exception $e) {
            error_log('Get available assets error: ' . $e->getMessage());
            // Return default assets on error
            return ['R_10', 'R_25', 'R_50', 'R_100', 'R_75'];
        }
    }
    
    /**
     * Place a trade (buy contract)
     */
    public function buyContract(
        string $symbol,
        string $contractType,
        float $amount,
        int $duration = 5,
        string $durationUnit = 't'
    ): array {
        try {
            $response = $this->sendRequest('buy', [
                'buy' => 1,
                'price' => $amount,
                'contract_type' => $contractType,
                'symbol' => $symbol,
                'duration' => $duration,
                'duration_unit' => $durationUnit,
            ]);
            
            if (!isset($response['buy'])) {
                throw new Exception('Failed to place trade');
            }
            
            $buyData = $response['buy'];
            
            return [
                'contract_id' => (int)($buyData['contract_id'] ?? 0),
                'buy_price' => (float)($buyData['buy_price'] ?? 0),
                'sell_price' => (float)($buyData['sell_price'] ?? 0),
                'currency' => $buyData['currency'] ?? 'USD',
                'date_start' => $buyData['date_start'] ?? 0,
                'date_expiry' => $buyData['date_expiry'] ?? 0,
                'tick_count' => (int)($buyData['tick_count'] ?? 0),
                'current_spot' => (float)($buyData['current_spot'] ?? 0),
                'current_spot_time' => $buyData['current_spot_time'] ?? 0,
                'entry_tick' => $buyData['entry_tick'] ?? 0,
                'entry_tick_time' => $buyData['entry_tick_time'] ?? 0,
                'profit' => 0,
                'status' => 'open',
                'is_expired' => 0,
                'is_settleable' => 0,
                'is_sold' => 0,
            ];
            
        } catch (Exception $e) {
            error_log('Buy contract error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Sell a contract
     */
    public function sellContract(int $contractId): array
    {
        try {
            $response = $this->sendRequest('sell', [
                'sell' => $contractId,
                'price' => 0, // Market price
            ]);
            
            if (!isset($response['sell'])) {
                throw new Exception('Failed to sell contract');
            }
            
            $sellData = $response['sell'];
            $profit = (float)($sellData['profit'] ?? 0);
            
            return [
                'contract_id' => (int)($sellData['contract_id'] ?? 0),
                'buy_price' => (float)($sellData['buy_price'] ?? 0),
                'sell_price' => (float)($sellData['sell_price'] ?? 0),
                'currency' => $sellData['currency'] ?? 'USD',
                'date_start' => $sellData['date_start'] ?? 0,
                'date_expiry' => $sellData['date_expiry'] ?? 0,
                'tick_count' => (int)($sellData['tick_count'] ?? 0),
                'current_spot' => (float)($sellData['current_spot'] ?? 0),
                'current_spot_time' => $sellData['current_spot_time'] ?? 0,
                'entry_tick' => $sellData['entry_tick'] ?? 0,
                'entry_tick_time' => $sellData['entry_tick_time'] ?? 0,
                'exit_tick' => $sellData['exit_tick'] ?? null,
                'exit_tick_time' => $sellData['exit_tick_time'] ?? null,
                'profit' => $profit,
                'status' => $profit > 0 ? 'won' : 'lost',
                'is_expired' => $sellData['is_expired'] ?? 0,
                'is_settleable' => $sellData['is_settleable'] ?? 0,
                'is_sold' => $sellData['is_sold'] ?? 0,
            ];
            
        } catch (Exception $e) {
            error_log('Sell contract error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get contract information
     */
    public function getContractInfo(int $contractId): array
    {
        try {
            $response = $this->sendRequest('contract', [
                'contract_id' => $contractId,
            ]);
            
            if (!isset($response['contract'])) {
                throw new Exception('Contract not found');
            }
            
            $contract = $response['contract'];
            
            return [
                'contract_id' => (int)($contract['contract_id'] ?? 0),
                'buy_price' => (float)($contract['buy_price'] ?? 0),
                'sell_price' => (float)($contract['sell_price'] ?? 0),
                'currency' => $contract['currency'] ?? 'USD',
                'date_start' => $contract['date_start'] ?? 0,
                'date_expiry' => $contract['date_expiry'] ?? 0,
                'tick_count' => (int)($contract['tick_count'] ?? 0),
                'current_spot' => (float)($contract['current_spot'] ?? 0),
                'current_spot_time' => $contract['current_spot_time'] ?? 0,
                'entry_tick' => $contract['entry_tick'] ?? 0,
                'entry_tick_time' => $contract['entry_tick_time'] ?? 0,
                'exit_tick' => $contract['exit_tick'] ?? null,
                'exit_tick_time' => $contract['exit_tick_time'] ?? null,
                'profit' => (float)($contract['profit'] ?? 0),
                'status' => $contract['status'] ?? 'open',
                'is_expired' => $contract['is_expired'] ?? 0,
                'is_settleable' => $contract['is_settleable'] ?? 0,
                'is_sold' => $contract['is_sold'] ?? 0,
            ];
            
        } catch (Exception $e) {
            error_log('Get contract info error: ' . $e->getMessage());
            throw $e;
        }
    }
    
    /**
     * Get ticks (market data) for a symbol
     */
    public function getTicks(string $symbol, int $count = 1): array
    {
        try {
            $response = $this->sendRequest('ticks', [
                'ticks' => $symbol,
                'count' => $count,
            ]);
            
            if (!isset($response['ticks'])) {
                return [];
            }
            
            return $response['ticks'];
            
        } catch (Exception $e) {
            error_log('Get ticks error: ' . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Check if API is connected and authorized
     */
    public function isConnected(): bool
    {
        if (!$this->wsClient) {
            return false;
        }
        
        // Check if WebSocket is still connected
        if (!$this->wsClient->isConnected()) {
            $this->isAuthorized = false;
            $this->authData = null;
            return false;
        }
        
        // If we have auth data, we're connected
        return $this->isAuthorized && $this->authData !== null;
    }
    
    /**
     * Check connection health (non-throwing)
     */
    public function checkConnectionHealth(): bool
    {
        try {
            if (!$this->isConnected()) {
                return false;
            }
            
            // Try a lightweight operation to verify connection
            // We'll just check if we have valid auth data
            return $this->isAuthorized && $this->authData !== null;
        } catch (Exception $e) {
            @error_log("[DerivAPI::checkConnectionHealth] Health check failed: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Close WebSocket connection
     */
    public function close(): void
    {
        if ($this->wsClient) {
            try {
                $this->wsClient->close();
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
            $this->wsClient = null;
        }
        
        $this->isAuthorized = false;
        $this->authData = null;
    }
    
    /**
     * Get rate limit status
     */
    public function getRateLimitStatus(): array
    {
        $now = time();
        
        if ($now > $this->rateLimitResetTime) {
            return [
                'calls' => 0,
                'limit' => self::RATE_LIMIT_MAX_CALLS,
                'resetTime' => $now + self::RATE_LIMIT_WINDOW,
            ];
        }
        
        $activeCalls = count(array_filter($this->rateLimitCalls, function($time) use ($now) {
            return ($now - $time) < self::RATE_LIMIT_WINDOW;
        }));
        
        return [
            'calls' => $activeCalls,
            'limit' => self::RATE_LIMIT_MAX_CALLS,
            'resetTime' => $this->rateLimitResetTime,
        ];
    }
}
