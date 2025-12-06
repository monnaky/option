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
