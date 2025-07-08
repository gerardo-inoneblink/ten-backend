<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class MindbodyAPI
{
    private string $apiBaseUrl = 'https://api.mindbodyonline.com/public/v6';
    private array $credentials;
    private bool $debugMode = false;
    private Logger $logger;
    private AppConfig $config;

    private const MAX_RETRIES = 3;
    private array $rateLimits = [
        'requests_per_minute' => 1000,
        'requests_per_day' => 2000,
    ];

    private array $requestTracking = [
        'minute' => ['count' => 0, 'timestamp' => 0],
        'day' => ['count' => 0, 'timestamp' => 0],
    ];

    public function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->logger = Logger::getInstance();
        $this->debugMode = $this->config->isDebug();

        $mindbodyConfig = $this->config->getMindbodyConfig();

        if (empty($mindbodyConfig['api_key'])) {
            throw new \Exception('API Key is required');
        }
        if (empty($mindbodyConfig['site_id'])) {
            throw new \Exception('Site ID is required');
        }
        if (empty($mindbodyConfig['source_name'])) {
            throw new \Exception('Source Name is required');
        }
        if (empty($mindbodyConfig['password'])) {
            throw new \Exception('Password is required');
        }

        $this->credentials = [
            'api_key' => $mindbodyConfig['api_key'],
            'site_ids' => [$mindbodyConfig['site_id']],
            'source_name' => $mindbodyConfig['source_name'],
            'password' => $mindbodyConfig['password']
        ];

        $this->logger->logMindbodyApi('API client initialized', [
            'api_key' => substr($mindbodyConfig['api_key'], 0, 5) . '...',
            'site_id' => $mindbodyConfig['site_id'],
            'source_name' => $mindbodyConfig['source_name']
        ]);
    }

    private function debugLog(string $message, array $context = []): void
    {
        if ($this->debugMode) {
            $this->logger->logMindbodyApi($message, $context);
        }
    }

    private function getFreshToken(string $siteId): string
    {
        $this->debugLog('Starting token request', [
            'site_id' => $siteId,
            'source_name' => $this->credentials['source_name']
        ]);

        try {
            $requestData = [
                'username' => $this->credentials['source_name'],
                'password' => $this->credentials['password']
            ];

            $url = $this->apiBaseUrl . '/usertoken/issue';
            $headers = [
                'API-Key: ' . $this->credentials['api_key'],
                'SiteId: ' . $siteId,
                'Content-Type: application/json'
            ];

            $this->debugLog('Making token request', [
                'url' => $url,
                'username' => $this->credentials['source_name']
            ]);

            $response = $this->makeHttpRequest($url, 'POST', $requestData, $headers);

            if (empty($response['AccessToken'])) {
                $errorMessage = $response['Message'] ?? 'Unknown error';
                throw new \Exception("Failed to obtain token: {$errorMessage}");
            }

            $this->debugLog('Token obtained successfully');
            return $response['AccessToken'];

        } catch (\Exception $e) {
            $this->debugLog('Token request failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    private function getFreshStaffToken(): string
    {
        $this->logger->info('=== MBO AUTH START ===');
        $this->logger->info('Site ID: ' . $this->credentials['site_ids'][0]);
        $this->logger->info('Source Name: ' . $this->credentials['source_name']);
        $this->logger->info('API Key (first 5 chars): ' . substr($this->credentials['api_key'], 0, 5) . '...');

        $authUrl = $this->apiBaseUrl . '/usertoken/issue';
        $this->logger->info('Auth URL: ' . $authUrl);

        $headers = [
            'API-Key: ' . $this->credentials['api_key'],
            'SiteId: ' . $this->credentials['site_ids'][0],
            'Content-Type: application/json'
        ];

        $body = [
            'username' => $this->credentials['source_name'],
            'password' => $this->credentials['password']
        ];

        $response = $this->makeHttpRequest($authUrl, 'POST', $body, $headers);

        if (empty($response['AccessToken'])) {
            throw new \Exception('Failed to obtain staff token');
        }

        return $response['AccessToken'];
    }

    private function checkRateLimits(): void
    {
        $currentTime = time();

        if ($currentTime - $this->requestTracking['minute']['timestamp'] >= 60) {
            $this->requestTracking['minute'] = ['count' => 0, 'timestamp' => $currentTime];
        }

        if ($currentTime - $this->requestTracking['day']['timestamp'] >= 86400) {
            $this->requestTracking['day'] = ['count' => 0, 'timestamp' => $currentTime];
        }

        if ($this->requestTracking['minute']['count'] >= $this->rateLimits['requests_per_minute']) {
            throw new \Exception('Rate limit exceeded: too many requests per minute');
        }

        if ($this->requestTracking['day']['count'] >= $this->rateLimits['requests_per_day']) {
            throw new \Exception('Rate limit exceeded: too many requests per day');
        }

        $this->requestTracking['minute']['count']++;
        $this->requestTracking['day']['count']++;
    }

    public function makeRequest(string $endpoint, array $params = [], string $method = 'GET', bool $useStaffToken = false): array
    {
        $this->checkRateLimits();

        $retries = 0;
        while ($retries < self::MAX_RETRIES) {
            try {
                $siteId = $this->getDefaultSiteId();
                $token = $useStaffToken ? $this->getFreshStaffToken() : $this->getFreshToken($siteId);

                $url = $this->apiBaseUrl . $endpoint;
                $headers = [
                    'API-Key: ' . $this->credentials['api_key'],
                    'SiteId: ' . $siteId,
                    'Authorization: ' . $token,
                    'Content-Type: application/json'
                ];

                if ($method === 'GET' && !empty($params)) {
                    $url .= '?' . http_build_query($params);
                    $data = null;
                } else {
                    $data = $params;
                }

                $this->debugLog("Making API request", [
                    'method' => $method,
                    'url' => $url,
                    'params' => $params
                ]);

                $response = $this->makeHttpRequest($url, $method, $data, $headers);

                $this->debugLog("API request successful", [
                    'endpoint' => $endpoint,
                    'response_size' => strlen(json_encode($response))
                ]);

                return $response;

            } catch (\Exception $e) {
                $retries++;
                $this->logger->warning("API request failed (attempt {$retries}): " . $e->getMessage());

                if ($retries >= self::MAX_RETRIES) {
                    throw new \Exception("API request failed after " . self::MAX_RETRIES . " attempts: " . $e->getMessage());
                }

                sleep(1);
            }
        }

        throw new \Exception('Maximum retries exceeded');
    }

    private function makeHttpRequest(string $url, string $method = 'GET', array $data = null, array $headers = []): array
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'FlexKit-Ten/1.0',
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        if ($error && (strpos($error, 'SSL') !== false || strpos($error, 'SSL_ERROR_SYSCALL') !== false)) {
            $this->logger->warning("SSL error detected, retrying with relaxed SSL settings", ['error' => $error]);
            
            curl_close($ch);
            $ch = curl_init();
            
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_USERAGENT => 'FlexKit-Ten/1.0',
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($data) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
        }

        curl_close($ch);

        if ($error) {
            throw new \Exception("cURL error: {$error}");
        }

        if ($httpCode >= 400) {
            $this->logger->error("Mindbody API HTTP error", [
                'http_code' => $httpCode,
                'response' => $response,
                'url' => $url,
                'method' => $method,
                'headers' => $headers
            ]);
            throw new \Exception("HTTP error {$httpCode}: {$response}");
        }

        $decoded = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Invalid JSON response: " . json_last_error_msg());
        }

        return $decoded;
    }

    public function getClientById(int $clientId, string $siteId = null): ?array
    {
        try {
            $response = $this->makeRequest('/client/clients', [
                'clientIds' => [$clientId]
            ]);

            return $response['Clients'][0] ?? null;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching client by ID: " . $e->getMessage());
            return null;
        }
    }

    public function getClientByEmail(string $email, string $siteId = null): ?array
    {
        try {
            $response = $this->makeRequest('/client/clients', [
                'searchText' => $email
            ]);

            foreach ($response['Clients'] ?? [] as $client) {
                if (strtolower($client['Email']) === strtolower($email)) {
                    return $client;
                }
            }

            return null;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching client by email: " . $e->getMessage());
            return null;
        }
    }

    public function getClassSchedule(array $params = []): array
    {
        $defaultParams = [
            'startDateTime' => date('c'),
            'endDateTime' => date('c', strtotime('+30 days')),
            'locationIds' => [],
            'programIds' => [],
            'sessionTypeIds' => []
        ];

        $params = array_merge($defaultParams, $params);

        return $this->makeRequest('/class/classes', $params);
    }

    public function getSessionTypes(array $params = []): array
    {
        return $this->makeRequest('/site/sessiontypes', $params);
    }

    public function getDefaultSiteId(): string
    {
        return $this->credentials['site_ids'][0];
    }

    public function testConnection(): bool
    {
        try {
            $this->makeRequest('/site/sites');
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Connection test failed: " . $e->getMessage());
            return false;
        }
    }

    public function addClientToClass(int $clientId, int $classId, string $siteId = null): array
    {
        return $this->makeRequest('/class/addclienttoclass', [
            'clientId' => $clientId,
            'classId' => $classId
        ], 'POST');
    }

    public function createClient(array $clientData): array
    {
        return $this->makeRequest('/client/addclient', $clientData, 'POST');
    }

    public function updateClient(array $clientData, string $siteId = null): array
    {
        return $this->makeRequest('/client/updateclient', $clientData, 'POST');
    }

    public function getServices(string $siteId = null): array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        
        $this->logger->info("Fetching services", ['site_id' => $siteId]);
        
        try {
            $response = $this->makeRequest('/sale/services', [
                'limit' => 200,
                'offset' => 0,
                'sellOnline' => true
            ], 'GET', true);
            
            $this->logger->info("Services fetched successfully", [
                'site_id' => $siteId,
                'services_count' => count($response['Services'] ?? [])
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching services: " . $e->getMessage(), [
                'site_id' => $siteId
            ]);
            throw $e;
        }
    }

    public function getServiceById(int $serviceId, string $siteId = null): ?array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        
        $this->logger->info("Fetching service by ID", [
            'service_id' => $serviceId,
            'site_id' => $siteId
        ]);
        
        try {
            $response = $this->makeRequest('/sale/services', [
                'ServiceIds' => [$serviceId],
                'sellOnline' => true
            ], 'GET', true);
            
            $service = $response['Services'][0] ?? null;
            
            if ($service) {
                $this->logger->info("Service found", [
                    'service_id' => $serviceId,
                    'service_name' => $service['Name'] ?? 'Unknown'
                ]);
            } else {
                $this->logger->warning("Service not found", ['service_id' => $serviceId]);
            }
            
            return $service;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching service by ID: " . $e->getMessage(), [
                'service_id' => $serviceId,
                'site_id' => $siteId
            ]);
            throw $e;
        }
    }

    public function getContracts(string $siteId = null, int $locationId = null): array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        $locationId = $locationId ?: 1;
        
        $this->logger->info("Fetching contracts", [
            'site_id' => $siteId,
            'location_id' => $locationId
        ]);
        
        try {
            $response = $this->makeRequest('/sale/contracts', [
                'limit' => 100,
                'offset' => 0,
                'LocationId' => $locationId,
                'SoldOnline' => true
            ], 'GET', true);
            
            $this->logger->info("Contracts fetched successfully", [
                'site_id' => $siteId,
                'location_id' => $locationId,
                'contracts_count' => count($response['Contracts'] ?? [])
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching contracts: " . $e->getMessage(), [
                'site_id' => $siteId,
                'location_id' => $locationId
            ]);
            throw $e;
        }
    }

    public function getContractById(int $contractId, string $siteId = null, int $locationId = null): ?array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        $locationId = $locationId ?: 1;
        
        $this->logger->info("Fetching contract by ID", [
            'contract_id' => $contractId,
            'site_id' => $siteId,
            'location_id' => $locationId
        ]);
        
        try {
            $response = $this->makeRequest('/sale/contracts', [
                'ContractIds' => [$contractId],
                'LocationId' => $locationId,
                'SoldOnline' => true
            ], 'GET', true);
            
            $contract = $response['Contracts'][0] ?? null;
            
            if ($contract) {
                $this->logger->info("Contract found", [
                    'contract_id' => $contractId,
                    'contract_name' => $contract['Name'] ?? 'Unknown'
                ]);
            } else {
                $this->logger->warning("Contract not found", ['contract_id' => $contractId]);
            }
            
            return $contract;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching contract by ID: " . $e->getMessage(), [
                'contract_id' => $contractId,
                'site_id' => $siteId,
                'location_id' => $locationId
            ]);
            throw $e;
        }
    }

    public function purchaseContract(array $purchaseData): array
    {
        if (empty($purchaseData['clientId'])) {
            throw new \Exception('ClientId is required');
        }

        if (empty($purchaseData['contract_id'])) {
            throw new \Exception('ContractId is required');
        }

        if (empty($purchaseData['location_id'])) {
            throw new \Exception('LocationId is required');
        }

        $paymentType = $purchaseData['paymentType'] ?? 'CreditCard';

        $params = [
            'ClientId' => $purchaseData['clientId'],
            'LocationId' => $purchaseData['location_id'],
            'ContractId' => $purchaseData['contract_id'],
            'PaymentType' => $paymentType,
        ];

        if (!empty($purchaseData['promotion_code'])) {
            $params['PromotionCode'] = $purchaseData['promotion_code'];
        }
        if (isset($purchaseData['overridePaymentAmount'])) {
            $params['OverridePaymentAmount'] = $purchaseData['overridePaymentAmount'];
        }
        if (isset($purchaseData['discountAmount'])) {
            $params['DiscountAmount'] = $purchaseData['discountAmount'];
        }

        if (in_array($paymentType, ['CreditCard', 'EFT'])) {
            if (empty($purchaseData['credit_card']) || !is_array($purchaseData['credit_card'])) {
                throw new \Exception('CreditCard information is required for CreditCard or EFT payment types');
            }

            $cc = $purchaseData['credit_card'];

            $params['CreditCardInfo'] = [
                'CreditCardNumber' => $cc['number'] ?? '',
                'ExpMonth' => isset($cc['exp_month']) ? (int) $cc['exp_month'] : 0,
                'ExpYear' => isset($cc['exp_year']) ? (int) $cc['exp_year'] : 0,
                'BillingName' => $cc['billing_name'] ?? '',
                'BillingAddress' => $cc['billing_address'] ?? '',
                'BillingCity' => $cc['billing_city'] ?? '',
                'BillingState' => $cc['billing_state'] ?? '',
                'BillingPostalCode' => $cc['billing_postal_code'] ?? '',
            ];

            if (!empty($cc['cvc'])) {
                $params['CreditCardInfo']['CVV'] = $cc['cvc'];
            }
        }

        return $this->makeRequest('/sale/purchasecontract', $params, 'POST', true);
    }

    public function searchClientByEmail(string $email): array
    {
        $this->logger->info("Searching client by email", ['email' => $email]);
        try {
            $response = $this->makeRequest('/client/clients', [
                'searchText' => $email
            ]);
            $this->logger->info("Client search successful", [
                'email' => $email,
                'clients_found' => count($response['Clients'] ?? [])
            ]);
            return $response;
        } catch (\Exception $e) {
            $this->logger->error("Client search failed", [
                'email' => $email,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    public function getClientCompleteInfo(int $clientId, string $siteId, string $startDate = null, string $endDate = null): array
    {
        $params = [
            'clientId' => $clientId
        ];

        if ($startDate) {
            $params['startDate'] = $startDate;
        }
        if ($endDate) {
            $params['endDate'] = $endDate;
        }

        $clientResponse = $this->makeRequest('/client/clients', ['clientIds' => [$clientId]]);
        $client = $clientResponse['Clients'][0] ?? null;

        if (!$client) {
            throw new \Exception('Client not found');
        }

        $scheduleResponse = $this->makeRequest('/client/clientschedule', $params);

        $visitsResponse = $this->makeRequest('/client/clientvisits', $params);

        return [
            'client' => $client,
            'schedule' => $scheduleResponse,
            'visits' => $visitsResponse
        ];
    }

    public function getPromotionCodes(string $siteId = null): array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        
        $this->logger->info("Fetching promotion codes", ['site_id' => $siteId]);
        
        try {
            $response = $this->makeRequest('/sale/promotions', [
                'limit' => 100,
                'offset' => 0
            ], 'GET', true);
            
            $this->logger->info("Promotion codes fetched successfully", [
                'site_id' => $siteId,
                'promotions_count' => count($response['Promotions'] ?? [])
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching promotion codes: " . $e->getMessage(), [
                'site_id' => $siteId
            ]);
            throw $e;
        }
    }
}