<?php

$action = $_GET['action'] ?? $_POST['action'] ?? '';

if ($action === 'clear') {
    $path = __DIR__ . '/getSignal.txt';
    if (!file_exists($path)) {
        @touch($path);
    }

    $result = @file_put_contents($path, '');

    header('Content-Type: application/json');
    if ($result === false) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to clear getSignal.txt',
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Signal file cleared',
        ]);
    }
    exit;
}

if (empty($_POST["text"])) die("failed... text not specified...");

$text = $_POST["text"];

$file = fopen("getSignal.txt", "w") or die("failed... unable to open file");

fwrite($file, $text);

fclose($file);

?>
