<?php

/**
 * Настройки сервера и БД
 * Скопируйте в server_config.php и впишите реальные данные:
 *
 *   cp server_config.example.php server_config.php
 */

function detectServerConfig()
{
    $config = [];

    $serverName = $_SERVER['SERVER_NAME'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost';
    $hostOnly = preg_replace('/:\d+$/', '', $serverName);

    $isLocalHost = strpos($hostOnly, 'localhost') !== false
        || strpos($hostOnly, '127.0.0.1') !== false
        || preg_match('/^192\.168\.\d+\.\d+$/', $hostOnly)
        || preg_match('/^10\.\d+\.\d+\.\d+$/', $hostOnly)
        || preg_match('/^172\.(1[6-9]|2\d|3[0-1])\.\d+\.\d+$/', $hostOnly);

    if (strpos($serverName, 'krg-ktsk.kz') !== false || strpos($hostOnly, 'krg-ktsk.kz') !== false) {
        // ===== ПРОДАКШН — замените на свои данные =====
        $config = [
            'host' => 'localhost',
            'username' => 'YOUR_DB_USER',
            'password' => 'YOUR_DB_PASSWORD',
            'database' => 'YOUR_DB_NAME',
            'charset' => 'utf8mb4',
            'environment' => 'production'
        ];
    } elseif ($isLocalHost) {
        // Локальная разработка (MAMP)
        $config = [
            'host' => 'localhost',
            'username' => 'root',
            'password' => 'root',
            'database' => 'baza',
            'charset' => 'utf8mb4',
            'environment' => 'local'
        ];
    } else {
        $config = [
            'host' => $_ENV['DB_HOST'] ?? 'localhost',
            'username' => $_ENV['DB_USERNAME'] ?? 'YOUR_DB_USER',
            'password' => $_ENV['DB_PASSWORD'] ?? 'YOUR_DB_PASSWORD',
            'database' => $_ENV['DB_NAME'] ?? 'YOUR_DB_NAME',
            'charset' => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
            'environment' => 'production'
        ];
    }

    return $config;
}

$serverConfig = detectServerConfig();

define('DB_HOST', $serverConfig['host']);
define('DB_USERNAME', $serverConfig['username']);
define('DB_PASSWORD', $serverConfig['password']);
define('DB_NAME', $serverConfig['database']);
define('DB_CHARSET', $serverConfig['charset']);
define('ENVIRONMENT', $serverConfig['environment']);

function testDatabaseConnection()
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            return [
                'success' => false,
                'error' => 'Ошибка подключения: ' . $mysqli->connect_error,
                'config' => [
                    'host' => DB_HOST,
                    'username' => DB_USERNAME,
                    'database' => DB_NAME,
                    'environment' => ENVIRONMENT
                ]
            ];
        }

        $mysqli->set_charset(DB_CHARSET);
        $mysqli->close();

        return [
            'success' => true,
            'message' => 'Подключение успешно',
            'config' => [
                'host' => DB_HOST,
                'username' => DB_USERNAME,
                'database' => DB_NAME,
                'environment' => ENVIRONMENT
            ]
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Исключение: ' . $e->getMessage(),
            'config' => [
                'host' => DB_HOST,
                'username' => DB_USERNAME,
                'database' => DB_NAME,
                'environment' => ENVIRONMENT
            ]
        ];
    }
}

function ensureImportTableExists()
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            return false;
        }

        $mysqli->set_charset(DB_CHARSET);

        $createTable = "CREATE TABLE IF NOT EXISTS imported_student_data (
            id INT AUTO_INCREMENT PRIMARY KEY,
            iin VARCHAR(12) NOT NULL UNIQUE,
            first_name VARCHAR(100),
            last_name VARCHAR(100),
            middle_name VARCHAR(100),
            nationality VARCHAR(100),
            phone VARCHAR(20),
            email VARCHAR(100),
            permanent_address_ru TEXT,
            imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            imported_by INT DEFAULT 1,
            INDEX idx_iin (iin)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $result = $mysqli->query($createTable);
        $mysqli->close();

        return $result;
    } catch (Exception $e) {
        return false;
    }
}
