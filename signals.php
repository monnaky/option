
require_once __DIR__ . '/app/bootstrap.php';
use App\Services\SignalService;
use App\Services\QueueService;
use App\Jobs\ProcessSignal;

// Enable CORS
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Handle clear action (legacy support, though less relevant with queue)
if ($action === 'clear') {
    // With queue, we don't really "clear" a file, but we can acknowledge
    echo json_encode([
        'success' => true,
        'message' => 'Signal queue system active',
    ]);
    exit;
}

// Get text from POST (support both form-data and JSON)
$text = $_POST["text"] ?? '';
if (empty($text)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';
}

if (empty($text)) {
    http_response_code(400);
    die("failed... text not specified...");
}

$text = trim($text);

try {
    // 1. Parse the text to extract signal details
    // Expected format: ASSET,SIGNAL_TYPE,TIMESTAMP or similar
    // We'll use a helper or simple logic here to parse it before queuing
    // so we can validate it early.
    
    $parts = explode(',', $text);
    $asset = trim($parts[0] ?? '');
    $message = trim($parts[1] ?? '');
    
    // Determine type
    $type = null;
    $messageLower = strtolower($message);
    if (strpos($messageLower, 'buy') !== false || strpos($messageLower, 'call') !== false || strpos($messageLower, 'rise') !== false) {
        $type = 'RISE';
    } elseif (strpos($messageLower, 'sell') !== false || strpos($messageLower, 'put') !== false || strpos($messageLower, 'fall') !== false) {
        $type = 'FALL';
    }
    
    if (!$asset || !$type) {
        // Fallback: Let SignalService handle raw parsing if we can't determine it here
        // But for the queue, we prefer structured data.
        // If we can't parse it, we might log it and fail, or push a "RawSignal" job.
        // For now, let's try to persist it via SignalService first to get an ID.
    }
    
    $signalService = SignalService::getInstance();
    
    // Create the signal record in DB first
    $result = $signalService->receiveSignal([
        'type' => $type ?? 'UNKNOWN', // SignalService will validate/refine
        'asset' => $asset,
        'rawText' => $text,
        'source' => 'api_webhook',
        'skip_execution' => true, // NEW FLAG: Don't execute immediately, just save
    ]);
    
    if ($result['success']) {
        // 2. Push to Queue
        $queue = QueueService::getInstance();
        $jobId = $queue->push(ProcessSignal::class, [
            'signal_id' => $result['signal_id'],
            'signal_type' => $result['signal_type'],
            'asset' => $result['asset'],
        ]);
        
        echo "success"; // Keep legacy response format for MT5 compatibility
    } else {
        http_response_code(400);
        echo "failed: " . ($result['error'] ?? 'Unknown error');
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log("Signal endpoint error: " . $e->getMessage());
    echo "failed... server error";
}

