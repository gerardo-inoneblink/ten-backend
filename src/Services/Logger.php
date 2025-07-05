<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;
use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static ?Logger $instance = null;
    private MonologLogger $logger;
    private AppConfig $config;

    private function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->setupLogger();
    }

    public static function getInstance(): Logger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function setupLogger(): void
    {
        $this->logger = new MonologLogger($this->config->get('APP_NAME', 'FlexKitTen'));

        $logFile = $this->config->get('LOG_FILE');
        $logDir = dirname($logFile);

        if (!is_dir($logDir)) {
            if (!mkdir($logDir, 0755, true)) {
                throw new \RuntimeException("Failed to create log directory: {$logDir}");
            }
        }

        $fileHandler = new RotatingFileHandler(
            $logFile,
            7,
            $this->getLogLevel()
        );

        $formatter = new LineFormatter(
            "[%datetime%] %level_name%: %message% %context% %extra%\n",
            'Y-m-d H:i:s'
        );
        $fileHandler->setFormatter($formatter);

        $this->logger->pushHandler($fileHandler);

        if ($this->config->isDevelopment()) {
            $consoleHandler = new StreamHandler('php://stderr', $this->getLogLevel());
            $consoleHandler->setFormatter($formatter);
            $this->logger->pushHandler($consoleHandler);
        }
    }

    private function getLogLevel(): int
    {
        $level = strtolower($this->config->get('LOG_LEVEL', 'info'));

        return match ($level) {
            'debug' => MonologLogger::DEBUG,
            'info' => MonologLogger::INFO,
            'notice' => MonologLogger::NOTICE,
            'warning' => MonologLogger::WARNING,
            'error' => MonologLogger::ERROR,
            'critical' => MonologLogger::CRITICAL,
            'alert' => MonologLogger::ALERT,
            'emergency' => MonologLogger::EMERGENCY,
            default => MonologLogger::INFO
        };
    }

    public function debug(string $message, array $context = []): void
    {
        $this->logger->debug($message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->logger->info($message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->logger->notice($message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->logger->alert($message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->logger->emergency($message, $context);
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $this->logger->log($level, $message, $context);
    }

    public function logMindbodyApi(string $message, array $context = []): void
    {
        $this->info('[MINDBODY API] ' . $message, $context);
    }

    public function logOtpOperation(string $message, array $context = []): void
    {
        $this->info('[OTP] ' . $message, $context);
    }

    public function logSessionOperation(string $message, array $context = []): void
    {
        $this->info('[SESSION] ' . $message, $context);
    }

    public function logTimetableOperation(string $message, array $context = []): void
    {
        $this->info('[TIMETABLE] ' . $message, $context);
    }
}