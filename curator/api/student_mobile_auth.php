<?php
/**
 * API куратора: генерация инвайт-кода и сброс устройства студента
 * POST JSON: { action: generate_invite|reset_device|status, student_id }
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/User.php';
require_once __DIR__ . '/../../classes/Group.php';
require_once __DIR__ . '/../../classes/PermissionChecker.php';
require_once __DIR__ . '/../../classes/StudentMobileAuth.php';
require_once __DIR__ . '/../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function curatorMobileJson($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    curatorMobileJson(['success' => false, 'error' => 'Метод не поддерживается'], 405);
}

if (!isLoggedIn()) {
    curatorMobileJson(['success' => false, 'error' => 'Требуется авторизация'], 401);
}

$raw = file_get_contents('php://input');
$body = json_decode($raw ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

$action = $body['action'] ?? '';
$studentId = (int)($body['student_id'] ?? 0);

if ($studentId <= 0) {
    curatorMobileJson(['success' => false, 'error' => 'Не указан студент'], 400);
}

$permissionChecker = new PermissionChecker();
$current_user = getCurrentUser();
$group = new Group();
$db = getDB();

$canManage = !empty($_SESSION['admin_logged_in'])
    || $permissionChecker->hasPermission($current_user['id'], 'edit_own_students')
    || $permissionChecker->hasPermission($current_user['id'], 'edit_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_own_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all');

if (!$canManage) {
    curatorMobileJson(['success' => false, 'error' => 'Недостаточно прав'], 403);
}

$stmt = $db->prepare("SELECT id, group_id, last_name, first_name, middle_name FROM students WHERE id = ? LIMIT 1");
$stmt->bind_param('i', $studentId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student) {
    curatorMobileJson(['success' => false, 'error' => 'Студент не найден'], 404);
}

$canViewAll = !empty($_SESSION['admin_logged_in'])
    || $permissionChecker->hasPermission($current_user['id'], 'view_all_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all');

if (!$canViewAll) {
    $curator_groups = $group->getGroupsByCurator($current_user['id']);
    $allowed = array_map('intval', array_column($curator_groups, 'id'));
    if (!empty($student['group_id']) && !in_array((int)$student['group_id'], $allowed, true)) {
        curatorMobileJson(['success' => false, 'error' => 'Нет доступа к этому студенту'], 403);
    }
}

$mobileAuth = new StudentMobileAuth();

try {
    switch ($action) {
        case 'status':
            $authRow = $mobileAuth->ensureAuthRow($studentId);
            curatorMobileJson([
                'success' => true,
                'data' => [
                    'device_bound' => !empty($authRow['device_id']),
                    'device_status' => $mobileAuth->deviceStatusLabel($authRow),
                    'device_bound_at' => $authRow['device_bound_at'] ?? null,
                    'token_version' => (int)($authRow['token_version'] ?? 0),
                ],
            ]);
            break;

        case 'generate_invite':
            $invite = $mobileAuth->createInvite($studentId, $current_user['id'] ?? null);
            $name = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? ''));
            curatorMobileJson([
                'success' => true,
                'data' => [
                    'code' => $invite['code'],
                    'expires_at' => $invite['expires_at'],
                    'student_name' => $name,
                ],
                'message' => 'Код создан. Действует 24 часа, одноразовый.',
            ]);
            break;

        case 'reset_device':
            $authRow = $mobileAuth->resetDevice($studentId);
            curatorMobileJson([
                'success' => true,
                'data' => [
                    'device_bound' => false,
                    'device_status' => $mobileAuth->deviceStatusLabel($authRow),
                    'token_version' => (int)($authRow['token_version'] ?? 0),
                ],
                'message' => 'Устройство сброшено. Выдайте студенту новый код.',
            ]);
            break;

        default:
            curatorMobileJson(['success' => false, 'error' => 'Неизвестное действие'], 400);
    }
} catch (Exception $e) {
    curatorMobileJson(['success' => false, 'error' => 'Ошибка: ' . $e->getMessage()], 500);
}
