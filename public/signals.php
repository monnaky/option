<?php
/**
 * Signal Receiver for VTM Option Trading Bot
 * 
 * This script receives trading signals from your signal provider
 * and forwards them to the VTM Option backend API.
 * 
 * Configuration:
 * - Set SIGNAL_API_KEY: Your secure API key (from backend environment)
 * - Set BACKEND_URL: Your backend API URL (default: https://api.vtmoption.com)
 * 
 * Usage:
 * POST to this script with: text=RISE or text=FALL or text=RISE R_10
 * 
 * Domain: Configured for vtmoption.com
 */

// Configuration
$SIGNAL_API_KEY = getenv('SIGNAL_API_KEY') ?: 'your-signal-api-key-here';
// Default to Railway backend URL - set BACKEND_URL environment variable to override
// Railway: Use your Railway public domain (e.g., https://your-service.up.railway.app)
// Or use custom domain backend URL (e.g., https://api.vtmoption.com)
$BACKEND_URL = getenv('BACKEND_URL') ?: 'https://api.vtmoption.com';

// Validate input
if (empty($_POST["text"])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Missing "text" parameter',
        'message' => 'Please provide a "text" parameter with the signal (RISE, FALL, RISE R_10, etc.)'
    ]);
    exit;
}

$text = trim($_POST["text"]);

// Validate signal format
$textUpper = strtoupper($text);
$isValidSignal = ($textUpper === 'RISE' || 
                  $textUpper === 'FALL' || 
                  preg_match('/^(RISE|FALL)\s+\w+$/', $textUpper));

if (!$isValidSignal) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Invalid signal format',
        'message' => 'Signal must be "RISE", "FALL", "RISE <asset>", or "FALL <asset>"',
        'received' => $text
    ]);
    exit;
}

// Prepare API request
$apiUrl = rtrim($BACKEND_URL, '/') . '/api/signals/receive';
$postData = json_encode(['text' => $text]);

// Initialize cURL
$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . $SIGNAL_API_KEY,
        'Content-Length: ' . strlen($postData)
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);

// Execute request
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

// Handle response
if ($curlError) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => 'Connection error',
        'message' => 'Failed to connect to backend API: ' . $curlError
    ]);
    exit;
}

// Parse response
$responseData = json_decode($response, true);

if ($httpCode >= 200 && $httpCode < 300) {
    // Success
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'message' => 'Signal forwarded successfully',
        'signal' => $text,
        'backend_response' => $responseData
    ]);
} else {
    // Error from backend
    http_response_code($httpCode);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => $responseData['error'] ?? 'Unknown error',
        'message' => $responseData['message'] ?? 'Backend API returned an error',
        'http_code' => $httpCode,
        'backend_response' => $responseData
    ]);
}
?>

