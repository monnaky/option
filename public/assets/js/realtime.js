/**
 * Real-time Updates JavaScript Module
 * 
 * Replaces Socket.IO client with AJAX polling
 * 
 * Usage:
 *   const realtime = new RealtimeManager();
 *   realtime.startPolling();
 */

// Ensure apiCall is available
if (typeof apiCall === 'undefined') {
    console.error('apiCall function not found. Please include api.js before realtime.js');
}

class RealtimeManager {
    constructor(options = {}) {
        this.userId = options.userId || null;
        this.pollInterval = options.pollInterval || 3000; // 3 seconds default
        this.balanceInterval = options.balanceInterval || 5000; // 5 seconds for balance
        this.tradesInterval = options.tradesInterval || 2000; // 2 seconds for trades
        this.intervals = {};
        this.lastUpdate = null;
        this.callbacks = {
            status: [],
            // balance: [] - REMOVED: Use BalanceManager instead
            trades: [],
            updates: [],
            notifications: [],
            error: [],
        };
        this.isPolling = false;
    }
    
    /**
     * Start all polling
     * NOTE: Balance polling removed - use BalanceManager instead
     */
    startPolling() {
        if (this.isPolling) {
            return;
        }
        
        this.isPolling = true;
        this.lastUpdate = new Date().toISOString();
        
        // Start status polling
        this.startStatusPolling();
        
        // Balance polling REMOVED - BalanceManager handles balance updates
        // This eliminates conflicts and provides single source of truth
        
        // Start trades polling
        this.startTradesPolling();
        
        // Start comprehensive updates polling
        this.startUpdatesPolling();
        
        // Start notifications polling
        this.startNotificationsPolling();
        
        console.log('Real-time polling started (balance handled by BalanceManager)');
    }
    
    /**
     * Stop all polling
     */
    stopPolling() {
        this.isPolling = false;
        
        Object.values(this.intervals).forEach(interval => {
            if (interval) {
                clearInterval(interval);
            }
        });
        
        this.intervals = {};
        console.log('Real-time polling stopped');
    }
    
    /**
     * Poll trading status
     */
    startStatusPolling() {
        this.intervals.status = setInterval(async () => {
            try {
                const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
                const data = await apiCall(`${apiBase}/realtime.php?action=status`);
                this.lastUpdate = data.timestamp;
                this.emit('status', data);
            } catch (error) {
                this.emit('error', { type: 'status', error });
            }
        }, this.pollInterval);
    }
    
    /**
     * Balance polling REMOVED
     * 
     * Balance updates are now handled exclusively by BalanceManager
     * to eliminate conflicts and provide a single source of truth.
     * 
     * If you need balance updates, use:
     *   window.balanceManager.loadBalance()
     *   window.balanceManager.getBalance()
     * 
     * Or listen to the 'balanceUpdated' event:
     *   window.addEventListener('balanceUpdated', (e) => { ... })
     */
    startBalancePolling() {
        console.warn('[RealtimeManager] startBalancePolling() called but balance polling is disabled. Use BalanceManager instead.');
        // Do nothing - balance is handled by BalanceManager
    }
    
    /**
     * Poll trades
     */
    startTradesPolling() {
        this.intervals.trades = setInterval(async () => {
            try {
                const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
                const data = await apiCall(`${apiBase}/realtime.php?action=trades`);
                this.emit('trades', data);
            } catch (error) {
                this.emit('error', { type: 'trades', error });
            }
        }, this.tradesInterval);
    }
    
    /**
     * Poll comprehensive updates
     */
    startUpdatesPolling() {
        this.intervals.updates = setInterval(async () => {
            try {
                const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
                const url = this.lastUpdate 
                    ? `${apiBase}/realtime.php?action=updates&lastUpdate=${encodeURIComponent(this.lastUpdate)}`
                    : `${apiBase}/realtime.php?action=updates`;
                
                const data = await apiCall(url);
                this.lastUpdate = data.timestamp;
                this.emit('updates', data);
                
                // Handle new trades
                if (data.newTrades && data.newTrades.length > 0) {
                    data.newTrades.forEach(trade => {
                        if (trade.status === 'pending') {
                            this.emit('trade-started', { trade });
                        } else {
                            this.emit('trade-result', { trade });
                        }
                    });
                }
            } catch (error) {
                this.emit('error', { type: 'updates', error });
            }
        }, this.pollInterval);
    }
    
    /**
     * Poll notifications
     */
    startNotificationsPolling() {
        this.intervals.notifications = setInterval(async () => {
            try {
                const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
                const data = await apiCall(`${apiBase}/realtime.php?action=notifications`);
                
                if (data.notifications && data.notifications.length > 0) {
                    data.notifications.forEach(notification => {
                        this.emit('notification', notification);
                    });
                }
            } catch (error) {
                this.emit('error', { type: 'notifications', error });
            }
        }, this.pollInterval);
    }
    
    /**
     * Register event callback
     */
    on(event, callback) {
        if (this.callbacks[event]) {
            this.callbacks[event].push(callback);
        }
    }
    
    /**
     * Remove event callback
     */
    off(event, callback) {
        if (this.callbacks[event]) {
            this.callbacks[event] = this.callbacks[event].filter(cb => cb !== callback);
        }
    }
    
    /**
     * Emit event to callbacks
     */
    emit(event, data) {
        if (this.callbacks[event]) {
            this.callbacks[event].forEach(callback => {
                try {
                    callback(data);
                } catch (error) {
                    console.error('Error in callback:', error);
                }
            });
        }
    }
}

// Export for use in other scripts
if (typeof window !== 'undefined') {
    window.RealtimeManager = RealtimeManager;
}

