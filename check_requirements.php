<?php

/**
 * PHP Requirements Check
 * 
 * Run this script to check if your hosting environment meets requirements
 * 
 * Usage: php check_requirements.php
 * Or visit: https://yourdomain.com/check_requirements.php
 */

// Start output buffering
ob_start();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>VTM Option - Requirements Check</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 800px;
            margin: 50px auto;
            padding: 20px;
            background: #f5f5f5;
        }
        .container {
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 3px solid #007bff;
            padding-bottom: 10px;
        }
        .check {
            margin: 15px 0;
            padding: 15px;
            border-radius: 5px;
            display: flex;
            align-items: center;
        }
        .check.pass {
            background: #d4edda;
            color: #155724;
            border-left: 4px solid #28a745;
        }
        .check.fail {
            background: #f8d7da;
            color: #721c24;
            border-left: 4px solid #dc3545;
        }
        .check.warning {
            background: #fff3cd;
            color: #856404;
            border-left: 4px solid #ffc107;
        }
        .icon {
            font-size: 24px;
            margin-right: 15px;
            font-weight: bold;
        }
        .info {
            flex: 1;
        }
        .label {
            font-weight: bold;
            display: block;
            margin-bottom: 5px;
        }
        .value {
            font-size: 0.9em;
            color: #666;
        }
        .summary {
            margin-top: 30px;
            padding: 20px;
            background: #e9ecef;
            border-radius: 5px;
        }
        .summary h2 {
            margin-top: 0;
        }
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: monospace;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>VTM Option - System Requirements Check</h1>
        
        <?php
        
        // Helper function for parsing size
        function parseSize($size) {
            $unit = preg_replace('/[^bkmgtpezy]/i', '', $size);
            $size = preg_replace('/[^0-9\.]/', '', $size);
            if ($unit) {
                return round($size * pow(1024, stripos('bkmgtpezy', $unit[0])));
            } else {
                return round($size);
            }
        }
        
        $checks = [];
        $allPassed = true;
        
        // PHP Version Check
        $phpVersion = PHP_VERSION;
        $requiredVersion = '7.4.0';
        $phpVersionOk = version_compare($phpVersion, $requiredVersion, '>=');
        $checks[] = [
            'name' => 'PHP Version',
            'status' => $phpVersionOk ? 'pass' : 'fail',
            'message' => $phpVersionOk 
                ? "PHP {$phpVersion} (Required: {$requiredVersion}+)" 
                : "PHP {$phpVersion} (Required: {$requiredVersion}+)",
            'value' => $phpVersion
        ];
        if (!$phpVersionOk) $allPassed = false;
        
        // Required Extensions
        $requiredExtensions = [
            'pdo' => 'PDO',
            'pdo_mysql' => 'PDO MySQL',
            'openssl' => 'OpenSSL',
            'mbstring' => 'mbstring',
            'json' => 'JSON',
            'curl' => 'cURL',
            'session' => 'Session',
        ];
        
        foreach ($requiredExtensions as $ext => $name) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name' => $name . ' Extension',
                'status' => $loaded ? 'pass' : 'fail',
                'message' => $loaded ? "{$name} extension is loaded" : "{$name} extension is missing",
                'value' => $loaded ? 'Loaded' : 'Not Loaded'
            ];
            if (!$loaded) $allPassed = false;
        }
        
        // Optional Extensions
        $optionalExtensions = [
            'sockets' => 'Sockets (for WebSocket)',
            'zip' => 'ZIP',
            'gd' => 'GD (for image processing)',
        ];
        
        foreach ($optionalExtensions as $ext => $name) {
            $loaded = extension_loaded($ext);
            $checks[] = [
                'name' => $name,
                'status' => $loaded ? 'pass' : 'warning',
                'message' => $loaded ? "{$name} extension is loaded" : "{$name} extension is optional",
                'value' => $loaded ? 'Loaded' : 'Optional'
            ];
        }
        
        // PHP Configuration
        $memoryLimit = ini_get('memory_limit');
        $memoryBytes = parseSize($memoryLimit);
        $minMemory = 128 * 1024 * 1024; // 128MB
        $memoryOk = $memoryBytes >= $minMemory;
        
        $checks[] = [
            'name' => 'Memory Limit',
            'status' => $memoryOk ? 'pass' : 'warning',
            'message' => "Memory limit: {$memoryLimit} (Recommended: 128M+)",
            'value' => $memoryLimit
        ];
        
        $maxExecutionTime = ini_get('max_execution_time');
        $checks[] = [
            'name' => 'Max Execution Time',
            'status' => 'pass',
            'message' => "Max execution time: {$maxExecutionTime} seconds",
            'value' => $maxExecutionTime
        ];
        
        $uploadMaxFilesize = ini_get('upload_max_filesize');
        $checks[] = [
            'name' => 'Upload Max Filesize',
            'status' => 'pass',
            'message' => "Upload max filesize: {$uploadMaxFilesize}",
            'value' => $uploadMaxFilesize
        ];
        
        // File Permissions
        $writableDirs = [
            'public' => __DIR__ . '/public',
            'app' => __DIR__ . '/app',
        ];
        
        foreach ($writableDirs as $name => $path) {
            $writable = is_writable($path);
            $checks[] = [
                'name' => "{$name} Directory Writable",
                'status' => $writable ? 'pass' : 'warning',
                'message' => $writable ? "{$name} directory is writable" : "{$name} directory should be writable",
                'value' => $writable ? 'Writable' : 'Not Writable'
            ];
        }
        
        // Composer Check
        $composerExists = file_exists(__DIR__ . '/vendor/autoload.php');
        $checks[] = [
            'name' => 'Composer Dependencies',
            'status' => $composerExists ? 'pass' : 'fail',
            'message' => $composerExists 
                ? 'Composer dependencies are installed' 
                : 'Composer dependencies not found. Run: composer install',
            'value' => $composerExists ? 'Installed' : 'Not Installed'
        ];
        if (!$composerExists) $allPassed = false;
        
        // Config Check
        $configExists = file_exists(__DIR__ . '/config.php');
        $checks[] = [
            'name' => 'Configuration File',
            'status' => $configExists ? 'pass' : 'fail',
            'message' => $configExists 
                ? 'config.php exists' 
                : 'config.php not found. Please create it.',
            'value' => $configExists ? 'Exists' : 'Missing'
        ];
        if (!$configExists) $allPassed = false;
        
        // Database Connection Check (if config exists)
        if ($configExists) {
            try {
                require_once __DIR__ . '/config.php';
                $db = \App\Config\Database::getInstance();
                $connection = $db->getConnection();
                $connection->query("SELECT 1");
                
                $checks[] = [
                    'name' => 'Database Connection',
                    'status' => 'pass',
                    'message' => 'Database connection successful',
                    'value' => 'Connected'
                ];
            } catch (Exception $e) {
                $checks[] = [
                    'name' => 'Database Connection',
                    'status' => 'fail',
                    'message' => 'Database connection failed: ' . $e->getMessage(),
                    'value' => 'Failed'
                ];
                $allPassed = false;
            }
        }
        
        // Display checks
        foreach ($checks as $check) {
            $icon = $check['status'] === 'pass' ? '✓' : ($check['status'] === 'warning' ? '⚠' : '✗');
            echo "<div class='check {$check['status']}'>";
            echo "<span class='icon'>{$icon}</span>";
            echo "<div class='info'>";
            echo "<span class='label'>{$check['name']}</span>";
            echo "<span class='value'>{$check['message']}</span>";
            echo "</div>";
            echo "</div>";
        }
        
        // Summary
        echo "<div class='summary'>";
        echo "<h2>Summary</h2>";
        
        if ($allPassed) {
            echo "<p style='color: #28a745; font-weight: bold;'>✓ All critical requirements are met! Your server is ready to run VTM Option.</p>";
        } else {
            echo "<p style='color: #dc3545; font-weight: bold;'>✗ Some requirements are not met. Please fix the issues above before deploying.</p>";
        }
        
        echo "<h3>Next Steps:</h3>";
        echo "<ol>";
        echo "<li>Update <code>config.php</code> with your database credentials</li>";
        echo "<li>Run <code>composer install</code> to install dependencies</li>";
        echo "<li>Run <code>php database/setup.php</code> to set up the database</li>";
        echo "<li>Configure your domain in cPanel</li>";
        echo "<li>Test the application</li>";
        echo "</ol>";
        echo "</div>";
        
        ?>
    </div>
</body>
</html>

