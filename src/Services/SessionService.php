<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class SessionService
{
    private static ?SessionService $instance = null;
    private AppConfig $config;
    private Logger $logger;

    private function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->logger = Logger::getInstance();
        $this->initializeSession();
    }

    public static function getInstance(): SessionService
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function initializeSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 30 * 24 * 3600,
                'path' => '/',
                'domain' => '',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Lax',
            ]);

            session_start();

            $this->logger->logSessionOperation("Session initialized", [
                'session_id' => $this->getSessionId(),
                'secure' => isset($_SERVER['HTTPS'])
            ]);
        }
    }

    public function getSessionId(): string
    {
        return session_id();
    }

    public function regenerateId(): string
    {
        session_regenerate_id(true);
        $newSessionId = session_id();

        $this->logger->logSessionOperation("Session ID regenerated", [
            'new_session_id' => $newSessionId
        ]);

        return $newSessionId;
    }

    public function set(string $key, $value): void
    {
        $_SESSION[$key] = $value;

        $this->logger->debug("Session value set", [
            'key' => $key,
            'session_id' => $this->getSessionId()
        ]);
    }

    public function get(string $key, $default = null)
    {
        return $_SESSION[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        unset($_SESSION[$key]);

        $this->logger->debug("Session value removed", [
            'key' => $key,
            'session_id' => $this->getSessionId()
        ]);
    }

    public function clear(): void
    {
        $sessionId = $this->getSessionId();
        $_SESSION = [];

        $this->logger->logSessionOperation("Session cleared", [
            'session_id' => $sessionId
        ]);
    }

    public function destroy(): void
    {
        $sessionId = $this->getSessionId();

        $_SESSION = [];

        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 3600, '/');
        }

        session_destroy();

        $this->logger->logSessionOperation("Session destroyed", [
            'session_id' => $sessionId
        ]);
    }

    public function isExpired(): bool
    {
        $lastActivity = $this->get('last_activity');

        if (!$lastActivity) {
            return true;
        }

        $maxLifetime = 30 * 24 * 3600;
        return (time() - $lastActivity) > $maxLifetime;
    }

    public function updateActivity(): void
    {
        $this->set('last_activity', time());
    }

    public function getAll(): array
    {
        return $_SESSION;
    }

    public function flash(string $key, $value = null)
    {
        if ($value !== null) {
            $this->set('_flash_' . $key, $value);
        } else {
            $value = $this->get('_flash_' . $key);
            $this->remove('_flash_' . $key);
            return $value;
        }
    }

    public function hasFlash(string $key): bool
    {
        return $this->has('_flash_' . $key);
    }

    public function getFlashMessages(): array
    {
        $messages = [];

        foreach ($_SESSION as $key => $value) {
            if (str_starts_with($key, '_flash_')) {
                $messageKey = substr($key, 7);
                $messages[$messageKey] = $value;
                unset($_SESSION[$key]);
            }
        }

        return $messages;
    }
}