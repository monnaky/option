<?php
/**
 * Trading Page
 * 
 * Dedicated trading interface with real-time updates
 */

require_once __DIR__ . '/app/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication
$user = App\Middleware\AuthMiddleware::requireAuth();

$pageTitle = 'Trading - VTM Option';
include __DIR__ . '/views/includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Page Header -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h1 class="h3 mb-1 fw-bold">
                                <i class="bi bi-graph-up"></i> Trading Interface
                            </h1>
                            <p class="text-secondary-custom mb-0">Real-time trading dashboard</p>
                        </div>
                        <div class="d-flex gap-2 mt-3 mt-md-0">
                            <a href="<?php echo url('index.php'); ?>" class="btn btn-outline-secondary">
                                <i class="bi bi-arrow-left"></i> Back to Dashboard
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Trading Controls -->
        <div class="col-lg-4">
            <!-- Quick Stats -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="bi bi-speedometer2"></i> Quick Stats
                    </h5>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-dark rounded mb-2">
                            <span class="text-secondary-custom">Account Balance</span>
                            <span class="text-primary-custom fw-bold fs-5">
                                $<span id="balance">0.00</span>
                            </span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-dark rounded mb-2">
                            <span class="text-secondary-custom">Today's Profit</span>
                            <span class="text-success fw-bold" id="todayProfit">+$0.00</span>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-dark rounded mb-2">
                            <span class="text-secondary-custom">Today's Loss</span>
                            <span class="text-danger fw-bold" id="todayLoss">-$0.00</span>
                        </div>
                    </div>
                    
                    <div class="pt-3 border-top border-dark-custom">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-primary bg-opacity-10 rounded">
                            <span class="fw-semibold">Net Profit</span>
                            <span class="fs-4 fw-bold" id="netProfit">$0.00</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Bot Status -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-robot"></i> Bot Status
                    </h5>
                    
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <span class="text-secondary-custom">Status:</span>
                        <span id="botStatusBadge" class="badge bg-secondary">Stopped</span>
                    </div>
                    
                    <div class="mb-3">
                        <div class="progress" style="height: 8px;">
                            <div id="botProgress" class="progress-bar" role="progressbar" style="width: 0%"></div>
                        </div>
                        <small class="text-secondary-custom d-block mt-1" id="botProgressText">Inactive</small>
                    </div>
                    
                    <div id="botError" class="alert alert-danger d-none" role="alert"></div>
                </div>
            </div>

            <!-- Current Settings -->
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-gear"></i> Current Settings
                    </h5>
                    
                    <div class="mb-2">
                        <small class="text-secondary-custom">Stake:</small>
                        <div class="fw-bold">$<span id="currentStake">1.00</span></div>
                    </div>
                    
                    <div class="mb-2">
                        <small class="text-secondary-custom">Target:</small>
                        <div class="fw-bold">$<span id="currentTarget">100.00</span></div>
                    </div>
                    
                    <div class="mb-2">
                        <small class="text-secondary-custom">Stop Limit:</small>
                        <div class="fw-bold">$<span id="currentStopLimit">50.00</span></div>
                    </div>
                    
                    <a href="<?php echo url('index.php'); ?>" class="btn btn-outline-primary btn-sm w-100 mt-3">
                        <i class="bi bi-pencil"></i> Edit Settings
                    </a>
                </div>
            </div>
        </div>

        <!-- Main Trading Area -->
        <div class="col-lg-8">
            <!-- Live Trades -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-lightning-charge"></i> Live Trades
                        </h5>
                        <span id="liveTradesCount" class="badge bg-primary">0 Active</span>
                    </div>
                    
                    <div id="liveTradesContainer" class="overflow-auto" style="max-height: 500px;">
                        <div class="text-center py-5 text-secondary-custom">
                            <i class="bi bi-graph-up-arrow fs-1 d-block mb-3"></i>
                            <p>No active trades</p>
                            <small>Active trades will appear here in real-time</small>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Recent Trade History -->
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-4">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-clock-history"></i> Recent Trades
                        </h5>
                        <button class="btn btn-sm btn-outline-secondary" onclick="loadTradeHistory()">
                            <i class="bi bi-arrow-clockwise"></i> Refresh
                        </button>
                    </div>
                    
                    <div id="tradeHistoryContainer" class="overflow-auto" style="max-height: 400px;">
                        <div class="text-center py-5 text-secondary-custom">
                            <div class="spinner-border text-primary mb-3" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                            <p>Loading trade history...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Trading Statistics Chart Area -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="bi bi-bar-chart"></i> Trading Statistics
                    </h5>
                    
                    <div class="row g-4">
                        <div class="col-md-3">
                            <div class="text-center p-3 bg-dark rounded">
                                <div class="text-secondary-custom small mb-1">Total Trades</div>
                                <div class="fs-3 fw-bold" id="totalTrades">0</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 bg-dark rounded">
                                <div class="text-secondary-custom small mb-1">Won Trades</div>
                                <div class="fs-3 fw-bold text-success" id="wonTrades">0</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 bg-dark rounded">
                                <div class="text-secondary-custom small mb-1">Lost Trades</div>
                                <div class="fs-3 fw-bold text-danger" id="lostTrades">0</div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="text-center p-3 bg-dark rounded">
                                <div class="text-secondary-custom small mb-1">Win Rate</div>
                                <div class="fs-3 fw-bold text-primary-custom" id="winRate">0%</div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Real-time JavaScript -->
