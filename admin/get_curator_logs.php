<?php
require_once '../config/config.php';
require_once '../classes/ActivityLog.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$activityLog = new ActivityLog();

// Получаем ID куратора
$curator_id = isset($_GET['curator_id']) ? (int)$_GET['curator_id'] : null;

if (!$curator_id) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Curator ID is required']);
    exit;
}

// Получаем логи и статистику
$logs = $activityLog->getLogs($curator_id, 50);
$stats = $activityLog->getStatistics($curator_id);

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'logs' => $logs,
    'stats' => $stats
], JSON_UNESCAPED_UNICODE);



