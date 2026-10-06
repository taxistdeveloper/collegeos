<?php

/**
 * Сохранение импортированных студентов в ту же базу, из которой
 * форма «Добавить студента» ищет данные по ИИН.
 */

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../config/server_config.php';

function importPreferValue($current, $incoming)
{
    $incoming = trim((string) $incoming);
    $current = (string) $current;

    if ($incoming === '') {
        return $current;
    }

    // В части выгрузок казахские буквы уже заменены на «?».
    // Не затираем ими уже сохранённое нормальное ФИО.
    if (strpos($incoming, '?') !== false && $current !== '' && strpos($current, '?') === false) {
        return $current;
    }

    return $incoming;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || ($_POST['action'] ?? '') !== 'save_imported_data') {
    echo json_encode(['success' => false, 'error' => 'Неизвестное действие'], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($_POST['data'] ?? '', true);
if (!is_array($data)) {
    echo json_encode(['success' => false, 'error' => 'Некорректные данные'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (isset($data['iin']) && !isset($data[0])) {
    $data = [$data];
}

if (!ensureImportTableExists()) {
    echo json_encode(['success' => false, 'error' => 'Не удалось подготовить таблицу импорта'], JSON_UNESCAPED_UNICODE);
    exit;
}

$mysqli = new mysqli(DB_HOST, DB_USERNAME, DB_PASSWORD, DB_NAME);
if ($mysqli->connect_error) {
    echo json_encode(['success' => false, 'error' => 'Ошибка подключения к базе данных'], JSON_UNESCAPED_UNICODE);
    exit;
}
$mysqli->set_charset(DB_CHARSET);

$select = $mysqli->prepare('SELECT first_name, last_name, middle_name, nationality, phone, email, permanent_address_ru FROM imported_student_data WHERE iin = ?');
$insert = $mysqli->prepare('INSERT INTO imported_student_data (iin, first_name, last_name, middle_name, nationality, phone, email, permanent_address_ru, imported_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
$update = $mysqli->prepare('UPDATE imported_student_data SET first_name = ?, last_name = ?, middle_name = ?, nationality = ?, phone = ?, email = ?, permanent_address_ru = ? WHERE iin = ?');

if (!$select || !$insert || !$update) {
    echo json_encode(['success' => false, 'error' => 'Ошибка подготовки запроса'], JSON_UNESCAPED_UNICODE);
    $mysqli->close();
    exit;
}

$inserted = 0;
$updated = 0;
$errors = [];

foreach ($data as $index => $student) {
    if (!is_array($student)) {
        continue;
    }

    $iin = preg_replace('/\D/', '', (string) ($student['iin'] ?? ''));
    if (strlen($iin) !== 12) {
        $errors[] = 'Строка ' . ($index + 1) . ': некорректный ИИН';
        continue;
    }

    $incoming = [
        'first_name' => trim((string) ($student['first_name'] ?? '')),
        'last_name' => trim((string) ($student['last_name'] ?? '')),
        'middle_name' => trim((string) ($student['middle_name'] ?? '')),
        'nationality' => trim((string) ($student['nationality'] ?? '')),
        'phone' => trim((string) ($student['phone'] ?? '')),
        'email' => trim((string) ($student['email'] ?? '')),
        'permanent_address_ru' => trim((string) ($student['permanent_address_ru'] ?? '')),
    ];

    $select->bind_param('s', $iin);
    $select->execute();
    $existing = $select->get_result()->fetch_assoc();

    if ($existing) {
        $first = importPreferValue($existing['first_name'], $incoming['first_name']);
        $last = importPreferValue($existing['last_name'], $incoming['last_name']);
        $middle = importPreferValue($existing['middle_name'], $incoming['middle_name']);
        $nationality = importPreferValue($existing['nationality'], $incoming['nationality']);
        $phone = importPreferValue($existing['phone'], $incoming['phone']);
        $email = importPreferValue($existing['email'], $incoming['email']);
        $address = importPreferValue($existing['permanent_address_ru'], $incoming['permanent_address_ru']);

        $update->bind_param('ssssssss', $first, $last, $middle, $nationality, $phone, $email, $address, $iin);
        if ($update->execute()) {
            $updated++;
        } else {
            $errors[] = 'ИИН ' . $iin . ': ' . $update->error;
        }
        continue;
    }

    $insert->bind_param(
        'ssssssss',
        $iin,
        $incoming['first_name'],
        $incoming['last_name'],
        $incoming['middle_name'],
        $incoming['nationality'],
        $incoming['phone'],
        $incoming['email'],
        $incoming['permanent_address_ru']
    );
    if ($insert->execute()) {
        $inserted++;
    } else {
        $errors[] = 'ИИН ' . $iin . ': ' . $insert->error;
    }
}

$select->close();
$insert->close();
$update->close();
$mysqli->close();

echo json_encode([
    'success' => $inserted + $updated > 0 || count($errors) === 0,
    'inserted' => $inserted,
    'updated' => $updated,
    'saved' => $inserted + $updated,
    'errors' => count($errors),
    'error_messages' => array_slice($errors, 0, 5),
], JSON_UNESCAPED_UNICODE);