<?php
if (!function_exists('asset')) {
    require_once __DIR__ . '/app/helpers.php';
}
?>
<script src="<?php echo asset('js/api.js'); ?>"></script>
<script src="<?php echo asset('js/realtime.js'); ?>"></script>

<script>
// Get API base path from config
const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';

// Trading page state
let tradingData = {
    balance: 0.00,
    dailyProfit: 0.00,
    dailyLoss: 0.00,
    isBotActive: false,
    stake: 1.00,
    target: 100.00,
    stopLimit: 50.00,
    trades: [],
    liveTrades: [],
    stats: {
        totalTrades: 0,
        wonTrades: 0,
        lostTrades: 0,
        winRate: 0
    }
};

// Initialize real-time manager for trading page
const tradingRealtime = new RealtimeManager({
    pollInterval: 2000,      // 2 seconds for faster updates
    balanceInterval: 5000,   // 5 seconds for balance
    tradesInterval: 2000,    // 2 seconds for trades
});

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    loadTradingData();
    setupTradingRealtime();
    tradingRealtime.startPolling();
});

// Load trading data
async function loadTradingData() {
    try {
        const data = await apiCall(`${apiBase}/trading.php?action=stats`);
        
        if (data.settings) {
            tradingData.isBotActive = data.settings.is_bot_active || false;
            tradingData.stake = parseFloat(data.settings.stake) || 1.00;
            tradingData.target = parseFloat(data.settings.target) || 100.00;
            tradingData.stopLimit = parseFloat(data.settings.stop_limit) || 50.00;
            tradingData.dailyProfit = parseFloat(data.settings.daily_profit) || 0.00;
            tradingData.dailyLoss = parseFloat(data.settings.daily_loss) || 0.00;
        }
        
        if (data.stats) {
            tradingData.stats = {
                totalTrades: parseInt(data.stats.total_trades) || 0,
                wonTrades: parseInt(data.stats.won_trades) || 0,
                lostTrades: parseInt(data.stats.lost_trades) || 0,
                winRate: parseFloat(data.stats.win_rate) || 0
            };
        }
        
        updateTradingUI();
        
    } catch (error) {
        console.error('Error loading trading data:', error);
        showToast('Failed to load trading data', 'error');
    }
}

