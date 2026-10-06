<?php
header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Простая версия без авторизации для тестирования
try {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'save_imported_data') {
            $data = json_decode($_POST['data'], true);

            if (!$data) {
                echo json_encode(['success' => false, 'error' => 'Некорректные данные']);
                exit;
            }

            // Подключаемся к базе данных напрямую
            $host = 'localhost';
            $username = 'root';
            $password = '';
            $database = 'arqandata';

            $mysqli = new mysqli($host, $username, $password, $database);

            if ($mysqli->connect_error) {
                throw new Exception("Ошибка подключения: " . $mysqli->connect_error);
            }

            $mysqli->set_charset("utf8mb4");

            // Создаем таблицу для хранения импортированных данных, если её нет
            $sql = "CREATE TABLE IF NOT EXISTS imported_student_data (
                id INT AUTO_INCREMENT PRIMARY KEY,
                iin VARCHAR(12) NOT NULL UNIQUE,
                first_name VARCHAR(100),
                last_name VARCHAR(100),
                middle_name VARCHAR(100),
                nationality VARCHAR(100),
                phone VARCHAR(20),
                email VARCHAR(100),
                permanent_address_ru TEXT,
                imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                imported_by INT DEFAULT 1,
                INDEX idx_iin (iin)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

            if (!$mysqli->query($sql)) {
                throw new Exception("Ошибка создания таблицы: " . $mysqli->error);
            }

            // Сохраняем данные
            $stmt = $mysqli->prepare("
                INSERT INTO imported_student_data 
                (iin, first_name, last_name, middle_name, nationality, phone, email, permanent_address_ru, imported_by) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE 
                first_name = VALUES(first_name),
                last_name = VALUES(last_name),
                middle_name = VALUES(middle_name),
                nationality = VALUES(nationality),
                phone = VALUES(phone),
                email = VALUES(email),
                permanent_address_ru = VALUES(permanent_address_ru),
                imported_at = CURRENT_TIMESTAMP,
                imported_by = VALUES(imported_by)
            ");

            if (!$stmt) {
                throw new Exception("Ошибка подготовки запроса: " . $mysqli->error);
            }

            $stmt->bind_param(
                "ssssssssi",
                $data['iin'],
                $data['first_name'] ?? '',
                $data['last_name'] ?? '',
                $data['middle_name'] ?? '',
                $data['nationality'] ?? '',
                $data['phone'] ?? '',
                $data['email'] ?? '',
                $data['permanent_address_ru'] ?? '',
                1 // Временный ID пользователя
            );

            if (!$stmt->execute()) {
                throw new Exception("Ошибка выполнения запроса: " . $stmt->error);
            }

            $stmt->close();
            $mysqli->close();

            echo json_encode(['success' => true, 'message' => 'Данные сохранены успешно']);
        } elseif ($action === 'get_student_data') {
            $iin = $_POST['iin'] ?? '';

            if (empty($iin) || strlen($iin) !== 12 || !preg_match('/^\d{12}$/', $iin)) {
                echo json_encode(['success' => false, 'error' => 'Некорректный ИИН']);
                exit;
            }

            // Подключаемся к базе данных
            $host = 'localhost';
            $username = 'root';
            $password = '';
            $database = 'arqandata';

            $mysqli = new mysqli($host, $username, $password, $database);

            if ($mysqli->connect_error) {
                throw new Exception("Ошибка подключения: " . $mysqli->connect_error);
            }

            $mysqli->set_charset("utf8mb4");

            $stmt = $mysqli->prepare("SELECT * FROM imported_student_data WHERE iin = ?");
            $stmt->bind_param("s", $iin);
            $stmt->execute();
            $result = $stmt->get_result();
            $student = $result->fetch_assoc();

            $stmt->close();
            $mysqli->close();

            if ($student) {
                echo json_encode(['success' => true, 'data' => $student]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Данные не найдены']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'Неизвестное действие']);
        }
    } else {
        echo json_encode(['success' => false, 'error' => 'Метод не поддерживается']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Ошибка сервера: ' . $e->getMessage()]);
}
