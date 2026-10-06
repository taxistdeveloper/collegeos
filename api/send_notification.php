<?php

/**
 * API для отправки уведомлений администратору
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Обработка preflight запросов
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once '../config/server_config.php';
session_start();

// Функция для отправки уведомления о ненайденном студенте
function sendMissingStudentNotification($iin, $curator_id = null, $curator_name = null)
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            throw new Exception('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset(DB_CHARSET);

        // Проверяем, не отправляли ли уже уведомление для этого ИИН в последние 24 часа
        $check_stmt = $mysqli->prepare("
            SELECT id FROM admin_notifications 
            WHERE type = 'missing_student_data' 
            AND iin = ? 
            AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
        $check_stmt->bind_param("s", $iin);
        $check_stmt->execute();
        $existing = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        if ($existing) {
            return [
                'success' => true,
                'message' => 'Уведомление уже отправлено в течение последних 24 часов'
            ];
        }

        // Создаем уведомление
        $title = "Не найдены данные студента";
        $message = "Куратор " . ($curator_name ?: 'неизвестен') . " искал студента с ИИН: " . $iin . ", но данные не найдены в системе.";

        $stmt = $mysqli->prepare("
            INSERT INTO admin_notifications (type, title, message, iin, curator_id, curator_name) 
            VALUES (?, ?, ?, ?, ?, ?)
        ");

        $type = 'missing_student_data';
        $stmt->bind_param("ssssis", $type, $title, $message, $iin, $curator_id, $curator_name);

        if ($stmt->execute()) {
            $notification_id = $mysqli->insert_id;
            $stmt->close();
            $mysqli->close();

            return [
                'success' => true,
                'notification_id' => $notification_id,
                'message' => 'Уведомление отправлено администратору'
            ];
        } else {
            throw new Exception('Ошибка при создании уведомления: ' . $stmt->error);
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка отправки уведомления: ' . $e->getMessage()
        ];
    }
}

// Функция для получения уведомлений (для админа)
function getNotifications($limit = 50, $unread_only = false)
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            throw new Exception('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset(DB_CHARSET);

        $where_clause = $unread_only ? "WHERE is_read = FALSE" : "";
        $sql = "SELECT * FROM admin_notifications $where_clause ORDER BY created_at DESC LIMIT ?";

        $stmt = $mysqli->prepare($sql);
        $stmt->bind_param("i", $limit);
        $stmt->execute();
        $result = $stmt->get_result();

        $notifications = [];
        while ($row = $result->fetch_assoc()) {
            $notifications[] = $row;
        }

        $stmt->close();
        $mysqli->close();

        return [
            'success' => true,
            'data' => $notifications
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка получения уведомлений: ' . $e->getMessage()
        ];
    }
}

// Функция для отметки уведомления как прочитанного
function markNotificationAsRead($notification_id)
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            throw new Exception('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset(DB_CHARSET);

        $stmt = $mysqli->prepare("UPDATE admin_notifications SET is_read = TRUE, read_at = NOW() WHERE id = ?");
        $stmt->bind_param("i", $notification_id);

        if ($stmt->execute()) {
            $affected_rows = $stmt->affected_rows;
            $stmt->close();
            $mysqli->close();

            return [
                'success' => true,
                'affected_rows' => $affected_rows
            ];
        } else {
            throw new Exception('Ошибка при обновлении уведомления: ' . $stmt->error);
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка обновления уведомления: ' . $e->getMessage()
        ];
    }
}

// Основная логика API
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'send_missing_student_notification') {
            $iin = $_POST['iin'] ?? '';
            $curator_id = $_POST['curator_id'] ?? null;
            $curator_name = $_POST['curator_name'] ?? null;

            if (empty($iin) || strlen($iin) !== 12 || !preg_match('/^\d{12}$/', $iin)) {
                echo json_encode(['success' => false, 'error' => 'Некорректный ИИН']);
                exit;
            }

            $result = sendMissingStudentNotification($iin, $curator_id, $curator_name);
            echo json_encode($result);
        } elseif ($action === 'get_notifications') {
            // Проверяем права доступа (только для админов)
            if (!isset($_SESSION['admin_logged_in'])) {
                echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
                exit;
            }

            $limit = intval($_POST['limit'] ?? 50);
            $unread_only = isset($_POST['unread_only']) && $_POST['unread_only'] === 'true';

            $result = getNotifications($limit, $unread_only);
            echo json_encode($result);
        } elseif ($action === 'mark_as_read') {
            // Проверяем права доступа (только для админов)
            if (!isset($_SESSION['admin_logged_in'])) {
                echo json_encode(['success' => false, 'error' => 'Доступ запрещен']);
                exit;
            }

            $notification_id = intval($_POST['notification_id'] ?? 0);

            if ($notification_id <= 0) {
                echo json_encode(['success' => false, 'error' => 'Некорректный ID уведомления']);
                exit;
            }

            $result = markNotificationAsRead($notification_id);
            echo json_encode($result);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
