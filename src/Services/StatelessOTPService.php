<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class StatelessOTPService
{
    private Database $database;
    private MindbodyAPI $mindbodyApi;
    private Logger $logger;
    private AppConfig $config;
    private EmailService $emailService;
    private JWTService $jwtService;

    private const OTP_EXPIRY_MINUTES = 10;

    public function __construct(
        Database $database,
        MindbodyAPI $mindbodyApi,
        Logger $logger,
        JWTService $jwtService
    ) {
        $this->database = $database;
        $this->mindbodyApi = $mindbodyApi;
        $this->logger = $logger;
        $this->config = AppConfig::getInstance();
        $this->emailService = new EmailService($logger);
        $this->jwtService = $jwtService;
    }

    private function generateOtp(): string
    {
        return (string) random_int(100000, 999999);
    }

    private function generateRequestId(): string
    {
        return bin2hex(random_bytes(16));
    }

    private function cleanupOldOtps(string $email): void
    {
        // Clean up old OTPs for this email
        $this->database->delete('flexkit_otp_requests', [
            'client_email' => $email
        ]);

        // Clean up expired OTPs globally
        $this->database->query(
            "DELETE FROM flexkit_otp_requests WHERE expires_at < UTC_TIMESTAMP() OR used = 1"
        );
    }

    public function sendEmailOtp(string $email): array
    {
        $this->logger->logOtpOperation("Starting stateless email OTP process", ['email' => $email]);

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

            $requestId = $this->generateRequestId();
            $otp = $this->generateOtp();

            // Clean up old OTPs for this email
            $this->cleanupOldOtps($email);

            $expiresAt = date('Y-m-d H:i:s', time() + (self::OTP_EXPIRY_MINUTES * 60));

            // Store OTP request in database with request_id instead of session_id
            $otpId = $this->database->insert('flexkit_otp_requests', [
                'request_id' => $requestId,
                'otp_code' => password_hash($otp, PASSWORD_BCRYPT), // Hash the OTP
                'client_id' => $client['Id'],
                'client_email' => $email,
                'client_data' => json_encode($client), // Store full client data
                'delivery_method' => 'email',
                'expires_at' => $expiresAt,
                'used' => 0
            ]);

            $this->logger->logOtpOperation("OTP stored in database", [
                'otp_id' => $otpId,
                'request_id' => $requestId,
                'expires_at' => $expiresAt
            ]);

            // Send email with OTP
            $emailSent = $this->sendOtpEmail($email, $otp, $client);

            if (!$emailSent) {
                throw new \Exception('Failed to send OTP email');
            }

            $this->logger->logOtpOperation("OTP email sent successfully", ['email' => $email]);

            // Return request_id that client needs to provide for verification
            return [
                'success' => true,
                'message' => 'OTP sent to your email address',
                'request_id' => $requestId, // Client needs this for verification
                'email' => $email,
                'expires_in_minutes' => self::OTP_EXPIRY_MINUTES
            ];

        } catch (\Exception $e) {
            $this->logger->error("Stateless email OTP failed: " . $e->getMessage(), [
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
        return $this->emailService->sendOtpEmail($email, $otp, $client);
    }

    public function verifyOtp(string $requestId, string $otpCode): array
    {
        $this->logger->logOtpOperation("Starting stateless OTP verification", [
            'request_id' => $requestId,
            'otp_code' => $otpCode
        ]);

        try {
            // Find OTP request by request_id
            $otpRecord = $this->database->findOne('flexkit_otp_requests', [
                'request_id' => $requestId,
                'used' => 0
            ]);

            if (!$otpRecord) {
                throw new \Exception('Invalid or expired OTP request');
            }

            // Check expiration
            if (strtotime($otpRecord['expires_at']) < time()) {
                // Clean up expired record
                $this->database->delete('flexkit_otp_requests', ['id' => $otpRecord['id']]);
                throw new \Exception('OTP code has expired');
            }

            // Verify OTP code
            if (!password_verify($otpCode, $otpRecord['otp_code'])) {
                throw new \Exception('Invalid OTP code');
            }

            $this->logger->logOtpOperation("Valid OTP found", [
                'otp_id' => $otpRecord['id'],
                'client_id' => $otpRecord['client_id']
            ]);

            // Mark OTP as used
            $this->database->update(
                'flexkit_otp_requests',
                ['used' => 1, 'used_at' => date('Y-m-d H:i:s')],
                ['id' => $otpRecord['id']]
            );

            // Get client data from stored record
            $client = json_decode($otpRecord['client_data'], true);

            if (!$client) {
                throw new \Exception('Client data not found');
            }

            // Generate JWT token for authenticated client
            $jwtToken = $this->jwtService->generateToken($client, [
                'auth_time' => time(),
                'auth_method' => 'otp_email'
            ]);

            $this->logger->logOtpOperation("Stateless OTP verification successful", [
                'client_id' => $client['Id'],
                'email' => $client['Email']
            ]);

            return [
                'success' => true,
                'message' => 'Authentication successful',
                'access_token' => $jwtToken,
                'token_type' => 'Bearer',
                'expires_in' => 24 * 60 * 60, // 24 hours in seconds
                'client' => [
                    'id' => $client['Id'],
                    'first_name' => $client['FirstName'] ?? '',
                    'last_name' => $client['LastName'] ?? '',
                    'email' => $client['Email'] ?? '',
                    'phone' => $client['MobilePhone'] ?? ''
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error("Stateless OTP verification failed: " . $e->getMessage(), [
                'request_id' => $requestId,
                'otp_code' => $otpCode
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    public function validateAccessToken(string $token): ?array
    {
        return $this->jwtService->validateToken($token);
    }

    public function getClientFromToken(string $token): ?array
    {
        return $this->jwtService->getClientFromToken($token);
    }

    public function isAuthenticated(string $token): bool
    {
        return $this->validateAccessToken($token) !== null;
    }
}
