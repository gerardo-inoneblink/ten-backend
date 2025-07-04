<?php

require_once __DIR__ . '/../vendor/autoload.php';

use FlexkitTen\Config\AppConfig;
use FlexkitTen\Services\Database;
use FlexkitTen\Services\Logger;
use FlexkitTen\Services\Router;
use FlexkitTen\Services\MindbodyAPI;
use FlexkitTen\Services\OTPService;
use FlexkitTen\Services\SessionService;

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    $config = AppConfig::getInstance();
    $logger = Logger::getInstance();
    $database = Database::getInstance();
    $router = new Router();
    $sessionService = SessionService::getInstance();

    $mindbodyApi = new MindbodyAPI();
    $otpService = new OTPService($database, $mindbodyApi, $logger, $sessionService);

    $logger->info("FlexKit Ten Auth API started", [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    $router->enableCors();

    $sessionService->updateActivity();

    $router->addMiddleware(function($request, $response) use ($logger) {
        $logger->debug("CORS middleware processed");
        return true;
    });

    $router->addMiddleware(function($request, $response) use ($logger) {
        $logger->info("Request received", [
            'method' => $request['method'],
            'path' => $request['path'],
            'query' => $request['query'],
            'user_agent' => $request['headers']['user-agent'] ?? 'Unknown'
        ]);
        return true;
    });

    // Health check endpoint
    $router->get('/', function($request, $response) use ($config, $database) {
        return [
            'success' => true,
            'message' => 'FlexKit Ten Auth API is running',
            'version' => '1.0.0',
            'environment' => $config->get('APP_ENV'),
            'database_connected' => true,
            'timestamp' => date('c')
        ];
    });

    // API status endpoint
    $router->get('/api/status', function($request, $response) use ($config, $mindbodyApi) {
        $mindbodyStatus = false;
        try {
            $mindbodyStatus = $mindbodyApi->testConnection();
        } catch (\Exception $e) {
            // Connection test failed
        }

        return [
            'success' => true,
            'status' => 'operational',
            'services' => [
                'database' => true,
                'mindbody_api' => $mindbodyStatus,
                'session' => session_status() === PHP_SESSION_ACTIVE
            ],
            'timestamp' => date('c')
        ];
    });

    // =======================
    // AUTHENTICATION ROUTES
    // =======================

    // Send OTP via email
    $router->post('/api/auth/email', function($request, $response) use ($otpService, $router, $logger) {
        $email = $request['body']['email'] ?? '';

        if (empty($email)) {
            return $router->sendError('Email is required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $router->sendError('Invalid email format', 400);
        }

        try {
            $logger->info("Starting email OTP process", ['email' => $email]);
            $result = $otpService->sendEmailOtp($email);
            
            if ($result['success']) {
                return $router->sendSuccess($result, 'OTP sent successfully');
            } else {
                $logger->error("OTP service returned error", ['result' => $result]);
                return $router->sendError($result['message'], 400);
            }
        } catch (\Exception $e) {
            $logger->error("Exception in email OTP route", [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return $router->sendError('Internal server error: ' . $e->getMessage(), 500);
        }
    });

    // Verify OTP
    $router->post('/api/auth/verify', function($request, $response) use ($otpService, $router) {
        $otpCode = $request['body']['otp_code'] ?? '';
        
        if (empty($otpCode)) {
            return $router->sendError('OTP code is required', 400);
        }

        $result = $otpService->verifyOtp($otpCode);
        
        if ($result['success']) {
            return $router->sendSuccess($result, 'Authentication successful');
        } else {
            return $router->sendError($result['message'], 401);
        }
    });

    // Logout
    $router->post('/api/auth/logout', function($request, $response) use ($otpService, $router) {
        $otpService->logout();
        return $router->sendSuccess(null, 'Logged out successfully');
    });

    // Check authentication status
    $router->get('/api/auth/status', function($request, $response) use ($otpService, $sessionService) {
        $isAuthenticated = $otpService->isAuthenticated();
        $client = $sessionService->get('authenticated_client');
        
        return [
            'authenticated' => $isAuthenticated,
            'client' => $isAuthenticated ? [
                'id' => $client['Id'] ?? null,
                'first_name' => $client['FirstName'] ?? '',
                'last_name' => $client['LastName'] ?? '',
                'email' => $client['Email'] ?? ''
            ] : null,
            'session_id' => $sessionService->getSessionId()
        ];
    });

    $router->handle();

} catch (\Throwable $e) {
    $errorMessage = 'Internal Server Error';
    $statusCode = 500;
    
    if (isset($logger)) {
        $logger->critical('Application error: ' . $e->getMessage(), [
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString()
        ]);
    } else {
        error_log('FlexKit Ten Error: ' . $e->getMessage());
    }

    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $errorMessage,
        'status' => $statusCode
    ], JSON_PRETTY_PRINT);
} 