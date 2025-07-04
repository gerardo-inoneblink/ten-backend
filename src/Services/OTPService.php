<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class OTPService
{
    private Database $database;
    private MindbodyAPI $mindbodyApi;
    private Logger $logger;
    private AppConfig $config;
    private SessionService $sessionService;
    
    private const OTP_EXPIRY_MINUTES = 10;

    public function __construct(
        Database $database, 
        MindbodyAPI $mindbodyApi, 
        Logger $logger,
        SessionService $sessionService
    ) {
        $this->database = $database;
        $this->mindbodyApi = $mindbodyApi;
        $this->logger = $logger;
        $this->config = AppConfig::getInstance();
        $this->sessionService = $sessionService;
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function cleanupOldOtps(string $sessionId): void
    {
        $this->database->delete('flexkit_otp_sessions', [
            'session_id' => $sessionId
        ]);

        $this->database->query(
            "DELETE FROM flexkit_otp_sessions WHERE expires_at < UTC_TIMESTAMP() OR used = 1"
        );
    }

    public function sendEmailOtp(string $email): array
    {
        $this->logger->logOtpOperation("Starting email OTP process", ['email' => $email]);

        try {
            $this->logger->logOtpOperation("Searching client by email in Mindbody");
            $response = $this->mindbodyApi->searchClientByEmail($email);
            
            if (empty($response['Clients'])) {
                throw new \Exception('Client not found in Mindbody system');
            }

            $client = null;
            foreach ($response['Clients'] as $clientData) {
                if (strtolower($clientData['Email']) === strtolower($email)) {
                    $client = $clientData;
                    break;
                }
            }

            if (!$client) {
                throw new \Exception('Client with exact email match not found');
            }

            $this->logger->logOtpOperation("Client found in Mindbody", [
                'client_id' => $client['Id'],
                'first_name' => $client['FirstName'] ?? '',
                'last_name' => $client['LastName'] ?? ''
            ]);

            $sessionId = $this->sessionService->getSessionId();
            $this->sessionService->set('mbo_client', $client);

            $otp = $this->generateOtp();
            
            $this->cleanupOldOtps($sessionId);

            $expiresAt = date('Y-m-d H:i:s', time() + (self::OTP_EXPIRY_MINUTES * 60));
            
            $otpId = $this->database->insert('flexkit_otp_sessions', [
                'session_id' => $sessionId,
                'otp_code' => $otp,
                'client_id' => $client['Id'],
                'client_email' => $email,
                'delivery_method' => 'email',
                'expires_at' => $expiresAt,
                'used' => 0
            ]);

            $this->logger->logOtpOperation("OTP stored in database", [
                'otp_id' => $otpId,
                'expires_at' => $expiresAt
            ]);

            $emailSent = $this->sendOtpEmail($email, $otp, $client);
            
            if (!$emailSent) {
                throw new \Exception('Failed to send OTP email');
            }

            $this->logger->logOtpOperation("OTP email sent successfully", ['email' => $email]);

            return [
                'success' => true,
                'message' => 'OTP sent to your email address',
                'email' => $email,
                'expires_in_minutes' => self::OTP_EXPIRY_MINUTES
            ];

        } catch (\Exception $e) {
            $this->logger->error("Email OTP failed: " . $e->getMessage(), [
                'email' => $email,
                'full_error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function sendOtpEmail(string $email, string $otp, array $client): bool
    {
        $clientName = trim(($client['FirstName'] ?? '') . ' ' . ($client['LastName'] ?? ''));
        $subject = 'FlexKit Verification Code';
        
        $message = "Hi " . ($clientName ?: 'there') . ",\n\n";
        $message .= "Your FlexKit verification code is: " . $otp . "\n\n";
        $message .= "This code will expire in " . self::OTP_EXPIRY_MINUTES . " minutes.\n";
        $message .= "Never share this code with anyone.\n\n";
        $message .= "Powered by FlexKit.";

        $headers = [
            'From: FlexKit <noreply@flexkit.app>',
            'Reply-To: noreply@flexkit.app',
            'Content-Type: text/plain; charset=UTF-8',
            'X-Mailer: FlexKit OTP Service'
        ];

        $environment = $this->config->get('APP_ENV', 'production');
        
        if ($environment === 'development') {
            $this->logger->logOtpOperation("DEVELOPMENT MODE: OTP would be sent", [
                'email' => $email,
                'otp_code' => $otp,
                'subject' => $subject,
                'message' => $message
            ]);
            return true;
        }

        $result = mail($email, $subject, $message, implode("\r\n", $headers));
        
        if (!$result) {
            $error = error_get_last();
            $this->logger->error("Email sending failed", [
                'email' => $email,
                'error' => $error,
                'mail_function_result' => $result
            ]);
        }
        
        $this->logger->logOtpOperation("Email send attempt", [
            'email' => $email,
            'result' => $result ? 'success' : 'failed'
        ]);

        return $result;
    }

    public function verifyOtp(string $otpCode): array
    {
        $this->logger->logOtpOperation("Starting OTP verification", ['otp_code' => $otpCode]);

        try {
            $sessionId = $this->sessionService->getSessionId();
            
            $otpRecord = $this->database->findOne('flexkit_otp_sessions', [
                'session_id' => $sessionId,
                'otp_code' => $otpCode,
                'used' => 0
            ]);

            if (!$otpRecord) {
                throw new \Exception('Invalid or expired OTP code');
            }

            if (strtotime($otpRecord['expires_at']) < time()) {
                throw new \Exception('OTP code has expired');
            }

            $this->logger->logOtpOperation("Valid OTP found", [
                'otp_id' => $otpRecord['id'],
                'client_id' => $otpRecord['client_id']
            ]);

            $this->database->update('flexkit_otp_sessions', 
                ['used' => 1], 
                ['id' => $otpRecord['id']]
            );

            $client = $this->mindbodyApi->getClientById($otpRecord['client_id']);
            
            if (!$client) {
                throw new \Exception('Client not found in Mindbody system');
            }

            $this->storeClientDetails($client, $sessionId);
            $this->sessionService->set('authenticated_client', $client);
            $this->sessionService->set('auth_time', time());

            $this->logger->logOtpOperation("OTP verification successful", [
                'client_id' => $client['Id'],
                'email' => $client['Email']
            ]);

            return [
                'success' => true,
                'message' => 'Authentication successful',
                'client' => [
                    'id' => $client['Id'],
                    'first_name' => $client['FirstName'] ?? '',
                    'last_name' => $client['LastName'] ?? '',
                    'email' => $client['Email'] ?? '',
                    'phone' => $client['MobilePhone'] ?? ''
                ],
                'session_id' => $sessionId
            ];

        } catch (\Exception $e) {
            $this->logger->error("OTP verification failed: " . $e->getMessage(), [
                'otp_code' => $otpCode
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    private function storeClientDetails(array $client, string $sessionId): void
    {
        $siteId = $this->mindbodyApi->getDefaultSiteId();
        $expiresAt = date('Y-m-d H:i:s', time() + (30 * 24 * 3600));

        $existingClient = $this->database->findOne('flexkit_client_details', [
            'mindbody_client_id' => $client['Id']
        ]);

        $clientData = [
            'session_id' => $sessionId,
            'site_id' => $siteId,
            'first_name' => $client['FirstName'] ?? '',
            'last_name' => $client['LastName'] ?? '',
            'email' => $client['Email'] ?? '',
            'phone' => $client['MobilePhone'] ?? '',
            'last_login' => date('Y-m-d H:i:s'),
            'expires_at' => $expiresAt
        ];

        if ($existingClient) {
            $this->database->update('flexkit_client_details', 
                $clientData, 
                ['mindbody_client_id' => $client['Id']]
            );
            
            $this->logger->logOtpOperation("Updated existing client details", [
                'client_id' => $client['Id']
            ]);
        } else {
            $clientData['mindbody_client_id'] = $client['Id'];
            $this->database->insert('flexkit_client_details', $clientData);
            
            $this->logger->logOtpOperation("Stored new client details", [
                'client_id' => $client['Id']
            ]);
        }
    }

    public function getClientDetails(string $sessionId = null): ?array
    {
        $sessionId = $sessionId ?: $this->sessionService->getSessionId();
        
        $clientDetails = $this->database->findOne('flexkit_client_details', [
            'session_id' => $sessionId
        ]);

        if (!$clientDetails) {
            return null;
        }

        if (strtotime($clientDetails['expires_at']) < time()) {
            $this->database->delete('flexkit_client_details', [
                'id' => $clientDetails['id']
            ]);
            return null;
        }

        return $clientDetails;
    }

    public function isAuthenticated(): bool
    {
        $clientDetails = $this->getClientDetails();
        $sessionClient = $this->sessionService->get('authenticated_client');
        
        return $clientDetails !== null && $sessionClient !== null;
    }

    public function logout(): void
    {
        $sessionId = $this->sessionService->getSessionId();
        
        $this->database->delete('flexkit_client_details', [
            'session_id' => $sessionId
        ]);

        $this->sessionService->clear();
        
        $this->logger->logOtpOperation("User logged out", [
            'session_id' => $sessionId
        ]);
    }
} 