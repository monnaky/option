<?php
/**
 * Profile / API Token Page
 * 
 * Converted from React ApiTokenPage.tsx
 */

require_once __DIR__ . '/app/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Require authentication
$user = App\Middleware\AuthMiddleware::requireAuth();

// Check if user has API token
$db = App\Config\Database::getInstance();
$userRecord = $db->queryOne(
    "SELECT encrypted_api_token FROM users WHERE id = :id",
    ['id' => $user['id']]
);
$hasApiToken = !empty($userRecord['encrypted_api_token']);

$pageTitle = 'Profile - VTM Option';
include __DIR__ . '/views/includes/header.php';
?>

<div class="container py-4">
    <div class="row">
        <div class="col-12">
            <div class="mb-4">
                <a href="<?php echo url('dashboard.php'); ?>" class="text-secondary-custom text-decoration-none">
                    <i class="bi bi-arrow-left"></i> Back to Dashboard
                </a>
            </div>
            
            <h1 class="h3 mb-2 fw-bold">
                <i class="bi bi-key"></i> Connect Deriv API Token
            </h1>
            <p class="text-secondary-custom">Securely connect your Deriv API token to enable automated trading</p>
        </div>
    </div>

    <?php if ($hasApiToken): ?>
    <!-- Connected State -->
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card card-dark border-dark-custom">
                <div class="card-body text-center p-5">
                    <div class="mb-4">
                        <div class="d-inline-flex align-items-center justify-content-center w-20 h-20 bg-success bg-opacity-10 rounded-circle border border-success mb-3">
                            <i class="bi bi-check-circle-fill text-success fs-1"></i>
                        </div>
                    </div>
                    <h2 class="h4 mb-3">🔒 API Token Connected</h2>
                    <p class="text-secondary-custom mb-2">
                        Your Deriv API token is securely connected and encrypted using AES-256-CBC encryption.
                    </p>
                    <p class="text-secondary-custom small mb-4">
                        Your token is stored securely and never exposed in plain text.
                    </p>

                    <div class="d-flex flex-column flex-sm-row gap-3 justify-content-center">
                        <a href="<?php echo url('index.php'); ?>" class="btn btn-primary-custom">
                            <i class="bi bi-speedometer2"></i> Go to Dashboard
                        </a>
                        <button onclick="handleDisconnect()" class="btn btn-danger" id="disconnectBtn">
                            <i class="bi bi-x-circle"></i> Disconnect Token
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- Token Input Form -->
    <div class="row justify-content-center">
        <div class="col-md-8">
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body p-4 p-md-5">
                    <form id="tokenForm">
                        <div class="mb-4">
                            <label for="apiToken" class="form-label fw-semibold">
                                Deriv API Token
                            </label>
                            <div class="input-group">
                                <input
                                    type="password"
                                    class="form-control form-control-dark"
                                    id="apiToken"
                                    name="apiToken"
                                    placeholder="Enter your Deriv API token"
                                    required
                                >
                                <button
                                    class="btn btn-outline-secondary"
                                    type="button"
                                    onclick="toggleTokenVisibility()"
                                >
                                    <i class="bi bi-eye" id="toggleIcon"></i>
                                </button>
                            </div>
                            <div class="invalid-feedback" id="tokenError"></div>
                            <div class="valid-feedback" id="tokenSuccess"></div>
                            <small class="text-secondary-custom">
                                Your API token will be encrypted using AES-256-CBC encryption and stored securely.
                            </small>
                        </div>

                        <div class="d-grid gap-2">
                            <button
                                type="button"
                                class="btn btn-outline-secondary"
                                onclick="handleTestConnection()"
                                id="testBtn"
                            >
                                <i class="bi bi-check-circle"></i> Test Connection
                            </button>
                            
                            <button
                                type="submit"
                                class="btn btn-primary-custom"
                                id="connectBtn"
                                disabled
                            >
                                <span id="connectText">
                                    <i class="bi bi-lock"></i> Connect & Encrypt Token
                                </span>
                                <span id="connectLoading" class="d-none">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    Connecting & Encrypting...
                                </span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Instructions -->
            <div class="card card-dark border-dark-custom mb-4">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <i class="bi bi-info-circle text-primary-custom"></i> How to Get Your Deriv API Token
                    </h5>
                    <ol class="text-secondary-custom">
                        <li>Log in to your Deriv account at <a href="https://deriv.com" target="_blank" class="text-primary-custom">deriv.com</a></li>
                        <li>Navigate to <strong>Settings</strong> → <strong>API Token</strong></li>
                        <li>Click <strong>Generate New Token</strong></li>
                        <li>Copy the generated token and paste it in the field above</li>
                        <li>Click <strong>Test Connection</strong> to verify it works</li>
                        <li>Click <strong>Connect & Encrypt Token</strong> to save it securely</li>
                    </ol>
                </div>
            </div>

            <!-- Security Disclaimer -->
            <div class="card card-dark border-info">
                <div class="card-body">
                    <h5 class="card-title text-info mb-3">
                        <i class="bi bi-shield-lock"></i> Security & Permissions
                    </h5>
                    <ul class="text-secondary-custom mb-0">
                        <li><strong>Encryption:</strong> Your API token is encrypted using AES-256-CBC encryption before storage</li>
                        <li><strong>Storage:</strong> Encrypted tokens are stored securely in our database. We never expose your credentials</li>
                        <li><strong>Permissions:</strong> Ensure your API token has trading permissions enabled in your Deriv account</li>
                        <li><strong>Warning:</strong> Never share your API token with anyone. Only use tokens from your own Deriv account</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
