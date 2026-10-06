<?php

/**
 * Универсальный API для поиска студентов по ИИН
 * Работает с любой конфигурацией сервера
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

// Подключаем универсальную конфигурацию
require_once '../config/server_config.php';

// Функция для поиска студента по ИИН
function searchStudentByIIN($iin)
{
    try {
        // Убеждаемся, что таблица существует
        if (!ensureImportTableExists()) {
            throw new Exception('Не удалось создать таблицу для импорта');
        }

        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            throw new Exception('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset(DB_CHARSET);

        $stmt = $mysqli->prepare("SELECT * FROM imported_student_data WHERE iin = ?");
        $stmt->bind_param("s", $iin);
        $stmt->execute();
        $result = $stmt->get_result();
        $student = $result->fetch_assoc();

        $stmt->close();
        $mysqli->close();

        if ($student) {
            return [
                'success' => true,
                'data' => $student
            ];
        } else {
            return [
                'success' => false,
                'error' => 'Данные не найдены',
                'send_notification' => true
            ];
        }
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка поиска: ' . $e->getMessage()
        ];
    }
}

// Функция для получения всех импортированных студентов
function getAllImportedStudents()
{
    try {
        $mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);

        if ($mysqli->connect_error) {
            throw new Exception('Ошибка подключения к базе данных: ' . $mysqli->connect_error);
        }

        $mysqli->set_charset(DB_CHARSET);

        $stmt = $mysqli->prepare("SELECT * FROM imported_student_data ORDER BY imported_at DESC");
        $stmt->execute();
        $result = $stmt->get_result();

        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $row;
        }

        $stmt->close();
        $mysqli->close();

        return [
            'success' => true,
            'data' => $students
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'Ошибка получения данных: ' . $e->getMessage()
        ];
    }
}

// Основная логика API
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'get_student_data') {
            // Поиск студента по ИИН
            $iin = $_POST['iin'] ?? '';

            if (empty($iin) || strlen($iin) !== 12 || !preg_match('/^\d{12}$/', $iin)) {
                echo json_encode(['success' => false, 'error' => 'Некорректный ИИН']);
                exit;
            }

            $result = searchStudentByIIN($iin);
            echo json_encode($result);
        } elseif ($action === 'get_all_students') {
            // Получение всех студентов
            $result = getAllImportedStudents();
            echo json_encode($result);
        } elseif ($action === 'test_connection') {
            // Тест подключения
            $connectionTest = testDatabaseConnection();
            echo json_encode($connectionTest);
        } else {
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