// Load trade history
async function loadTradeHistory() {
    try {
        // This would call a dedicated trade history endpoint
        // For now, we'll simulate with empty array
        tradingData.trades = [];
        updateTradeHistory();
        
    } catch (error) {
        console.error('Error loading trade history:', error);
        showToast('Failed to load trade history', 'error');
    }
}

// Update trading UI
function updateTradingUI() {
    // Update balance
    document.getElementById('balance').textContent = tradingData.balance.toFixed(2);
    
    // Update daily stats
    document.getElementById('todayProfit').textContent = `+$${tradingData.dailyProfit.toFixed(2)}`;
    document.getElementById('todayLoss').textContent = `-$${tradingData.dailyLoss.toFixed(2)}`;
    
    const netProfit = tradingData.dailyProfit - tradingData.dailyLoss;
    const netProfitEl = document.getElementById('netProfit');
    netProfitEl.textContent = `${netProfit >= 0 ? '+' : ''}$${netProfit.toFixed(2)}`;
    netProfitEl.className = `fs-4 fw-bold ${netProfit >= 0 ? 'text-success' : 'text-danger'}`;
    
    // Update bot status
    const statusBadge = document.getElementById('botStatusBadge');
    const progressBar = document.getElementById('botProgress');
    const progressText = document.getElementById('botProgressText');
    
    if (tradingData.isBotActive) {
        statusBadge.className = 'badge bg-success';
        statusBadge.textContent = '🟢 Running';
        progressBar.className = 'progress-bar bg-success progress-bar-striped progress-bar-animated';
        progressBar.style.width = '100%';
        progressText.textContent = 'Bot is actively trading';
    } else {
        statusBadge.className = 'badge bg-secondary';
        statusBadge.textContent = '⚫ Stopped';
        progressBar.className = 'progress-bar bg-secondary';
        progressBar.style.width = '0%';
        progressText.textContent = 'Bot is inactive';
    }
    
    // Update current settings
    document.getElementById('currentStake').textContent = tradingData.stake.toFixed(2);
    document.getElementById('currentTarget').textContent = tradingData.target.toFixed(2);
    document.getElementById('currentStopLimit').textContent = tradingData.stopLimit.toFixed(2);
    
    // Update statistics
    document.getElementById('totalTrades').textContent = tradingData.stats.totalTrades;
    document.getElementById('wonTrades').textContent = tradingData.stats.wonTrades;
    document.getElementById('lostTrades').textContent = tradingData.stats.lostTrades;
    document.getElementById('winRate').textContent = `${tradingData.stats.winRate}%`;
}

// Update live trades display
function updateLiveTrades(tradeList) {
    tradingData.liveTrades = tradeList;
    const container = document.getElementById('liveTradesContainer');
    const count = document.getElementById('liveTradesCount');
    
    count.textContent = `${tradeList.length} Active`;
    
    if (tradeList.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5 text-secondary-custom">
                <i class="bi bi-graph-up-arrow fs-1 d-block mb-3"></i>
                <p>No active trades</p>
                <small>Active trades will appear here in real-time</small>
            </div>
        `;
        return;
    }
    
    const html = tradeList.map(trade => `
        <div class="card bg-dark mb-3 border-dark-custom">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-3">
                        <div class="fw-bold">${trade.asset}</div>
                        <small class="text-secondary-custom">${new Date(trade.timestamp).toLocaleTimeString()}</small>
                    </div>
                    <div class="col-md-2">
                        <span class="badge ${trade.direction === 'RISE' ? 'bg-success' : 'bg-danger'}">
                            ${trade.direction}
                        </span>
                    </div>
                    <div class="col-md-2">
                        <small class="text-secondary-custom">Stake:</small>
                        <div class="fw-bold">$${parseFloat(trade.stake).toFixed(2)}</div>
                    </div>
                    <div class="col-md-2">
                        <span class="badge ${
                            trade.status === 'won' ? 'bg-success' : 
                            trade.status === 'lost' ? 'bg-danger' : 
                            'bg-warning'
                        }">
                            ${trade.status === 'pending' ? '⏳ Pending' : trade.status.toUpperCase()}
                        </span>
                    </div>
                    <div class="col-md-3 text-end">
                        <div class="fw-bold fs-5 ${
                            trade.profit > 0 ? 'text-success' : 
                            trade.profit < 0 ? 'text-danger' : 
                            'text-secondary-custom'
                        }">
                            ${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
}

