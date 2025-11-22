<?php
$pageTitle = 'System Health';
require_once __DIR__ . '/../../views/includes/admin-header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-gear"></i> System Health & Logs
    </h1>
    <div>
        <button class="btn btn-sm btn-outline-primary" onclick="refreshSystem()">
            <i class="bi bi-arrow-clockwise"></i> Refresh
        </button>
    </div>
</div>

<div class="row g-3">
    <!-- System Status -->
    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-activity"></i> System Status</h5>
            </div>
            <div class="card-body" id="systemStatus">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Server Information -->
    <div class="col-md-6">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-server"></i> Server Information</h5>
            </div>
            <div class="card-body" id="serverInfo">
                <div class="text-center py-3">
                    <div class="spinner-border spinner-border-sm text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cron Jobs Status -->
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Cron Jobs Status</h5>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-dark-custom">
                        <thead>
                            <tr>
                                <th>Job</th>
                                <th>Schedule</th>
                                <th>Status</th>
                                <th>Last Run</th>
                                <th>Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>trading_loop.php</code></td>
                                <td>Every minute</td>
                                <td><span class="badge bg-success" id="cronTradingStatus">Active</span></td>
                                <td id="cronTradingLastRun">-</td>
                                <td>Processes trading loop for all active users</td>
                            </tr>
                            <tr>
                                <td><code>contract_monitor.php</code></td>
                                <td>Every minute</td>
                                <td><span class="badge bg-success" id="cronContractStatus">Active</span></td>
                                <td id="cronContractLastRun">-</td>
                                <td>Monitors and processes contract results</td>
                            </tr>
                            <tr>
                                <td><code>signal_processor.php</code></td>
                                <td>Every minute</td>
                                <td><span class="badge bg-success" id="cronSignalStatus">Active</span></td>
                                <td id="cronSignalLastRun">-</td>
                                <td>Processes unprocessed signals from queue</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
    
    <!-- System Logs -->
    <div class="col-md-12">
        <div class="card card-dark">
            <div class="card-header bg-dark border-dark-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-file-text"></i> Recent System Activity</h5>
                <small class="text-muted">Last 24 hours</small>
            </div>
            <div class="card-body">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i> 
                    System logs are stored in the server error log. Check your hosting control panel or server logs for detailed information.
                </div>
                <div id="systemLogs">
                    <p class="text-muted">Log viewing requires server access. Check your hosting control panel.</p>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
let systemInterval;

async function loadSystemInfo() {
    try {
        const data = await apiCall('/api/admin.php?action=system');
        
        // System Status
        const statusHtml = `
            <div class="d-flex justify-content-between mb-3">
                <span>Database:</span>
                <span class="badge ${data.database.status === 'online' ? 'bg-success' : 'bg-danger'} badge-custom">
                    <i class="bi bi-${data.database.status === 'online' ? 'check-circle' : 'x-circle'}"></i>
                    ${data.database.status.toUpperCase()}
                </span>
            </div>
            <div class="d-flex justify-content-between mb-3">
                <span>Cron Jobs:</span>
                <span class="badge ${data.cron.active ? 'bg-success' : 'bg-warning'} badge-custom">
                    <i class="bi bi-${data.cron.active ? 'check-circle' : 'exclamation-triangle'}"></i>
                    ${data.cron.active ? 'Active' : 'Inactive'}
                </span>
            </div>
            <div class="d-flex justify-content-between">
                <span>Overall Status:</span>
                <span class="badge ${data.database.status === 'online' && data.cron.active ? 'bg-success' : 'bg-warning'} badge-custom">
                    ${data.database.status === 'online' && data.cron.active ? 'Healthy' : 'Warning'}
                </span>
            </div>
        `;
        document.getElementById('systemStatus').innerHTML = statusHtml;
        
        // Server Info
        const serverHtml = `
            <div class="d-flex justify-content-between mb-2">
                <span>PHP Version:</span>
                <strong>${data.server.php_version}</strong>
            </div>
            <div class="d-flex justify-content-between mb-2">
                <span>Server Software:</span>
                <strong>${data.server.server_software}</strong>
            </div>
            <div class="d-flex justify-content-between">
                <span>Server Time:</span>
                <strong>${data.server.server_time}</strong>
            </div>
        `;
        document.getElementById('serverInfo').innerHTML = serverHtml;
        
        // Update cron status
        if (data.cron.active) {
            document.getElementById('cronTradingStatus').className = 'badge bg-success';
            document.getElementById('cronTradingStatus').textContent = 'Active';
            document.getElementById('cronContractStatus').className = 'badge bg-success';
            document.getElementById('cronContractStatus').textContent = 'Active';
            document.getElementById('cronSignalStatus').className = 'badge bg-success';
            document.getElementById('cronSignalStatus').textContent = 'Active';
        } else {
            document.getElementById('cronTradingStatus').className = 'badge bg-warning';
            document.getElementById('cronTradingStatus').textContent = 'Inactive';
            document.getElementById('cronContractStatus').className = 'badge bg-warning';
            document.getElementById('cronContractStatus').textContent = 'Inactive';
            document.getElementById('cronSignalStatus').className = 'badge bg-warning';
            document.getElementById('cronSignalStatus').textContent = 'Inactive';
        }
        
    } catch (error) {
        console.error('Error loading system info:', error);
        document.getElementById('systemStatus').innerHTML = 
            '<div class="alert alert-danger">Failed to load system information</div>';
    }
}

function refreshSystem() {
    loadSystemInfo();
}

// Load initial data
loadSystemInfo();

// Auto-refresh every 60 seconds
systemInterval = setInterval(() => {
    loadSystemInfo();
}, 60000);

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (systemInterval) {
        clearInterval(systemInterval);
    }
});
</script>

<?php
$adminScripts = [];
require_once __DIR__ . '/../../views/includes/admin-footer.php';
?>

