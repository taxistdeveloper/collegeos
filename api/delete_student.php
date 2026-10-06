<?php
header('Content-Type: application/json');
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/Group.php';

// Разрешаем DELETE и POST (на случай ограничений браузера)
if (!in_array($_SERVER['REQUEST_METHOD'], ['DELETE', 'POST'])) {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Метод не разрешен']);
    exit;
}

checkAuth();
$current_user = getCurrentUser();
$permissionChecker = new PermissionChecker();
$group = new Group();
$db = getDB();

$student_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
} else {
    // Для POST запросов проверяем и $_POST и JSON payload
    if (isset($_POST['id'])) {
        $student_id = (int)$_POST['id'];
    } elseif (isset($_GET['id'])) {
        $student_id = (int)$_GET['id'];
    } else {
        // Пробуем получить из JSON payload
        $json_input = file_get_contents('php://input');
        if ($json_input) {
            $payload = json_decode($json_input, true);
            if (isset($payload['id'])) {
                $student_id = (int)$payload['id'];
            } elseif (isset($payload['student_id'])) {
                $student_id = (int)$payload['student_id'];
            }
        }
    }
}

if (!$student_id) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Не указан ID студента',
        'debug' => [
            'method' => $_SERVER['REQUEST_METHOD'],
            'post_data' => $_POST,
            'get_data' => $_GET,
            'raw_input' => file_get_contents('php://input')
        ]
    ]);
    exit;
}

try {
    // Проверяем существование и определяем группу студента
    $check_sql = "SELECT id, group_id FROM students WHERE id = ?";
    $check_stmt = $db->prepare($check_sql);
    $check_stmt->bind_param("i", $student_id);
    $check_stmt->execute();
    $check_result = $check_stmt->get_result();
    $student_row = $check_result->fetch_assoc();

    if (!$student_row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Студент не найден']);
        exit;
    }

    // Проверка прав: администраторы могут удалять всех студентов
    $is_admin = isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true;

    $can_delete_all = $is_admin ||
        $permissionChecker->hasPermission($current_user['id'], 'delete_students') ||
        $permissionChecker->hasPermission($current_user['id'], 'delete_all_students') ||
        $permissionChecker->hasPermission($current_user['id'], 'all');

    if (!$can_delete_all) {
        $can_delete_own = $permissionChecker->hasPermission($current_user['id'], 'delete_own_students');
        if (!$can_delete_own) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Нет прав на удаление студентов']);
            exit;
        }
        // Проверяем, что студент из группы текущего куратора
        $curator_groups = $group->getGroupsByCurator($current_user['id']);
        $allowed_group_ids = array_column($curator_groups, 'id');
        if (!in_array((int)$student_row['group_id'], $allowed_group_ids, true)) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Можно удалять только студентов из своих групп']);
            exit;
        }
    }

    $old_group_id = (int)$student_row['group_id'];

    // Удаляем студента
    $delete_sql = "DELETE FROM students WHERE id = ?";
    $delete_stmt = $db->prepare($delete_sql);

    if (!$delete_stmt) {
        echo json_encode(['success' => false, 'error' => 'Ошибка подготовки запроса']);
        exit;
    }

    $delete_stmt->bind_param("i", $student_id);
    $result = $delete_stmt->execute();

    if ($result) {
        $affected_rows = $delete_stmt->affected_rows;
        if ($affected_rows > 0) {
            // Обновляем счетчик студентов в группе
            if ($old_group_id) {
                $group->updateStudentCount($old_group_id);
            }
            echo json_encode([
                'success' => true,
                'message' => 'Студент успешно удален',
                'student_id' => $student_id
            ]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Студент не был удален (возможно, уже удален)']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Ошибка при выполнении запроса удаления']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
