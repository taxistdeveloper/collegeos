<?php
/**
 * Скрипт автоматического применения миграции практики на сервере.
 * Запуск:
 * 1. Через консоль: php database/run_practice_migration.php
 * 2. Либо через браузер авторизованным администратором: https://your-server.kz/database/run_practice_migration.php
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';

$isCli = (php_sapi_name() === 'cli');

if (!$isCli) {
    if (session_status() === PHP_SESSION_NONE) {
        startSessionSafely();
    }
    bridgeAdminSessionIfNeeded();
    if (empty($_SESSION['admin_logged_in']) && ($_SESSION['user_role'] ?? '') !== 'admin') {
        die("Доступ только для администратора системы.");
    }
}

$sqlFile = __DIR__ . '/practice_migration.sql';
if (!is_file($sqlFile)) {
    die("Файл practice_migration.sql не найден.\n");
}

$mysqli = Database::getInstance()->getConnection();
$sql = file_get_contents($sqlFile);

if ($mysqli->multi_query($sql)) {
    do {
        if ($res = $mysqli->store_result()) {
            $res->free();
        }
    } while ($mysqli->more_results() && $mysqli->next_result());
    
    $msg = "Успех! Миграция практики и роли «Практикант» успешно применена к базе данных!";
    if ($isCli) {
        echo $msg . "\n";
    } else {
        echo "<h2 style='color:green;'>{$msg}</h2><p><a href='../admin/index.php'>Вернуться в админ-панель</a></p>";
    }
} else {
    $err = "Ошибка выполнения SQL: " . $mysqli->error;
    if ($isCli) {
        echo $err . "\n";
    } else {
        echo "<h2 style='color:red;'>{$err}</h2>";
    }
}

