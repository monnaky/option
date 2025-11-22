<?php
/**
 * Landing Page / Dashboard Router
 * 
 * Shows landing page for guests, redirects authenticated users to dashboard
 */

@error_reporting(0);
@ini_set('display_errors', '0');

require_once __DIR__ . '/app/autoload.php';

// Start session
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// If user is logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    // Load helpers for URL function
    if (!function_exists('url')) {
        require_once __DIR__ . '/app/helpers.php';
    }
    header('Location: ' . url('dashboard.php'));
    exit;
}

$pageTitle = 'VTM Option - Automated Trading Bot on Deriv';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --dark-bg: #0f172a;
            --dark-card: #1e293b;
            --dark-border: #334155;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--dark-bg);
            color: #ffffff;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        
        /* Hero Section */
        .hero-section {
            flex: 1;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            text-align: center;
            min-height: calc(100vh - 250px);
            max-height: 100vh;
        }
        
        .hero-title {
            font-size: 2.75rem;
            font-weight: 700;
            color: #ffffff;
            margin-bottom: 0.5rem;
            letter-spacing: -0.02em;
        }
        
        .hero-subtitle {
            font-size: 1.25rem;
            color: #ffffff;
            margin-bottom: 0.25rem;
            font-weight: 400;
        }
        
        .hero-description {
            font-size: 0.95rem;
            color: #e2e8f0;
            margin-bottom: 1.5rem;
            opacity: 0.9;
        }
        
        .hero-buttons {
            display: flex;
            gap: 0.75rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-bottom: 0;
        }
        
        .btn-hero-login {
            background-color: var(--primary-color);
            border: none;
            color: #ffffff;
            padding: 0.65rem 1.75rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-hero-login:hover {
            background-color: var(--primary-dark);
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.4);
        }
        
        .btn-hero-register {
            background-color: #374151;
            border: none;
            color: #ffffff;
            padding: 0.65rem 1.75rem;
            font-size: 1rem;
            font-weight: 600;
            border-radius: 0.5rem;
            text-decoration: none;
            transition: all 0.3s ease;
        }
        
        .btn-hero-register:hover {
            background-color: #4b5563;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(55, 65, 81, 0.4);
        }
        
        /* Features Section */
        .features-section {
            padding: 1.5rem 1rem;
            max-width: 1200px;
            width: 100%;
            margin: 0 auto;
            margin-top: 0;
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.25rem;
            margin-top: 0.5rem;
        }
        
        .feature-card {
            text-align: center;
            padding: 2rem;
            background-color: transparent;
            border: none;
        }
        
        .feature-icon {
            font-size: 3rem;
            margin-bottom: 1.5rem;
            display: block;
        }
        
        .feature-icon.robot {
            color: #ffffff;
        }
        
        .feature-icon.secure {
            color: #fbbf24;
        }
        
        .feature-icon.chart {
            color: #ffffff;
        }
        
        .feature-title {
            font-size: 1.5rem;
            font-weight: 600;
            color: #ffffff;
            margin-bottom: 1rem;
        }
        
        .feature-description {
            font-size: 1rem;
            color: #e2e8f0;
            line-height: 1.6;
            opacity: 0.9;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            .hero-section {
                padding: 1.5rem 1rem;
                min-height: calc(100vh - 150px);
            }
            
            .hero-title {
                font-size: 2.25rem;
                margin-bottom: 0.5rem;
            }
            
            .hero-subtitle {
                font-size: 1.15rem;
                margin-bottom: 0.25rem;
            }
            
            .hero-description {
                font-size: 0.95rem;
                margin-bottom: 1.5rem;
            }
            
            .hero-buttons {
                flex-direction: column;
                width: 100%;
                max-width: 300px;
                gap: 0.75rem;
            }
            
            .btn-hero-login,
            .btn-hero-register {
                width: 100%;
                padding: 0.65rem 1.5rem;
                font-size: 1rem;
            }
            
            .features-section {
                padding: 1.5rem 1rem;
            }
            
            .features-grid {
                grid-template-columns: 1fr;
                gap: 1.25rem;
            }
        }
        
        @media (min-width: 769px) and (max-width: 1024px) {
            .hero-title {
                font-size: 2.75rem;
            }
            
            .features-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (min-width: 1025px) {
            .hero-section {
                padding: 2.5rem 2rem;
            }
        }
    </style>
</head>
<body>
    <!-- Hero Section -->
    <section class="hero-section">
        <h1 class="hero-title">VTM Option</h1>
        <p class="hero-subtitle">Automated Trading Bot on Deriv</p>
        <p class="hero-description">Let our proprietary bot handle your trading decisions</p>
        
        <div class="hero-buttons">
            <?php
            // Load helpers for URL function
            if (!function_exists('url')) {
                require_once __DIR__ . '/app/helpers.php';
            }
            ?>
            <a href="<?php echo url('login.php'); ?>" class="btn-hero-login">LOGIN</a>
            <a href="<?php echo url('register.php'); ?>" class="btn-hero-register">REGISTER</a>
        </div>
    </section>
    
    <!-- Features Section -->
    <section class="features-section">
        <div class="features-grid">
            <!-- Feature 1: Automated Trading -->
            <div class="feature-card">
                <i class="bi bi-robot feature-icon robot"></i>
                <h3 class="feature-title">Automated Trading</h3>
                <p class="feature-description">
                    Our bot makes all trading decisions automatically. You only set your risk parameters.
                </p>
            </div>
            
            <!-- Feature 2: Secure -->
            <div class="feature-card">
                <i class="bi bi-shield-lock feature-icon secure"></i>
                <h3 class="feature-title">Secure</h3>
                <p class="feature-description">
                    Your API tokens are encrypted and stored securely. We never expose your credentials.
                </p>
            </div>
            
            <!-- Feature 3: Real-time Updates -->
            <div class="feature-card">
                <i class="bi bi-graph-up feature-icon chart"></i>
                <h3 class="feature-title">Real-time Updates</h3>
                <p class="feature-description">
                    Monitor your trades in real-time with live updates and comprehensive analytics.
                </p>
            </div>
        </div>
    </section>
    
    <!-- Bootstrap 5 JS Bundle - Defer for performance -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js" defer></script>
</body>
</html>
