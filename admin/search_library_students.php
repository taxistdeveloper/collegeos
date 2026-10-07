<?php
require_once '../config/config.php';
require_once '../classes/Library.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_logged_in'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'students' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

$library = new Library();
$students = $library->searchStudents($q, 15);

echo json_encode(['success' => true, 'students' => $students], JSON_UNESCAPED_UNICODE);
