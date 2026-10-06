<?php
require_once '../config/config.php';
require_once '../classes/User.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$excludeId = (int)($_GET['exclude_id'] ?? 0);

if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'users' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = new User();
$users = $user->searchUsersByFio($q, 10, $excludeId);

$result = array_map(static function ($u) {
    $fio = trim(($u['last_name'] ?? '') . ' ' . ($u['first_name'] ?? '') . ' ' . ($u['middle_name'] ?? ''));
    $labels = $u['role_labels'] ?? [];
    if (empty($labels) && !empty($u['role_name'])) {
        $labels = [$u['role_name']];
    }
    return [
        'id' => (int)$u['id'],
        'fio' => $fio,
        'last_name' => $u['last_name'] ?? '',
        'first_name' => $u['first_name'] ?? '',
        'middle_name' => $u['middle_name'] ?? '',
        'login' => $u['login'] ?? '',
        'is_active' => (int)($u['is_active'] ?? 0),
        'role_labels' => $labels,
    ];
}, $users);

echo json_encode(['success' => true, 'users' => $result], JSON_UNESCAPED_UNICODE);
