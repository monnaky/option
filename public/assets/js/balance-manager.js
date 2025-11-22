/**
 * Balance Manager - Rock-Solid Balance Display System
 * 
 * Ensures balance ALWAYS displays reliably with:
 * - DOM readiness checks
 * - Retry mechanisms with exponential backoff
 * - Operation queuing
 * - Heartbeat monitoring
 * - Multiple element selector strategies
 * - Guaranteed display logic
 */

class BalanceManager {
    constructor(options = {}) {
        this.apiBase = options.apiBase || (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        this.maxRetries = options.maxRetries || 3; // Reduced from 10 for faster failure
        this.retryDelay = options.retryDelay || 50; // Reduced from 100ms for faster retries
        this.maxRetryDelay = options.maxRetryDelay || 1000; // Reduced from 5000ms to 1s max
        this.heartbeatInterval = options.heartbeatInterval || 5000; // Check every 5 seconds
        this.domReadyTimeout = options.domReadyTimeout || 2000; // Reduced from 10s to 2s max wait
        this.initialLoadTimeout = options.initialLoadTimeout || 500; // Fast initial load timeout
        
        // State
        this.currentBalance = null;
        this.lastDisplayedBalance = null;
        this.isUpdating = false;
        this.operationQueue = [];
        this.heartbeatTimer = null;
        this.retryCount = 0;
        this.domReady = false;
        
        // Element cache
        this.balanceElement = null;
        this.balanceNoteElement = null;
        
        // Statistics
        this.stats = {
            updatesAttempted: 0,
            updatesSuccessful: 0,
            updatesFailed: 0,
            retriesUsed: 0,
            domWaitTime: 0
        };
        
        // Loading state flag
        this.isLoading = false;
        
        console.log('[BalanceManager] Initialized with options:', {
            apiBase: this.apiBase,
            maxRetries: this.maxRetries,
            heartbeatInterval: this.heartbeatInterval
        });
    }
    
    /**
     * Initialize - optimized for fast initial load
     */
    async initialize() {
        console.log('[BalanceManager] Initializing (fast mode)...');
        const startTime = Date.now();
        
        // Show loading state immediately
        this.showLoadingState();
        
        // Try to find elements immediately (don't wait for full DOM ready)
        this.findElements();
        
        // If elements not found, wait briefly (max 500ms)
        if (!this.balanceElement) {
            await this.waitForDOMReadyFast();
            this.findElements();
        }
        
        // If still not found, wait for full DOM ready (but with shorter timeout)
        if (!this.balanceElement) {
            await this.waitForDOMReady();
            this.findElements();
        }
        
        // Start heartbeat monitoring (non-blocking)
        this.startHeartbeat();
        
        // Load initial balance IMMEDIATELY (don't wait for heartbeat)
        // This is the critical path - make it fast
        const loadPromise = this.loadBalance();
        
        // Don't await - let it load in background while we continue
        loadPromise.catch(error => {
            console.error('[BalanceManager] Initial load error:', error);
        });
        
        const initTime = Date.now() - startTime;
        console.log(`[BalanceManager] Initialization complete in ${initTime}ms (balance loading in background)`);
        
        // Return immediately - balance will update when ready
        return loadPromise;
    }
    
    /**
     * Fast DOM ready check (for initial load)
     */
    async waitForDOMReadyFast() {
        // Check if DOM is already ready
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            this.domReady = true;
            return;
        }
        
        // Wait briefly for DOMContentLoaded (max 500ms)
        return new Promise((resolve) => {
            if (document.readyState === 'loading') {
                const timeout = setTimeout(() => {
                    this.domReady = true;
                    resolve();
                }, this.initialLoadTimeout);
                
                document.addEventListener('DOMContentLoaded', () => {
                    clearTimeout(timeout);
                    this.domReady = true;
                    resolve();
                }, { once: true });
            } else {
                this.domReady = true;
                resolve();
            }
        });
    }
    