// Update trade history display
function updateTradeHistory() {
    const container = document.getElementById('tradeHistoryContainer');
    
    if (tradingData.trades.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5 text-secondary-custom">
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
                    <th>Status</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                ${tradingData.trades.map(trade => `
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
                            'text-secondary-custom'
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

// Setup real-time event handlers for trading page
function setupTradingRealtime() {
    // Handle status updates
    tradingRealtime.on('status', function(data) {
        if (data.settings) {
            tradingData.isBotActive = data.settings.isBotActive || false;
            tradingData.dailyProfit = parseFloat(data.settings.dailyProfit) || 0;
            tradingData.dailyLoss = parseFloat(data.settings.dailyLoss) || 0;
        }
        
        if (data.stats) {
            tradingData.stats = {
                totalTrades: parseInt(data.stats.totalTrades) || 0,
                wonTrades: parseInt(data.stats.wonTrades) || 0,
                lostTrades: parseInt(data.stats.lostTrades) || 0,
                winRate: parseFloat(data.stats.winRate) || 0
            };
        }
        
        updateTradingUI();
    });
    
    // Handle balance updates
    tradingRealtime.on('balance', function(data) {
        if (data.balance !== undefined) {
            tradingData.balance = parseFloat(data.balance) || 0;
            document.getElementById('balance').textContent = tradingData.balance.toFixed(2);
        }
    });
    
    // Handle trade updates
    tradingRealtime.on('trades', function(data) {
        if (data.liveTrades) {
            updateLiveTrades(data.liveTrades);
        }
        
        if (data.trades) {
            tradingData.trades = data.trades;
            updateTradeHistory();
        }
    });
    
    // Handle comprehensive updates
    tradingRealtime.on('updates', function(data) {
        if (data.settings) {
            tradingData.dailyProfit = parseFloat(data.settings.dailyProfit) || 0;
            tradingData.dailyLoss = parseFloat(data.settings.dailyLoss) || 0;
        }
        
        if (data.balance !== undefined) {
            tradingData.balance = parseFloat(data.balance) || 0;
        }
        
        if (data.liveTrades) {
            updateLiveTrades(data.liveTrades);
        }
        
        if (data.recentTrades) {
            tradingData.trades = data.recentTrades;
            updateTradeHistory();
        }
        
        updateTradingUI();
    });
    
    // Handle trade started
    tradingRealtime.on('trade-started', function(data) {
        const message = `Trade started: ${data.trade.direction} on ${data.trade.asset}`;
        showToast(message, 'info');
        updateLiveTrades([data.trade, ...tradingData.liveTrades].slice(0, 10));
    });
    
    // Handle trade result
    tradingRealtime.on('trade-result', function(data) {
        const status = data.trade.status === 'won' ? 'success' : 'error';
        const message = `Trade ${data.trade.status}: ${data.trade.profit > 0 ? '+' : ''}$${parseFloat(data.trade.profit).toFixed(2)}`;
        showToast(message, status);
        
        // Update live trades
        tradingData.liveTrades = tradingData.liveTrades.filter(t => t.trade_id !== data.trade.trade_id);
        updateLiveTrades(tradingData.liveTrades);
        
        // Reload history
        setTimeout(() => {
            loadTradeHistory();
        }, 1000);
    });
    
    // Handle notifications
    tradingRealtime.on('notification', function(notification) {
        showToast(notification.message, notification.type);
    });
}

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    tradingRealtime.stopPolling();
});
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>

