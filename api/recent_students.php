<?php
header('Content-Type: application/json');
require_once '../config/config.php';

try {
    $db = getDB();
    
    // Получение последних 5 добавленных студентов
    $sql = "SELECT id, iin, first_name, middle_name, course, academic_leave, graduation_date, created_at 
            FROM students 
            ORDER BY created_at DESC 
            LIMIT 5";
    
    $result = $db->query($sql);
    $students = $result->fetch_all(MYSQLI_ASSOC);
    
    echo json_encode([
        'success' => true,
        'students' => $students
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>

