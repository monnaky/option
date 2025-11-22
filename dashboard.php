<?php
/**
 * Main Dashboard Page
 * 
 * Converted from React DashboardPage.tsx
 */

require_once __DIR__ . '/app/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication
$user = App\Middleware\AuthMiddleware::requireAuth();

$pageTitle = 'Dashboard - VTM Option';
include __DIR__ . '/views/includes/header.php';
?>

<div class="container-fluid py-4">
    <!-- Header Section -->
    <div class="row mb-4">
        <div class="col-12">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <h1 class="h3 mb-1 fw-bold">VTM Option Dashboard</h1>
                            <p class="text-secondary-custom mb-0">
                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($user['email']); ?>
                            </p>
                        </div>
                        <div class="d-flex gap-2 mt-3 mt-md-0">
                            <a href="<?php echo url('profile.php'); ?>" class="btn btn-outline-warning">
                                <i class="bi bi-key"></i> API Token
                            </a>
                            <a href="<?php echo url('logout.php'); ?>" class="btn btn-danger">
                                <i class="bi bi-box-arrow-right"></i> Logout
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Left Panel - Controls -->
        <div class="col-lg-4">
            <!-- Start/Stop Button -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body text-center">
                    <button 
                        id="startStopBtn" 
                        class="btn btn-lg w-100 mb-3"
                        onclick="handleStartStop()"
                    >
                        <span id="startStopText">▶️ START TRADING</span>
                    </button>
                    <div id="startStopLoading" class="d-none">
                        <div class="spinner-border text-light" role="status">
                            <span class="visually-hidden">Loading...</span>
                        </div>
                        <p class="mt-2 text-secondary-custom">Processing...</p>
                    </div>
                    <p id="apiTokenWarning" class="text-warning small d-none">
                        ⚠️ Please connect your Deriv API token first
                    </p>
                </div>
            </div>

            <!-- Status Indicator -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-activity"></i> Trading Status
                    </h5>
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="text-secondary-custom">Status:</span>
                        <span id="botStatus" class="badge bg-secondary">Stopped</span>
                    </div>
                    <div id="botError" class="alert alert-danger mt-3 d-none" role="alert"></div>
                    <div id="botRunning" class="alert alert-success mt-3 d-none" role="alert">
                        Bot is actively trading. Monitoring for trade opportunities...
                    </div>
                </div>
            </div>

            <!-- Balance -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <h6 class="text-secondary-custom text-uppercase small mb-2 fw-semibold">Account Balance</h6>
                    <h2 class="text-primary-custom mb-0">
                        $<span id="balance" data-loading="true">Loading...</span>
                        <small class="text-secondary-custom fs-6">USD</small>
                    </h2>
                    <p id="balanceNote" class="text-secondary-custom small mt-2 d-none">
                        Connect API token to load balance
                    </p>
                </div>
            </div>

            <!-- Today's Performance -->
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h5 class="card-title mb-4">Today's Performance</h5>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-dark rounded">
                            <span class="text-secondary-custom">Daily Profit</span>
                            <span class="text-success fw-bold" id="dailyProfit">+$0.00</span>
                        </div>
                    </div>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center p-2 bg-dark rounded">
                            <span class="text-secondary-custom">Daily Loss</span>
                            <span class="text-danger fw-bold" id="dailyLoss">-$0.00</span>
                        </div>
                    </div>
                    <div class="pt-3 border-top border-dark-custom">
                        <div class="d-flex justify-content-between align-items-center p-3 bg-dark rounded">
                            <span class="fw-semibold">Net Profit</span>
                            <span class="fs-4 fw-bold" id="netProfit">$0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Right Panel - Configuration -->
        <div class="col-lg-8">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h2 class="card-title mb-2">Configuration</h2>
                    <p class="text-secondary-custom small mb-4">Set your trading parameters</p>
                    
                    <form id="settingsForm">
                        <div class="mb-4">
                            <label for="stake" class="form-label fw-semibold text-uppercase small">
                                STAKE ($1 minimum)
                            </label>
                            <input 
                                type="number" 
                                class="form-control form-control-dark" 
                                id="stake" 
                                name="stake"
                                min="1" 
                                step="0.01"
                                value="1.00"
                                required
                            >
                            <div class="invalid-feedback" id="stakeError"></div>
                            <small class="text-secondary-custom">Amount per trade (minimum $1.00)</small>
                        </div>

                        <div class="mb-4">
                            <label for="target" class="form-label fw-semibold text-uppercase small">
                                TARGET (Daily Profit Target)
                            </label>
                            <input 
                                type="number" 
                                class="form-control form-control-dark" 
                                id="target" 
                                name="target"
                                min="0" 
                                step="0.01"
                                value="100.00"
                                required
                            >
                            <div class="invalid-feedback" id="targetError"></div>
                            <small class="text-secondary-custom">Daily profit goal (bot stops when reached)</small>
                        </div>

                        <div class="mb-4">
                            <label for="stopLimit" class="form-label fw-semibold text-uppercase small">
                                STOP LIMIT (Daily Loss Limit)
                            </label>
                            <input 
                                type="number" 
                                class="form-control form-control-dark" 
                                id="stopLimit" 
                                name="stopLimit"
                                min="0" 
                                step="0.01"
                                value="50.00"
                                required
                            >
                            <div class="invalid-feedback" id="stopLimitError"></div>
                            <small class="text-secondary-custom">Maximum daily loss (bot stops when reached)</small>
                        </div>

                        <button 
                            type="submit" 
                            class="btn btn-primary-custom w-100 py-3"
                            id="updateSettingsBtn"
                        >
                            <i class="bi bi-save"></i> Update Settings
                        </button>
                        <p id="settingsWarning" class="text-warning small text-center mt-2 d-none">
                            ⚠️ Stop the bot to update settings
                        </p>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Bottom Section - Live Results and Trade History -->
    <div class="row g-4 mt-2">
        <div class="col-lg-6">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="bi bi-graph-up"></i> Live Trade Results
                        <span id="liveTradesCount" class="badge bg-primary ms-2 d-none">0</span>
                    </h5>
                    <div id="liveTradesContainer" class="overflow-auto" style="max-height: 400px;">
                        <div class="text-center py-5 text-secondary-custom">
                            <i class="bi bi-graph-up-arrow fs-1 d-block mb-3"></i>
                            <p>No active trades</p>
                            <small>Trades will appear here when bot is active</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <i class="bi bi-clock-history"></i> Trade History
                        <span id="tradeHistoryCount" class="badge bg-secondary ms-2">0</span>
                    </h5>
                    <div id="tradeHistoryContainer" class="overflow-auto" style="max-height: 400px;">
                        <div class="text-center py-5 text-secondary-custom">
                            <i class="bi bi-graph-up fs-1 d-block mb-3"></i>
                            <p>No trades yet</p>
                            <small>Trade history will appear here</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Notifications Area -->
    <div class="row mt-4">
        <div class="col-12">
            <div class="card card-dark border-dark-custom">
                <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="card-title mb-0">System Notifications</h5>
                        <button class="btn btn-sm btn-outline-secondary" onclick="clearNotifications()">
                            Clear all
                        </button>
                    </div>
                    <div id="notificationsContainer" class="overflow-auto" style="max-height: 300px;">
                        <p class="text-secondary-custom text-center py-3">No notifications</p>
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
<script src="<?php echo asset('js/balance-manager.js'); ?>"></script>
<script src="<?php echo asset('js/realtime.js'); ?>"></script>
<script src="<?php echo asset('js/dashboard-realtime.js'); ?>"></script>

