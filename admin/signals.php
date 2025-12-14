<?php
$pageTitle = 'Signal Manager';
require_once __DIR__ . '/../views/includes/admin-header.php';

use App\Services\SignalService;
use App\Services\QueueService;
use App\Jobs\ProcessSignal;

$signalService = SignalService::getInstance();
$queueService = QueueService::getInstance();

$message = '';
$error = '';

// Handle Manual Signal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'send_signal') {
    $type = $_POST['type'] ?? '';
    $asset = $_POST['asset'] ?? '';
    
    if ($type && $asset) {
        try {
            // Create signal
            $result = $signalService->receiveSignal([
                'type' => $type,
                'asset' => $asset,
                'source' => 'admin_manual',
                'skip_execution' => true,
            ]);
            
            if ($result['success']) {
                // Push to queue
                $queueService->push(ProcessSignal::class, [
                    'signal_id' => $result['signal_id'],
                    'signal_type' => $result['signal_type'],
                    'asset' => $result['asset'],
                ]);
                $message = "Signal queued successfully! ID: " . $result['signal_id'];
            } else {
                $error = "Error: " . $result['error'];
            }
        } catch (Exception $e) {
            $error = "Exception: " . $e->getMessage();
        }
    }
}

// Get recent signals
$signals = [];
$historyError = '';
try {
    $signals = $signalService->getSignalHistory(20);
} catch (Exception $e) {
    $historyError = $e->getMessage();
}
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h3 mb-0">
        <i class="bi bi-broadcast"></i> Signal Manager
    </h1>
    <div>
        <button class="btn btn-sm btn-outline-primary" onclick="location.reload()">
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
                    <label class="form-label text-muted">Duration</label>
                    <input type="number" class="form-control bg-dark text-light border-secondary" id="tradeDuration" min="1" value="5" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label text-muted">Unit</label>
                    <select class="form-select bg-dark text-light border-secondary" id="tradeDurationUnit" required>
                        <option value="t">Ticks</option>
                        <option value="s">Seconds</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label text-muted">User ID (optional)</label>
                    <input type="number" class="form-control bg-dark text-light border-secondary" id="tradeDurationUserId" min="1" placeholder="Apply to one user">
                </div>
                <div class="col-md-3">
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="tradeDurationApplyAll" checked>
                        <label class="form-check-label text-muted" for="tradeDurationApplyAll">Apply to all users</label>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        Save
                    </button>
                </div>
            </div>
            <div class="form-text text-muted mt-2">Safety limits: ticks max 10, seconds max 300.</div>
        </form>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="bi bi-check-circle me-2"></i> <?= htmlspecialchars($message) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-triangle me-2"></i> <?= htmlspecialchars($error) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<?php if ($historyError): ?>
    <div class="alert alert-warning alert-dismissible fade show" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i> Error loading history: <?= htmlspecialchars($historyError) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="row g-4">
    <!-- Manual Signal Form -->
    <div class="col-md-4">
        <div class="card card-dark h-100">
            <div class="card-header bg-dark border-dark-custom">
                <h5 class="mb-0"><i class="bi bi-send"></i> Send Manual Signal</h5>
            </div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="action" value="send_signal">
                    <div class="mb-3">
                        <label class="form-label text-muted">Signal Type</label>
                        <select name="type" class="form-select bg-dark text-light border-secondary" required>
                            <option value="RISE">RISE (Buy/Call)</option>
                            <option value="FALL">FALL (Sell/Put)</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label text-muted">Asset Symbol</label>
                        <input type="text" name="asset" class="form-control bg-dark text-light border-secondary" placeholder="e.g. R_100" required>
                        <div class="form-text text-muted">Enter the exact symbol name (e.g. R_100, EURUSD)</div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="bi bi-lightning-charge"></i> Queue Signal
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Signal History -->
    <div class="col-md-8">
        <div class="card card-dark h-100">
            <div class="card-header bg-dark border-dark-custom d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clock-history"></i> Recent Signals</h5>
                <span class="badge bg-secondary" id="signalCount">0 total</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-dark-custom table-hover mb-0 align-middle">
                        <thead>
                            <tr>
                                <th class="ps-4">ID</th>
                                <th>Type</th>
                                <th>Asset</th>
                                <th>Source</th>
                                <th>Status</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody id="signalsTableBody">
                            <tr>
                                <td colspan="6" class="text-center py-5">
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
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        const body = {
            tradeDuration: duration,
            tradeDurationUnit: unit,
            apply_to_all: applyToAll,
        };

        if (!applyToAll) {
            body.user_id = userId;
        }

        const res = await apiCall(`${apiBase}/admin.php?action=update-trade-duration`, {
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
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        const data = await apiCall(`${apiBase}/admin.php?action=signals&limit=20`);
        
        const tbody = document.getElementById('signalsTableBody');
        document.getElementById('signalCount').textContent = `${data.total} total`;
        
        if (!data.signals || data.signals.length === 0) {
            tbody.innerHTML = `
                <tr>
                    <td colspan="6" class="text-center py-5 text-muted">
                        <i class="bi bi-inbox display-6 d-block mb-3"></i>
                        No signals found recently.
                    </td>
                </tr>`;
            return;
        }
        
        tbody.innerHTML = data.signals.map(signal => `
            <tr>
                <td class="ps-4">#${signal.id}</td>
                <td>
                    <span class="badge ${signal.signal_type === 'RISE' ? 'bg-success bg-opacity-10 text-success border border-success border-opacity-25' : 'bg-danger bg-opacity-10 text-danger border border-danger border-opacity-25'}">
                        <i class="bi bi-graph-${signal.signal_type === 'RISE' ? 'up' : 'down'}-arrow"></i> ${signal.signal_type}
                    </span>
                </td>
                <td class="fw-bold">${escapeHtml(signal.asset)}</td>
                <td>
                    <small class="text-muted">
                        <i class="bi bi-hdd-network"></i> ${escapeHtml(signal.source)}
                    </small>
                </td>
                <td>
                    <span class="badge ${signal.processed ? 'bg-success' : 'bg-warning text-dark'}">
                        <i class="bi bi-${signal.processed ? 'check-lg' : 'hourglass-split'}"></i> ${signal.processed ? 'Processed' : 'Pending'}
                    </span>
                </td>
                <td class="text-muted small">
                    ${new Date(signal.timestamp).toLocaleString()}
                </td>
            </tr>
        `).join('');
        
    } catch (error) {
        console.error('Error loading signals:', error);
        document.getElementById('signalsTableBody').innerHTML = 
            '<tr><td colspan="6" class="text-center text-danger py-5">Failed to load signals</td></tr>';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
}

// Load initial data when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    loadSignals();

    // Auto-refresh every 10 seconds (faster than others for real-time feel)
    signalsInterval = setInterval(() => {
        loadSignals();
    }, 10000);
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (signalsInterval) {
        clearInterval(signalsInterval);
    }
});
</script>

<?php
require_once __DIR__ . '/../views/includes/admin-footer.php';
?>
