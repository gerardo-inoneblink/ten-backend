<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;

class Router
{
    private array $routes = [];
    private array $middleware = [];
    private AppConfig $config;
    private Logger $logger;

    public function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->logger = Logger::getInstance();
    }

    public function addMiddleware(callable $middleware): void
    {
        $this->middleware[] = $middleware;
    }

    public function get(string $path, callable $handler): void
    {
        $this->addRoute('GET', $path, $handler);
    }

    public function post(string $path, callable $handler): void
    {
        $this->addRoute('POST', $path, $handler);
    }

    public function put(string $path, callable $handler): void
    {
        $this->addRoute('PUT', $path, $handler);
    }

    public function delete(string $path, callable $handler): void
    {
        $this->addRoute('DELETE', $path, $handler);
    }

    public function patch(string $path, callable $handler): void
    {
        $this->addRoute('PATCH', $path, $handler);
    }

    private function addRoute(string $method, string $path, callable $handler): void
    {
        $this->routes[] = [
            'method' => $method,
            'path' => $path,
            'handler' => $handler
        ];
    }

    public function handle(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
            $path = $this->getPath();

            $this->logger->debug("Handling request: {$method} {$path}");

            $request = $this->buildRequest();
            $response = $this->buildResponse();

            foreach ($this->middleware as $middleware) {
                $result = $middleware($request, $response);
                if ($result === false) {
                    return;
                }
            }

            $handler = $this->findRoute($method, $path);
            
            if ($handler === null) {
                $this->sendNotFound();
                return;
            }

            $result = $handler($request, $response);
            
            if (is_array($result) || is_object($result)) {
                $this->sendJson($result);
            }

        } catch (\Throwable $e) {
            $this->logger->error('Router error: ' . $e->getMessage(), [
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            $this->sendError('Internal Server Error', 500);
        }
    }

    private function getPath(): string
    {
        $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
        
        $path = parse_url($requestUri, PHP_URL_PATH);
        
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path;
    }

    private function findRoute(string $method, string $path): ?callable
    {
        foreach ($this->routes as $route) {
            if ($route['method'] === $method && $this->pathMatches($route['path'], $path)) {
                return $route['handler'];
            }
        }

        return null;
    }

    private function pathMatches(string $routePath, string $requestPath): bool
    {
        return $routePath === $requestPath;
    }

    private function buildRequest(): array
    {
        $input = file_get_contents('php://input');
        $body = $input ? json_decode($input, true) : [];

        return [
            'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'path' => $this->getPath(),
            'query' => $_GET,
            'body' => $body,
            'headers' => $this->getAllHeaders(),
            'server' => $_SERVER
        ];
    }

    private function buildResponse(): array
    {
        return [
            'status' => 200,
            'headers' => [],
            'body' => null
        ];
    }

    private function getAllHeaders(): array
    {
        $headers = [];
        
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headerName = str_replace('_', '-', substr($key, 5));
                $headers[strtolower($headerName)] = $value;
            }
        }

        return $headers;
    }

    public function sendJson($data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit;
    }

    public function sendError(string $message, int $status = 400): void
    {
        $this->sendJson([
            'error' => true,
            'message' => $message,
            'status' => $status
        ], $status);
    }

    public function sendSuccess($data = null, string $message = 'Success'): void
    {
        $response = [
            'success' => true,
            'message' => $message
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        $this->sendJson($response);
    }

    private function sendNotFound(): void
    {
        $this->sendError('Route not found', 404);
    }

    public function enableCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
        
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
} 