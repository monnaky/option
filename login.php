<?php
/**
 * Login Page
 * 
 * Converted from React LoginPage.tsx
 */

// Load helpers first
if (!function_exists('url')) {
    require_once __DIR__ . '/app/helpers.php';
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: ' . url('dashboard.php'));
    exit;
}

$pageTitle = 'Login - VTM Option';
include __DIR__ . '/views/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <h1 class="display-5 fw-bold mb-2">VTM Option</h1>
                    <p class="text-secondary-custom">Sign in to your account</p>
                </div>

                <div class="card card-dark border-dark-custom shadow-lg">
                    <div class="card-body p-4 p-md-5">
                        <form id="loginForm" method="POST" action="<?php echo api('auth.php'); ?>">
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
document.getElementById('loginForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
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
        // Ensure we use POST method explicitly
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '<?php echo api(""); ?>'.replace(/\/$/, '');
        const endpoint = `${apiBase}/auth.php?action=login`;
        
        console.log('[Login] Sending POST request to:', endpoint);
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                email: email,
                password: password
            })
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: 'Login failed' }));
            throw new Error(errorData.error || 'Login failed');
        }
        
        const result = await response.json();
        
        if (result.success || result.user) {
            showToast('Login successful! Redirecting...', 'success');
            
            // Redirect to dashboard
            setTimeout(() => {
                window.location.href = '<?php echo url("dashboard.php"); ?>';
            }, 1000);
        } else {
            throw new Error(result.error || 'Login failed');
        }
        
    } catch (error) {
        generalError.textContent = error.message || 'Invalid credentials';
        generalError.classList.remove('d-none');
        showToast(error.message || 'Login failed', 'error');
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
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>
