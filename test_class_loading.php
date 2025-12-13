<?php
// Simple test to check class loading
echo "Testing class loading...\n";

// Load config and autoload
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/autoload.php';

echo "Files loaded.\n";

// Check if classes exist
echo "DerivAPI class exists: " . (class_exists('DerivAPI') ? 'YES' : 'NO') . "\n";
echo "TradingBotService class exists: " . (class_exists('TradingBotService') ? 'YES' : 'NO') . "\n";
echo "Database class exists: " . (class_exists('App\Config\Database') ? 'YES' : 'NO') . "\n";

// List all loaded classes starting with 'Deriv'
$classes = get_declared_classes();
$derivClasses = array_filter($classes, function($class) {
    return strpos($class, 'Deriv') !== false || strpos($class, 'TradingBot') !== false;
});

echo "\nRelevant classes found:\n";
foreach ($derivClasses as $class) {
    echo "- $class\n";
}
