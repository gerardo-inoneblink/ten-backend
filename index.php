<?php

require_once __DIR__ . '/vendor/autoload.php';

use FlexkitTen\Config\AppConfig;
use FlexkitTen\Services\Database;
use FlexkitTen\Services\Logger;
use FlexkitTen\Services\Router;
use FlexkitTen\Services\MindbodyAPI;
use FlexkitTen\Services\OTPService;
use FlexkitTen\Services\SessionService;
use FlexkitTen\Services\TimetableService;

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
    $timetableService = new TimetableService($mindbodyApi, $logger);

    $logger->info("FlexKit Ten application started", [
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

    $router->get('/', function($request, $response) use ($config, $database) {
        return [
            'status' => 'success',
            'message' => 'FlexKit Ten API is running',
            'version' => '1.0.0',
            'environment' => $config->get('APP_ENV'),
            'database_connected' => true,
            'timestamp' => date('c')
        ];
    });

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
                    'session' => session_status() === PHP_SESSION_ACTIVE
                ],
                'timestamp' => date('c')
            ]
        ];
    });

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
                // Format response according to API_ENDPOINTS.md
                $responseData = [
                    'email' => $email,
                    'expires_in' => 300
                ];
                return $router->sendSuccess($responseData, 'Verification code sent successfully.', 'otp_sent');
            } else {
                $logger->error("OTP service returned error", ['result' => $result]);
                // Check if client not found
                if (strpos($result['message'], 'not found') !== false) {
                    return $router->sendError($result['message'], 404);
                }
                return $router->sendError($result['message'], 500);
            }
        } catch (\Exception $e) {
            $logger->error("Exception in email OTP route", [
                'email' => $email,
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            return $router->sendError('API configuration error', 500, null, [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
        }
    });

    $router->post('/api/auth/verify', function($request, $response) use ($otpService, $router, $mindbodyApi, $sessionService) {
        $email = $request['body']['email'] ?? '';
        $otpCode = $request['body']['otp'] ?? '';
        
        if (empty($email)) {
            return $router->sendError('Email is required', 400);
        }
        
        if (empty($otpCode)) {
            return $router->sendError('OTP code is required', 400);
        }

        $result = $otpService->verifyOtp($otpCode);
        
        if ($result['success']) {
            try {
                // Get client details from session
                $client = $sessionService->get('authenticated_client');
                
                if (!$client) {
                    return $router->sendError('Client not found', 404);
                }
                
                // Get client schedule
                $schedule = [];
                try {
                    $scheduleResult = $mindbodyApi->makeRequest('/client/clientschedule', [
                        'ClientId' => $client['Id']
                    ]);
                    $schedule = $scheduleResult['Visits'] ?? [];
                } catch (\Exception $e) {
                    // Schedule fetch failed, but continue with empty schedule
                }
                
                // Generate session token
                $sessionToken = bin2hex(random_bytes(32));
                $sessionService->set('session_token', $sessionToken);
                $sessionService->set('token_expires_at', time() + (24 * 60 * 60)); // 24 hours
                
                $responseData = [
                    'client' => [
                        'id' => $client['Id'],
                        'first_name' => $client['FirstName'] ?? '',
                        'last_name' => $client['LastName'] ?? '',
                        'email' => $client['Email'] ?? ''
                    ],
                    'schedule' => $schedule,
                    'session' => [
                        'id' => $sessionToken,
                        'expires_at' => date('c', time() + (24 * 60 * 60)),
                        'last_activity' => date('c')
                    ]
                ];
                
                return $router->sendSuccess($responseData, 'Verification successful.', 'verification_successful');
            } catch (\Exception $e) {
                return $router->sendError('System error', 500, null, [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine()
                ]);
            }
        } else {
            if (strpos($result['message'], 'not found') !== false) {
                return $router->sendError($result['message'], 404);
            }
            return $router->sendError($result['message'], 400, 'invalid_otp');
        }
    });

    $router->get('/api/auth/status', function($request, $response) use ($otpService, $sessionService) {
        $isAuthenticated = $otpService->isAuthenticated();
        $client = $sessionService->get('authenticated_client');
        
        return [
            'status' => 'success',
            'message' => 'Authentication status retrieved',
            'data' => [
                'authenticated' => $isAuthenticated,
                'client' => $isAuthenticated ? [
                    'id' => $client['Id'] ?? null,
                    'first_name' => $client['FirstName'] ?? '',
                    'last_name' => $client['LastName'] ?? '',
                    'email' => $client['Email'] ?? ''
                ] : null,
                'session_id' => $sessionService->getSessionId()
            ]
        ];
    });

    $router->get('/api/timetable/filters', function($request, $response) use ($timetableService, $router) {
        try {
            $onlineOnly = ($request['query']['online_only'] ?? 'true') === 'true';
            $filters = $timetableService->getFilterOptions($onlineOnly);
            return $router->sendSuccess($filters, 'Filter options retrieved successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to get filter options: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/timetable/data', function($request, $response) use ($timetableService, $router, $sessionService) {
        try {
            // Required parameters
            $programType = $request['query']['programType'] ?? '';
            $startDateTime = $request['query']['startDateTime'] ?? '';
            $endDateTime = $request['query']['endDateTime'] ?? '';
            
            if (empty($programType)) {
                return $router->sendError('programType is required', 400);
            }
            
            if (!in_array($programType, ['classes', 'appointments'])) {
                return $router->sendError('programType must be either "classes" or "appointments"', 400);
            }
            
            if (empty($startDateTime)) {
                return $router->sendError('startDateTime is required', 400);
            }
            
            if (empty($endDateTime)) {
                return $router->sendError('endDateTime is required', 400);
            }
            
            // Optional parameters
            $sessionTypeId = isset($request['query']['sessionTypeId']) ? (int)$request['query']['sessionTypeId'] : null;
            $programId = isset($request['query']['programId']) ? (int)$request['query']['programId'] : null;
            $locationId = isset($request['query']['locationId']) ? (int)$request['query']['locationId'] : null;
            
            // Type-specific validation
            if ($programType === 'appointments' && empty($sessionTypeId)) {
                return $router->sendError('sessionTypeId is required for appointments', 400);
            }
            
            // Build filters for TimetableService
            $filters = [
                'scheduleType' => $programType === 'classes' ? 'Class' : 'Appointment',
                'startDate' => $startDateTime,
                'endDate' => $endDateTime
            ];
            
            if ($locationId) {
                $filters['locationIds'] = [$locationId];
            }
            
            if ($programType === 'classes' && $programId) {
                $filters['programIds'] = [$programId];
            } elseif ($programType === 'appointments' && $sessionTypeId) {
                $filters['sessionTypeIds'] = [$sessionTypeId];
            }
            
            // Get authenticated client for visits (optional)
            $clientId = null;
            $authHeader = $request['headers']['authorization'] ?? '';
            if (strpos($authHeader, 'Bearer ') === 0) {
                $token = substr($authHeader, 7);
                $storedToken = $sessionService->get('session_token');
                $tokenExpiry = $sessionService->get('token_expires_at');
                
                if ($token === $storedToken && $tokenExpiry > time()) {
                    $client = $sessionService->get('authenticated_client');
                    if ($client) {
                        $clientId = $client['Id'];
                    }
                }
            }
            
            $data = $timetableService->getTimetableData($filters, $clientId);
            return $router->sendSuccess($data, 'Timetable data retrieved successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to get timetable data: ' . $e->getMessage(), 500);
        }
    });

    $router->post('/api/class/book', function($request, $response) use ($timetableService, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }

        $classId = $request['body']['classId'] ?? 0;

        if (empty($classId)) {
            return $router->sendError('Class ID is required', 400);
        }

        $result = $timetableService->bookClass($client['Id'], $classId);
        
        if ($result['success']) {
            return $router->sendSuccess($result['booking_data'] ?? [], 'Class booked successfully');
        } else {
            if (strpos($result['message'], 'remaining sessions') !== false || 
                strpos($result['message'], 'active packages') !== false) {
                return $router->sendError($result['message'], 403);
            }
            return $router->sendError($result['message'], 500);
        }
    });

    $router->post('/api/class/cancel', function($request, $response) use ($timetableService, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }
        
        $classId = $request['body']['classId'] ?? 0;
        $startDateTime = $request['body']['startDateTime'] ?? '';

        if (empty($classId)) {
            return $router->sendError('Class ID is required', 400);
        }
        
        if (empty($startDateTime)) {
            return $router->sendError('startDateTime is required', 400);
        }

        // Check if cancellation is late (within 12 hours)
        $lateCancel = false;
        // $classTime = strtotime($startDateTime);
        // if ($classTime && $classTime - time() < 12 * 60 * 60) {
        //     $lateCancel = true;
        // }

        $result = $timetableService->cancelClass($client['Id'], $classId, $lateCancel);
        
        if ($result['success']) {
            $responseData = [
                'late_cancel' => $lateCancel,
                'result' => (object)[]
            ];
            return $router->sendSuccess($responseData, 'Class cancelled successfully');
        } else {
            return $router->sendError($result['message'], 500);
        }
    });

    $router->post('/api/appointment/book', function($request, $response) use ($timetableService, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }
        
        $startDateTime = $request['body']['startDateTime'] ?? '';
        $staffId = $request['body']['staffId'] ?? 0;
        $locationId = $request['body']['locationId'] ?? 0;
        $sessionTypeId = $request['body']['sessionTypeId'] ?? 0;
        $notes = $request['body']['notes'] ?? '';

        if (empty($startDateTime)) {
            return $router->sendError('startDateTime is required', 400);
        }

        if (empty($staffId)) {
            return $router->sendError('staffId is required', 400);
        }

        if (empty($locationId)) {
            return $router->sendError('locationId is required', 400);
        }

        if (empty($sessionTypeId)) {
            return $router->sendError('sessionTypeId is required', 400);
        }

        if (!strtotime($startDateTime)) {
            return $router->sendError('Invalid startDateTime format. Use ISO 8601 format (e.g., 2024-01-01T10:00:00)', 400);
        }

        $appointmentData = [
            'startDateTime' => $startDateTime,
            'staffId' => $staffId,
            'locationId' => $locationId,
            'sessionTypeId' => $sessionTypeId
        ];

        if (!empty($notes)) {
            $appointmentData['notes'] = $notes;
        }

        $result = $timetableService->bookAppointment($client['Id'], $appointmentData);
        
        if ($result['success']) {
            $responseData = [
                'AppointmentId' => $result['appointmentId'] ?? null,
                'StartDateTime' => $startDateTime
            ];
            return $router->sendSuccess($responseData, 'Appointment booked successfully');
        } else {
            $statusCode = 400;
            $errorResponse = [
                'status' => 'error',
                'message' => $result['message']
            ];
            
            if (isset($result['code'])) {
                $errorResponse['code'] = $result['code'];
                
                if (in_array($result['code'], ['no_active_services', 'invalid_service_type', 'no_remaining_sessions'])) {
                    $statusCode = 403;
                }
            }
            
            if (isset($result['redirect'])) {
                $errorResponse['redirect'] = $result['redirect'];
            }
            
            return $router->sendJson($errorResponse, $statusCode);
        }
    });

    $router->post('/api/appointment/cancel', function($request, $response) use ($timetableService, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }

        $appointmentId = $request['body']['appointmentId'] ?? 0;
        $sendEmail = $request['body']['sendEmail'] ?? true;
        $lateCancel = $request['body']['lateCancel'] ?? false;

        if (empty($appointmentId)) {
            return $router->sendError('appointmentId is required', 400);
        }

        if (!is_numeric($appointmentId)) {
            return $router->sendError('appointmentId must be a valid number', 400);
        }

        if (!is_bool($sendEmail) && !in_array($sendEmail, ['true', 'false', '1', '0'], true)) {
            return $router->sendError('sendEmail must be a boolean value', 400);
        }

        if (!is_bool($lateCancel) && !in_array($lateCancel, ['true', 'false', '1', '0'], true)) {
            return $router->sendError('lateCancel must be a boolean value', 400);
        }

        $sendEmail = is_bool($sendEmail) ? $sendEmail : ($sendEmail === 'true' || $sendEmail === '1');
        $lateCancel = is_bool($lateCancel) ? $lateCancel : ($lateCancel === 'true' || $lateCancel === '1');

        $result = $timetableService->cancelAppointment($client['Id'], $appointmentId, $sendEmail, $lateCancel);
        
        if ($result['success']) {
            return $router->sendSuccess((object)[], 'Appointment cancelled successfully');
        } else {
            return $router->sendError($result['message'], 500);
        }
    });

    $router->post('/api/client/register', function($request, $response) use ($mindbodyApi, $router) {
        $data = $request['body'] ?? [];
        
        // Validate required fields
        if (empty($data['email'])) {
            return $router->sendError('Email is required', 400);
        }
        if (empty($data['firstName'])) {
            return $router->sendError('First name is required', 400);
        }
        if (empty($data['lastName'])) {
            return $router->sendError('Last name is required', 400);
        }
        if (empty($data['phone'])) {
            return $router->sendError('Phone number is required', 400);
        }
        if (empty($data['dateOfBirth'])) {
            return $router->sendError('Date of birth is required', 400);
        }
        if (empty($data['gender'])) {
            return $router->sendError('Gender is required', 400);
        }
        if (!isset($data['termsAccepted']) || !$data['termsAccepted']) {
            return $router->sendError('Terms and conditions must be accepted', 400);
        }

        try {
            $result = $mindbodyApi->createClient($data);
            
            // Transform response to match API documentation
            $responseData = [
                'client' => [
                    'id' => $result['Client']['Id'],
                    'email' => $result['Client']['Email'],
                    'first_name' => $result['Client']['FirstName'],
                    'last_name' => $result['Client']['LastName']
                ]
            ];
            
            return $router->sendSuccess($responseData, 'Account created successfully! Please log in to continue.');
        } catch (\Exception $e) {
            return $router->sendError('Failed to register client: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/client/complete-info', function($request, $response) use ($mindbodyApi, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }
        $startDate = $request['query']['start_date'] ?? null;
        $endDate = $request['query']['end_date'] ?? null;

        try {
            $result = $mindbodyApi->getClientCompleteInfo(
                $client['Id'], 
                $mindbodyApi->getDefaultSiteId(), 
                $startDate, 
                $endDate
            );
            
            // Return response format exactly as specified in API documentation
            $response = [
                'status' => 'success',
                'data' => $result
            ];
            
            return $router->sendJson($response);
        } catch (\Exception $e) {
            return $router->sendError('Failed to get client information: ' . $e->getMessage(), 500);
        }
    });

    $router->put('/api/client/update', function($request, $response) use ($mindbodyApi, $sessionService, $router) {
        // Check Bearer token authentication
        $authHeader = $request['headers']['authorization'] ?? '';
        if (strpos($authHeader, 'Bearer ') !== 0) {
            return $router->sendError('Authentication required', 401);
        }
        
        $token = substr($authHeader, 7);
        $storedToken = $sessionService->get('session_token');
        $tokenExpiry = $sessionService->get('token_expires_at');
        
        if ($token !== $storedToken || $tokenExpiry <= time()) {
            return $router->sendError('Authentication required', 401);
        }
        
        $client = $sessionService->get('authenticated_client');
        if (!$client) {
            return $router->sendError('Authentication required', 401);
        }

        $userData = $request['body'] ?? [];
        
        // Validate required fields for update
        $hasPersonalInfo = !empty($userData['first_name']) || !empty($userData['last_name']) || !empty($userData['email']);
        $hasCreditCardInfo = !empty($userData['Client']['ClientCreditCard']);
        
        if (!$hasPersonalInfo && !$hasCreditCardInfo) {
            return $router->sendError('At least one field (personal info or credit card info) is required for update', 400);
        }

        try {
            // Transform frontend data to Mindbody API format
            $clientData = [
                'Client' => [
                    'Id' => $client['Id']  // Required for update
                ]
            ];

            // Map frontend fields to Mindbody fields
            if (!empty($userData['first_name'])) {
                $clientData['Client']['FirstName'] = $userData['first_name'];
            }
            if (!empty($userData['last_name'])) {
                $clientData['Client']['LastName'] = $userData['last_name'];
            }
            if (!empty($userData['email'])) {
                $clientData['Client']['Email'] = $userData['email'];
            }
            if (!empty($userData['mobile_phone'])) {
                $clientData['Client']['MobilePhone'] = $userData['mobile_phone'];
            }
            if (!empty($userData['home_phone'])) {
                $clientData['Client']['HomePhone'] = $userData['home_phone'];
            }
            if (!empty($userData['birth_date'])) {
                $clientData['Client']['BirthDate'] = $userData['birth_date'];
            }
            if (!empty($userData['gender'])) {
                $clientData['Client']['Gender'] = ucfirst($userData['gender']);
            }
            if (!empty($userData['referred_by'])) {
                $clientData['Client']['ReferredBy'] = $userData['referred_by'];
            }
            if (isset($userData['send_promotional_emails'])) {
                $clientData['Client']['SendPromotionalEmails'] = $userData['send_promotional_emails'];
            }

            // Address fields - must be nested under Address object according to Mindbody API
            if (!empty($userData['line1'])) {
                $clientData['Client']['AddressLine1'] = $userData['line1'];
            }
            if (!empty($userData['city'])) {
                $clientData['Client']['City'] = $userData['city'];
            }
            if (!empty($userData['postal_code'])) {
                $clientData['Client']['PostalCode'] = $userData['postal_code'];
            }
            if (!empty($userData['country'])) {
                $clientData['Client']['Country'] = $userData['country'];
            }

            // Handle ClientCreditCard data if provided
            if (!empty($userData['Client']['ClientCreditCard'])) {
                $creditCardData = $userData['Client']['ClientCreditCard'];
                
                // Validate required credit card fields
                $requiredFields = ['CardHolder', 'CardNumber', 'ExpMonth', 'ExpYear'];
                foreach ($requiredFields as $field) {
                    if (empty($creditCardData[$field])) {
                        return $router->sendError("Credit card field '$field' is required", 400);
                    }
                }
                
                // Set credit card data in the format expected by Mindbody API
                $clientData['Client']['ClientCreditCard'] = [
                    'CardHolder' => $creditCardData['CardHolder'],
                    'CardNumber' => $creditCardData['CardNumber'],
                    'ExpMonth' => $creditCardData['ExpMonth'],
                    'ExpYear' => $creditCardData['ExpYear'],
                    'PostalCode' => $creditCardData['PostalCode'] ?? ''
                ];
            }

            // Log the client data being sent to Mindbody API for debugging
            error_log('Sending client data to Mindbody API: ' . json_encode($clientData, JSON_PRETTY_PRINT));
            
            $result = $mindbodyApi->updateClient($clientData);
            
            // Update the session with new client data if successful
            if (isset($result['Client'])) {
                $sessionService->set('authenticated_client', $result['Client']);
            }
            
            // Return response in expected format
            $responseData = [
                'client' => $result['Client'] ?? $client,
                'success' => true
            ];
            
            return $router->sendSuccess($responseData, 'Profile updated successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to update client profile: ' . $e->getMessage(), 500);
        }
    });

    $router->post('/api/purchase/contract', function($request, $response) use ($mindbodyApi, $otpService, $sessionService, $router) {
        if (!$otpService->isAuthenticated()) {
            return $router->sendError('Authentication required', 401);
        }

        $client = $sessionService->get('authenticated_client');
        $purchaseData = $request['body'] ?? [];
        
        $purchaseData['clientId'] = $client['Id'];

        if (empty($purchaseData['contract_id'])) {
            return $router->sendError('Contract ID is required', 400);
        }

        if (empty($purchaseData['location_id'])) {
            return $router->sendError('Location ID is required', 400);
        }

        try {
            $result = $mindbodyApi->purchaseContract($purchaseData);
            return $router->sendSuccess($result, 'Contract purchased successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to purchase contract: ' . $e->getMessage(), 500);
        }
    });

    $router->post('/api/purchase', function($request, $response) use ($mindbodyApi, $router) {
        $data = $request['body'] ?? [];
        
        // Validate required fields
        if (empty($data['type'])) {
            return $router->sendError('Purchase type is required', 400);
        }
        if (empty($data['id'])) {
            return $router->sendError('Purchase ID is required', 400);
        }
        if (empty($data['email'])) {
            return $router->sendError('Email is required', 400);
        }
        if (empty($data['first_name'])) {
            return $router->sendError('First name is required', 400);
        }
        if (empty($data['last_name'])) {
            return $router->sendError('Last name is required', 400);
        }
        if (empty($data['phone'])) {
            return $router->sendError('Phone is required', 400);
        }
        if (empty($data['credit_card'])) {
            return $router->sendError('Credit card information is required', 400);
        }

        try {
            if ($data['type'] === 'contract') {
                $result = $mindbodyApi->unifiedPurchaseContract($data);
                return $router->sendSuccess($result, 'Contract purchased successfully');
            } else {
                return $router->sendError('Unsupported purchase type', 400);
            }
        } catch (\Exception $e) {
            return $router->sendError('Failed to complete purchase: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/contracts', function($request, $response) use ($mindbodyApi, $router) {
        try {
            $locationId = isset($request['query']['location_id']) ? (int)$request['query']['location_id'] : null;
            $contracts = $mindbodyApi->getContracts(null, $locationId);
            return $router->sendSuccess($contracts, 'Contracts retrieved successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to get contracts: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/promotions', function($request, $response) use ($mindbodyApi, $router) {
        try {
            $promotions = $mindbodyApi->getPromotionCodes();
            return $router->sendSuccess($promotions, 'Promotion codes retrieved successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to get promotion codes: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/services', function($request, $response) use ($mindbodyApi, $router) {
        try {
            $services = $mindbodyApi->getServices();
            return $router->sendSuccess($services, 'Services retrieved successfully');
        } catch (\Exception $e) {
            return $router->sendError('Failed to get services: ' . $e->getMessage(), 500);
        }
    });

    $router->get('/api/test', function($request, $response) use ($mindbodyApi, $router) {
        try {
            // Simple API call to test connection
            $test = $mindbodyApi->makeRequest('/site/locations', [
                'limit' => 1
            ]);
            
            return $router->sendSuccess($test, 'API connection test completed');
        } catch (\Exception $e) {
            return $router->sendError('API connection test failed: ' . $e->getMessage(), 500);
        }
    });

    $router->post('/api/purchase/details', function($request, $response) use ($mindbodyApi, $router) {
        $type = $request['body']['type'] ?? 'service';
        $id = $request['body']['id'] ?? 0;
        
        if (empty($id)) {
            return $router->sendError('ID is required', 400);
        }
        
        try {
            if ($type === 'contract') {
                $locationId = isset($request['body']['location_id']) ? (int)$request['body']['location_id'] : null;
                $contract = $mindbodyApi->getContractById((int)$id, null, $locationId);
                
                if (!$contract) {
                    return $router->sendError('Contract not found', 404);
                }
                
                return $router->sendSuccess($contract, 'Contract details retrieved successfully');
            } else {
                // For services, get the locationId from request or use default
                $locationId = isset($request['body']['location_id']) ? (int)$request['body']['location_id'] : null;
                $service = $mindbodyApi->getServiceDetails((int)$id, $locationId);
                
                if (!$service) {
                    return $router->sendError('Service not found', 404);
                }
                
                return $router->sendSuccess($service, 'Service details retrieved successfully');
            }
        } catch (\Exception $e) {
            return $router->sendError('Failed to get purchase details: ' . $e->getMessage(), 500);
        }
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

    // Add CORS headers for error responses
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $allowedOrigins = [
        'http://localhost:5000',
        'https://localhost:5000',
        'http://127.0.0.1:5000',
        'https://127.0.0.1:5000',
        'http://localhost:5001',
        'https://localhost:5001',
        'http://127.0.0.1:5001',
        'https://127.0.0.1:5001'
    ];
    
    if (in_array($origin, $allowedOrigins)) {
        header('Access-Control-Allow-Origin: ' . $origin);
    } else {
        header('Access-Control-Allow-Origin: *');
    }
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS, PATCH');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept, Origin, X-Session-ID');
    header('Access-Control-Allow-Credentials: true');
    
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode([
        'error' => true,
        'message' => $errorMessage,
        'status' => $statusCode
    ], JSON_PRETTY_PRINT);
} 