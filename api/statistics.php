<?php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../includes/student_status.php';

try {
    $db = getDB();
    $not_graduated = sqlNotGraduatedCondition('');
    $graduated = sqlGraduatedCondition('');
    
    // Общее количество студентов
    $total_students = $db->query("SELECT COUNT(*) as count FROM students")->fetch_assoc()['count'];
    
    // Активные студенты (не в академическом отпуске и не выпускники)
    $active_students = $db->query("SELECT COUNT(*) as count FROM students WHERE academic_leave = 0 AND $not_graduated")->fetch_assoc()['count'];
    
    // Студенты в академическом отпуске
    $academic_leave = $db->query("SELECT COUNT(*) as count FROM students WHERE academic_leave = 1")->fetch_assoc()['count'];
    
    // Выпускники
    $graduated_count = $db->query("SELECT COUNT(*) as count FROM students WHERE $graduated")->fetch_assoc()['count'];
    
    echo json_encode([
        'success' => true,
        'total_students' => $total_students,
        'active_students' => $active_students,
        'academic_leave' => $academic_leave,
        'graduated' => $graduated_count
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

