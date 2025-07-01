<?php

namespace FlexkitTen\Config;

use Dotenv\Dotenv;

class AppConfig
{
    private static $instance = null;
    private $config = [];

    private function __construct()
    {
        $this->loadConfig();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function loadConfig()
    {
        $dotenv = Dotenv::createImmutable(__DIR__ . '/../../');
        $dotenv->load();

        $this->config['app_name'] = getenv('APP_NAME') ?: 'FlexkitTen';
        $this->config['app_version'] = getenv('APP_VERSION') ?: '1.0.0';
        $this->config['debug_mode'] = getenv('DEBUG_MODE') === 'true';
    }

    public function get($key)
    {
        return $this->config[$key] ?? null;
    }
}