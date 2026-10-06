<?php
require_once '../../config/config.php';
require_once '../../classes/Uchebni.php';
require_once '../../classes/PermissionChecker.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

checkRole(['methodist']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_teachers');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$excludeTeacherId = (int)($_GET['exclude_teacher_id'] ?? 0);

if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'users' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$uchebni = new Uchebni();
$users = $uchebni->searchPortalUsers($q, 15, $excludeTeacherId);

echo json_encode(['success' => true, 'users' => $users], JSON_UNESCAPED_UNICODE);
