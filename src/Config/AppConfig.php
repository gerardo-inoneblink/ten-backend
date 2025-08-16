<?php

namespace FlexkitTen\Config;

class AppConfig
{
    private static ?AppConfig $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->loadEnvironmentVariables();
        $this->setDefaults();
    }

    public static function getInstance(): AppConfig
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadEnvironmentVariables(): void
    {
        $envPath = dirname(__DIR__, 2) . '/.env';
        
        if (file_exists($envPath)) {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            
            foreach ($lines as $line) {
                if (strpos($line, '#') === 0) {
                    continue; // Skip comments
                }
                
                if (strpos($line, '=') !== false) {
                    list($key, $value) = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value);
                    
                    // Remove quotes if present
                    if (preg_match('/^"(.*)"$/', $value, $matches)) {
                        $value = $matches[1];
                    } elseif (preg_match("/^'(.*)'$/", $value, $matches)) {
                        $value = $matches[1];
                    }
                    
                    $_ENV[$key] = $value;
                    putenv("$key=$value");
                }
            }
        }
    }

    private function setDefaults(): void
    {
        $defaults = [
            'APP_NAME' => 'FlexKit Ten',
            'APP_ENV' => 'development',
            'APP_DEBUG' => 'true',
            'DB_HOST' => 'localhost',
            'DB_NAME' => 'flexkit_ten',
            'DB_USER' => 'root',
            'DB_PASS' => '123qweasd!Q',
            'MINDBODY_API_KEY' => '',
            'MINDBODY_SITE_ID' => '',
            'MINDBODY_SOURCE_NAME' => '_BLINK',
            'MINDBODY_PASSWORD' => '',
            'SERVER_HOST' => 'localhost',
            'SERVER_PORT' => '8000',
            'LOG_LEVEL' => 'info',
            'LOG_FILE' => 'logs/application.log',
            'RESEND_API_KEY' => '',
            'RESEND_FROM_EMAIL' => 'no-reploy@inoneblink.com',
            'RESEND_FROM_NAME' => 'FlexKit',
            'JWT_SECRET' => 'dwfkwoivhweusdkfjwesecurekey12345678'
        ];

        foreach ($defaults as $key => $value) {
            $this->config[$key] = $_ENV[$key] ?? $value;
        }
    }

    public function get(string $key, $default = null)
    {
        return $this->config[$key] ?? $default;
    }

    public function set(string $key, $value): void
    {
        $this->config[$key] = $value;
    }

    public function isDebug(): bool
    {
        return $this->get('APP_DEBUG') === 'true';
    }

    public function isDevelopment(): bool
    {
        return $this->get('APP_ENV') === 'development';
    }

    public function getMindbodyConfig(): array
    {
        return [
            'api_key' => $this->get('MINDBODY_API_KEY'),
            'site_id' => $this->get('MINDBODY_SITE_ID'),
            'source_name' => $this->get('MINDBODY_SOURCE_NAME'),
            'password' => $this->get('MINDBODY_PASSWORD')
        ];
    }

    public function getDatabaseConfig(): array
    {
        return [
            'host' => $this->get('DB_HOST'),
            'name' => $this->get('DB_NAME'),
            'user' => $this->get('DB_USER'),
            'pass' => $this->get('DB_PASS')
        ];
    }
} 