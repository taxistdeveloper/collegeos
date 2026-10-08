<?php
require_once '../config/config.php';
require_once '../classes/DiplomaSupplement.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$diploma = new DiplomaSupplement();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id > 0) {
    $student = $diploma->studentPayloadById($id);
    echo json_encode([
        'success' => (bool)$student,
        'student' => $student,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
echo json_encode([
    'success' => true,
    'students' => $diploma->searchStudents($q, 15),
], JSON_UNESCAPED_UNICODE);
