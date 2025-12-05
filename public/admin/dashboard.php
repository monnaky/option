<?php
$pageTitle = 'Dashboard';
require_once __DIR__ . '/../../views/includes/admin-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-speedometer2"></i> Admin Dashboard
    </h1>
    <div>
        <button class="btn btn-sm btn-outline-primary" onclick="refreshStats()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4" id="statsCards">
    <!-- Stats will be loaded here -->
    <div class="col-12 text-center py-5">
        <div class="spinner-border text-primary" role="status">
            <span class="visually-hidden">Loading...</span>
        </div>
    </div>
</div>

<!-- Recent Activity -->
<div class="row g-3">
    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-activity"></i> Recent Activity (24h)</h5>
            </div>
            <div class="card-body" id="recentActivity">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-graph-up-arrow"></i> System Health</h5>
            </div>
            <div class="card-body" id="systemHealth">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let statsInterval;

async function loadStats() {
    try {
        const data = await apiCall('/api/admin.php?action=stats');
        
        // Update stats cards
        const statsHtml = `
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total Users</div>
                    <div class="stat-value text-primary">${data.users.total}</div>
                    <small class="text-muted">${data.users.active} active</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Active Trading</div>
                    <div class="stat-value text-success">${data.users.active_trading}</div>
                    <small class="text-muted">Users trading now</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total Trades</div>
                    <div class="stat-value text-info">${data.trades.total.toLocaleString()}</div>
                    <small class="text-muted">${data.trades.win_rate}% win rate</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Net Profit</div>
                    <div class="stat-value ${parseFloat(data.profit.net_profit.replace(/,/g, '')) >= 0 ? 'text-success' : 'text-danger'}">
                        $${data.profit.net_profit}
                    </div>
                    <small class="text-muted">All time</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Active Sessions</div>
                    <div class="stat-value text-warning">${data.sessions.active}</div>
                    <small class="text-muted">Running now</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total Signals</div>
                    <div class="stat-value text-info">${data.signals.total_signals}</div>
                    <small class="text-muted">${data.signals.processed_signals} processed</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Pending Trades</div>
                    <div class="stat-value text-warning">${data.trades.pending}</div>
                    <small class="text-muted">Awaiting results</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Success Rate</div>
                    <div class="stat-value text-success">${data.signals.success_rate}%</div>
                    <small class="text-muted">Signal execution</small>
                </div>
            </div>
        `;
        document.getElementById('statsCards').innerHTML = statsHtml;
        
        // Update recent activity
        const recentHtml = `
            <div class="d-flex justify-content-between mb-2">
                <span>Trades (24h):</span>
                <strong>${data.recent_activity.trades_24h}</strong>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span>Signals (24h):</span>
                <strong>${data.recent_activity.signals_24h}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Won Trades:</span>
                <strong class="text-success">${data.trades.won}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Lost Trades:</span>
                <strong class="text-danger">${data.trades.lost}</strong>
            </div>
        `;
        document.getElementById('recentActivity').innerHTML = recentHtml;
        
    } catch (error) {
        console.error('Error loading stats:', error);
        document.getElementById('statsCards').innerHTML = 
            '<div class="col-12"><div class="alert alert-danger">Failed to load statistics</div></div>';
    }
}

async function loadSystemHealth() {
    try {
        const data = await apiCall('/api/admin.php?action=system');
        
        const healthHtml = `
            <div class="d-flex justify-content-between mb-2">
                <span>Database:</span>
                <span class="badge ${data.database.status === 'online' ? 'bg-success' : 'bg-danger'}">
                    ${data.database.status}
                </span>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span>PHP Version:</span>
                <strong>${data.server.php_version}</strong>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span>Cron Jobs:</span>
                <span class="badge ${data.cron.active ? 'bg-success' : 'bg-warning'}">
                    ${data.cron.active ? 'Active' : 'Inactive'}
                </span>
            </div>
            <div class="d-flex justify-content-between">
                <span>Server Time:</span>
                <strong>${data.server.server_time}</strong>
            </div>
        `;
        document.getElementById('systemHealth').innerHTML = healthHtml;
        
    } catch (error) {
        console.error('Error loading system health:', error);
    }
}

function refreshStats() {
    loadStats();
    loadSystemHealth();
}

// Load initial data
loadStats();
loadSystemHealth();

// Auto-refresh every 30 seconds
statsInterval = setInterval(() => {
    loadStats();
    loadSystemHealth();
}, 30000);

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (statsInterval) {
        clearInterval(statsInterval);
    }
});
</script>

<?php
$adminScripts = [];
require_once __DIR__ . '/../../views/includes/admin-footer.php';
?>

