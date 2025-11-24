<?php
// import-database.php
$host = 'ag44gc8wwg8co44k884soswo';
$user = 'vtmoption_user';
$password = 'green@11111';
$database = 'vtmoption';

// Connect to database
$mysqli = new mysqli($host, $user, $password, $database);

if ($mysqli->connect_error) {
    die("Connection failed: " . $mysqli->connect_error);
}

echo "Connected to database successfully!\n";

// Import first file
echo "Importing 001_initial_schema.sql...\n";
$sql1 = file_get_contents('https://raw.githubusercontent.com/monnaky/option/main/database/migrations/001_initial_schema.sql');
if ($mysqli->multi_query($sql1)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "✓ First file imported successfully!\n";
} else {
    echo "✗ Error importing first file: " . $mysqli->error . "\n";
}

// Import second file  
echo "Importing 002_add_admin_column.sql...\n";
$sql2 = file_get_contents('https://raw.githubusercontent.com/monnaky/option/main/database/migrations/002_add_admin_column.sql');
if ($mysqli->multi_query($sql2)) {
    do {
        if ($result = $mysqli->store_result()) {
            $result->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    echo "✓ Second file imported successfully!\n";
} else {
    echo "✗ Error importing second file: " . $mysqli->error . "\n";
}

echo "Database import completed!\n";
$mysqli->close();
?>