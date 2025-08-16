<?php

namespace FlexkitTen\Services;

class StatelessAuthMiddleware
{
    private JWTService $jwtService;
    private Logger $logger;

    public function __construct(JWTService $jwtService, Logger $logger)
    {
        $this->jwtService = $jwtService;
        $this->logger = $logger;
    }

    /**
     * Extract and validate Bearer token from request headers
     */
    public function validateRequest(array $request): ?array
    {
        $authHeader = $request['headers']['authorization'] ?? '';
        
        if (strpos($authHeader, 'Bearer ') !== 0) {
            $this->logger->warning('Missing or invalid Authorization header');
            return null;
        }
        
        $token = substr($authHeader, 7); // Remove "Bearer " prefix
        return $this->jwtService->validateToken($token);
    }

    /**
     * Middleware function for protected routes
     */
    public function requireAuth(array $request): array
    {
        $tokenData = $this->validateRequest($request);
        
        if (!$tokenData) {
            throw new \Exception('Authentication required', 401);
        }
        
        // Add client data to request for easy access
        $request['auth'] = [
            'token_data' => $tokenData,
            'client' => $tokenData['client'] ?? null,
            'client_id' => $tokenData['sub'] ?? null
        ];
        
        return $request;
    }

    /**
     * Get client data from authenticated request
     */
    public function getAuthenticatedClient(array $request): ?array
    {
        return $request['auth']['client'] ?? null;
    }

    /**
     * Get client ID from authenticated request
     */
    public function getAuthenticatedClientId(array $request): ?string
    {
        return $request['auth']['client_id'] ?? null;
    }
}
