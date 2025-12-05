/**
 * Dashboard Real-time Integration
 * 
 * Integrates RealtimeManager with dashboard UI
 */

// Initialize real-time manager
const realtime = new RealtimeManager({
    pollInterval: 3000,      // 3 seconds for status
    balanceInterval: 5000,   // 5 seconds for balance
    tradesInterval: 2000,     // 2 seconds for trades
});

// Track previous state for change detection
let previousState = {
    balance: null,
    isBotActive: null,
    dailyProfit: null,
    dailyLoss: null,
    lastTradeId: null,
};

// Start polling when page loads
document.addEventListener('DOMContentLoaded', function() {
    realtime.startPolling();
    setupEventHandlers();
});

// Setup event handlers
function setupEventHandlers() {
    // Handle status updates
    realtime.on('status', function(data) {
        updateTradingStatus(data);
    });
    
    // Balance updates REMOVED - handled by BalanceManager
    // BalanceManager is the single source of truth for balance display
    // Listen to BalanceManager's 'balanceUpdated' event if needed
    
    // Handle trade updates
    realtime.on('trades', function(data) {
        updateTrades(data);
    });
    
    // Handle comprehensive updates
    realtime.on('updates', function(data) {
        updateDashboard(data);
    });
    
    // Handle trade started
    realtime.on('trade-started', function(data) {
        handleTradeStarted(data.trade);
    });
    
    // Handle trade result
    realtime.on('trade-result', function(data) {
        handleTradeResult(data.trade);
    });
    
    // Handle notifications
    realtime.on('notification', function(notification) {
        handleNotification(notification);
    });
    
    // Handle errors
    realtime.on('error', function(error) {
        console.error('Real-time error:', error);
    });
}

/**
 * Update trading status
 */
function updateTradingStatus(data) {
    if (!data) return;
    
    // Update bot active status
    if (data.isActive !== previousState.isBotActive) {
        previousState.isBotActive = data.isActive;
        updateBotStatus(data.isActive);
    }
    
    // Update settings if changed
    if (data.settings) {
        if (data.settings.dailyProfit !== previousState.dailyProfit ||
            data.settings.dailyLoss !== previousState.dailyLoss) {
            previousState.dailyProfit = data.settings.dailyProfit;
            previousState.dailyLoss = data.settings.dailyLoss;
            updateDailyStats(data.settings);
        }
    }
}

/**
 * Balance update function REMOVED
 * 
 * Balance updates are now handled exclusively by BalanceManager.
 * This function is kept for backwards compatibility but does nothing.
 * 
 * Use BalanceManager directly:
 *   window.balanceManager.loadBalance()
 *   window.balanceManager.getBalance()
 */
function updateBalance(data) {
    console.warn('[updateBalance] Function called but balance updates are handled by BalanceManager. This call is ignored.');
    // BalanceManager handles all balance updates - no action needed
}

/**
 * Update trades
 */
function updateTrades(data) {
    if (!data) return;
    
    // Update live trades
    if (data.liveTrades) {
        updateLiveTrades(data.liveTrades);
    }
    
    // Update trade history
    if (data.trades) {
        updateTradeHistory(data.trades);
    }
}

/**
 * Update dashboard with comprehensive data
 */
function updateDashboard(data) {
    if (!data) return;
    
    // Update settings
    if (data.settings) {
        updateSettings(data.settings);
    }
    
    // Update session info
    if (data.session) {
        updateSessionInfo(data.session);
    }
    
    // Balance updates handled by BalanceManager - skip here
    
    // Update trades
    if (data.recentTrades) {
        updateTradeHistory(data.recentTrades);
    }
    
    if (data.liveTrades) {
        updateLiveTrades(data.liveTrades);
    }
    
    // Handle new trades
    if (data.newTrades && data.newTrades.length > 0) {
        data.newTrades.forEach(trade => {
            if (trade.status === 'pending') {
                handleTradeStarted(trade);
            } else {
                handleTradeResult(trade);
            }
        });
    }
}

/**
 * Handle trade started event
 */
function handleTradeStarted(trade) {
    if (trade.trade_id === previousState.lastTradeId) {
        return; // Already processed
    }
    
    previousState.lastTradeId = trade.trade_id;
    
    const message = `Trade started: ${trade.direction} on ${trade.asset}`;
    showToast(message, 'info');
    addNotification('info', message);
    
    // Add to live trades
    addLiveTrade(trade);
    
    // Reload trade history
    loadTradeHistory();
}

/**
 * Handle trade result event
 */
function handleTradeResult(trade) {
    if (trade.trade_id === previousState.lastTradeId && trade.status !== 'pending') {
        return; // Already processed
    }
    
    const status = trade.status === 'won' ? 'success' : 'error';
    const message = `Trade ${trade.status}: ${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}`;
    
    showToast(message, status);
    addNotification(status, message);
    
    // Update live trades
    updateLiveTradeStatus(trade);
    
    // Reload trade history after delay
    setTimeout(() => {
        loadTradeHistory();
    }, 2000);
}

