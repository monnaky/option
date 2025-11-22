<?php
/**
 * Register Page
 * 
 * Converted from React RegisterPage.tsx
 */

@error_reporting(0);
@ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$pageTitle = 'Register - VTM Option';
include __DIR__ . '/../views/includes/header.php';
?>

<div class="min-vh-100 d-flex align-items-center justify-content-center bg-dark-custom py-5">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6 col-lg-5">
                <div class="text-center mb-5">
                    <h1 class="display-4 fw-bold text-white mb-2">VTM Option</h1>
                    <p class="text-muted">Create your free account</p>
                </div>

                <div class="card card-dark border-dark-custom shadow-lg">
                    <div class="card-body p-4 p-md-5">
                        <form id="registerForm">
                            <div class="mb-3">
                                <label for="email" class="form-label text-white">Email</label>
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
                                <label for="password" class="form-label text-white">Password</label>
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
                                <small class="text-muted">
                                    At least 6 characters with uppercase, lowercase, and number
                                </small>
                            </div>

                            <div class="mb-4">
                                <label for="confirmPassword" class="form-label text-white">Confirm Password</label>
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
                            <p class="text-muted mb-0">
                                Already have an account? 
                                <a href="/login.php" class="text-primary-custom text-decoration-none">
                                    Sign in
                                </a>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-4">
                    <a href="/" class="text-muted text-decoration-none">
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
        const response = await apiCall('/api/auth.php?action=register', 'POST', {
            email: email.toLowerCase(),
            password: password
        });
        
        showToast('Registration successful! Redirecting to login...', 'success');
        
        // Redirect to login after delay
        setTimeout(() => {
            window.location.href = '/login.php';
        }, 1500);
        
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

<?php include __DIR__ . '/../views/includes/footer.php'; ?>

