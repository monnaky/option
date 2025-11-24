<?php
// test-connection.php
$hosts_to_try = [
    'mariadb',
    'mysql',
    'database',
    'db',
    'localhost',
    '127.0.0.1'
];

$user = 'vtmoption_user';
$password = 'green@11111';
$database = 'vtmoption';

foreach ($hosts_to_try as $host) {
    echo "Trying to connect to: <strong>$host</strong><br>";
    
    $mysqli = @new mysqli($host, $user, $password, $database);
    
    if ($mysqli->connect_error) {
        echo "❌ Failed: " . $mysqli->connect_error . "<br><br>";
    } else {
        echo "✅ SUCCESS! Connected to: $host<br>";
        echo "Running import...<br>";
        
        // Import first file
        $sql1 = file_get_contents('https://raw.githubusercontent.com/monnaky/option/main/database/migrations/001_initial_schema.sql');
        if ($mysqli->multi_query($sql1)) {
            echo "✅ 001_initial_schema.sql imported successfully!<br>";
        } else {
            echo "❌ Error: " . $mysqli->error . "<br>";
        }
        
        // Import second file
        $sql2 = file_get_contents('https://raw.githubusercontent.com/monnaky/option/main/database/migrations/002_add_admin_column.sql');
        if ($mysqli->multi_query($sql2)) {
            echo "✅ 002_add_admin_column.sql imported successfully!<br>";
        } else {
            echo "❌ Error: " . $mysqli->error . "<br>";
        }
        
        $mysqli->close();
        break;
    }
}
?>