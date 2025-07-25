<?php
// Simple CORS test script
// Access this script directly to test CORS headers

// Get the origin from the request
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

// Allow specific origins (localhost for development)
$allowedOrigins = [
    'http://localhost:5000',
    'https://localhost:5000',
    'http://127.0.0.1:5000',
    'https://127.0.0.1:5000'
];

// Set CORS headers
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
} else {
    header('Access-Control-Allow-Origin: *');
}

header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
header('Access-Control-Allow-Credentials: true');
header('Access-Control-Max-Age: 86400');

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Set content type
header('Content-Type: application/json');

// Return test response
echo json_encode([
    'status' => 'success',
    'message' => 'CORS is working correctly',
    'origin' => $origin,
    'method' => $_SERVER['REQUEST_METHOD'],
    'headers_sent' => [
        'Access-Control-Allow-Origin' => in_array($origin, $allowedOrigins) ? $origin : '*',
        'Access-Control-Allow-Methods' => 'GET, POST, PUT, DELETE, OPTIONS, PATCH',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, X-Requested-With, Accept, Origin',
        'Access-Control-Allow-Credentials' => 'true'
    ],
    'timestamp' => date('c')
], JSON_PRETTY_PRINT); 