<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FlexkitTen\Config\AppConfig;
use FlexkitTen\Services\Database;

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    $config = AppConfig::getInstance();
    $db = Database::getInstance();

    // Create tables if they do not exist
    $db->createTables();

    // Set the base URL for the application
    $baseUrl = $config->get('base_url', 'http://localhost:8000');
    $config->set('base_url', rtrim($baseUrl, '/'));

} catch (\Throwable $e) {
    $errorMessage = "Internal Server Error: " . $e->getMessage();
    $statusCode = 500;

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $errorMessage,
        'status_code' => $statusCode
    ], JSON_PRETTY_PRINT);
}