/**
 * Handle notification
 */
function handleNotification(notification) {
    showToast(notification.message, notification.type);
    addNotification(notification.type, notification.message);
    
    // Handle special notifications
    if (notification.message.includes('target') || notification.message.includes('limit')) {
        // Bot stopped due to target/limit
        updateBotStatus(false);
    }
}

/**
 * Update bot status UI
 */
function updateBotStatus(isActive) {
    const statusBadge = document.getElementById('botStatus');
    const btn = document.getElementById('startStopBtn');
    const text = document.getElementById('startStopText');
    
    if (statusBadge) {
        if (isActive) {
            statusBadge.className = 'badge bg-success';
            statusBadge.textContent = '🟢 Running';
        } else {
            statusBadge.className = 'badge bg-secondary';
            statusBadge.textContent = '⚫ Stopped';
        }
    }
    
    if (btn && text) {
        if (isActive) {
            btn.className = 'btn btn-danger btn-lg w-100 mb-3';
            text.textContent = '🛑 STOP TRADING';
        } else {
            btn.className = 'btn btn-success btn-lg w-100 mb-3';
            text.textContent = '▶️ START TRADING';
        }
    }
}

/**
 * Update daily statistics
 */
function updateDailyStats(settings) {
    const dailyProfitEl = document.getElementById('dailyProfit');
    const dailyLossEl = document.getElementById('dailyLoss');
    const netProfitEl = document.getElementById('netProfit');
    
    if (dailyProfitEl) {
        dailyProfitEl.textContent = `+$${parseFloat(settings.dailyProfit).toFixed(2)}`;
    }
    
    if (dailyLossEl) {
        dailyLossEl.textContent = `-$${parseFloat(settings.dailyLoss).toFixed(2)}`;
    }
    
    if (netProfitEl) {
        const netProfit = parseFloat(settings.dailyProfit) - parseFloat(settings.dailyLoss);
        netProfitEl.textContent = `${netProfit >= 0 ? '+' : ''}$${netProfit.toFixed(2)}`;
        netProfitEl.className = `fs-4 fw-bold ${netProfit >= 0 ? 'text-success' : 'text-danger'}`;
    }
}

/**
 * Update settings display
 */
function updateSettings(settings) {
    // Update form fields if not being edited
    const stakeInput = document.getElementById('stake');
    const targetInput = document.getElementById('target');
    const stopLimitInput = document.getElementById('stopLimit');
    
    if (stakeInput && !stakeInput.matches(':focus')) {
        stakeInput.value = settings.stake;
    }
    
    if (targetInput && !targetInput.matches(':focus')) {
        targetInput.value = settings.target;
    }
    
    if (stopLimitInput && !stopLimitInput.matches(':focus')) {
        stopLimitInput.value = settings.stopLimit;
    }
}

/**
 * Update session info
 */
function updateSessionInfo(session) {
    // Update session display if needed
    console.log('Session updated:', session);
}

/**
 * Add live trade
 */
function addLiveTrade(trade) {
    // This will be handled by updateLiveTrades function
    // Just trigger a refresh
    realtime.emit('trades', { liveTrades: [trade] });
}

/**
 * Update live trade status
 */
function updateLiveTradeStatus(trade) {
    // Update the specific trade in live trades display
    const container = document.getElementById('liveTradesContainer');
    if (container) {
        // Find and update the trade element
        const tradeElement = container.querySelector(`[data-trade-id="${trade.trade_id}"]`);
        if (tradeElement) {
            // Update trade status
            const statusBadge = tradeElement.querySelector('.badge');
            if (statusBadge) {
                statusBadge.className = `badge ${trade.status === 'won' ? 'bg-success' : 'bg-danger'}`;
                statusBadge.textContent = trade.status.toUpperCase();
            }
            
            // Update profit
            const profitEl = tradeElement.querySelector('.profit');
            if (profitEl) {
                profitEl.textContent = `${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}`;
                profitEl.className = `fw-bold ${trade.profit > 0 ? 'text-success' : 'text-danger'}`;
            }
        }
    }
}

/**
 * Update live trades display
 */
