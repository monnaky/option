<?php
/**
 * Login Page
 * 
 * Converted from React LoginPage.tsx
 */

// Load helpers and bootstrap
if (!function_exists('url')) {
    require_once __DIR__ . '/app/helpers.php';
}

// Start session using new Authentication class
require_once __DIR__ . '/app/bootstrap.php';
use App\Middleware\Authentication;

// Use the new Authentication class for session management
Authentication::startSession();

// Redirect if already logged in using new system
if (Authentication::isLoggedIn()) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$pageTitle = 'Login - VTM Option';
include __DIR__ . '/views/includes/header.php';

// Check for queued requests from session expiry
$hasQueuedRequests = isset($_SESSION['queued_requests']) && count($_SESSION['queued_requests']) > 0;
if ($hasQueuedRequests) {
    $queuedCount = count($_SESSION['queued_requests']);
}
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <h1 class="display-5 fw-bold mb-2">VTM Option</h1>
                    <p class="text-secondary-custom">Sign in to your account</p>
                    
                    <?php if ($hasQueuedRequests): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <i class="bi bi-info-circle me-2"></i>
                        <strong>Session Expired</strong>
                        <p class="mb-0 mt-1">You have <?php echo $queuedCount; ?> pending action(s) that will be retried after login.</p>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (isset($_GET['message'])): ?>
                    <div class="alert alert-info alert-dismissible fade show" role="alert">
                        <?php
                        switch ($_GET['message']) {
                            case 'logged_out':
                                echo '<i class="bi bi-check-circle me-2"></i> You have been successfully logged out.';
                                break;
                            case 'session_expired':
                                echo '<i class="bi bi-exclamation-triangle me-2"></i> Your session has expired. Please login again.';
                                break;
                            case 'registered':
                                echo '<i class="bi bi-check-circle me-2"></i> Registration successful! Please login with your credentials.';
                                break;
                        }
                        ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="card card-dark border-dark-custom shadow-lg">
                    <div class="card-body p-4 p-md-5">
                        <form id="loginForm" method="POST">
                            <div id="generalError" class="alert alert-danger d-none" role="alert"></div>

                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input
                                    type="email"
                                    class="form-control form-control-dark"
                                    id="email"
                                    name="email"
                                    placeholder="your@email.com"
                                    required
                                    autocomplete="email"
                                    value="<?php echo isset($_SESSION['login_email']) ? htmlspecialchars($_SESSION['login_email']) : ''; ?>"
                                >
                                <div class="invalid-feedback" id="emailError"></div>
                            </div>

                            <div class="mb-4">
                                <label for="password" class="form-label">Password</label>
                                <input
                                    type="password"
                                    class="form-control form-control-dark"
                                    id="password"
                                    name="password"
                                    placeholder="••••••••"
                                    required
                                    autocomplete="current-password"
                                >
                                <div class="invalid-feedback" id="passwordError"></div>
                            </div>

                            <div class="form-check mb-4">
                                <input class="form-check-input" type="checkbox" id="rememberMe">
                                <label class="form-check-label text-secondary-custom" for="rememberMe">
                                    Keep me logged in for 8 hours
                                </label>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary-custom w-100 py-3 mb-3"
                                id="loginBtn"
                            >
                                <span id="loginText">Sign In</span>
                                <span id="loginLoading" class="d-none">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    Signing in...
                                </span>
                            </button>
                        </form>

                        <div class="text-center mt-4">
                            <p class="text-secondary-custom mb-0">
                                Don't have an account? 
                                <a href="<?php echo url('register.php'); ?>" class="text-primary-custom text-decoration-none fw-semibold">
                                    Register
                                </a>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <a href="<?php echo url('index.php'); ?>" class="text-secondary-custom text-decoration-none">
                        <i class="bi bi-arrow-left"></i> Back to home
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Check if we have session manager and retry queued requests
document.addEventListener('DOMContentLoaded', function() {
    if (window.sessionManager && <?php echo $hasQueuedRequests ? 'true' : 'false'; ?>) {
        // Retry any queued requests after login
        setTimeout(() => {
            window.sessionManager.retryQueuedRequests();
        }, 500);
    }
});

document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const rememberMe = document.getElementById('rememberMe').checked;
    const btn = document.getElementById('loginBtn');
    const text = document.getElementById('loginText');
    const loading = document.getElementById('loginLoading');
    const generalError = document.getElementById('generalError');
    
    // Clear errors
    generalError.classList.add('d-none');
    document.getElementById('email').classList.remove('is-invalid');
    document.getElementById('password').classList.remove('is-invalid');
    
    // Validate
    if (!email || !email.includes('@')) {
        showError('email', 'Please provide a valid email address');
        return;
    }
    
    if (!password) {
        showError('password', 'Password is required');
        return;
    }
    
    // Disable button
    btn.disabled = true;
    text.classList.add('d-none');
    loading.classList.remove('d-none');
    
    try {
        // Use new API endpoint or existing one
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '<?php echo api(""); ?>'.replace(/\/$/, '');
        const endpoint = `${apiBase}/auth.php?action=login`;
        
        console.log('[Login] Using new authentication system');
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'include', // Important for session cookies
            body: JSON.stringify({
                email: email,
                password: password,
                remember_me: rememberMe
            })
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: 'Login failed' }));
            throw new Error(errorData.error || `Login failed (${response.status})`);
        }
        
        const result = await response.json();
        
        if (result.success || result.user) {
            showToast('Login successful! Redirecting...', 'success');
            
            // Store email for convenience
            sessionStorage.setItem('login_email', email);
            
            // Redirect to dashboard or original destination
            let redirectUrl = '<?php echo url("dashboard.php"); ?>';
            
            // Check for stored redirect from session expiry
            if (<?php echo $hasQueuedRequests ? 'true' : 'false'; ?>) {
                const queuedRequests = <?php echo json_encode($_SESSION['queued_requests'] ?? []); ?>;
                if (queuedRequests.length > 0 && queuedRequests[0].redirect) {
                    redirectUrl = queuedRequests[0].redirect;
                }
            }
            
            // Clear any stored redirect
            if (window.sessionManager) {
                window.sessionManager.ajaxQueue = [];
            }
            
            // Start session manager keep-alive
            if (window.sessionManager) {
                window.sessionManager.startKeepAlive();
            }
            
            // Redirect
            setTimeout(() => {
                window.location.href = redirectUrl;
            }, 1000);
        } else {
            throw new Error(result.error || 'Login failed');
        }
        
    } catch (error) {
        generalError.textContent = error.message || 'Invalid credentials';
        generalError.classList.remove('d-none');
        showToast(error.message || 'Login failed', 'error');
        console.error('Login error:', error);
    } finally {
        btn.disabled = false;
        text.classList.remove('d-none');
        loading.classList.add('d-none');
    }
});

function showError(field, message) {
    const input = document.getElementById(field);
    const errorDiv = document.getElementById(field + 'Error');
    input.classList.add('is-invalid');
    errorDiv.textContent = message;
}

// Auto-fill from session storage
document.addEventListener('DOMContentLoaded', function() {
    const savedEmail = sessionStorage.getItem('login_email');
    if (savedEmail) {
        document.getElementById('email').value = savedEmail;
    }
});
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>
