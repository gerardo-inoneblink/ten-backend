<?php

require_once __DIR__ . '/vendor/autoload.php';

use FlexkitTen\Config\AppConfig;
use FlexkitTen\Services\Database;
use FlexkitTen\Services\Logger;
use FlexkitTen\Services\Router;
use FlexkitTen\Services\MindbodyAPI;
use FlexkitTen\Services\StatelessOTPService;
use FlexkitTen\Services\JWTService;
use FlexkitTen\Services\StatelessAuthMiddleware;
use FlexkitTen\Services\TimetableService;

error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    $config = AppConfig::getInstance();
    $logger = Logger::getInstance();
    $database = Database::getInstance();
    $router = new Router();

    $mindbodyApi = new MindbodyAPI();
    $jwtService = new JWTService();
    $otpService = new StatelessOTPService($database, $mindbodyApi, $logger, $jwtService);
    $authMiddleware = new StatelessAuthMiddleware($jwtService, $logger);
    $timetableService = new TimetableService($mindbodyApi, $logger);

    $logger->info("FlexKit Ten stateless application started", [
        'request_uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'
    ]);

    $router->enableCors();

    // Basic status endpoint
    $router->get('/api/status', function($request, $response) use ($config, $mindbodyApi) {
        $mindbodyStatus = false;
        try {
            $mindbodyStatus = $mindbodyApi->testConnection();
        } catch (\Exception $e) {
        }

        return [
            'status' => 'success',
            'message' => 'API status check completed',
            'data' => [
                'status' => 'operational',
                'services' => [
                    'database' => true,
                    'mindbody_api' => $mindbodyStatus,
                    'auth_method' => 'jwt_stateless'
                ],
                'timestamp' => date('c')
            ]
        ];
    });

    // STATELESS AUTH ENDPOINTS - No sessions needed

    $router->post('/api/auth/email', function($request, $response) use ($otpService, $router, $logger) {
        $email = $request['body']['email'] ?? '';

        if (empty($email)) {
            return $router->sendError('Email is required', 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $router->sendError('Invalid email format', 400);
        }

        try {
            $logger->info("Starting stateless email OTP process", ['email' => $email]);
            $result = $otpService->sendEmailOtp($email);
            
            if ($result['success']) {
                // Return request_id that client needs for verification
                $responseData = [
                    'request_id' => $result['request_id'], // NEW: Client needs this
                    'email' => $email,
                    'expires_in' => 600 // 10 minutes
                ];
                return $router->sendSuccess($responseData, 'Verification code sent successfully.', 'otp_sent');
            } else {
                $logger->error("OTP service returned error", ['result' => $result]);
                if (strpos($result['message'], 'not found') !== false) {
                    return $router->sendError($result['message'], 404);
                }
                return $router->sendError($result['message'], 500);
            }
        } catch (\Exception $e) {
            $logger->error("Exception in stateless email OTP route", [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return $router->sendError('API configuration error', 500);
        }
    });

    $router->post('/api/auth/verify', function($request, $response) use ($otpService, $router, $mindbodyApi, $logger) {
        $requestId = $request['body']['request_id'] ?? '';
        $otpCode = $request['body']['otp'] ?? '';
        
        if (empty($requestId)) {
            return $router->sendError('Request ID is required', 400);
        }
        
        if (empty($otpCode)) {
            return $router->sendError('OTP code is required', 400);
        }

        try {
            $result = $otpService->verifyOtp($requestId, $otpCode);
            
            if ($result['success']) {
                // Get client schedule (optional)
                $schedule = [];
                try {
                    $scheduleResult = $mindbodyApi->makeRequest('/client/clientschedule', [
                        'ClientId' => $result['client']['id']
                    ]);
                    $schedule = $scheduleResult['Visits'] ?? [];
                } catch (\Exception $e) {
                    // Schedule fetch failed, but continue with empty schedule
                }
                
                $responseData = [
                    'access_token' => $result['access_token'], // JWT token
                    'token_type' => $result['token_type'],
                    'expires_in' => $result['expires_in'],
                    'client' => $result['client'],
                    'schedule' => $schedule
                ];
                
                return $router->sendSuccess($responseData, 'Verification successful.', 'verification_successful');
            } else {
                if (strpos($result['message'], 'not found') !== false) {
                    return $router->sendError($result['message'], 404);
                }
                return $router->sendError($result['message'], 400, 'invalid_otp');
            }
        } catch (\Exception $e) {
            $logger->error("Exception in stateless verify route", [
                'request_id' => $requestId,
                'error' => $e->getMessage()
            ]);
            return $router->sendError('System error', 500);
        }
    });

    $router->get('/api/auth/status', function($request, $response) use ($authMiddleware) {
        $tokenData = $authMiddleware->validateRequest($request);
        
        return [
            'status' => 'success',
            'message' => 'Authentication status retrieved',
            'data' => [
                'authenticated' => $tokenData !== null,
                'client' => $tokenData['client'] ?? null,
                'expires_at' => $tokenData ? date('c', $tokenData['exp']) : null
            ]
        ];
    });

    // PROTECTED ROUTES - Require JWT authentication

    $router->post('/api/class/book', function($request, $response) use ($timetableService, $authMiddleware, $router) {
        try {
            // Validate authentication
            $request = $authMiddleware->requireAuth($request);
            $client = $authMiddleware->getAuthenticatedClient($request);
            
            $classId = $request['body']['classId'] ?? 0;

            if (empty($classId)) {
                return $router->sendError('Class ID is required', 400);
            }

            $result = $timetableService->bookClass($client['id'], $classId);
            
            if ($result['success']) {
                return $router->sendSuccess($result['booking_data'] ?? [], 'Class booked successfully');
            } else {
                if (strpos($result['message'], 'remaining sessions') !== false || 
                    strpos($result['message'], 'active packages') !== false) {
                    return $router->sendError($result['message'], 403);
                }
                return $router->sendError($result['message'], 500);
            }
        } catch (\Exception $e) {
            return $router->sendError($e->getMessage(), $e->getCode() ?: 401);
        }
    });

    $router->get('/api/client/complete-info', function($request, $response) use ($mindbodyApi, $authMiddleware, $router) {
        try {
            // Validate authentication
            $request = $authMiddleware->requireAuth($request);
            $client = $authMiddleware->getAuthenticatedClient($request);
            
            $startDate = $request['query']['start_date'] ?? null;
            $endDate = $request['query']['end_date'] ?? null;

            $result = $mindbodyApi->getClientCompleteInfo(
                $client['id'], 
                $mindbodyApi->getDefaultSiteId(), 
                $startDate, 
                $endDate
            );
            
            return $router->sendJson([
                'status' => 'success',
                'data' => $result
            ]);
        } catch (\Exception $e) {
            return $router->sendError($e->getMessage(), $e->getCode() ?: 401);
        }
    });

    // Add more protected routes here following the same pattern...

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

    // CORS headers for errors
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [
        'http://localhost:5000', 'https://localhost:5000',
        'http://127.0.0.1:5000', 'https://127.0.0.1:5000',
        'http://localhost:5001', 'https://localhost:5001',
        'http://127.0.0.1:5001', 'https://127.0.0.1:5001'
    ];
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin');
    header('Access-Control-Allow-Credentials: true');
    
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $errorMessage,
        'status' => $statusCode
    ], JSON_PRETTY_PRINT);
}
