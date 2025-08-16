<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class JWTService
{
    private AppConfig $config;
    private Logger $logger;
    private string $secret;
    private string $algorithm = 'HS256';

    public function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->logger = Logger::getInstance();
        $this->secret = $this->config->get('JWT_SECRET', 'your-secret-key-change-this');
        
        if (strlen($this->secret) < 32) {
            throw new \Exception('JWT_SECRET must be at least 32 characters long');
        }
    }

    /**
     * Generate a JWT token for authenticated client
     */
    public function generateToken(array $clientData, array $additionalClaims = []): string
    {
        $now = time();
        $expiry = $now + (24 * 60 * 60); // 24 hours

        $payload = [
            'iss' => $this->config->get('APP_NAME', 'FlexKit Ten'), // Issuer
            'iat' => $now, // Issued at
            'exp' => $expiry, // Expires
            'sub' => (string) $clientData['Id'], // Subject (client ID)
            'client' => [
                'id' => $clientData['Id'],
                'first_name' => $clientData['FirstName'] ?? '',
                'last_name' => $clientData['LastName'] ?? '',
                'email' => $clientData['Email'] ?? '',
                'phone' => $clientData['MobilePhone'] ?? ''
            ]
        ];

        // Add any additional claims
        $payload = array_merge($payload, $additionalClaims);

        return $this->encode($payload);
    }

    /**
     * Generate temporary token for OTP verification (shorter expiry)
     */
    public function generateOtpToken(string $email, string $otpCode): string
    {
        $now = time();
        $expiry = $now + (10 * 60); // 10 minutes

        $payload = [
            'iss' => $this->config->get('APP_NAME', 'FlexKit Ten'),
            'iat' => $now,
            'exp' => $expiry,
            'type' => 'otp_verification',
            'email' => $email,
            'otp' => password_hash($otpCode, PASSWORD_BCRYPT) // Hash the OTP for security
        ];

        return $this->encode($payload);
    }

    /**
     * Validate and decode JWT token
     */
    public function validateToken(string $token): ?array
    {
        try {
            $payload = $this->decode($token);

            // Check expiration
            if (isset($payload['exp']) && $payload['exp'] < time()) {
                $this->logger->warning('JWT token expired', ['exp' => $payload['exp'], 'now' => time()]);
                return null;
            }

            // Check issuer
            if (isset($payload['iss']) && $payload['iss'] !== $this->config->get('APP_NAME', 'FlexKit Ten')) {
                $this->logger->warning('JWT token has invalid issuer', ['iss' => $payload['iss']]);
                return null;
            }

            return $payload;
        } catch (\Exception $e) {
            $this->logger->error('JWT validation failed', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Verify OTP against temporary token
     */
    public function verifyOtpToken(string $token, string $providedOtp): ?array
    {
        $payload = $this->validateToken($token);
        
        if (!$payload || ($payload['type'] ?? '') !== 'otp_verification') {
            return null;
        }

        // Verify OTP
        if (!password_verify($providedOtp, $payload['otp'])) {
            $this->logger->warning('OTP verification failed', ['email' => $payload['email']]);
            return null;
        }

        return $payload;
    }

    /**
     * Get client data from validated token
     */
    public function getClientFromToken(string $token): ?array
    {
        $payload = $this->validateToken($token);
        return $payload['client'] ?? null;
    }

    /**
     * Encode payload to JWT
     */
    private function encode(array $payload): string
    {
        $header = [
            'typ' => 'JWT',
            'alg' => $this->algorithm
        ];

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Decode JWT token
     */
    private function decode(string $token): array
    {
        $parts = explode('.', $token);
        
        if (count($parts) !== 3) {
            throw new \Exception('Invalid JWT format');
        }

        [$headerEncoded, $payloadEncoded, $signatureEncoded] = $parts;

        // Verify signature
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        $actualSignature = $this->base64UrlDecode($signatureEncoded);

        if (!hash_equals($expectedSignature, $actualSignature)) {
            throw new \Exception('Invalid JWT signature');
        }

        // Decode header and payload
        $header = json_decode($this->base64UrlDecode($headerEncoded), true);
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);

        if (!$header || !$payload) {
            throw new \Exception('Invalid JWT payload');
        }

        return $payload;
    }

    /**
     * Base64 URL encode
     */
    private function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Base64 URL decode
     */
    private function base64UrlDecode(string $data): string
    {
        return base64_decode(str_pad(strtr($data, '-_', '+/'), strlen($data) % 4, '=', STR_PAD_RIGHT));
    }
}
