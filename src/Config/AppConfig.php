<?php

namespace FlexkitTen\Config;

class AppConfig
{
    private static ?AppConfig $instance = null;
    private array $config = [];

    private function __construct()
    {
        $this->setDefaults();
    }

    public static function getInstance(): AppConfig
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
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
            'DB_PASS' => '',
            'MINDBODY_API_KEY' => '5c3395a8c37d4c59a216c83ae4973f73',
            'MINDBODY_SITE_ID' => '-2147480113',
            'MINDBODY_SOURCE_NAME' => '_BLINK',
            'MINDBODY_PASSWORD' => 'wxtC2wqHsDFpvdSv9uSnX452CbM=',
            'SERVER_HOST' => 'localhost',
            'SERVER_PORT' => '8000',
            'LOG_LEVEL' => 'info',
            'LOG_FILE' => 'logs/application.log',
            'RESEND_API_KEY' => 're_SR9DqBBn_AKEdwxvDJ53gkYPKe97LtRPY',
            'RESEND_FROM_EMAIL' => 'onboarding@resend.dev',
            'RESEND_FROM_NAME' => 'FlexKit'
        ];

        foreach ($defaults as $key => $value) {
            $this->config[$key] = $value;
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