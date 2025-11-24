<?php

if (empty($_POST["text"])) {
    die("Error: 'text' parameter not specified.");
}

$text = $_POST["text"];

$filePath = "getSignal.txt";

// Use append mode ("a") if you want to keep previous data
$file = fopen($filePath, "w");

if (!$file) {
    die("Error: Unable to open file.");
}

fwrite($file, $text);
fclose($file);

echo "Success: Text written to file.";

?>