    /**
     * Wait for DOM to be fully ready (fallback)
     */
    async waitForDOMReady() {
        const startTime = Date.now();
        
        // Check if DOM is already ready
        if (document.readyState === 'complete' || document.readyState === 'interactive') {
            this.domReady = true;
            this.stats.domWaitTime = Date.now() - startTime;
            console.log('[BalanceManager] DOM ready immediately');
            return;
        }
        
        // Wait for DOMContentLoaded
        return new Promise((resolve) => {
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', () => {
                    this.domReady = true;
                    this.stats.domWaitTime = Date.now() - startTime;
                    console.log('[BalanceManager] DOM ready after event');
                    resolve();
                }, { once: true });
            } else {
                this.domReady = true;
                this.stats.domWaitTime = Date.now() - startTime;
                console.log('[BalanceManager] DOM ready (already loaded)');
                resolve();
            }
            
            // Timeout fallback (reduced from 10s to 2s)
            setTimeout(() => {
                if (!this.domReady) {
                    console.warn('[BalanceManager] DOM ready timeout, proceeding anyway');
                    this.domReady = true;
                    this.stats.domWaitTime = Date.now() - startTime;
                    resolve();
                }
            }, this.domReadyTimeout);
        });
    }
    
    /**
     * Find balance elements with multiple strategies
     */
    findElements() {
        console.log('[BalanceManager] Finding balance elements...');
        
        // Strategy 1: Direct ID
        this.balanceElement = document.getElementById('balance');
        
        // Strategy 2: Query selector
        if (!this.balanceElement) {
            this.balanceElement = document.querySelector('#balance');
        }
        
        // Strategy 3: Data attribute
        if (!this.balanceElement) {
            this.balanceElement = document.querySelector('[data-balance]');
        }
        
        // Strategy 4: Class-based (if balance is in a specific class)
        if (!this.balanceElement) {
            const candidates = document.querySelectorAll('.balance, [class*="balance"]');
            for (const el of candidates) {
                if (el.textContent.includes('$') || el.textContent.includes('0.00')) {
                    this.balanceElement = el;
                    break;
                }
            }
        }
        
        // Strategy 5: Search by text content pattern
        if (!this.balanceElement) {
            const allElements = document.querySelectorAll('*');
            for (const el of allElements) {
                if (el.id && el.id.toLowerCase().includes('balance')) {
                    this.balanceElement = el;
                    break;
                }
            }
        }
        
        // Find balance note element
        this.balanceNoteElement = document.getElementById('balanceNote') || 
                                  document.querySelector('#balanceNote') ||
                                  document.querySelector('[data-balance-note]');
        
        if (this.balanceElement) {
            console.log('[BalanceManager] ✅ Balance element found:', {
                id: this.balanceElement.id,
                tagName: this.balanceElement.tagName,
                currentText: this.balanceElement.textContent
            });
        } else {
            console.error('[BalanceManager] ❌ Balance element NOT found!');
            console.error('[BalanceManager] Available elements with "balance" in id:', 
                Array.from(document.querySelectorAll('[id*="balance"]')).map(el => ({
                    id: el.id,
                    tagName: el.tagName,
                    text: el.textContent.substring(0, 50)
                }))
            );
        }
        
        return this.balanceElement !== null;
    }
    
    /**
     * Queue an operation to prevent race conditions
     */
    async queueOperation(operation) {
        return new Promise((resolve, reject) => {
            this.operationQueue.push({ operation, resolve, reject });
            this.processQueue();
        });
    }
    
    /**
     * Process queued operations one at a time
     */
    async processQueue() {
        if (this.isUpdating || this.operationQueue.length === 0) {
            return;
        }
        
        this.isUpdating = true;
        
        while (this.operationQueue.length > 0) {
            const { operation, resolve, reject } = this.operationQueue.shift();
            
            try {
                const result = await operation();
                resolve(result);
            } catch (error) {
                reject(error);
            }
        }
        
        this.isUpdating = false;
    }
    
    /**
     * Load balance from API with retry mechanism
     */
    async loadBalance() {
        return this.queueOperation(async () => {
            console.log('[BalanceManager] Loading balance from API...');
            this.stats.updatesAttempted++;
            
            let lastError = null;
            
            for (let attempt = 1; attempt <= this.maxRetries; attempt++) {
                try {
                    const response = await this.fetchBalance();
                    
                    if (response && this.isValidBalance(response)) {
                        const balanceValue = this.extractBalance(response);
                        
                        if (balanceValue !== null && !isNaN(balanceValue)) {
                            const hasToken = response.hasToken !== false;
                            await this.updateDisplay(balanceValue, hasToken);
                            this.currentBalance = balanceValue;
                            this.retryCount = 0;
                            this.stats.updatesSuccessful++;
                            console.log('[BalanceManager] ✅ Balance loaded successfully:', balanceValue);
                            return balanceValue;
                        }
                    }
                    
                    throw new Error('Invalid balance response');
                    
                } catch (error) {
                    lastError = error;
                    this.stats.retriesUsed++;
                    
                    // Handle specific error types
                    if (error.errorType === 'no_token') {
                        // No token - don't retry, just show message
                        console.warn('[BalanceManager] No API token found - stopping retries');
                        await this.updateDisplay(0.00, false);
                        this.currentBalance = 0.00;
                        throw error; // Re-throw to stop retries
                    } else if (error.errorType === 'decryption_error') {
                        // Decryption error - don't retry
                        console.error('[BalanceManager] Token decryption failed - stopping retries');
                        await this.updateDisplay(0.00, false);
                        this.currentBalance = 0.00;
                        throw error;
                    }
                    
                    // For other errors, retry with faster exponential backoff
                    if (attempt < this.maxRetries) {
                        // Faster retry: 50ms, 100ms, 200ms (instead of 100ms, 200ms, 400ms...)
                        const delay = Math.min(
                            this.retryDelay * Math.pow(1.5, attempt - 1), // Reduced from 2.0 to 1.5
                            this.maxRetryDelay
                        );
                        console.warn(`[BalanceManager] Attempt ${attempt}/${this.maxRetries} failed, retrying in ${delay}ms:`, error.message);
                        await this.sleep(delay);
                    }
                }
            }
            
            this.stats.updatesFailed++;
            console.error('[BalanceManager] ❌ Failed to load balance after', this.maxRetries, 'attempts:', lastError);
            throw lastError;
        });
    }
    
    /**
     * Fetch balance from API
     */
    async fetchBalance() {
        // Use the trading API endpoint for balance
        const url = `${this.apiBase}/trading.php?action=balance`;
        console.log('[BalanceManager] Fetching from:', url);
        
        const response = await fetch(url, {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'same-origin',
        });
        
        if (!response.ok) {
            const errorText = await response.text();
            let errorData;
            try {
                errorData = JSON.parse(errorText);
            } catch (e) {
                errorData = { error: errorText };
            }
            throw new Error(`HTTP ${response.status}: ${errorData.error || response.statusText}`);
        }
        
        const data = await response.json();
        console.log('[BalanceManager] API response:', data);
        
        // Check for error in response
        if (data.error && data.errorType) {
            const error = new Error(data.errorMessage || 'Balance retrieval failed');
            error.errorType = data.errorType;
            error.hasToken = data.hasToken;
            throw error;
        }
        
        return data;
    }
    
    /**
     * Check if balance response is valid
     */
    isValidBalance(response) {
        if (!response || typeof response !== 'object') {
            return false;
        }
        
        // Check various response structures
        return (
            response.balance !== undefined ||
            (response.data && response.data.balance !== undefined) ||
            (response.success && response.data && response.data.balance !== undefined)
        );
    }
    
    /**
     * Extract balance value from response
     */
    extractBalance(response) {
        if (response.balance !== undefined) {
            return parseFloat(response.balance);
        }
        if (response.data && response.data.balance !== undefined) {
            return parseFloat(response.data.balance);
        }
        if (response.success && response.data && response.data.balance !== undefined) {
            return parseFloat(response.data.balance);
        }
        return null;
    }
    
    /**
     * Update balance display with guaranteed success
     * CRITICAL: Only update if we have a valid balance value (not null/undefined)
     */
    async updateDisplay(balanceValue, hasToken = true) {
        // CRITICAL: Never update to null/undefined - preserve existing balance
        if (balanceValue === null || balanceValue === undefined || isNaN(balanceValue)) {
            console.warn('[BalanceManager] ⚠️ Invalid balance value, preserving current balance:', {
                invalidValue: balanceValue,
                currentBalance: this.currentBalance
            });
            
            // If we have a cached balance, keep it
            if (this.currentBalance !== null) {
                balanceValue = this.currentBalance;
            } else {
                // Only set to 0 if we explicitly have no token
                balanceValue = hasToken ? (this.lastDisplayedBalance || 0) : 0;
            }
        }
        
        console.log('[BalanceManager] Updating display to:', balanceValue);
        
        // Ensure elements are found
        if (!this.balanceElement) {
            console.log('[BalanceManager] Elements not cached, finding again...');
            this.findElements();
        }
        
        // Retry finding elements if still not found
        let attempts = 0;
        while (!this.balanceElement && attempts < 10) {
            attempts++;
            console.log(`[BalanceManager] Element not found, retry ${attempts}/10`);
            await this.sleep(100 * attempts);
            this.findElements();
        }
        
        if (!this.balanceElement) {
            console.error('[BalanceManager] ❌ Cannot find balance element after 10 attempts!');
            throw new Error('Balance element not found');
        }
        
        // CRITICAL: Store balance BEFORE updating display to prevent race conditions
        this.currentBalance = parseFloat(balanceValue);
        this.lastDisplayedBalance = this.currentBalance;
        
        // Update the element - store balance in data attribute for recovery
        const formattedBalance = balanceValue.toFixed(2);
        this.balanceElement.textContent = formattedBalance;
        this.balanceElement.setAttribute('data-balance-value', balanceValue); // Store for recovery
        
        this.balanceElement.removeAttribute('data-loading'); // Remove loading state
        this.isLoading = false;
        
        // Verify the update worked
        if (this.balanceElement.textContent !== formattedBalance) {
            console.error('[BalanceManager] ❌ Balance update verification failed!');
            // Force update one more time
            this.balanceElement._balanceManagerUpdate = true;
            this.balanceElement.textContent = formattedBalance;
            this.balanceElement._balanceManagerUpdate = false;
        }
        
        // Update balance note visibility
        if (this.balanceNoteElement) {
            if (!hasToken) {
                this.balanceNoteElement.textContent = 'Connect API token to load balance';
                this.balanceNoteElement.classList.remove('d-none');
            } else if (balanceValue === 0) {
                this.balanceNoteElement.textContent = 'Balance is $0.00';
                this.balanceNoteElement.classList.remove('d-none');
            } else {
                this.balanceNoteElement.classList.add('d-none');
            }
        }
        
        console.log('[BalanceManager] ✅ Display updated successfully to:', formattedBalance);
        
        // Trigger custom event for other components
        window.dispatchEvent(new CustomEvent('balanceUpdated', {
            detail: { balance: balanceValue, hasToken }
        }));
    }
    
    /**
     * Start heartbeat monitoring
     */
    startHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
        }
        
        this.heartbeatTimer = setInterval(() => {
            this.checkBalanceDisplay();
        }, this.heartbeatInterval);
        
        console.log('[BalanceManager] Heartbeat monitoring started');
    }
    
    /**
     * Stop heartbeat monitoring
     */
    stopHeartbeat() {
        if (this.heartbeatTimer) {
            clearInterval(this.heartbeatTimer);
            this.heartbeatTimer = null;
        }
    }
    
    /**
     * Check if balance display is correct and fix if needed
     * CRITICAL: Never reset balance to 0.00 unless API explicitly returns 0.00
     */
    async checkBalanceDisplay() {
        // Verify element still exists
        if (!this.balanceElement || !document.contains(this.balanceElement)) {
            console.warn('[BalanceManager] ⚠️ Balance element lost, re-finding...');
            this.findElements();
        }
        
        // If we have a current balance but display doesn't match, fix it
        if (this.currentBalance !== null && this.balanceElement) {
            const displayedValue = parseFloat(this.balanceElement.textContent);
            const storedValue = parseFloat(this.balanceElement.getAttribute('data-balance-value'));
            
            // Use stored value from data attribute if available (more reliable)
            const expectedBalance = storedValue && !isNaN(storedValue) ? storedValue : this.currentBalance;
            
            // CRITICAL: Only fix if display is wrong AND not 0.00 (unless our balance is actually 0.00)
            // This prevents accidental resets from other scripts
            if (isNaN(displayedValue) || 
                (Math.abs(displayedValue - expectedBalance) > 0.01 && 
                 !(displayedValue === 0 && expectedBalance === 0))) {
                
                // If display shows 0.00 but we have a valid balance, restore it immediately
                if (displayedValue === 0 && expectedBalance > 0) {
                    console.warn('[BalanceManager] ⚠️ Balance reset to 0.00 detected! Restoring...', {
                        expected: expectedBalance,
                        displayed: displayedValue,
                        stored: storedValue
                    });
                    
                    // Immediately restore from stored value or cache
                    try {
                        const restoreValue = storedValue && !isNaN(storedValue) ? storedValue : this.currentBalance;
                        this.balanceElement.textContent = restoreValue.toFixed(2);
                        this.balanceElement.setAttribute('data-balance-value', restoreValue);
                        this.balanceElement.removeAttribute('data-loading');
                        this.currentBalance = restoreValue; // Update cache
                        console.log('[BalanceManager] ✅ Balance restored from cache');
                        return; // Don't reload from API, just restore display
                    } catch (error) {
                        console.error('[BalanceManager] ❌ Failed to restore balance:', error);
                    }
                }
                
                // For other mismatches, try to fix display
                console.warn('[BalanceManager] ⚠️ Display mismatch detected!', {
                    expected: expectedBalance,
                    displayed: displayedValue,
                    stored: storedValue
                });
                
                // Fix the display using cached/stored value (don't reload from API)
                try {
                    this.balanceElement.textContent = expectedBalance.toFixed(2);
                    this.balanceElement.setAttribute('data-balance-value', expectedBalance);
                    this.balanceElement.removeAttribute('data-loading');
                    this.currentBalance = expectedBalance; // Update cache
                    console.log('[BalanceManager] ✅ Display fixed by heartbeat');
                } catch (error) {
                    console.error('[BalanceManager] ❌ Failed to fix display:', error);
                }
            }
        }
    }
    
    /**
     * Force refresh balance
     */
    async refresh() {
        console.log('[BalanceManager] Force refresh requested');
        return this.loadBalance();
    }
    
    /**
     * Get current balance
     */
    getBalance() {
        return this.currentBalance;
    }
    
    /**
     * Get statistics
     */
    getStats() {
        return {
            ...this.stats,
            currentBalance: this.currentBalance,
            lastDisplayedBalance: this.lastDisplayedBalance,
            domReady: this.domReady,
            elementFound: this.balanceElement !== null
        };
    }
    
    /**
     * Show loading state
     */
    showLoadingState() {
        if (this.balanceElement) {
            this.balanceElement.textContent = 'Loading...';
            this.balanceElement.setAttribute('data-loading', 'true');
            this.isLoading = true;
        } else {
            // Try to find element quickly
            this.findElements();
            if (this.balanceElement) {
                this.balanceElement.textContent = 'Loading...';
                this.balanceElement.setAttribute('data-loading', 'true');
                this.isLoading = true;
            }
        }
    }
    
    /**
     * Sleep utility
     */
    sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    /**
     * Cleanup
     */
    destroy() {
        this.stopHeartbeat();
        this.operationQueue = [];
        console.log('[BalanceManager] Destroyed');
    }
}

// Export for use
if (typeof window !== 'undefined') {
    window.BalanceManager = BalanceManager;
}

