<?php

/**
 * Create Admin User Script
 * 
 * Quick script to create or promote a user to admin
 * 
 * Usage: php database/create_admin.php
 */

require_once __DIR__ . '/../config.php';

use App\Config\Database;

echo "VTM Option - Create Admin User\n";
echo "===============================\n\n";

try {
    $db = Database::getInstance();
    
    // Get email from command line or prompt
    if (php_sapi_name() === 'cli') {
        if (isset($argv[1])) {
            $email = $argv[1];
        } else {
            echo "Enter user email: ";
            $email = trim(fgets(STDIN));
        }
    } else {
        // Web interface
        $email = $_GET['email'] ?? $_POST['email'] ?? '';
        if (empty($email)) {
            die("Please provide email parameter: ?email=user@domain.com");
        }
    }
    
    if (empty($email)) {
        die("ERROR: Email is required\n");
    }
    
    // Validate email
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        die("ERROR: Invalid email format\n");
    }
    
    // Check if user exists
    $user = $db->queryOne(
        "SELECT id, email, is_active, is_admin FROM users WHERE email = :email",
        ['email' => strtolower(trim($email))]
    );
    
    if (!$user) {
        echo "User not found. Do you want to create a new admin user? (y/n): ";
        
        if (php_sapi_name() === 'cli') {
            $handle = fopen("php://stdin", "r");
            $line = fgets($handle);
            $create = trim(strtolower($line)) === 'y';
            fclose($handle);
        } else {
            $create = false; // Don't create via web for security
        }
        
        if ($create) {
            echo "Enter password: ";
            $password = trim(fgets(STDIN));
            
            if (empty($password) || strlen($password) < 6) {
                die("ERROR: Password must be at least 6 characters\n");
            }
            
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            $userId = $db->insert('users', [
                'email' => strtolower(trim($email)),
                'password' => $hashedPassword,
                'is_active' => 1,
                'is_admin' => 1,
            ]);
            
            echo "✓ Admin user created successfully!\n";
            echo "  User ID: {$userId}\n";
            echo "  Email: {$email}\n";
            echo "  Admin: Yes\n\n";
        } else {
            die("User does not exist. Please create the user first via registration.\n");
        }
    } else {
        // User exists, promote to admin
        if ($user['is_admin']) {
            echo "User is already an admin.\n";
            echo "  User ID: {$user['id']}\n";
            echo "  Email: {$user['email']}\n";
            echo "  Status: " . ($user['is_active'] ? 'Active' : 'Inactive') . "\n";
        } else {
            $db->execute(
                "UPDATE users SET is_admin = 1 WHERE id = :id",
                ['id' => $user['id']]
            );
            
            echo "✓ User promoted to admin successfully!\n";
            echo "  User ID: {$user['id']}\n";
            echo "  Email: {$user['email']}\n";
            echo "  Status: " . ($user['is_active'] ? 'Active' : 'Inactive') . "\n";
            echo "  Admin: Yes\n\n";
        }
    }
    
    echo "\n";
    echo "Next steps:\n";
    echo "1. Log in with this account at /login.php\n";
    echo "2. Access admin panel at /admin/dashboard.php\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Troubleshooting:\n";
    echo "1. Check that database migration has been run (002_add_admin_column.sql)\n";
    echo "2. Verify database connection in config.php\n";
    echo "3. Ensure users table exists\n";
    echo "\n";
    exit(1);
}

