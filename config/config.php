<?php

/**
 * Основные настройки приложения
 */

// Настройки приложения
define('APP_NAME', 'Цифровой колледж КВКИ');
define('APP_SHORT_NAME', 'КВКИ');
define('APP_TAGLINE', 'Единый цифровой портал колледжа');
define('APP_VERSION', '1.0.0');
define('APP_LOGO', 'assets/img/kvki-logo.png');
define('APP_LOGO_ALT', 'Қарағанды жоғары инжиниринг колледжі');

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
function appLogoUrl()
{
    return rtrim(BASE_URL, '/') . '/' . ltrim(APP_LOGO, '/');
}

function appFaviconTags()
{
    $base = rtrim(BASE_URL, '/');
    $v = defined('APP_VERSION') ? APP_VERSION : '1';
    echo '<link rel="icon" type="image/png" sizes="32x32" href="' . htmlspecialchars($base . '/assets/img/favicon-32.png?v=' . $v) . '">' . "\n";
    echo '    <link rel="icon" type="image/png" sizes="16x16" href="' . htmlspecialchars($base . '/assets/img/favicon-16.png?v=' . $v) . '">' . "\n";
    echo '    <link rel="apple-touch-icon" href="' . htmlspecialchars($base . '/assets/img/apple-touch-icon.png?v=' . $v) . '">' . "\n";
    echo '    <link rel="shortcut icon" href="' . htmlspecialchars($base . '/assets/img/favicon.png?v=' . $v) . '">' . "\n";
}

/** Inline FOUC-safe theme bootstrap — call early in <head> */
function appThemeInitScript()
{
    echo '<script>(function(){try{var k="portal-theme",p=localStorage.getItem(k)||"system",m=window.matchMedia("(prefers-color-scheme: dark)"),d=p==="dark"||(p!=="light"&&m.matches);document.documentElement.setAttribute("data-theme",d?"dark":"light");document.documentElement.setAttribute("data-bs-theme",d?"dark":"light");document.documentElement.setAttribute("data-theme-pref",p);}catch(e){}})();</script>' . "\n";
}

function appThemeStylesheet()
{
    $base = rtrim(BASE_URL, '/');
    $v = defined('APP_VERSION') ? APP_VERSION : '1';
    echo '<link href="' . htmlspecialchars($base . '/assets/css/theme.css?v=' . $v) . '" rel="stylesheet">' . "\n";
}

function appThemeScript()
{
    $base = rtrim(BASE_URL, '/');
    $v = defined('APP_VERSION') ? APP_VERSION : '1';
    echo '<script src="' . htmlspecialchars($base . '/assets/js/theme.js?v=' . $v) . '" defer></script>' . "\n";
}

function appThemeToggle($extraClass = '')
{
    $class = trim('theme-toggle ' . $extraClass);
    echo '<button type="button" class="' . htmlspecialchars($class) . '" data-theme-toggle aria-label="Переключить тему" title="Светлая / тёмная тема">'
        . '<i class="bi bi-moon-stars-fill theme-icon-moon" aria-hidden="true"></i>'
        . '<i class="bi bi-sun-fill theme-icon-sun" aria-hidden="true"></i>'
        . '</button>';
}

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