<script>
// Get API base path from config
const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';

// Global state
let settings = {
    stake: 1.00,
    target: 100.00,
    stopLimit: 50.00,
    isBotActive: false,
    dailyProfit: 0.00,
    dailyLoss: 0.00
};
let hasApiToken = false;
let balance = 0.00;
let trades = [];
let liveTrades = [];
let notifications = [];

// Initialize BalanceManager as single source of truth for balance
let balanceManager = null;

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize BalanceManager FIRST (single source of truth for balance)
    // Optimized for fast initial load
    const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
    balanceManager = new BalanceManager({
        apiBase: apiBase,
        maxRetries: 3, // Reduced for faster failure detection
        retryDelay: 50, // Faster retries (50ms instead of 100ms)
        maxRetryDelay: 1000, // Max 1 second delay (instead of 5s)
        heartbeatInterval: 5000, // Check every 5 seconds
        initialLoadTimeout: 500, // Fast initial load (500ms max wait)
        domReadyTimeout: 2000, // Reduced from 10s to 2s
    });
    
    // Store globally for other scripts
    window.balanceManager = balanceManager;
    
    // Initialize BalanceManager (waits for DOM, finds elements, starts heartbeat)
    balanceManager.initialize().then(() => {
        console.log('[Dashboard] BalanceManager initialized successfully');
    }).catch(error => {
        console.error('[Dashboard] BalanceManager initialization failed:', error);
    });
    
    loadProfile();
    loadTradeHistory();
    
    // Settings form handler
    document.getElementById('settingsForm').addEventListener('submit', handleUpdateSettings);
});