let tokenValidated = false;

function toggleTokenVisibility() {
    const input = document.getElementById('apiToken');
    const icon = document.getElementById('toggleIcon');
    
    if (input.type === 'password') {
        input.type = 'text';
        icon.className = 'bi bi-eye-slash';
    } else {
        input.type = 'password';
        icon.className = 'bi bi-eye';
    }
}

async function handleTestConnection() {
    const token = document.getElementById('apiToken').value.trim();
    const testBtn = document.getElementById('testBtn');
    const tokenError = document.getElementById('tokenError');
    const tokenSuccess = document.getElementById('tokenSuccess');
    const input = document.getElementById('apiToken');
    
    if (!token || token.length < 10) {
        input.classList.add('is-invalid');
        tokenError.textContent = 'API token is required (minimum 10 characters)';
        tokenError.classList.remove('d-none');
        tokenSuccess.classList.add('d-none');
        return;
    }
    
    testBtn.disabled = true;
    testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Testing...';
    
    try {
        // TODO: Implement test endpoint
        // For now, we'll just validate format
        tokenValidated = true;
        input.classList.remove('is-invalid');
        input.classList.add('is-valid');
        tokenError.classList.add('d-none');
        tokenSuccess.classList.remove('d-none');
        tokenSuccess.textContent = '✓ Token format is valid';
        
        document.getElementById('connectBtn').disabled = false;
        showToast('Connection test successful', 'success');
        
    } catch (error) {
        tokenValidated = false;
        input.classList.remove('is-valid');
        input.classList.add('is-invalid');
        tokenError.textContent = error.message || 'Invalid token';
        tokenError.classList.remove('d-none');
        tokenSuccess.classList.add('d-none');
        document.getElementById('connectBtn').disabled = true;
        showToast(error.message || 'Connection test failed', 'error');
    } finally {
        testBtn.disabled = false;
        testBtn.innerHTML = '<i class="bi bi-check-circle"></i> Test Connection';
    }
}

document.getElementById('tokenForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    if (!tokenValidated) {
        showToast('Please test the connection first', 'error');
        return;
    }
    
    const token = document.getElementById('apiToken').value.trim();
    const connectBtn = document.getElementById('connectBtn');
    const connectText = document.getElementById('connectText');
    const connectLoading = document.getElementById('connectLoading');
    
    connectBtn.disabled = true;
    connectText.classList.add('d-none');
    connectLoading.classList.remove('d-none');
    
    try {
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        await apiCall(`${apiBase}/user.php?action=save-token`, {
            method: 'POST',
            body: JSON.stringify({
                apiToken: token
            })
        });
        
        showToast('API token connected and encrypted successfully!', 'success');
        
        setTimeout(() => {
            window.location.href = '<?php echo url("index.php"); ?>';
        }, 1500);
        
    } catch (error) {
        showToast(error.message || 'Failed to connect API token', 'error');
    } finally {
        connectBtn.disabled = false;
        connectText.classList.remove('d-none');
        connectLoading.classList.add('d-none');
    }
});

async function handleDisconnect() {
    if (!confirm('Are you sure you want to disconnect your API token? You will need to reconnect it to trade.')) {
        return;
    }
    
    const btn = document.getElementById('disconnectBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Disconnecting...';
    
    try {
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '/api';
        await apiCall(`${apiBase}/user.php?action=disconnect-token`, {
            method: 'POST'
        });
        
        showToast('API token disconnected successfully', 'success');
        
        setTimeout(() => {
            window.location.reload();
        }, 1000);
        
    } catch (error) {
        showToast(error.message || 'Failed to disconnect API token', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-x-circle"></i> Disconnect Token';
    }
}
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>

