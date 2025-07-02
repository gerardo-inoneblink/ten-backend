<?php

namespace FlexkitTen\Services;

use FlexkitTen\Config\AppConfig;
use PDO;
use PDOException;

class Database
{
    private static ?Database $instance = null;
    private PDO $connection;
    private AppConfig $config;


    private function __construct()
    {
        $this->config = AppConfig::getInstance();
        $this->connect();
        $this->createTables();
    }

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function connect(): void
    {
        try {
            $dbConfig = $this->config->getDatabaseConfig();

            $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4',
                $dbConfig['host'],
                $dbConfig['name']
            );

            $this->connection = new PDO($dsn, $dbConfig['user'], $dbConfig['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
        }
    }

    private function createTables(): void
    {
        $this->createOptSessionsTable();
        $this->createClientDetailsTable();
    }

    private function createOtpSessionsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS flexkit_opt_sessions (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(255) NOT NULL,
            otp_code VARCHAR(6) NOT NULL,
            client_id BIGINT(20) NOT NULL,
            client_email VARCHAR(255) NOT NULL,
            client_phone VARCHAR(20) NOT NULL,
            delivery_method VARCHAR(10) NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            used TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY otp_code (otp_code),
            KEY client_id (client_id),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->connection->exec($sql);
    }

    private function createClientDetailsTable(): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS flexkit_client_details (
            id BIGINT(20) NOT NULL AUTO_INCREMENT,
            mindbody_client_id BIGINT(20) NOT NULL,
            session_id VARCHAR(255) NOT NULL,
            site_id VARCHAR(50) NOT NULL,
            first_name VARCHAR(100) NULL,
            last_name VARCHAR(100) NULL,
            email VARCHAR(255) NULL,
            phone VARCHAR(20) NULL,
            last_login DATETIME NULL,
            expires_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY mindbody_client_id (mindbody_client_id),
            KEY session_id (session_id),
            KEY site_id (site_id),
            KEY email (email),
            KEY phone (phone),
            KEY expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $this->connection->exec($sql);
    }

    public function getConnection(): PDO
    {
        return $this->connection;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function insert(string $table, array $data): int
    {
        $columns = implode(', ', array_keys($data));
        $placeholders = ':' . implode(', :', array_keys($data));

        $sql = "INSERT INTO {$table} ({$columns}) VALUES ({$placeholders})";

        $stmt = $this->connection->prepare($sql);
        $stmt->execute($data);

        return (int) $this->connection->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $setClause = implode(', ', array_map(fn($col) => "{$col} = :{$col}", array_keys($data)));
        $whereClause = implode(' AND ', array_map(fn($col) => "{$col} = :where_{$col}", array_keys($where)));
        
        $sql = "UPDATE {$table} SET {$setClause} WHERE {$whereClause}";
        
        $params = $data;
        foreach ($where as $key => $value) {
            $params["where_{$key}"] = $value;
        }
        
        $stmt = $this->connection->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->rowCount();
    }
}