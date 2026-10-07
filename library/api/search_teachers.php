<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkRole(['librarian', 'admin']);

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'teachers' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../../classes/Library.php';
$library = new Library();
$teachers = $library->searchTeachers($q, 15);

echo json_encode(['success' => true, 'teachers' => $teachers], JSON_UNESCAPED_UNICODE);
