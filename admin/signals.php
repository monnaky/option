<?php
$pageTitle = 'Signal Monitoring';
require_once __DIR__ . '/../views/includes/admin-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-broadcast"></i> Signal Monitoring
    </h1>
    <div>
        <button class="btn btn-sm btn-outline-primary" onclick="refreshSignals()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<!-- Signal Statistics -->
<div class="row g-3 mb-4" id="signalStats">
    <!-- Stats will be loaded here -->
</div>

<div class="card card-dark">
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-dark-custom table-hover" id="signalsTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Type</th>
                        <th>Asset</th>
                        <th>Source</th>
                        <th>Total Users</th>
                        <th>Successful</th>
                        <th>Failed</th>
                        <th>Success Rate</th>
                        <th>Execution Time</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody id="signalsTableBody">
                    <tr>
                        <td colspan="11" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status">
                                <span class="visually-hidden">Loading...</span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
let signalsInterval;

async function loadSignals() {
    try {
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        const data = await apiCall(`${apiBase}/admin.php?action=signals&limit=50`);
        
        const tbody = document.getElementById('signalsTableBody');
        
        if (data.signals.length === 0) {
            tbody.innerHTML = '<tr><td colspan="11" class="text-center py-4">No signals found</td></tr>';
            return;
        }
        
        tbody.innerHTML = data.signals.map(signal => {
            const successRate = signal.total_users > 0 
                ? ((signal.successful_executions / signal.total_users) * 100).toFixed(1)
                : '0.0';
            
            return `
                <tr>
                    <td>${signal.id}</td>
                    <td>
                        <span class="badge ${signal.signal_type === 'RISE' ? 'bg-success' : 'bg-danger'}">
                            ${signal.signal_type}
                        </span>
                    </td>
                    <td>${signal.asset || 'Any'}</td>
                    <td>
                        <span class="badge bg-secondary">${signal.source}</span>
                    </td>
                    <td>${signal.total_users}</td>
                    <td class="text-success">${signal.successful_executions}</td>
                    <td class="text-danger">${signal.failed_executions}</td>
                    <td>
                        <span class="badge ${parseFloat(successRate) >= 80 ? 'bg-success' : parseFloat(successRate) >= 50 ? 'bg-warning' : 'bg-danger'}">
                            ${successRate}%
                        </span>
                    </td>
                    <td>${signal.execution_time ? signal.execution_time + 'ms' : 'N/A'}</td>
                    <td>
                        <span class="badge ${signal.processed ? 'bg-success' : 'bg-warning'}">
                            ${signal.processed ? 'Processed' : 'Pending'}
                        </span>
                    </td>
                    <td>${new Date(signal.timestamp).toLocaleString()}</td>
                </tr>
            `;
        }).join('');
        
    } catch (error) {
        console.error('Error loading signals:', error);
        document.getElementById('signalsTableBody').innerHTML = 
            '<tr><td colspan="11" class="text-center text-danger py-4">Failed to load signals</td></tr>';
    }
}

async function loadSignalStats() {
    try {
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        const statsData = await apiCall(`${apiBase}/admin.php?action=stats`);
        const stats = statsData.signals;
        
        const statsHtml = `
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Total Signals</div>
                    <div class="stat-value text-primary">${stats.total_signals}</div>
                    <small class="text-muted">${stats.processed_signals} processed</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Pending Signals</div>
                    <div class="stat-value text-warning">${stats.pending_signals}</div>
                    <small class="text-muted">Awaiting processing</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Success Rate</div>
                    <div class="stat-value text-success">${stats.success_rate}%</div>
                    <small class="text-muted">Execution success</small>
                </div>
            </div>
            <div class="col-md-3">
                <div class="stat-card">
                    <div class="stat-label">Avg Execution</div>
                    <div class="stat-value text-info">${stats.avg_execution_time_ms}ms</div>
                    <small class="text-muted">Per signal</small>
                </div>
            </div>
        `;
        document.getElementById('signalStats').innerHTML = statsHtml;
        
    } catch (error) {
        console.error('Error loading signal stats:', error);
    }
}

function refreshSignals() {
    loadSignals();
    loadSignalStats();
}

// Load initial data
loadSignals();
loadSignalStats();

// Auto-refresh every 30 seconds
signalsInterval = setInterval(() => {
    loadSignals();
    loadSignalStats();
}, 30000);

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (signalsInterval) {
        clearInterval(signalsInterval);
    }
});
</script>

<?php
$adminScripts = [];
require_once __DIR__ . '/../views/includes/admin-footer.php';
?>

