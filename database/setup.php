<?php

/**
 * Database Setup Script
 * 
 * Run this script once to set up your database
 * 
 * Usage: php database/setup.php
 * 
 * IMPORTANT: Update config.php with your database credentials first!
 */

require_once __DIR__ . '/../config.php';

use App\Config\Database;

echo "VTM Option - Database Setup\n";
echo "============================\n\n";

// Check if config is set up
if (DB_NAME === 'your_database_name' || DB_USER === 'your_database_user') {
    die("ERROR: Please update config.php with your database credentials first!\n");
}

try {
    echo "1. Testing database connection...\n";
    
    // Test connection
    $db = Database::getInstance();
    $connection = $db->getConnection();
    
    echo "   ✓ Database connection successful!\n\n";
    
    echo "2. Checking if database exists...\n";
    
    // Check if database exists
    $stmt = $connection->query("SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMAS WHERE SCHEMA_NAME = '" . DB_NAME . "'");
    $exists = $stmt->fetch();
    
    if (!$exists) {
        echo "   ⚠ Database '" . DB_NAME . "' does not exist.\n";
        echo "   Please create it in cPanel first, then run this script again.\n";
        exit(1);
    }
    
    echo "   ✓ Database exists!\n\n";
    
    echo "3. Running database migrations...\n";
    
    // Read and execute migration file
    $migrationFile = __DIR__ . '/migrations/001_initial_schema.sql';
    
    if (!file_exists($migrationFile)) {
        die("ERROR: Migration file not found: {$migrationFile}\n");
    }
    
    $sql = file_get_contents($migrationFile);
    
    // Split SQL into individual statements
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        function($stmt) {
            return !empty($stmt) && !preg_match('/^--/', $stmt);
        }
    );
    
    $successCount = 0;
    $errorCount = 0;
    
    foreach ($statements as $statement) {
        if (empty(trim($statement))) {
            continue;
        }
        
        try {
            $connection->exec($statement);
            $successCount++;
        } catch (PDOException $e) {
            // Ignore "table already exists" errors
            if (strpos($e->getMessage(), 'already exists') === false) {
                echo "   ⚠ Warning: " . $e->getMessage() . "\n";
                $errorCount++;
            }
        }
    }
    
    echo "   ✓ Executed {$successCount} SQL statements";
    if ($errorCount > 0) {
        echo " ({$errorCount} warnings)";
    }
    echo "\n\n";
    
    echo "4. Verifying tables...\n";
    
    // Check if required tables exist
    $requiredTables = [
        'users',
        'settings',
        'trades',
        'trading_sessions',
        'signals',
        'sessions',
    ];
    
    $missingTables = [];
    
    foreach ($requiredTables as $table) {
        $stmt = $connection->query("SHOW TABLES LIKE '{$table}'");
        if (!$stmt->fetch()) {
            $missingTables[] = $table;
        }
    }
    
    if (!empty($missingTables)) {
        echo "   ⚠ Missing tables: " . implode(', ', $missingTables) . "\n";
        echo "   Please check the migration file and try again.\n";
        exit(1);
    }
    
    echo "   ✓ All required tables exist!\n\n";
    
    echo "5. Creating admin user (optional)...\n";
    echo "   Do you want to create an admin user? (y/n): ";
    
    if (php_sapi_name() === 'cli') {
        $handle = fopen("php://stdin", "r");
        $line = fgets($handle);
        $createAdmin = trim(strtolower($line)) === 'y';
        fclose($handle);
    } else {
        $createAdmin = false;
    }
    
    if ($createAdmin) {
        echo "   Enter admin email: ";
        $email = trim(fgets(STDIN));
        
        echo "   Enter admin password: ";
        $password = trim(fgets(STDIN));
        
        if (!empty($email) && !empty($password)) {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            
            try {
                $db->insert('admin_users', [
                    'email' => $email,
                    'password_hash' => $hashedPassword,
                    'role' => 'admin',
                    'is_active' => true,
                ]);
                
                echo "   ✓ Admin user created successfully!\n\n";
            } catch (Exception $e) {
                echo "   ⚠ Could not create admin user: " . $e->getMessage() . "\n\n";
            }
        }
    } else {
        echo "   Skipped admin user creation.\n\n";
    }
    
    echo "============================\n";
    echo "Database setup complete! ✓\n";
    echo "============================\n";
    echo "\n";
    echo "Next steps:\n";
    echo "1. Update config.php with your encryption key\n";
    echo "2. Set up your domain in cPanel\n";
    echo "3. Test the application\n";
    echo "\n";
    
} catch (Exception $e) {
    echo "\n";
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "\n";
    echo "Troubleshooting:\n";
    echo "1. Check that config.php has correct database credentials\n";
    echo "2. Verify database exists in cPanel\n";
    echo "3. Check database user has proper permissions\n";
    echo "4. Verify database host is correct (usually 'localhost')\n";
    echo "\n";
    exit(1);
}

