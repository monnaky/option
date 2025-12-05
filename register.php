<?php
/**
 * Register Page
 * 
 * Converted from React RegisterPage.tsx
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

$pageTitle = 'Register - VTM Option';
include __DIR__ . '/views/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center py-4">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-4">
                    <h1 class="display-5 fw-bold mb-2">VTM Option</h1>
                    <p class="text-secondary-custom">Create your free account</p>
                </div>

                <div class="card card-dark border-dark-custom shadow-lg">
                    <div class="card-body p-4 p-md-5">
                        <form id="registerForm" method="POST" action="<?php echo api('auth.php'); ?>">
                            <div class="mb-3">
                                <label for="email" class="form-label">Email</label>
                                <input
                                    type="email"
                                    class="form-control form-control-dark"
                                    id="email"
                                    name="email"
                                    placeholder="your@email.com"
                                    required
                                >
                                <div class="invalid-feedback" id="emailError"></div>
                            </div>

                            <div class="mb-3">
                                <label for="password" class="form-label">Password</label>
                                <input
                                    type="password"
                                    class="form-control form-control-dark"
                                    id="password"
                                    name="password"
                                    placeholder="••••••••"
                                    required
                                    minlength="6"
                                >
                                <div class="invalid-feedback" id="passwordError"></div>
                                <small class="text-secondary-custom">
                                    At least 6 characters with uppercase, lowercase, and number
                                </small>
                            </div>

                            <div class="mb-4">
                                <label for="confirmPassword" class="form-label">Confirm Password</label>
                                <input
                                    type="password"
                                    class="form-control form-control-dark"
                                    id="confirmPassword"
                                    name="confirmPassword"
                                    placeholder="••••••••"
                                    required
                                >
                                <div class="invalid-feedback" id="confirmPasswordError"></div>
                            </div>

                            <button
                                type="submit"
                                class="btn btn-primary-custom w-100 py-3 mb-3"
                                id="registerBtn"
                            >
                                <span id="registerText">Create Account</span>
                                <span id="registerLoading" class="d-none">
                                    <span class="spinner-border spinner-border-sm me-2"></span>
                                    Creating account...
                                </span>
                            </button>
                        </form>

                        <div class="text-center mt-4">
                            <p class="text-secondary-custom mb-0">
                                Already have an account? 
                                <a href="<?php echo url('login.php'); ?>" class="text-primary-custom text-decoration-none fw-semibold">
                                    Sign in
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
document.getElementById('registerForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const email = document.getElementById('email').value.trim();
    const password = document.getElementById('password').value;
    const confirmPassword = document.getElementById('confirmPassword').value;
    const btn = document.getElementById('registerBtn');
    const text = document.getElementById('registerText');
    const loading = document.getElementById('registerLoading');
    
    // Clear errors
    clearErrors();
    
    // Validate
    let hasErrors = false;
    
    if (!email || !email.includes('@')) {
        showError('email', 'Please provide a valid email address');
        hasErrors = true;
    }
    
    if (password.length < 6) {
        showError('password', 'Password must be at least 6 characters');
        hasErrors = true;
    }
    
    if (password !== confirmPassword) {
        showError('confirmPassword', 'Passwords do not match');
        hasErrors = true;
    }
    
    if (hasErrors) {
        return;
    }
    
    // Disable button
    btn.disabled = true;
    text.classList.add('d-none');
    loading.classList.remove('d-none');
    
    try {
        // Ensure we use POST method explicitly
        const apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) || '<?php echo api(""); ?>'.replace(/\/$/, '');
        const endpoint = `${apiBase}/auth.php?action=register`;
        
        console.log('[Register] Sending POST request to:', endpoint);
        
        const response = await fetch(endpoint, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            credentials: 'include',
            body: JSON.stringify({
                email: email.toLowerCase(),
                password: password
            })
        });
        
        if (!response.ok) {
            const errorData = await response.json().catch(() => ({ error: 'Registration failed' }));
            throw new Error(errorData.error || 'Registration failed');
        }
        
        const result = await response.json();
        
        if (result.success || result.user) {
            showToast('Registration successful! Redirecting to login...', 'success');
            
            // Redirect to login after delay
            setTimeout(() => {
                window.location.href = '<?php echo url("login.php"); ?>';
            }, 1500);
        } else {
            throw new Error(result.error || 'Registration failed');
        }
        
    } catch (error) {
        showToast(error.message || 'Registration failed', 'error');
        if (error.message && error.message.includes('already exists')) {
            showError('email', 'User already exists');
        }
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

function clearErrors() {
    ['email', 'password', 'confirmPassword'].forEach(field => {
        document.getElementById(field).classList.remove('is-invalid');
    });
}
</script>

<?php include __DIR__ . '/views/includes/footer.php'; ?>
