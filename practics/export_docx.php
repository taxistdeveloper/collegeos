<?php
require_once '../config/config.php';
require_once '../classes/Practice.php';
require_once '../includes/auth.php';

checkAuth();

$id = (int)($_GET['id'] ?? 0);
$practice = new Practice();
$workload = $practice->getWorkloadById($id);

if (!$workload) {
    die("Нагрузка практики не найдена");
}

$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$isTeacher = ($role === 'teacher');
if ($isTeacher) {
    $teacherId = $practice->getOrCreateTeacherByUserId((int)$current_user['id']);
    if ((int)$workload['teacher_id'] !== $teacherId) {
        die("У вас нет доступа к этому документу");
    }
}

try {
    $docxBytes = $practice->generateDocx($id);
    $filename = 'grafik_' . preg_replace('/[^\p{L}\p{N}_-]+/u', '_', $workload['teacher_name'] . '_' . $workload['study_group']) . '.docx';

    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($docxBytes));
    header('Cache-Control: max-age=0');
    header('Pragma: public');

    echo $docxBytes;
    exit;
} catch (Exception $e) {
    die("Ошибка формирования документа: " . htmlspecialchars($e->getMessage()));
}

