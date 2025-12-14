<?php
$pageTitle = 'Signal Monitoring';
require_once __DIR__ . '/../../views/includes/admin-header.php';
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

<div class="card card-dark mb-4">
    <div class="card-header bg-dark border-dark-custom">
        <h5 class="mb-0"><i class="bi bi-sliders"></i> Trade Duration Control</h5>
    </div>
    <div class="card-body">
        <form onsubmit="updateTradeDuration(event)">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Duration</label>
                    <input type="number" class="form-control form-control-dark" id="tradeDuration" min="1" value="5" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <select class="form-select form-control-dark" id="tradeDurationUnit" required>
                        <option value="t">Ticks</option>
                        <option value="s">Seconds</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">User ID (optional)</label>
                    <input type="number" class="form-control form-control-dark" id="tradeDurationUserId" min="1" placeholder="Apply to one user">
                </div>
                <div class="col-md-3">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="tradeDurationApplyAll" checked>
                        <label class="form-check-label" for="tradeDurationApplyAll">Apply to all users</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        Save
                    </button>
                </div>
            </div>
            <small class="text-muted d-block mt-2">
                Safety limits: ticks max 10, seconds max 300.
            </small>
        </form>
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

async function updateTradeDuration(e) {
    e.preventDefault();

    const duration = parseInt(document.getElementById('tradeDuration').value, 10);
    const unit = document.getElementById('tradeDurationUnit').value;
    const applyToAll = document.getElementById('tradeDurationApplyAll').checked;
    const userIdRaw = document.getElementById('tradeDurationUserId').value;
    const userId = userIdRaw ? parseInt(userIdRaw, 10) : null;

    if (!applyToAll && (!userId || userId <= 0)) {
        alert('Provide a User ID or enable Apply to all users');
        return;
    }

    try {
        const body = {
            tradeDuration: duration,
            tradeDurationUnit: unit,
            apply_to_all: applyToAll,
        };

        if (!applyToAll) {
            body.user_id = userId;
        }

        const res = await apiCall('/api/admin.php?action=update-trade-duration', {
            method: 'POST',
            body,
        });

        alert(`Trade duration updated: ${res.trade_duration} ${res.trade_duration_unit} (updated_users=${res.updated_users})`);
    } catch (error) {
        alert('Failed to update trade duration: ' + (error.message || error));
    }
}

async function loadSignals() {
    try {
        const data = await apiCall('/api/admin.php?action=signals&limit=50');
        
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
        const statsData = await apiCall('/api/admin.php?action=stats');
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
require_once __DIR__ . '/../../views/includes/admin-footer.php';
?>

