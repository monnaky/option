<?php
/**
 * Admin Header Include
 * 
 * Common header for admin pages
 */
@error_reporting(0);
@ini_set('display_errors', '0');

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Load autoloader to access AdminMiddleware
require_once __DIR__ . '/../../app/autoload.php';

use App\Middleware\AdminMiddleware;

// Check if user is logged in and is an admin
try {
    AdminMiddleware::requireAdmin(false);
} catch (Exception $e) {
    // AdminMiddleware::requireAdmin() already handles redirect
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - Admin' : 'Admin Dashboard - VTM Option'; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    
    <!-- DataTables CSS -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/dataTables.bootstrap5.min.css">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --admin-primary: #6366f1;
            --admin-primary-dark: #4f46e5;
            --admin-success: #10b981;
            --admin-danger: #ef4444;
            --admin-warning: #f59e0b;
            --admin-dark-bg: #0f172a;
            --admin-dark-card: #1e293b;
            --admin-dark-border: #334155;
        }
        
        body {
            background-color: var(--admin-dark-bg);
            color: #e2e8f0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
        }
        
        .admin-sidebar {
            min-height: 100vh;
            background-color: var(--admin-dark-card);
            border-right: 1px solid var(--admin-dark-border);
            padding: 0;
        }
        
        .admin-sidebar .nav-link {
            color: #cbd5e1;
            padding: 0.75rem 1.5rem;
            border-left: 3px solid transparent;
        }
        
        .admin-sidebar .nav-link:hover,
        .admin-sidebar .nav-link.active {
            background-color: rgba(99, 102, 241, 0.1);
            border-left-color: var(--admin-primary);
            color: #fff;
        }
        
        .admin-content {
            padding: 2rem;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--admin-dark-card) 0%, #1e293b 100%);
            border: 1px solid var(--admin-dark-border);
            border-radius: 0.75rem;
            padding: 1.5rem;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
        }
        
        .stat-value {
            font-size: 2rem;
            font-weight: 700;
            margin: 0.5rem 0;
        }
        
        .stat-label {
            color: #94a3b8;
            font-size: 0.875rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        
        .table-dark-custom {
            background-color: var(--admin-dark-card);
            color: #e2e8f0;
        }
        
        .table-dark-custom thead {
            background-color: #1e293b;
        }
        
        .badge-custom {
            padding: 0.35em 0.65em;
            font-weight: 500;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <!-- Sidebar -->
            <div class="col-md-2 admin-sidebar">
                <div class="p-3">
                    <h5 class="text-white mb-0">
                        <i class="bi bi-shield-check"></i> Admin Panel
                    </h5>
                </div>
                <nav class="nav flex-column">
                    <?php
                    if (!function_exists('url')) {
                        require_once __DIR__ . '/../../app/helpers.php';
                    }
                    ?>
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'dashboard.php') ? 'active' : ''; ?>" href="<?php echo url('admin/dashboard.php'); ?>">
                        <i class="bi bi-speedometer2"></i> Dashboard
                    </a>
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'users.php') ? 'active' : ''; ?>" href="<?php echo url('admin/users.php'); ?>">
                        <i class="bi bi-people"></i> Users
                    </a>
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'trades.php') ? 'active' : ''; ?>" href="<?php echo url('admin/trades.php'); ?>">
                        <i class="bi bi-graph-up"></i> Trades
                    </a>
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'signals.php') ? 'active' : ''; ?>" href="<?php echo url('admin/signals.php'); ?>">
                        <i class="bi bi-broadcast"></i> Signals
                    </a>
                    <a class="nav-link <?php echo (basename($_SERVER['PHP_SELF']) === 'system.php') ? 'active' : ''; ?>" href="<?php echo url('admin/system.php'); ?>">
                        <i class="bi bi-gear"></i> System
                    </a>
                    <hr class="text-secondary my-2">
                    <a class="nav-link" href="<?php echo url('index.php'); ?>">
                        <i class="bi bi-arrow-left"></i> Back to App
                    </a>
                    <a class="nav-link" href="<?php echo url('logout.php'); ?>">
                        <i class="bi bi-box-arrow-right"></i> Logout
                    </a>
                </nav>
            </div>
            
            <!-- Main Content -->
            <div class="col-md-10 admin-content">