// Load user profile and settings
async function loadProfile() {
    try {
        const data = await apiCall(`${apiBase}/trading.php?action=stats`);
        
        if (data.settings) {
            settings = {
                stake: parseFloat(data.settings.stake) || 1.00,
                target: parseFloat(data.settings.target) || 100.00,
                stopLimit: parseFloat(data.settings.stop_limit) || 50.00,
                isBotActive: data.settings.is_bot_active || false,
                dailyProfit: parseFloat(data.settings.daily_profit) || 0.00,
                dailyLoss: parseFloat(data.settings.daily_loss) || 0.00
            };
            
            // Update form fields
            document.getElementById('stake').value = settings.stake;
            document.getElementById('target').value = settings.target;
            document.getElementById('stopLimit').value = settings.stopLimit;
            
            // Update UI
            updateUI();
        }
        
        if (data.stats) {
            // Update stats display if needed
        }
        
    } catch (error) {
        console.error('Error loading profile:', error);
        showToast('Failed to load profile', 'error');
    }
}

// Load trade history
async function loadTradeHistory() {
    try {
        // This would call a trade history endpoint
        // For now, we'll use the stats endpoint
        const data = await apiCall(`${apiBase}/trading.php?action=stats`);
        
        // Update trade history display
        updateTradeHistory([]);
    } catch (error) {
        console.error('Error loading trade history:', error);
    }
}

// Start/Stop trading bot
async function handleStartStop() {
    const btn = document.getElementById('startStopBtn');
    const loading = document.getElementById('startStopLoading');
    const text = document.getElementById('startStopText');
    
    btn.disabled = true;
    loading.classList.remove('d-none');
    text.classList.add('d-none');
    
    try {
        const action = settings.isBotActive ? 'stop' : 'start';
        const endpoint = `${apiBase}/trading.php?action=${action}`;
        
        await apiCall(endpoint, {
            method: 'POST'
        });
        
        settings.isBotActive = !settings.isBotActive;
        updateUI();
        
        showToast(`Trading bot ${settings.isBotActive ? 'started' : 'stopped'} successfully`, 'success');
        
        // Reload profile to get latest settings
        await loadProfile();
        
    } catch (error) {
        const errorMessage = error.message || 'Failed to toggle bot';
        showToast(errorMessage, 'error');
        
        // Only redirect to profile page if the error is about missing API token
        if (errorMessage.toLowerCase().includes('api token') || 
            errorMessage.toLowerCase().includes('token not found') ||
            errorMessage.toLowerCase().includes('connect your deriv')) {
            setTimeout(() => {
                window.location.href = '<?php echo url("profile.php"); ?>';
            }, 2000);
        }
    } finally {
        btn.disabled = false;
        loading.classList.add('d-none');
        text.classList.remove('d-none');
    }
}

