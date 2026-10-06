<?php

/**
 * Основные настройки приложения
 */

// Настройки приложения
define('APP_NAME', 'Цифровой колледж КВКИ');
define('APP_SHORT_NAME', 'КВКИ');
define('APP_TAGLINE', 'Единый цифровой портал колледжа');
define('APP_VERSION', '1.0.0');

// BASE_URL по хосту (локально / продакшн)
$host = preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? 'localhost');
if (strpos($host, 'app.kvki.kz') !== false || strpos($host, 'kvki.kz') !== false) {
    define('BASE_URL', 'https://app.kvki.kz/');
} else {
    define('BASE_URL', 'http://localhost/portal/');
}

// Безопасная функция для запуска сессии
function startSessionSafely()
{
    if (session_status() === PHP_SESSION_NONE) {
        // Настройки сессии устанавливаются только перед запуском
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_only_cookies', 1);
        session_start();
    }
}

// Запускаем сессию безопасно
startSessionSafely();

// Подключение к базе данных
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../classes/AdminLog.php';
AdminLog::register();

// Функции-помощники
function sanitize($data)
{
    if ($data === null) {
        return '';
    }
    return htmlspecialchars(strip_tags(trim($data)));
}

function redirect($url)
{
    header("Location: " . $url);
    exit();
}

function formatDate($date)
{
    return date('d.m.Y', strtotime($date));
}

function isLoggedIn()
{
    if (!isset($_SESSION['user_logged_in']) || !isset($_SESSION['user_id'])) {
        return false;
    }

    // Дополнительная проверка статуса пользователя в базе данных
    // Проверяем, что функция getDB доступна (база данных инициализирована)
    if (!function_exists('getDB')) {
        return isset($_SESSION['user_logged_in']);
    }

    $user_id = $_SESSION['user_id'];
    $db = getDB();
    $sql = "SELECT is_active FROM users WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user_data = $result->fetch_assoc();

    return $user_data && $user_data['is_active'];
}
