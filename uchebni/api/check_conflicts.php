<?php
require_once '../../config/config.php';
require_once '../../classes/Uchebni.php';
require_once '../../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

checkRole(['methodist']);
requirePermission('manage_schedule');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;

if (!$period_id) {
    echo json_encode(['conflicts' => [['message' => 'Семестр не задан']]], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = [
    'period_id' => $period_id,
    'group_id' => (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0),
    'subject_id' => (int)($_GET['subject_id'] ?? $_POST['subject_id'] ?? 0),
    'teacher_id' => (int)($_GET['teacher_id'] ?? $_POST['teacher_id'] ?? 0),
    'classroom_id' => (int)($_GET['classroom_id'] ?? $_POST['classroom_id'] ?? 0),
    'day_of_week' => (int)($_GET['day_of_week'] ?? $_POST['day_of_week'] ?? 0),
    'pair_number' => (int)($_GET['pair_number'] ?? $_POST['pair_number'] ?? 0),
    'shift' => (int)($_GET['shift'] ?? $_POST['shift'] ?? 1),
    'week_kind' => $_GET['week_kind'] ?? $_POST['week_kind'] ?? 'all',
];

$conflicts = $uchebni->checkConflicts($data);
echo json_encode(['conflicts' => $conflicts], JSON_UNESCAPED_UNICODE);
