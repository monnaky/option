<?php
/**
 * Header Include
 * 
 * Common header for all pages
 */
@error_reporting(0);
@ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle : 'VTM Option'; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- Base Path Configuration -->
    <script>
        <?php
        if (!function_exists('getBasePath')) {
            require_once __DIR__ . '/../../app/helpers.php';
        }
        $basePath = getBasePath();
        ?>
        window.APP_CONFIG = {
            basePath: '<?php echo $basePath; ?>',
            apiBase: '<?php echo $basePath; ?>/api',
            assetBase: '<?php echo $basePath; ?>/public/assets'
        };
    </script>
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: #3b82f6;
            --primary-dark: #2563eb;
            --success-color: #10b981;
            --danger-color: #ef4444;
            --warning-color: #f59e0b;
            --dark-bg: #0f172a;
            --dark-card: #1e293b;
            --dark-border: #334155;
            --text-primary: #ffffff;
            --text-secondary: #cbd5e1;
            --text-muted: #94a3b8;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--dark-bg);
            color: var(--text-primary);
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            line-height: 1.6;
        }
        
        /* Ensure all text is visible on dark backgrounds */
        .text-white, h1, h2, h3, h4, h5, h6, .card-title, .fw-bold, .fw-semibold {
            color: var(--text-primary) !important;
        }
        
        /* Improved text-muted for better contrast */
        .text-muted {
            color: var(--text-muted) !important;
        }
        
        /* Secondary text color */
        .text-secondary-custom {
            color: var(--text-secondary) !important;
        }
        
        .bg-dark-custom {
            background-color: var(--dark-card) !important;
        }
        
        .border-dark-custom {
            border-color: var(--dark-border) !important;
        }
        
        .text-primary-custom {
            color: var(--primary-color) !important;
        }
        
        .btn-primary-custom {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
            color: #ffffff;
            font-weight: 600;
            transition: all 0.2s ease;
        }
        
        .btn-primary-custom:hover {
            background-color: var(--primary-dark);
            border-color: var(--primary-dark);
            color: #ffffff;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }
        
        .card-dark {
            background-color: var(--dark-card);
            border-color: var(--dark-border);
            color: var(--text-primary);
        }
        
        .card-dark .card-title,
        .card-dark .card-body {
            color: var(--text-primary);
        }
        
        /* Improved bg-dark for better contrast */
        .bg-dark {
            background-color: #1e293b !important;
            color: var(--text-primary) !important;
        }
        
        .form-control-dark {
            background-color: #334155;
            border-color: var(--dark-border);
            color: var(--text-primary);
        }
        
        .form-control-dark:focus {
            background-color: #475569;
            border-color: var(--primary-color);
            color: var(--text-primary);
            box-shadow: 0 0 0 0.2rem rgba(59, 130, 246, 0.25);
        }
        
        .form-label {
            color: var(--text-secondary) !important;
            font-weight: 500;
        }
        
        .spinner-border-sm {
            width: 1rem;
            height: 1rem;
            border-width: 0.15em;
        }
        
        /* Professional button styles */
        .btn {
            font-weight: 500;
            transition: all 0.2s ease;
        }
        
        .btn:hover {
            transform: translateY(-1px);
        }
        
        /* Improved badge visibility */
        .badge {
            font-weight: 600;
            padding: 0.4em 0.8em;
        }
        
        /* Table improvements */
        .table-dark {
            color: var(--text-primary);
        }
        
        .table-dark th {
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        /* Alert improvements */
        .alert {
            border: none;
            font-weight: 500;
        }
        
        /* Icon improvements */
        i.bi, .bi {
            color: inherit;
        }
        
        /* Navbar improvements */
        .navbar-dark .navbar-nav .nav-link {
            color: var(--text-secondary);
            transition: color 0.2s ease;
        }
        
        .navbar-dark .navbar-nav .nav-link:hover {
            color: var(--text-primary);
        }
        
        .navbar-dark .navbar-brand {
            color: var(--text-primary);
            font-weight: 700;
        }
        
        .navbar-dark .navbar-text {
            color: var(--text-secondary);
        }
    </style>
</head>
<body>
    <?php if (isset($_SESSION['user_id'])): ?>
    <!-- Navigation Bar -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark-custom border-bottom border-dark-custom">
        <div class="container-fluid">
            <?php
            // Load helpers if not already loaded
            if (!function_exists('url')) {
                require_once __DIR__ . '/../app/helpers.php';
            }
            ?>
            <a class="navbar-brand fw-bold" href="<?php echo url('dashboard.php'); ?>">
                <i class="bi bi-graph-up-arrow"></i> VTM Option
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('dashboard.php'); ?>">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('trading.php'); ?>">
                            <i class="bi bi-graph-up"></i> Trading
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('profile.php'); ?>">
                            <i class="bi bi-person"></i> Profile
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <span class="navbar-text me-3">
                            <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($_SESSION['user_email'] ?? ''); ?>
                        </span>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?php echo url('logout.php'); ?>">
                            <i class="bi bi-box-arrow-right"></i> Logout
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>
    <?php endif; ?>

