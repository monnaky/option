<?php

require_once __DIR__ . '/app/helpers.php';

$action = $_GET['action'] ?? $_POST['action'] ?? '';
vtm_signal_ensure_paths();
$permissionIssues = vtm_signal_verify_writable();
if (!empty($permissionIssues)) {
    http_response_code(500);
    vtm_signal_log_error('Signal storage paths not writable', ['paths' => $permissionIssues]);
    echo json_encode([
        'success' => false,
        'message' => 'Signal storage is not writable',
        'paths' => $permissionIssues,
    ]);
    exit;
}

if ($action === 'clear') {
    $cleared = vtm_signal_clear();
    header('Content-Type: application/json');
    if (!$cleared) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to clear signal buffer',
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => 'Signal buffer cleared',
        ]);
    }
    exit;
}

if (empty($_POST["text"])) {
    http_response_code(400);
    die("failed... text not specified...");
}

$text = trim($_POST["text"]);

if ($text === '') {
    http_response_code(400);
    die("failed... empty signal payload");
}

if (!vtm_signal_write($text)) {
    http_response_code(500);
    die("failed... unable to persist signal");
}

@chmod(vtm_signal_public_path(), 0666);

echo "success";

?>