// Update settings
async function handleUpdateSettings(e) {
    e.preventDefault();
    
    if (settings.isBotActive) {
        showToast('Cannot update settings while bot is active. Please stop the bot first.', 'error');
        return;
    }
    
    const stake = parseFloat(document.getElementById('stake').value);
    const target = parseFloat(document.getElementById('target').value);
    const stopLimit = parseFloat(document.getElementById('stopLimit').value);
    
    // Validate
    if (stake < 1) {
        showToast('Stake must be at least $1', 'error');
        return;
    }
    
    const btn = document.getElementById('updateSettingsBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Updating...';
    
    try {
        // Update via API
        await apiCall(`${apiBase}/trading.php?action=update-settings`, 'POST', {
            stake: stake,
            target: target,
            stopLimit: stopLimit
        });
        
        settings.stake = stake;
        settings.target = target;
        settings.stopLimit = stopLimit;
        
        showToast('Settings updated successfully', 'success');
        await loadProfile();
        
    } catch (error) {
        showToast(error.message || 'Failed to update settings', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-save"></i> Update Settings';
    }
}

// Update UI elements
function updateUI() {
    // Update start/stop button
    const btn = document.getElementById('startStopBtn');
    const text = document.getElementById('startStopText');
    
    if (settings.isBotActive) {
        btn.className = 'btn btn-danger btn-lg w-100 mb-3';
        text.textContent = '🛑 STOP TRADING';
    } else {
        btn.className = 'btn btn-success btn-lg w-100 mb-3';
        text.textContent = '▶️ START TRADING';
    }
    
    // Update bot status
    const statusBadge = document.getElementById('botStatus');
    const botError = document.getElementById('botError');
    const botRunning = document.getElementById('botRunning');
    
    if (settings.isBotActive) {
        statusBadge.className = 'badge bg-success';
        statusBadge.textContent = '🟢 Running';
        botRunning.classList.remove('d-none');
        botError.classList.add('d-none');
    } else {
        statusBadge.className = 'badge bg-secondary';
        statusBadge.textContent = '⚫ Stopped';
        botRunning.classList.add('d-none');
    }
    
    // Update daily stats
    document.getElementById('dailyProfit').textContent = `+$${settings.dailyProfit.toFixed(2)}`;
    document.getElementById('dailyLoss').textContent = `-$${settings.dailyLoss.toFixed(2)}`;
    
    const netProfit = settings.dailyProfit - settings.dailyLoss;
    const netProfitEl = document.getElementById('netProfit');
    netProfitEl.textContent = `${netProfit >= 0 ? '+' : ''}$${netProfit.toFixed(2)}`;
    netProfitEl.className = `fs-4 fw-bold ${netProfit >= 0 ? 'text-success' : 'text-danger'}`;
    
    // Update settings form disabled state
    const inputs = ['stake', 'stopLimit'];
    inputs.forEach(id => {
        document.getElementById(id).disabled = settings.isBotActive;
    });
    
    const warning = document.getElementById('settingsWarning');
    if (settings.isBotActive) {
        warning.classList.remove('d-none');
    } else {
        warning.classList.add('d-none');
    }
}

// Update trade history display
function updateTradeHistory(tradeList) {
    trades = tradeList;
    const container = document.getElementById('tradeHistoryContainer');
    const count = document.getElementById('tradeHistoryCount');
    
    count.textContent = trades.length;
    
    if (trades.length === 0) {
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
                    <th>Result</th>
                    <th class="text-end">Profit</th>
                </tr>
            </thead>
            <tbody>
                ${trades.map(trade => `
                    <tr>
                        <td>${new Date(trade.timestamp).toLocaleString()}</td>
                        <td>${trade.asset}</td>
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

// Update live trades display
function updateLiveTrades(tradeList) {
    liveTrades = tradeList;
    const container = document.getElementById('liveTradesContainer');
    const count = document.getElementById('liveTradesCount');
    
    if (liveTrades.length > 0) {
        count.textContent = liveTrades.length;
        count.classList.remove('d-none');
    } else {
        count.classList.add('d-none');
    }
    
    if (liveTrades.length === 0) {
        container.innerHTML = `
            <div class="text-center py-5 text-secondary-custom">
                <i class="bi bi-graph-up-arrow fs-1 d-block mb-3"></i>
                <p>No active trades</p>
                <small>Trades will appear here when bot is active</small>
            </div>
        `;
        return;
    }
    
    const html = liveTrades.map(trade => `
        <div class="card bg-dark mb-2 border-dark-custom">
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
                    <small class="text-secondary-custom">Stake: <span>$${parseFloat(trade.stake).toFixed(2)}</span></small>
                    <span class="fw-bold ${
                        trade.profit > 0 ? 'text-success' : 
                        trade.profit < 0 ? 'text-danger' : 
                        'text-secondary-custom'
                    }">
                        ${trade.profit > 0 ? '+' : ''}$${parseFloat(trade.profit).toFixed(2)}
                    </span>
                </div>
            </div>
        </div>
    `).join('');
    
    container.innerHTML = html;
}

// Add notification
function addNotification(type, message) {
    notifications.unshift({
        id: Date.now(),
        type: type,
        message: message,
        timestamp: new Date()
    });
    
    // Keep only last 10
    if (notifications.length > 10) {
        notifications = notifications.slice(0, 10);
    }
    
    updateNotifications();
}

// Update notifications display
function updateNotifications() {
    const container = document.getElementById('notificationsContainer');
    
    if (notifications.length === 0) {
        container.innerHTML = '<p class="text-secondary-custom text-center py-3">No notifications</p>';
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
            <div class="alert ${alertClass} alert-dismissible fade show" role="alert">
                ${notif.message}
                <small class="d-block text-secondary-custom mt-1">${notif.timestamp.toLocaleTimeString()}</small>
                <button type="button" class="btn-close" onclick="removeNotification(${notif.id})"></button>
            </div>
        `;
    }).join('');
    
    container.innerHTML = html;
}

// Remove notification
function removeNotification(id) {
    notifications = notifications.filter(n => n.id !== id);
    updateNotifications();
}

// Clear all notifications
function clearNotifications() {
    notifications = [];
    updateNotifications();
}

// Real-time updates are handled by dashboard-realtime.js
// No need for manual polling here
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>

