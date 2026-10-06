<?php
require_once '../../config/config.php';
require_once '../../classes/Uchebni.php';
require_once '../../classes/PermissionChecker.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

checkRole(['methodist']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_subjects');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'teachers' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$uchebni = new Uchebni();
$teachers = $uchebni->searchTeachers($q, 15);

echo json_encode(['success' => true, 'teachers' => $teachers], JSON_UNESCAPED_UNICODE);
