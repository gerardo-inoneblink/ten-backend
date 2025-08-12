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

                $this->debugLog("token", ["token is" => $token]);

                $url = "{$this->apiBaseUrl}{$endpoint}";
                $headers = [
                    "API-Key: {$this->credentials['api_key']}",
                    "SiteId: {$siteId}",
                    "Authorization: {$token}",
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
                    'headers' => $headers,
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
        // Transform the data to match Mindbody API format
        $payload = [
            'Client' => [
                'FirstName' => $clientData['firstName'],
                'LastName' => $clientData['lastName'],
                'Email' => $clientData['email'],
                'MobilePhone' => $clientData['phone'],
                'LiabilityRelease' => $clientData['termsAccepted'] ?? false,
                'Country' => $clientData['country'] ?? 'US',
                'ProspectStage' => 'Member'
            ]
        ];

        // Optional fields
        if (isset($clientData['dateOfBirth'])) {
            $payload['Client']['BirthDate'] = $clientData['dateOfBirth'];
        }
        if (isset($clientData['gender'])) {
            $payload['Client']['Gender'] = ucfirst($clientData['gender']);
        }
        if (isset($clientData['hearAbout'])) {
            $payload['Client']['ReferredBy'] = $clientData['hearAbout'];
        }
        if (isset($clientData['marketingAccepted'])) {
            $payload['Client']['SendPromotionalEmails'] = $clientData['marketingAccepted'];
            $payload['Client']['SendPromotionalTexts'] = $clientData['marketingAccepted'];
        }

        return $this->makeRequest('/client/addclient', $payload, 'POST');
    }

    public function updateClient(array $clientData, string $siteId = null): array
    {
        // Add CrossRegionalUpdate parameter for single-site businesses
        $payload = array_merge($clientData, [
            'CrossRegionalUpdate' => false
        ]);
        
        return $this->makeRequest('/client/updateclient', $payload, 'POST');
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

    public function getServiceDetails(int $serviceId, int $locationId = null): ?array
    {
        $siteId = $this->getDefaultSiteId();
        $locationId = $locationId ?: 1; // Default location ID
        
        $this->logger->info("Fetching service details for purchase", [
            'service_id' => $serviceId,
            'location_id' => $locationId,
            'site_id' => $siteId
        ]);
        
        try {
            // Call the endpoint as specified in API documentation
            $response = $this->makeRequest('/sale/services', [
                'limit' => 200,
                'offset' => 0,
                'locationId' => $siteId,
                'sellOnline' => true
            ], 'GET', true);
            // Find the specific service by ID
            $service = null;
            if (isset($response['Services'])) {
                foreach ($response['Services'] as $serviceData) {
                    if ($serviceData['Id'] == $serviceId) {
                        $service = $serviceData;
                        break;
                    }
                }
            }
            
            if (!$service) {
                $this->logger->warning("Service not found", ['service_id' => $serviceId]);
                return null;
            }
            
            // Transform response to match API documentation format
            $transformedService = [
                'name' => $service['Name'] ?? '',
                'price' => (float) ($service['Price'] ?? 0),
                'tax_rate' => (float) ($service['TaxRate'] ?? 0.08), // Default tax rate
                'id' => $service['Id'],
                'is_intro_offer' => $service['IsIntroOffer'] ?? false,
                'intro_offer_type' => $service['IntroOfferType'] ?? null
            ];
            
            $this->logger->info("Service details transformed successfully", [
                'service_id' => $serviceId,
                'service_name' => $transformedService['name'],
                'price' => $transformedService['price']
            ]);
            
            return $transformedService;
            
        } catch (\Exception $e) {
            $this->logger->error("Error fetching service details: " . $e->getMessage(), [
                'service_id' => $serviceId,
                'location_id' => $locationId,
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

    public function unifiedPurchaseContract(array $purchaseData): array
    {
        $this->logger->info("Starting unified contract purchase", [
            'email' => $purchaseData['email'],
            'contract_id' => $purchaseData['id'],
            'type' => $purchaseData['type']
        ]);

        try {
            // Step 1: Search for existing client or create new one
            $client = null;
            $clientResponse = $this->searchClientByEmail($purchaseData['email']);
            
            if (!empty($clientResponse['Clients'])) {
                foreach ($clientResponse['Clients'] as $clientData) {
                    if (strtolower($clientData['Email']) === strtolower($purchaseData['email'])) {
                        $client = $clientData;
                        break;
                    }
                }
            }

            if (!$client) {
                $this->logger->info("Client not found, creating new client", [
                    'email' => $purchaseData['email']
                ]);
                
                $clientData = [
                    'firstName' => $purchaseData['first_name'],
                    'lastName' => $purchaseData['last_name'],
                    'email' => $purchaseData['email'],
                    'phone' => $purchaseData['phone'],
                    'termsAccepted' => true
                ];
                
                // Add optional gender field
                if (isset($purchaseData['gender'])) {
                    $clientData['gender'] = $purchaseData['gender'];
                }
                
                $createResponse = $this->createClient($clientData);
                $client = $createResponse['Client'];
            }

            $this->logger->info("Using client for unified purchase", [
                'client_id' => $client['Id'],
                'email' => $client['Email']
            ]);

            // Step 2: Purchase the contract using purchasecontract
            $creditCard = $purchaseData['credit_card'];
            $contractId = $purchaseData['id']; // Note: uses 'id' instead of 'contract_id'
            $locationId = $purchaseData['location_id'] ?? 1; // Default to location 1 if not provided
            
            $params = [
                'ClientId' => $client['Id'],
                'LocationId' => $locationId,
                'ContractId' => $contractId,
                'PaymentType' => 'CreditCard'
            ];

            if (!empty($purchaseData['promotion_code'])) {
                $params['PromotionCode'] = $purchaseData['promotion_code'];
            }

            $params['CreditCardInfo'] = [
                'CreditCardNumber' => $creditCard['number'],
                'ExpMonth' => (int) $creditCard['exp_month'],
                'ExpYear' => (int) $creditCard['exp_year'],
                'BillingName' => $creditCard['billing_name'],
                'BillingAddress' => $creditCard['billing_address'],
                'BillingCity' => $creditCard['billing_city'],
                'BillingState' => $creditCard['billing_state'],
                'BillingPostalCode' => $creditCard['billing_postal_code']
            ];

            if (!empty($creditCard['cvc'])) {
                $params['CreditCardInfo']['CVV'] = $creditCard['cvc'];
            }

            $this->logger->info("Calling purchasecontract for unified purchase", [
                'client_id' => $client['Id'],
                'contract_id' => $contractId
            ]);

            $response = $this->makeRequest('/sale/purchasecontract', $params, 'POST', true);

            $this->logger->info("Unified contract purchase successful", [
                'client_id' => $client['Id'],
                'contract_id' => $contractId
            ]);

            // Return response in the simplified format for unified purchase
            return [
                'ClientId' => $client['Id'],
                'ContractId' => $contractId,
                'Totals' => [
                    'Total' => $response['Totals']['Total'] ?? 0.00
                ]
            ];

        } catch (\Exception $e) {
            $this->logger->error("Unified contract purchase failed: " . $e->getMessage(), [
                'email' => $purchaseData['email'],
                'contract_id' => $purchaseData['id'],
                'type' => $purchaseData['type']
            ]);
            throw $e;
        }
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
        $this->debugLog('🟦 Starting client complete info request', [
            'client_id' => $clientId,
            'site_id' => $siteId
        ]);

        try {
            // Get client info
            $clientInfo = $this->makeRequest('/client/clientcompleteinfo', [
                'clientID' => $clientId,
                'limit' => 200,
                'offset' => 0
            ]);

            if (!isset($clientInfo['Client'])) {
                throw new \Exception('Client not found');
            }

            $now = new \DateTime();
            
            // Format client data - keep the 'client' key as requested
            $clientData = $clientInfo['Client'];
            $formattedResponse = [
                'client' => [
                    'id' => $clientData['Id'] ?? $clientId,
                    'first_name' => $clientData['FirstName'] ?? '',
                    'last_name' => $clientData['LastName'] ?? '',
                    'email' => $clientData['Email'] ?? '',
                    'mobile_phone' => $clientData['MobilePhone'] ?? '',
                    'home_phone' => $clientData['HomePhone'] ?? '',
                    'work_phone' => $clientData['WorkPhone'] ?? '',
                    'gender' => $clientData['Gender'] ?? '',
                    'status' => $clientData['Status'] ?? '',
                    'creation_date' => $clientData['CreationDate'] ?? '',
                    'birth_date' => $clientData['BirthDate'] ?? '',
                    'referred_by' => $clientData['ReferredBy'] ?? '',
                    'send_promotional_emails' => $clientData['SendPromotionalEmails'] ?? false,
                    'address' => [
                        'line1' => $clientData['AddressLine1'] ?? '',
                        'line2' => $clientData['AddressLine2'] ?? '',
                        'city' => $clientData['City'] ?? '',
                        'state' => $clientData['State'] ?? '',
                        'postal_code' => $clientData['PostalCode'] ?? '',
                        'country' => $clientData['Country'] ?? ''
                    ],
                    'account_balance' => $clientData['AccountBalance'] ?? 0,
                    'credit_card' => $clientData['ClientCreditCard'] ?? null
                ]
            ];

            // Process memberships and services using the helper method
            $services = $clientInfo['ClientServices'] ?? [];
            $memberships = $clientInfo['ClientMemberships'] ?? [];
            $allItems = array_merge($services, $memberships);
            
            $formattedResponse['memberships'] = $this->processMemberships($allItems, $now);

            // Process contracts - structured like old backend
            $contracts = $clientInfo['ClientContracts'] ?? [];
            $formattedResponse['contracts'] = [
                'active' => [],
                'terminated' => []
            ];

            foreach ($contracts as $contract) {
                $formattedContract = [
                    'id' => $contract['Id'],
                    'name' => $contract['ContractName'],
                    'start_date' => $contract['StartDate'],
                    'end_date' => $contract['EndDate'],
                    'agreement_date' => $contract['AgreementDate'],
                    'autopay_status' => $contract['AutopayStatus'],
                    'auto_renewing' => $contract['AutoRenewing'],
                    'upcoming_payments' => array_map(function($event) {
                        return [
                            'date' => $event['ScheduleDate'],
                            'amount' => $event['ChargeAmount'],
                            'product_id' => $event['ProductId']
                        ];
                    }, $contract['UpcomingAutopayEvents'] ?? [])
                ];

                if ($contract['TerminationDate']) {
                    $formattedResponse['contracts']['terminated'][] = $formattedContract;
                } else {
                    $formattedResponse['contracts']['active'][] = $formattedContract;
                }
            }

            // Get visits with extended date range like old backend
            $pastDate = (new \DateTime())->modify('-3 years')->format('Y-m-d');
            $futureDate = (new \DateTime())->modify('+3 months')->format('Y-m-d');

            $this->debugLog('📥 Fetching client visits', [
                'client_id' => $clientId,
                'start_date' => $startDate ?? $pastDate,
                'end_date' => $endDate ?? $futureDate,
                'now' => $now->format('Y-m-d H:i:s'),
                'date_range' => '3 years back, 3 months forward'
            ]);

            // Make separate request for visits
            $visitsResponse = $this->makeRequest('/client/clientvisits', [
                'clientId' => $clientId,
                'startDate' => $startDate ?? $pastDate,
                'endDate' => $endDate ?? $futureDate,
                'limit' => 200
            ]);

            $visits = $visitsResponse['Visits'] ?? [];
            
            $this->debugLog('📦 Processing visits response', [
                'total_visits' => count($visits),
                'has_visits_key' => isset($visitsResponse['Visits']),
                'response_keys' => array_keys($visitsResponse)
            ]);
            
            // Process visits into session_history format like old backend
            $upcomingSessions = [];
            $previousSessions = [];
            $sessionTypes = [];
            
            foreach ($visits as $visit) {
                $visitDate = new \DateTime($visit['StartDateTime']);
                $isClass = !empty($visit['ClassId']);
                
                // Format visit status
                $status = $this->formatVisitStatus($visit);
                
                $this->debugLog('📦 Processing visit', [
                    'visit_id' => $visit['Id'],
                    'date' => $visitDate->format('Y-m-d H:i:s'),
                    'is_future' => $visitDate > $now,
                    'name' => $visit['Name'],
                    'status' => $status,
                    'appointment_status' => $visit['AppointmentStatus'],
                    'now' => $now->format('Y-m-d H:i:s'),
                    'date_comparison' => $visitDate > $now ? 'future' : 'past',
                    'will_be_upcoming' => ($visitDate > $now && $visit['AppointmentStatus'] === 'Booked') ? 'yes' : 'no'
                ]);
                
                // Format session data like old backend
                $formattedVisit = [
                    'id' => $visit['Id'],
                    'date' => $visitDate->format('Y-m-d'),
                    'time' => $visitDate->format('g:i A'),
                    'end_time' => (new \DateTime($visit['EndDateTime']))->format('g:i A'),
                    'name' => $visit['Name'],
                    'type' => $isClass ? 'class' : 'appointment',
                    'status' => $status,
                    'location' => [
                        'id' => $visit['LocationId'],
                        'name' => $visit['LocationName'] ?? 'TBD'
                    ],
                    'staff' => [
                        'id' => $visit['StaffId'],
                        'name' => $visit['StaffName'] ?? 'TBD'
                    ],
                    'service' => [
                        'id' => $visit['ServiceId'],
                        'name' => $visit['ServiceName'],
                        'product_id' => $visit['ProductId']
                    ],
                    'can_cancel' => !$visit['LateCancelled'] && $status !== 'cancelled',
                    'is_late_cancel' => $visit['LateCancelled'],
                    'signed_in' => $visit['SignedIn'],
                    'missed' => $visit['Missed'] ?? false
                ];

                // Track unique session types
                if (!isset($sessionTypes[$visit['Name']])) {
                    $sessionTypes[$visit['Name']] = [
                        'name' => $visit['Name'],
                        'type' => $isClass ? 'class' : 'appointment',
                        'count' => 1
                    ];
                } else {
                    $sessionTypes[$visit['Name']]['count']++;
                }

                // Sort into upcoming/previous based on date and status like old backend
                if ($visitDate > $now && $visit['AppointmentStatus'] === 'Booked') {
                    $upcomingSessions[] = $formattedVisit;
                } else {
                    $previousSessions[] = $formattedVisit;
                }
            }

            // Sort sessions like old backend
            usort($upcomingSessions, function($a, $b) {
                return strtotime($a['date'] . ' ' . $a['time']) - strtotime($b['date'] . ' ' . $b['time']);
            });
            
            usort($previousSessions, function($a, $b) {
                return strtotime($b['date'] . ' ' . $b['time']) - strtotime($a['date'] . ' ' . $a['time']);
            });

            // Build session_history like old backend
            $formattedResponse['session_history'] = [
                'upcoming' => $upcomingSessions,
                'previous' => $previousSessions,
                'stats' => [
                    'total_sessions' => count($visits),
                    'upcoming_count' => count($upcomingSessions),
                    'previous_count' => count($previousSessions),
                    'session_types' => array_values($sessionTypes)
                ]
            ];

            $this->debugLog('✅ Successfully compiled client info', [
                'total_visits' => count($visits),
                'upcoming' => count($upcomingSessions),
                'previous' => count($previousSessions)
            ]);
            
            return $formattedResponse;

        } catch (\Exception $e) {
            $this->debugLog('❌ Error in getClientCompleteInfo', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw $e;
        }
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

    public function getClientServices(int $clientId, int $sessionTypeId = null, string $siteId = null): array
    {
        $siteId = $siteId ?: $this->getDefaultSiteId();
        
        $this->logger->info("Fetching client services", [
            'client_id' => $clientId,
            'session_type_id' => $sessionTypeId,
            'site_id' => $siteId
        ]);
        
        try {
            $params = [
                'ClientId' => $clientId,
                'limit' => 100,
                'offset' => 0
            ];
            
            if ($sessionTypeId) {
                $params['sessionTypeId'] = $sessionTypeId;
            }
            
            $response = $this->makeRequest('/client/clientservices', $params, 'GET', true);
            
            $this->logger->info("Client services fetched successfully", [
                'client_id' => $clientId,
                'session_type_id' => $sessionTypeId,
                'services_count' => count($response['ClientServices'] ?? [])
            ]);
            
            return $response;
        } catch (\Exception $e) {
            $this->logger->error("Error fetching client services: " . $e->getMessage(), [
                'client_id' => $clientId,
                'session_type_id' => $sessionTypeId,
                'site_id' => $siteId
            ]);
            throw $e;
        }
    }

    /**
     * Process memberships and services into a structured format
     * 
     * @param array $allItems Combined services and memberships
     * @param \DateTime $now Current date/time
     * @return array Processed memberships data
     */
    private function processMemberships(array $allItems, \DateTime $now): array
    {
        $processed = [];
        
        foreach ($allItems as $item) {
            $expirationDate = null;
            if (!empty($item['ExpirationDate'])) {
                $expirationDate = new \DateTime($item['ExpirationDate']);
            }
            
            $processed[] = [
                'id' => $item['Id'],
                'name' => $item['Name'],
                'count' => $item['Count'] ?? 0,
                'remaining' => $item['Remaining'] ?? 0,
                'active_date' => $item['ActiveDate'] ?? '',
                'expiration_date' => $item['ExpirationDate'] ?? '',
                'payment_date' => $item['PaymentDate'] ?? '',
                'current' => $item['Current'] ?? false,
                'product_id' => $item['ProductId'] ?? '',
                'program' => $item['Program'] ?? [],
                'site_id' => $item['SiteId'] ?? '',
                'client_id' => $item['ClientID'] ?? '',
                'is_expired' => $expirationDate ? $expirationDate < $now : false,
                'days_until_expiry' => $expirationDate ? $expirationDate->diff($now)->days : null
            ];
        }
        
        return $processed;
    }

    /**
     * Format visit status for display
     * 
     * @param array $visit Visit data
     * @return string Formatted status
     */
    private function formatVisitStatus(array $visit): string
    {
        $appointmentStatus = $visit['AppointmentStatus'] ?? '';
        $signedIn = $visit['SignedIn'] ?? false;
        $lateCancelled = $visit['LateCancelled'] ?? false;
        $missed = $visit['Missed'] ?? false;
        
        if ($lateCancelled) {
            return 'cancelled';
        }
        
        if ($missed) {
            return 'missed';
        }
        
        switch (strtolower($appointmentStatus)) {
            case 'booked':
                return $signedIn ? 'completed' : 'booked';
            case 'cancelled':
                return 'cancelled';
            case 'noshow':
                return 'no-show';
            case 'confirmed':
                return 'confirmed';
            default:
                return strtolower($appointmentStatus) ?: 'unknown';
        }
    }
}