function updateLiveTrades(trades) {
    const container = document.getElementById('liveTradesContainer');
    const count = document.getElementById('liveTradesCount');
    
    if (!container) return;
    
    if (count) {
        count.textContent = `${trades.length} Active`;
        if (trades.length > 0) {
            count.classList.remove('d-none');
        } else {
            count.classList.add('d-none');
        }
    }
    
    if (trades.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="bi bi-graph-up-arrow fs-1 d-block mb-3"></i>
                <p>No active trades</p>
                <small>Trades will appear here when bot is active</small>
            </div>
        `;
        return;
    }
    
    const html = trades.map(trade => `
        <div class="card bg-dark mb-2 border-dark-custom" data-trade-id="${trade.trade_id}">
            <div class="card-body p-3">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div>
                        <span class="fw-bold">${trade.asset}</span>
                        <span class="badge ${trade.direction === 'RISE' ? 'bg-success' : 'bg-danger'} ms-2">
                            ${trade.direction}
                        </span>
                    </div>
                    <span class="badge ${
                        trade.status === 'won' ? 'bg-success' : 
                        trade.status === 'lost' ? 'bg-danger' : 
                        'bg-warning'
                    }">
                        ${trade.status === 'pending' ? '⏳ Pending...' : trade.status.toUpperCase()}
                    </span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top border-dark-custom">
                    <small class="text-muted">Stake: <span class="text-white">$${parseFloat(trade.stake).toFixed(2)}</span></small>
                    <span class="fw-bold profit ${
                        trade.profit > 0 ? 'text-success' : 
                        trade.profit < 0 ? 'text-danger' : 
                        'text-muted'
                    }">
                        ${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}
                    </span>
                </div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
}

/**
 * Update trade history display
 */
function updateTradeHistory(trades) {
    const container = document.getElementById('tradeHistoryContainer');
    const count = document.getElementById('tradeHistoryCount');
    
    if (!container) return;
    
    if (count) {
        count.textContent = trades.length;
    }
    
    if (trades.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5 text-muted">
                <i class="bi bi-graph-up fs-1 d-block mb-3"></i>
                <p>No trades yet</p>
                <small>Trade history will appear here</small>
            </div>
        `;
        return;
    }
    
    const table = `
        <table class="table table-dark table-hover">
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Asset</th>
                    <th>Direction</th>
                    <th>Stake</th>
                    <th>Result</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                ${trades.map(trade => `
                    <tr>
                        <td>${new Date(trade.timestamp).toLocaleString()}</td>
                        <td class="fw-bold">${trade.asset}</td>
                        <td>
                            <span class="badge ${trade.direction === 'RISE' ? 'bg-success' : 'bg-danger'}">
                                ${trade.direction}
                            </span>
                        </td>
                        <td>$${parseFloat(trade.stake).toFixed(2)}</td>
                        <td>
                            <span class="badge ${
                                trade.status === 'won' ? 'bg-success' : 
                                trade.status === 'lost' ? 'bg-danger' : 
                                'bg-warning'
                            }">
                                ${trade.status.toUpperCase()}
                            </span>
                        </td>
                        <td class="text-end ${
                            trade.profit > 0 ? 'text-success' : 
                            trade.profit < 0 ? 'text-danger' : 
                            'text-muted'
                        }">
                            ${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
    
    container.innerHTML = table;
}

/**
 * Load trade history (manual refresh)
 */
async function loadTradeHistory() {
    try {
        const data = await apiCall('/api/realtime.php?action=trades');
        if (data.trades) {
            updateTradeHistory(data.trades);
        }
    } catch (error) {
        console.error('Error loading trade history:', error);
    }
}

/**
 * Add notification
 */
function addNotification(type, message) {
    const container = document.getElementById('notificationsContainer');
    if (!container) return;
    
    const notification = {
        id: Date.now(),
        type: type,
        message: message,
        timestamp: new Date(),
    };
    
    // Get existing notifications
    let notifications = [];
    const existing = container.querySelectorAll('.alert');
    existing.forEach(el => {
        const id = el.getAttribute('data-notification-id');
        if (id) {
            notifications.push({
                id: parseInt(id),
                type: el.classList.contains('alert-success') ? 'success' :
                      el.classList.contains('alert-danger') ? 'error' :
                      el.classList.contains('alert-warning') ? 'warning' : 'info',
                message: el.querySelector('p')?.textContent || '',
            });
        }
    });
    
    // Add new notification
    notifications.unshift(notification);
    
    // Keep only last 10
    if (notifications.length > 10) {
        notifications = notifications.slice(0, 10);
    }
    
    // Update display
    if (notifications.length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3">No notifications</p>';
        return;
    }
    
    const html = notifications.map(notif => {
        const alertClass = {
            success: 'alert-success',
            error: 'alert-danger',
            warning: 'alert-warning',
            info: 'alert-info'
        }[notif.type] || 'alert-info';
        
        return `
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert" data-notification-id="${notif.id}">
                <p class="mb-0">${notif.message}</p>
                <small class="d-block text-muted mt-1">${new Date(notif.timestamp).toLocaleTimeString()}</small>
                <button type="button" class="btn-close" onclick="removeNotification(${notif.id})"></button>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

/**
 * Remove notification
 */
function removeNotification(id) {
    const container = document.getElementById('notificationsContainer');
    if (!container) return;
    
    const notification = container.querySelector(`[data-notification-id="${id}"]`);
    if (notification) {
        notification.remove();
    }
    
    // Update empty state
    if (container.querySelectorAll('.alert').length === 0) {
        container.innerHTML = '<p class="text-muted text-center py-3">No notifications</p>';
    }
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    realtime.stopPolling();
});

