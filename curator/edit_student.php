<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../classes/ErrorTranslator.php';
require_once '../includes/auth.php';

// Проверка авторизации
checkAuth();
$current_user = getCurrentUser();
$permissionChecker = new PermissionChecker();

// Инициализация базы данных
$db = getDB();

// Получаем ID студента
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: my_students.php');
    exit;
}

// Загружаем студента
$sql = "SELECT * FROM students WHERE id = ?";
$stmt = $db->prepare($sql);
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
if (!$student) {
    header('Location: my_students.php');
    exit;
}

$user = new User();
$group = new Group();
$dynamicField = new DynamicField();
$current_user = getCurrentUser();

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);

// Получение динамических полей
$dynamic_fields = $dynamicField->getActiveFields();

$message = '';
$error = '';

// Функция для получения сохраненного значения поля
function getFieldValue($field_name, $default = '')
{
    global $student;
    if (isset($_POST[$field_name])) {
        return htmlspecialchars($_POST[$field_name]);
    }
    return htmlspecialchars($student[$field_name] ?? $default);
}

// Функция для проверки, выбрано ли значение в select
function isSelected($field_name, $value)
{
    global $student;
    if (isset($_POST[$field_name])) {
        return $_POST[$field_name] == $value ? 'selected' : '';
    }
    return isset($student[$field_name]) && $student[$field_name] == $value ? 'selected' : '';
}

// Функция для проверки, отмечен ли checkbox
function isChecked($field_name)
{
    global $student;
    if (isset($_POST[$field_name])) {
        return 'checked';
    }
    return isset($student[$field_name]) && $student[$field_name] ? 'checked' : '';
}

// Функция для перевода ошибок базы данных на русский язык (использует ErrorTranslator)
function translateDatabaseError($error_message)
{
    return ErrorTranslator::translate($error_message);
}

// Обработка редактирования студента
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        // Проверяем, что выбранная группа принадлежит куратору
        $selected_group_id = (int)$_POST['group_id'];
        $group_belongs_to_curator = false;

        foreach ($curator_groups as $group_item) {
            if ($group_item['id'] == $selected_group_id) {
                $group_belongs_to_curator = true;
                break;
            }
        }

        if (!$group_belongs_to_curator) {
            $error = 'Вы можете добавлять студентов только в свои группы!';
        } else {
            // Проверка обязательных полей
            $required_fields = [
                'iin' => 'ИИН',
                'first_name' => 'Имя',
                'last_name' => 'Фамилия',
                'middle_name' => 'Отчество',
                'birth_date' => 'Дата рождения',
                'gender' => 'Пол',
                'nationality' => 'Национальность',
                'permanent_address_ru' => 'Постоянный адрес (русский)',
                'phone' => 'Телефон',
                'email' => 'Email',
                'course' => 'Курс',
                'course_start_date' => 'Дата начала курса',
                'language' => 'Язык обучения',
                'study_form' => 'Форма обучения',
                'specialty' => 'Специальность'
            ];

            $validation_errors = [];

            foreach ($required_fields as $field => $label) {
                if (empty($_POST[$field])) {
                    $validation_errors[] = "Поле '$label' обязательно для заполнения!";
                }
            }

            // Дополнительные проверки
            if (!empty($_POST['iin']) && !preg_match('/^\d{12}$/', $_POST['iin'])) {
                $validation_errors[] = "ИИН должен содержать ровно 12 цифр!";
            }

            if (!empty($_POST['phone']) && !preg_match('/^[\+]?[0-9\s\-\(\)]{10,}$/', $_POST['phone'])) {
                $validation_errors[] = "Неверный формат телефона!";
            }

            if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = "Неверный формат email!";
            }

            if (!empty($_POST['birth_date'])) {
                $birth_date = new DateTime($_POST['birth_date']);
                $today = new DateTime();
                $age = $today->diff($birth_date)->y;

                if ($age < 16 || $age > 65) {
                    $validation_errors[] = "Возраст должен быть от 16 до 65 лет!";
                }
            }

            if (!empty($_POST['course_start_date']) && !empty($_POST['course_end_date'])) {
                $start_date = new DateTime($_POST['course_start_date']);
                $end_date = new DateTime($_POST['course_end_date']);

                if ($end_date <= $start_date) {
                    $validation_errors[] = "Дата окончания курса должна быть позже даты начала!";
                }
            }

            // Убираем серверный фолбэк автоподстановки для residence_type, чтобы не перетирать выбор пользователя

            // Устанавливаем значение по умолчанию если поле пустое
            if (empty($_POST['residence_type'])) {
                $_POST['residence_type'] = 'городская местность';
            }

            // Принудительно проверяем и исправляем значение residence_type для enum
            $valid_residence_types = ['городская местность', 'сельская местность'];
            if (!in_array($_POST['residence_type'], $valid_residence_types)) {
                $_POST['residence_type'] = 'городская местность';
            }

            // Принудительно проверяем и исправляем значение practice_type для enum
            $valid_practice_types = ['производственная', 'учебная', 'преддипломная', 'не проходит'];
            if (empty($_POST['practice_type'])) {
                $_POST['practice_type'] = 'не проходит';
            } elseif (!in_array($_POST['practice_type'], $valid_practice_types)) {
                $_POST['practice_type'] = 'не проходит';
            }

            // Формируем итоговую ошибку после всех проверок
            if (!empty($validation_errors)) {
                $error = implode('<br>', $validation_errors);
            }

            if (empty($error)) {
                // Проверка и установка значений по умолчанию для ENUM полей
                $arrival_from = sanitize($_POST['arrival_from']);
                if (empty($arrival_from)) {
                    $arrival_from = 'данного района (города, села) данной области';
                }

                $education_type = sanitize($_POST['education_type']);
                if (empty($education_type)) {
                    $education_type = 'средняя школа';
                }

                $residence_type = sanitize($_POST['residence_type']);
                if (empty($residence_type)) {
                    $residence_type = 'городская местность';
                }

                $study_duration = sanitize($_POST['study_duration']);
                if (empty($study_duration)) {
                    $study_duration = '3 года';
                }

                $course = sanitize($_POST['course']);
                if (empty($course)) {
                    $course = '1 курс';
                }

                $language = sanitize($_POST['language']);
                if (empty($language)) {
                    $language = 'казахский';
                }

                $study_form = sanitize($_POST['study_form']);
                if (empty($study_form)) {
                    $study_form = 'очная';
                }

                $practice_type = sanitize($_POST['practice_type']);
                if (empty($practice_type)) {
                    $practice_type = 'не проходит';
                }

                error_log("arrival_from value: " . $arrival_from);

                // Подготовка данных
                $data = [
                    'iin' => sanitize($_POST['iin']),
                    'first_name' => sanitize($_POST['first_name']),
                    'last_name' => sanitize($_POST['last_name']),
                    'middle_name' => sanitize($_POST['middle_name']),
                    'birth_date' => $_POST['birth_date'],
                    'gender' => $_POST['gender'],
                    'nationality' => sanitize($_POST['nationality']),
                    'permanent_address_ru' => sanitize($_POST['permanent_address_ru'] ?? ''),
                    'temporary_address_ru' => sanitize($_POST['temporary_address_ru'] ?? ''),
                    'temporary_address_kz' => isset($_POST['temporary_address_kz']) ? sanitize($_POST['temporary_address_kz']) : null,
                    'arrival_date' => !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null,
                    'enrollment_order_number' => sanitize($_POST['enrollment_order_number']),
                    'enrollment_type' => 'обычное', // Значение по умолчанию
                    'arrival_from' => $arrival_from,
                    'phone' => sanitize($_POST['phone']),
                    'email' => sanitize($_POST['email']),
                    'education_type' => $education_type,
                    'residence_type' => $residence_type,
                    'study_duration' => $study_duration,
                    'graduation_date' => !empty($_POST['graduation_date']) ? $_POST['graduation_date'] : null,
                    'course' => $course,
                    'course_start_date' => !empty($_POST['course_start_date']) ? $_POST['course_start_date'] : null,
                    'course_end_date' => !empty($_POST['course_end_date']) ? $_POST['course_end_date'] : null,
                    'language' => $language,
                    'study_form' => $study_form,
                    'specialty' => sanitize($_POST['specialty']),
                    'group_code' => sanitize($_POST['group_code']),
                    'academic_leave' => isset($_POST['academic_leave']) ? 1 : 0,
                    'academic_leave_reason' => !empty($_POST['academic_leave_reason']) ? sanitize($_POST['academic_leave_reason']) : null,
                    'academic_leave_order_date' => !empty($_POST['academic_leave_order_date']) ? $_POST['academic_leave_order_date'] : null,
                    'quota_category' => !empty($_POST['quota_category']) ? sanitize($_POST['quota_category']) : 'не относится ни к одной из указанных категорий',
                    'hot_meal' => isset($_POST['hot_meal']) ? 1 : 0,
                    'free_hot_meal' => isset($_POST['free_hot_meal']) ? 1 : 0,
                    'buffet_meal' => isset($_POST['buffet_meal']) ? 1 : 0,
                    'free_buffet_meal' => isset($_POST['free_buffet_meal']) ? 1 : 0,
                    'youth_committee' => isset($_POST['youth_committee']) ? 1 : 0,
                    'student_parliament' => isset($_POST['student_parliament']) ? 1 : 0,
                    'practice_type' => $practice_type,
                    'paid_practice' => isset($_POST['paid_practice']) ? 1 : 0,
                    'jas_sarbaz' => isset($_POST['jas_sarbaz']) ? 1 : 0,
                    'competitions' => isset($_POST['competitions']) ? sanitize($_POST['competitions']) : '',
                    'orphan' => isset($_POST['orphan']) ? 1 : 0,
                    'without_parental_care' => isset($_POST['without_parental_care']) ? 1 : 0,
                    'disability' => isset($_POST['disability']) ? 1 : 0,
                    'primary_violation' => !empty($_POST['primary_violation']) ? sanitize($_POST['primary_violation']) : null,
                    'hearing_impairment' => !empty($_POST['hearing_impairment']) ? sanitize($_POST['hearing_impairment']) : null,
                    'vision_impairment' => !empty($_POST['vision_impairment']) ? sanitize($_POST['vision_impairment']) : null,
                    'intellectual_impairment' => !empty($_POST['intellectual_impairment']) ? sanitize($_POST['intellectual_impairment']) : null,
                    'social_assistance' => isset($_POST['social_assistance']) ? 1 : 0,
                    'large_family' => isset($_POST['large_family']) ? 1 : 0,
                    'group_id' => $selected_group_id
                ];

                // Добавление студента
                $db = getDB();

                // Проверяем, есть ли поле group_id в таблице
                $result = $db->query("SHOW COLUMNS FROM students LIKE 'group_id'");
                $has_group_id = $result->num_rows > 0;

                if ($has_group_id) {
                    // Полный запрос с group_id
                    $sql = "INSERT INTO students (
                    iin, first_name, last_name, middle_name, birth_date, gender, nationality,
                    permanent_address_ru, temporary_address_ru,
                    arrival_date, enrollment_order_number, enrollment_type, arrival_from,
                    phone, email, education_type, residence_type,
                    study_duration, graduation_date, course, course_start_date, course_end_date,
                    language, study_form, specialty, group_code,
                    academic_leave, academic_leave_reason, academic_leave_order_date,
                    quota_category, hot_meal, free_hot_meal, buffet_meal, free_buffet_meal,
                    youth_committee, student_parliament, practice_type, paid_practice, jas_sarbaz, competitions,
                    orphan, without_parental_care, disability, primary_violation,
                    hearing_impairment, vision_impairment, intellectual_impairment,
                    social_assistance, large_family, group_id
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                } else {
                    // Упрощенный запрос без group_id
                    $sql = "INSERT INTO students (
                    iin, first_name, last_name, middle_name, birth_date, gender, nationality,
                    permanent_address_ru, temporary_address_ru,
                    arrival_date, enrollment_order_number, enrollment_type, arrival_from,
                    phone, email, education_type, residence_type,
                    study_duration, graduation_date, course, course_start_date, course_end_date,
                    language, study_form, specialty, group_code,
                    academic_leave, academic_leave_reason, academic_leave_order_date,
                    quota_category, hot_meal, free_hot_meal, buffet_meal, free_buffet_meal,
                    youth_committee, student_parliament, practice_type, paid_practice, jas_sarbaz, competitions,
                    orphan, without_parental_care, disability, primary_violation,
                    hearing_impairment, vision_impairment, intellectual_impairment,
                    social_assistance, large_family
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                }

                // Отладочная информация
                $placeholder_count = substr_count($sql, '?');
                error_log("SQL Query: " . $sql);
                error_log("Placeholder count: " . $placeholder_count);

                // Подготовка параметров для bind_param
                if ($has_group_id) {
                    $params = [
                        $data['iin'],
                        $data['first_name'],
                        $data['last_name'],
                        $data['middle_name'],
                        $data['birth_date'],
                        $data['gender'],
                        $data['nationality'],
                        $data['permanent_address_ru'],
                        $data['temporary_address_ru'],
                        $data['arrival_date'],
                        $data['enrollment_order_number'],
                        $data['enrollment_type'],
                        $data['arrival_from'],
                        $data['phone'],
                        $data['email'],
                        $data['education_type'],
                        $data['residence_type'],
                        $data['study_duration'],
                        $data['graduation_date'],
                        $data['course'],
                        $data['course_start_date'],
                        $data['course_end_date'],
                        $data['language'],
                        $data['study_form'],
                        $data['specialty'],
                        $data['group_code'],
                        $data['academic_leave'],
                        $data['academic_leave_reason'],
                        $data['academic_leave_order_date'],
                        $data['quota_category'],
                        $data['hot_meal'],
                        $data['free_hot_meal'],
                        $data['buffet_meal'],
                        $data['free_buffet_meal'],
                        $data['youth_committee'],
                        $data['student_parliament'],
                        $data['practice_type'],
                        $data['paid_practice'],
                        $data['jas_sarbaz'],
                        $data['competitions'],
                        $data['orphan'],
                        $data['without_parental_care'],
                        $data['disability'],
                        $data['primary_violation'],
                        $data['hearing_impairment'],
                        $data['vision_impairment'],
                        $data['intellectual_impairment'],
                        $data['social_assistance'],
                        $data['large_family'],
                        $data['group_id']
                    ];
                } else {
                    $params = [
                        $data['iin'],
                        $data['first_name'],
                        $data['last_name'],
                        $data['middle_name'],
                        $data['birth_date'],
                        $data['gender'],
                        $data['nationality'],
                        $data['permanent_address_ru'],
                        $data['temporary_address_ru'],
                        $data['arrival_date'],
                        $data['enrollment_order_number'],
                        $data['enrollment_type'],
                        $data['arrival_from'],
                        $data['phone'],
                        $data['email'],
                        $data['education_type'],
                        $data['residence_type'],
                        $data['study_duration'],
                        $data['graduation_date'],
                        $data['course'],
                        $data['course_start_date'],
                        $data['course_end_date'],
                        $data['language'],
                        $data['study_form'],
                        $data['specialty'],
                        $data['group_code'],
                        $data['academic_leave'],
                        $data['academic_leave_reason'],
                        $data['academic_leave_order_date'],
                        $data['quota_category'],
                        $data['hot_meal'],
                        $data['free_hot_meal'],
                        $data['buffet_meal'],
                        $data['free_buffet_meal'],
                        $data['youth_committee'],
                        $data['student_parliament'],
                        $data['practice_type'],
                        $data['paid_practice'],
                        $data['jas_sarbaz'],
                        $data['competitions'],
                        $data['orphan'],
                        $data['without_parental_care'],
                        $data['disability'],
                        $data['primary_violation'],
                        $data['hearing_impairment'],
                        $data['vision_impairment'],
                        $data['intellectual_impairment'],
                        $data['social_assistance'],
                        $data['large_family']
                    ];
                }

                error_log("Parameters count: " . count($params));

                $stmt = $db->prepare($sql);
                if ($stmt) {

                    // Создание строки типов
                    $types = '';
                    foreach ($params as $param) {
                        if (is_int($param)) {
                            $types .= 'i'; // integer
                        } else {
                            $types .= 's'; // string
                        }
                    }

                    error_log("Types string: " . $types);

                    // Привязка параметров
                    $stmt->bind_param($types, ...$params);
                    $result = $stmt->execute();

                    if ($result) {
                        // Получаем ID добавленного студента
                        $student_id = $db->getLastInsertId();

                        // Альтернативный способ получения ID, если первый не сработал
                        if (!$student_id || $student_id <= 0) {
                            $connection = $db->getConnection();
                            $student_id = $connection->insert_id;
                            error_log("Использован альтернативный способ получения ID: " . $student_id);
                        }

                        // Проверяем, что ID получен корректно
                        if (!$student_id || $student_id <= 0) {
                            error_log("Ошибка: не удалось получить ID добавленного студента. getLastInsertId() вернул: " . var_export($student_id, true));
                            $error = 'Ошибка при сохранении студента: не удалось получить ID записи';
                        } else {
                            error_log("Студент успешно добавлен с ID: " . $student_id);

                            // Сохраняем динамические поля
                            if (isset($_POST['dynamic_fields']) && !empty($_POST['dynamic_fields'])) {
                                try {
                                    error_log("Сохраняем динамические поля для студента ID: " . $student_id);
                                    $dynamicField->saveStudentDynamicFields($student_id, $_POST['dynamic_fields']);
                                    error_log("Динамические поля успешно сохранены");
                                } catch (Exception $e) {
                                    error_log("Ошибка сохранения динамических полей: " . $e->getMessage());
                                    // Не прерываем выполнение, так как основные данные уже сохранены
                                }
                            }

                            // Обновляем количество студентов в группе (только если есть поле group_id)
                            if ($has_group_id) {
                                $group->updateStudentCount($selected_group_id);
                            }

                            // Перенаправляем на страницу "Мои студенты" с сообщением об успехе
                            $_SESSION['success_message'] = 'Студент успешно добавлен!';
                            header('Location: my_students.php');
                            exit;
                        }
                    } else {
                        // Показываем конкретную ошибку в понятном виде
                        $sql_error = $stmt->error;
                        $error = translateDatabaseError($sql_error);

                        // Если это ошибка дублирования, перенаправляем на my_students.php с сообщением об ошибке
                        if (strpos($sql_error, 'Duplicate entry') !== false) {
                            $_SESSION['error_message'] = $error;
                            header('Location: my_students.php');
                            exit;
                        }
                    }
                } else {
                    // Показываем конкретную ошибку подготовки
                    $prepare_error = $db->error;
                    $error = '';

                    // Детальная диагностика
                    error_log("=== SQL PREPARE ERROR DEBUG ===");
                    error_log("Prepare Error: " . $prepare_error);
                    error_log("MySQL Error Code: " . $db->errno);
                    error_log("SQL Query: " . $sql);
                    error_log("Placeholder count: " . $placeholder_count);
                    error_log("Parameters count: " . count($params ?? []));
                    error_log("Has group_id: " . ($has_group_id ? 'true' : 'false'));

                    // Проверяем структуру таблицы
                    $table_check = $db->query("DESCRIBE students");
                    if ($table_check) {
                        $columns = [];
                        while ($row = $table_check->fetch_assoc()) {
                            $columns[] = $row['Field'];
                        }
                        error_log("Table columns: " . implode(', ', $columns));
                    }

                    // Проверяем количество параметров vs плейсхолдеров
                    $param_count = count($params ?? []);
                    if ($placeholder_count != $param_count) {
                        error_log("MISMATCH: Placeholders ($placeholder_count) != Parameters ($param_count)");
                    }

                    if ($db->errno == 1054) {
                        $error = 'Ошибка структуры базы данных (код ' . $db->errno . '): ' . $prepare_error;
                    } elseif ($db->errno == 1064) {
                        $error = 'Ошибка в запросе к базе данных (код ' . $db->errno . '): ' . $prepare_error;
                    } elseif ($db->errno == 1146) {
                        $error = 'Таблица не найдена (код ' . $db->errno . '): ' . $prepare_error;
                    } else {
                        $error = 'Ошибка подготовки запроса (код ' . ($db->errno ?: 'неизвестен') . '): ' . $prepare_error;
                        if ($placeholder_count != $param_count) {
                            $error .= '<br>Несоответствие количества параметров: плейсхолдеров ' . $placeholder_count . ', параметров ' . $param_count;
                        }
                    }
                }
            }
        }
    }
}

$page_title = 'Редактировать студента';
$page_subtitle = isset($student) ? trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '')) : '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать студента - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
    <style>
        .is-invalid {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }

        .is-invalid:focus {
            border-color: #dc3545 !important;
            box-shadow: 0 0 0 0.2rem rgba(220, 53, 69, 0.25) !important;
        }

        .form-label.required-field::after {
            content: " *";
            color: #dc3545;
            font-weight: bold;
        }

        .validation-error {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }

        .alert-danger {
            border-left: 4px solid #dc3545;
        }

        .alert-danger h6 {
            color: #721c24;
            font-weight: 600;
        }

        .alert-danger ul {
            margin-bottom: 0;
        }

        .alert-danger li {
            margin-bottom: 0.25rem;
        }

        /* Индикаторы статуса полей */
        .field-status {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.2em;
            z-index: 10;
        }

        .field-status.valid {
            color: #28a745;
        }

        .field-status.invalid {
            color: #dc3545;
        }

        .form-group {
            position: relative;
        }

        .form-control,
        .form-select {
            padding-right: 40px;
        }

        .field-hint {
            font-size: 0.875em;
            margin-top: 0.25rem;
            display: none;
        }

        .field-hint.invalid {
            color: #dc3545;
            display: block;
        }

        .field-hint.valid {
            color: #28a745;
            display: block;
        }

        /* Стили для аккордеона */
        .accordion-button {
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            font-weight: 600;
            color: #495057;
        }

        .accordion-button:not(.collapsed) {
            background-color: #e3f2fd;
            color: #1976d2;
            border-color: #1976d2;
        }

        .accordion-button:focus {
            box-shadow: 0 0 0 0.25rem rgba(25, 118, 210, 0.25);
        }

        .accordion-item {
            border: 1px solid #dee2e6;
            margin-bottom: 1rem;
        }

        .accordion-body {
            padding: 1.5rem;
        }

        .section-icon {
            font-size: 1.2em;
            margin-right: 0.5rem;
        }

        .section-badge {
            font-size: 0.8em;
            margin-left: 0.5rem;
        }
    </style>
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <div class="curator-action-buttons">
                    <a href="my_students.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-mortarboard me-1"></i>Мои студенты
                    </a>
                    <?php if (!empty($student['id'])): ?>
                        <a href="view_student.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-outline-primary btn-sm">
                            <i class="bi bi-eye me-1"></i>Просмотр
                        </a>
                    <?php endif; ?>
                    <a href="my_groups.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-collection me-1"></i>Мои группы
                    </a>
                </div>
            </div>

                    <?php if ($message): ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <?php echo $message; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger alert-dismissible fade show" role="alert">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                    <?php echo $error; ?>
                                </div>
                                <div class="ms-3">
                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="scrollToForm()">
                                        <i class="bi bi-arrow-clockwise me-1"></i>Попробовать еще раз
                                    </button>
                                    <button type="button" class="btn-close ms-2" data-bs-dismiss="alert"></button>
                                </div>
                            </div>
                        </div>

                        <?php if (isset($GLOBALS['error_section_id']) && !empty($GLOBALS['error_section_id'])): ?>
                            <script>
                                // Передаем ID секции с ошибкой в глобальную переменную
                                window.errorSectionId = '<?php echo $GLOBALS['error_section_id']; ?>';
                            </script>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (empty($curator_groups)): ?>
                        <div class="alert alert-warning" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <strong>Внимание!</strong> У вас нет назначенных групп. Обратитесь к администратору для назначения групп.
                        </div>
                    <?php else: ?>
                        <!-- Форма добавления студента -->
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pencil me-2"></i>
                                    Редактирование студента
                                </h5>
                                <div class="d-flex gap-2 mt-2">
                                    <span class="badge bg-info">
                                        <i class="bi bi-info-circle me-1"></i>
                                        ВНИМАНИЕ ВНИМАНИЕ!!!
                                    </span>
                                </div>
                            </div>
                            <div class="card-body">
                                <form method="POST" id="add-student-form" novalidate>
                                    <input type="hidden" name="action" value="update">
                                    <input type="hidden" name="student_id" value="<?php echo $student_id; ?>">

                                    <!-- Основная информация -->
                                    <div class="accordion" id="accordionMain">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingMain">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMain" aria-expanded="false" aria-controls="collapseMain">
                                                    <i class="bi bi-person-fill section-icon"></i>Основная информация
                                                    <span class="badge bg-primary section-badge" id="main-badge">0/8</span>
                                                </button>
                                            </h2>
                                            <div id="collapseMain" class="accordion-collapse collapse" aria-labelledby="headingMain" data-bs-parent="#accordionMain">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="iin" class="form-label required-field">ИИН</label>
                                                                <div class="input-group">
                                                                    <input type="text" class="form-control" id="iin" name="iin" required maxlength="12" value="<?php echo getFieldValue('iin'); ?>">
                                                                    <button type="button" class="btn btn-outline-primary" id="searchImportedDataBtn" onclick="searchImportedData()" title="Поиск в импортированных данных">
                                                                        <i class="bi bi-search"></i>
                                                                    </button>
                                                                </div>
                                                                <div class="form-text">
                                                                    <i class="bi bi-info-circle text-primary me-1"></i>
                                                                    Введите 12 цифр ИИН - дата рождения и пол заполнятся автоматически
                                                                </div>
                                                                <button type="button" class="btn btn-sm btn-outline-info mt-1" onclick="document.getElementById('iin').value='960325350262'; autoFillDataFromIIN();">Тест</button>
                                                                <span class="field-status" id="iin-status"></span>
                                                                <div class="field-hint" id="iin-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="first_name" class="form-label required-field">Имя</label>
                                                                <input type="text" class="form-control" id="first_name" name="first_name" required value="<?php echo getFieldValue('first_name'); ?>">
                                                                <span class="field-status" id="first_name-status"></span>
                                                                <div class="field-hint" id="first_name-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="last_name" class="form-label required-field">Фамилия</label>
                                                                <input type="text" class="form-control" id="last_name" name="last_name" required value="<?php echo getFieldValue('last_name'); ?>">
                                                                <span class="field-status" id="last_name-status"></span>
                                                                <div class="field-hint" id="last_name-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="middle_name" class="form-label required-field">Отчество</label>
                                                                <input type="text" class="form-control" id="middle_name" name="middle_name" required value="<?php echo getFieldValue('middle_name'); ?>">
                                                                <span class="field-status" id="middle_name-status"></span>
                                                                <div class="field-hint" id="middle_name-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="birth_date" class="form-label required-field">Дата рождения</label>
                                                                <input type="date" class="form-control" id="birth_date" name="birth_date" required value="<?php echo getFieldValue('birth_date'); ?>">
                                                                <div class="form-text">
                                                                    <i class="bi bi-arrow-down-circle text-primary me-1"></i>
                                                                    Автозаполняется из ИИН
                                                                </div>
                                                                <span class="field-status" id="birth_date-status"></span>
                                                                <div class="field-hint" id="birth_date-hint"></div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="gender" class="form-label required-field">Пол</label>
                                                                <select class="form-select" id="gender" name="gender" required>
                                                                    <option value="">Выберите пол</option>
                                                                    <option value="мужской" <?php echo isSelected('gender', 'мужской'); ?>>Мужской</option>
                                                                    <option value="женский" <?php echo isSelected('gender', 'женский'); ?>>Женский</option>
                                                                </select>
                                                                <div class="form-text">
                                                                    <i class="bi bi-arrow-down-circle text-primary me-1"></i>
                                                                    Автозаполняется из ИИН
                                                                </div>
                                                                <span class="field-status" id="gender-status"></span>
                                                                <div class="field-hint" id="gender-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="nationality" class="form-label required-field">Национальность</label>
                                                                <input type="text" class="form-control" id="nationality" name="nationality" required
                                                                    placeholder="Введите национальность"
                                                                    value="<?php echo getFieldValue('nationality'); ?>">
                                                                <div class="form-text">
                                                                    <i class="bi bi-info-circle text-primary me-1"></i>
                                                                    Например: казах, русский, узбек, украинец, немец, татарин, кореец
                                                                </div>
                                                                <span class="field-status" id="nationality-status"></span>
                                                                <div class="field-hint" id="nationality-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="phone" class="form-label required-field">Телефон</label>
                                                                <input
                                                                    type="tel"
                                                                    class="form-control"
                                                                    id="phone"
                                                                    name="phone"
                                                                    required
                                                                    placeholder="+7(7__) ___-__-__"
                                                                    value="<?php echo getFieldValue('phone'); ?>"
                                                                    maxlength="17">
                                                                <span class="field-status" id="phone-status"></span>
                                                                <div class="field-hint" id="phone-hint"></div>
                                                            </div>
                                                            <script>
                                                                function setCursorPosition(pos, elem) {
                                                                    elem.focus();
                                                                    if (elem.setSelectionRange) elem.setSelectionRange(pos, pos);
                                                                    else if (elem.createTextRange) {
                                                                        var range = elem.createTextRange();
                                                                        range.collapse(true);
                                                                        range.moveEnd('character', pos);
                                                                        range.moveStart('character', pos);
                                                                        range.select();
                                                                    }
                                                                }

                                                                function maskPhone(event) {
                                                                    var matrix = "+7(7__) ___-__-__",
                                                                        i = 0,
                                                                        def = matrix.replace(/\D/g, ""),
                                                                        val = event.target.value.replace(/\D/g, "");
                                                                    if (def.length >= val.length) val = def;
                                                                    event.target.value = matrix.replace(/./g, function(a) {
                                                                        return /[_\d]/.test(a) && i < val.length ? val.charAt(i++) : i >= val.length ? "" : a;
                                                                    });
                                                                    if (event.type === "blur") {
                                                                        if (event.target.value.length < 17) event.target.value = "";
                                                                    } else {
                                                                        setCursorPosition(event.target.value.length, event.target);
                                                                    }
                                                                }

                                                                document.addEventListener('DOMContentLoaded', function() {
                                                                    var phoneInput = document.getElementById('phone');
                                                                    phoneInput.addEventListener("input", maskPhone, false);
                                                                    phoneInput.addEventListener("focus", maskPhone, false);
                                                                    phoneInput.addEventListener("blur", maskPhone, false);
                                                                    phoneInput.addEventListener("keydown", maskPhone, false);
                                                                    // If empty, set initial mask
                                                                    if (phoneInput.value === '') {
                                                                        phoneInput.value = '+7(7';
                                                                    }
                                                                });
                                                            </script>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="email" class="form-label required-field">Email</label>
                                                                <input type="email" class="form-control" id="email" name="email" required value="<?php echo getFieldValue('email'); ?>">
                                                                <span class="field-status" id="email-status"></span>
                                                                <div class="field-hint" id="email-hint"></div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Адреса -->
                                    <div class="accordion" id="accordionAddress">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingAddress">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAddress" aria-expanded="false" aria-controls="collapseAddress">
                                                    <i class="bi bi-geo-alt-fill section-icon me-2"></i>
                                                    <span class="fw-bold">Адреса</span>
                                                    <span class="badge bg-primary section-badge ms-2" id="address-badge">0/1</span>
                                                </button>
                                            </h2>
                                            <div id="collapseAddress" class="accordion-collapse collapse" aria-labelledby="headingAddress" data-bs-parent="#accordionAddress">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <div class="d-flex gap-3">
                                                                <!-- Permanent Address -->
                                                                <div class="flex-fill">
                                                                    <div class="form-group mb-3">
                                                                        <label for="permanent_address_ru" class="form-label required-field d-flex align-items-center mb-2">
                                                                            <i class="bi bi-house-door-fill text-primary me-2"></i>
                                                                            Постоянный адрес (русский)
                                                                        </label>
                                                                        <div class="input-group">
                                                                            <span class="input-group-text bg-light">
                                                                                <i class="bi bi-geo-alt"></i>
                                                                            </span>
                                                                            <textarea class="form-control" id="permanent_address_ru" name="permanent_address_ru" rows="2" required placeholder="Введите постоянный адрес"><?php echo getFieldValue('permanent_address_ru'); ?></textarea>
                                                                        </div>
                                                                        <span class="field-status" id="permanent_address_ru-status"></span>
                                                                        <div class="field-hint" id="permanent_address_ru-hint"></div>
                                                                    </div>
                                                                </div>
                                                                <!-- Temporary Address -->
                                                                <div class="flex-fill">
                                                                    <div class="form-group mb-3">
                                                                        <label for="temporary_address_ru" class="form-label d-flex align-items-center mb-2">
                                                                            <i class="bi bi-clock-history text-secondary me-2"></i>
                                                                            Временный адрес (русский)
                                                                        </label>
                                                                        <div class="input-group">
                                                                            <span class="input-group-text bg-light">
                                                                                <i class="bi bi-geo"></i>
                                                                            </span>
                                                                            <textarea class="form-control" id="temporary_address_ru" name="temporary_address_ru" rows="2" placeholder="Введите временный адрес"><?php echo getFieldValue('temporary_address_ru'); ?></textarea>
                                                                        </div>
                                                                        <span class="field-status" id="temporary_address_ru-status"></span>
                                                                        <div class="field-hint" id="temporary_address_ru-hint"></div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Информация о зачислении -->
                                    <div class="accordion" id="accordionEnrollment">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingEnrollment">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseEnrollment" aria-expanded="false" aria-controls="collapseEnrollment">
                                                    <i class="bi bi-mortarboard-fill section-icon"></i>Информация о зачислении
                                                    <span class="badge bg-primary section-badge" id="enrollment-badge">0/6</span>
                                                </button>
                                            </h2>
                                            <div id="collapseEnrollment" class="accordion-collapse collapse" aria-labelledby="headingEnrollment" data-bs-parent="#accordionEnrollment">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <label for="group_id" class="form-label required-field">Группа</label>
                                                            <select class="form-select" id="group_id" name="group_id" required>
                                                                <option value="">Выберите группу</option>
                                                                <?php foreach ($curator_groups as $group_item): ?>
                                                                    <option value="<?php echo $group_item['id']; ?>" <?php echo isSelected('group_id', $group_item['id']); ?>>
                                                                        <?php echo htmlspecialchars($group_item['name'] . ' - ' . $group_item['specialty']); ?>
                                                                    </option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                            <div class="form-text">
                                                                <i class="bi bi-info-circle text-primary me-1"></i>
                                                                При выборе группы поля автоматически заполнятся
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="course" class="form-label required-field">
                                                                    Курс
                                                                    <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                                </label>
                                                                <select class="form-select" id="course" name="course" required>
                                                                    <option value="">Выберите курс</option>
                                                                    <option value="1 курс" <?php echo isSelected('course', '1 курс'); ?>>1 курс</option>
                                                                    <option value="2 курс" <?php echo isSelected('course', '2 курс'); ?>>2 курс</option>
                                                                    <option value="3 курс" <?php echo isSelected('course', '3 курс'); ?>>3 курс</option>
                                                                    <option value="4 курс" <?php echo isSelected('course', '4 курс'); ?>>4 курс</option>
                                                                    <option value="5 курс" <?php echo isSelected('course', '5 курс'); ?>>5 курс</option>
                                                                </select>
                                                                <span class="field-status" id="course-status"></span>
                                                                <div class="field-hint" id="course-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="language" class="form-label required-field">
                                                                    Язык обучения
                                                                    <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                                </label>
                                                                <select class="form-select" id="language" name="language" required>
                                                                    <option value="">Выберите язык</option>
                                                                    <option value="казахский" <?php echo isSelected('language', 'казахский'); ?>>Казахский</option>
                                                                    <option value="русский" <?php echo isSelected('language', 'русский'); ?>>Русский</option>
                                                                    <option value="английский" <?php echo isSelected('language', 'английский'); ?>>Английский</option>
                                                                </select>
                                                                <span class="field-status" id="language-status"></span>
                                                                <div class="field-hint" id="language-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="study_form" class="form-label required-field">
                                                                    Форма обучения
                                                                    <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                                </label>
                                                                <select class="form-select" id="study_form" name="study_form" required>
                                                                    <option value="">Выберите форму</option>
                                                                    <option value="очная" <?php echo isSelected('study_form', 'очная'); ?>>Очная</option>
                                                                    <option value="заочная" <?php echo isSelected('study_form', 'заочная'); ?>>Заочная</option>
                                                                    <option value="вечерняя" <?php echo isSelected('study_form', 'вечерняя'); ?>>Вечерняя</option>
                                                                </select>
                                                                <span class="field-status" id="study_form-status"></span>
                                                                <div class="field-hint" id="study_form-hint"></div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="arrival_date" class="form-label">Дата прибытия</label>
                                                            <input type="date" class="form-control" id="arrival_date" name="arrival_date" value="<?php echo getFieldValue('arrival_date'); ?>">
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="enrollment_order_number" class="form-label">Номер приказа</label>
                                                            <input type="text" class="form-control" id="enrollment_order_number" name="enrollment_order_number" value="<?php echo getFieldValue('enrollment_order_number'); ?>">
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="arrival_from" class="form-label">Прибыл из</label>
                                                            <select class="form-select" id="arrival_from" name="arrival_from">
                                                                <option value="">Выберите откуда</option>
                                                                <option value="данного района (города, села) данной области" <?php echo isSelected('arrival_from', 'данного района (города, села) данной области'); ?>>Данного района (города, села) данной области</option>
                                                                <option value="другого района (города) данной области" <?php echo isSelected('arrival_from', 'другого района (города) данной области'); ?>>Другого района (города) данной области</option>
                                                                <option value="другой области" <?php echo isSelected('arrival_from', 'другой области'); ?>>Другой области</option>
                                                                <option value="другого государства СНГ" <?php echo isSelected('arrival_from', 'другого государства СНГ'); ?>>Другого государства СНГ</option>
                                                                <option value="дальнего зарубежья" <?php echo isSelected('arrival_from', 'дальнего зарубежья'); ?>>Дальнего зарубежья</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <label for="education_type" class="form-label">Тип образования</label>
                                                            <select class="form-select" id="education_type" name="education_type">
                                                                <option value="">Выберите тип</option>
                                                                <option value="основная школа" <?php echo isSelected('education_type', 'основная школа'); ?>>Основная школа</option>
                                                                <option value="средняя школа" <?php echo isSelected('education_type', 'средняя школа'); ?>>Средняя школа</option>
                                                                <option value="организация ТиПО" <?php echo isSelected('education_type', 'организация ТиПО'); ?>>Организация ТиПО</option>
                                                                <option value="ВУЗ" <?php echo isSelected('education_type', 'ВУЗ'); ?>>ВУЗ</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="residence_type" class="form-label required-field">Тип местности</label>
                                                                <select class="form-select" id="residence_type" name="residence_type" required>
                                                                    <option value="">Выберите тип</option>
                                                                    <option value="городская местность" <?php echo isSelected('residence_type', 'городская местность'); ?>>Городская местность</option>
                                                                    <option value="сельская местность" <?php echo isSelected('residence_type', 'сельская местность'); ?>>Сельская местность</option>
                                                                </select>
                                                                <span class="field-status" id="residence_type-status"></span>
                                                                <div class="field-hint" id="residence_type-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <label for="study_duration" class="form-label">
                                                                Срок обучения
                                                                <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                            </label>
                                                            <select class="form-select" id="study_duration" name="study_duration">
                                                                <option value="">Выберите срок</option>
                                                                <option value="1 год" <?php echo isSelected('study_duration', '1 год'); ?>>1 год</option>
                                                                <option value="2 года" <?php echo isSelected('study_duration', '2 года'); ?>>2 года</option>
                                                                <option value="3 года" <?php echo isSelected('study_duration', '3 года'); ?>>3 года</option>
                                                                <option value="4 года" <?php echo isSelected('study_duration', '4 года'); ?>>4 года</option>
                                                                <option value="5 лет" <?php echo isSelected('study_duration', '5 лет'); ?>>5 лет</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="course_start_date" class="form-label required-field">
                                                                    Дата начала курса
                                                                    <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                                </label>
                                                                <input type="date" class="form-control" id="course_start_date" name="course_start_date" required value="<?php echo getFieldValue('course_start_date'); ?>">
                                                                <span class="field-status" id="course_start_date-status"></span>
                                                                <div class="field-hint" id="course_start_date-hint"></div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <label for="course_end_date" class="form-label">
                                                                Дата окончания курса
                                                                <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                            </label>
                                                            <input type="date" class="form-control" id="course_end_date" name="course_end_date" value="<?php echo getFieldValue('course_end_date'); ?>">
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <label for="graduation_date" class="form-label">
                                                                Дата фактического выпуска
                                                                <i class="bi bi-info-circle text-muted ms-1" title="Заполняется ТОЛЬКО если студент уже получил диплом. Для текущих студентов оставьте пустым - они автоматически станут выпускниками после окончания курса"></i>
                                                            </label>
                                                            <input type="date" class="form-control" id="graduation_date" name="graduation_date" value="<?php echo getFieldValue('graduation_date'); ?>">
                                                            <small class="text-muted">Оставьте пустым - студент автоматически станет выпускником после окончания курса</small>
                                                        </div>
                                                        <div class="col-md-3 mb-3">
                                                            <div class="form-group">
                                                                <label for="specialty" class="form-label required-field">
                                                                    Специальность
                                                                    <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                                </label>
                                                                <input type="text" class="form-control" id="specialty" name="specialty" required value="<?php echo getFieldValue('specialty'); ?>">
                                                                <span class="field-status" id="specialty-status"></span>
                                                                <div class="field-hint" id="specialty-hint"></div>
                                                            </div>
                                                        </div>
                                                    </div>

                                                    <div class="row">
                                                        <div class="col-md-3 mb-3">
                                                            <label for="group_code" class="form-label">
                                                                Код группы
                                                                <i class="bi bi-arrow-down-circle text-primary ms-1" title="Автозаполняется из группы"></i>
                                                            </label>
                                                            <input type="text" class="form-control" id="group_code" name="group_code" readonly value="<?php echo getFieldValue('group_code'); ?>">
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Квота и социальные категории -->
                                    <div class="accordion" id="accordionQuota">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingQuota">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseQuota" aria-expanded="false" aria-controls="collapseQuota">
                                                    <i class="bi bi-people-fill section-icon"></i>Социальные категории
                                                    <span class="badge bg-primary section-badge" id="quota-badge">0/1</span>
                                                </button>
                                            </h2>
                                            <div id="collapseQuota" class="accordion-collapse collapse" aria-labelledby="headingQuota" data-bs-parent="#accordionQuota">
                                                <div class="accordion-body">
                                                    <div class="row g-4">
                                                        <div class="col-lg-6">
                                                            <div class="p-3 rounded-3 border bg-white h-100 shadow-sm">
                                                                <h6 class="mb-3 text-primary fw-bold"><i class="bi bi-emoji-frown text-warning"></i> Статус сироты</h6>
                                                                <div class="form-switch mb-2">
                                                                    <input class="form-check-input" type="checkbox" id="orphan" name="orphan" <?php echo isChecked('orphan'); ?> onclick="toggleOrphanOptions()">
                                                                    <label class="form-check-label ms-2" for="orphan">Дети-сироты</label>
                                                                </div>
                                                                <div id="orphan-options" class="ps-3 mt-2" style="display: <?php echo isset($_POST['orphan']) ? 'block' : 'none'; ?>;">
                                                                    <div class="text-muted small mb-2">Уточните категорию:</div>
                                                                    <div class="btn-group-vertical w-100" role="group" aria-label="Orphan type">
                                                                        <input type="radio" class="btn-check" name="orphan_type" id="without_parental_care_radio" value="without_parental_care" <?php echo (isset($_POST['orphan_type']) && $_POST['orphan_type'] == 'without_parental_care') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="without_parental_care_radio">
                                                                            <i class="bi bi-person-dash"></i> Оставшиеся без попечения родителей
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="orphan_type" id="with_guardians" value="with_guardians" <?php echo (isset($_POST['orphan_type']) && $_POST['orphan_type'] == 'with_guardians') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary" for="with_guardians">
                                                                            <i class="bi bi-people"></i> Проживающие с опекунами
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <h6 class="mb-3 text-primary fw-bold"><i class="bi bi-people"></i> Социальные категории</h6>
                                                                <div class="row row-cols-2 g-2">
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="without_parental_care" name="without_parental_care" <?php echo isChecked('without_parental_care'); ?>>
                                                                            <label class="form-check-label" for="without_parental_care">
                                                                                <i class="bi bi-person-dash"></i> Без попечения родителей
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="disability" name="disability" <?php echo isChecked('disability'); ?>>
                                                                            <label class="form-check-label" for="disability">
                                                                                <i class="bi bi-person-wheelchair"></i> С инвалидностью
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="social_assistance" name="social_assistance" <?php echo isChecked('social_assistance'); ?>>
                                                                            <label class="form-check-label" for="social_assistance">
                                                                                <i class="bi bi-cash-coin"></i> Получатель соц. помощи
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="large_family" name="large_family" <?php echo isChecked('large_family'); ?>>
                                                                            <label class="form-check-label" for="large_family">
                                                                                <i class="bi bi-people"></i> Из многодетной семьи
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <h6 class="mb-3 text-primary fw-bold"><i class="bi bi-house-heart"></i> Семейный статус</h6>
                                                                <div class="row row-cols-2 g-2">
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="patronage" name="patronage" <?php echo isChecked('patronage'); ?>>
                                                                            <label class="form-check-label" for="patronage">
                                                                                На патронатном воспитании
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="patronage2" name="patronage2" <?php echo isChecked('patronage2'); ?>>
                                                                            <label class="form-check-label" for="patronage2">
                                                                                На патронатном воспитании (альт.)
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="low_income_family" name="low_income_family" <?php echo isChecked('low_income_family'); ?>>
                                                                            <label class="form-check-label" for="low_income_family">
                                                                                <i class="bi bi-cash-stack"></i> Малообеспеченных семей
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="single_parent_family" name="single_parent_family" <?php echo isChecked('single_parent_family'); ?> onchange="toggleSingleParentOptions()">
                                                                            <label class="form-check-label" for="single_parent_family">
                                                                                <i class="bi bi-person"></i> Неполная семья
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <div id="single_parent_options" class="ps-3 mt-2" style="display: <?php echo isset($_POST['single_parent_family']) ? 'block' : 'none'; ?>;">
                                                                    <div class="text-muted small mb-2">Уточните тип:</div>
                                                                    <div class="btn-group-vertical w-100" role="group" aria-label="Single parent type">
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="loss_of_breadwinner" value="loss_of_breadwinner" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'loss_of_breadwinner') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="loss_of_breadwinner">
                                                                            <i class="bi bi-person-x"></i> С утерей кормильца
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="single_mother" value="single_mother" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'single_mother') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="single_mother">
                                                                            <i class="bi bi-gender-female"></i> Семья матери-одиночки
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="raised_by_father" value="raised_by_father" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'raised_by_father') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="raised_by_father">
                                                                            <i class="bi bi-gender-male"></i> Воспитывает отец
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="raised_by_mother" value="raised_by_mother" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'raised_by_mother') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="raised_by_mother">
                                                                            <i class="bi bi-gender-female"></i> Воспитывает мать
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="stepfather_family" value="stepfather_family" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'stepfather_family') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="stepfather_family">
                                                                            <i class="bi bi-person"></i> Семья с отчимом
                                                                        </label>
                                                                        <input type="radio" class="btn-check" name="single_parent_type" id="stepmother_family" value="stepmother_family" <?php echo (isset($_POST['single_parent_type']) && $_POST['single_parent_type'] == 'stepmother_family') ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary" for="stepmother_family">
                                                                            <i class="bi bi-person"></i> Семья с мачехой
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <h6 class="mb-3 text-primary fw-bold"><i class="bi bi-person-wheelchair"></i> Студенты с ООП</h6>
                                                                <div class="form-switch mb-2">
                                                                    <input class="form-check-input" type="checkbox" id="oop" name="oop" <?php echo isChecked('oop'); ?> onclick="toggleOOPOptions()">
                                                                    <label class="form-check-label ms-2" for="oop">Есть ООП</label>
                                                                </div>
                                                                <div id="oop-options" class="ps-3 mt-2" style="display: <?php echo isset($_POST['oop']) ? 'block' : 'none'; ?>;">
                                                                    <div class="text-muted small mb-2">Уточните категории:</div>
                                                                    <div class="btn-group-vertical w-100" role="group" aria-label="OOP types">
                                                                        <input type="checkbox" class="btn-check" id="oop_motor" name="oop_types[]" value="motor" <?php echo (isset($_POST['oop_types']) && in_array('motor', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_motor">
                                                                            <i class="bi bi-person-walking"></i> Нарушение опорно-двигательного аппарата
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_hearing" name="oop_types[]" value="hearing" <?php echo (isset($_POST['oop_types']) && in_array('hearing', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_hearing">
                                                                            <i class="bi bi-ear"></i> Нарушение слуха
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_vision" name="oop_types[]" value="vision" <?php echo (isset($_POST['oop_types']) && in_array('vision', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_vision">
                                                                            <i class="bi bi-eye-slash"></i> Нарушение зрения
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_intellect" name="oop_types[]" value="intellect" <?php echo (isset($_POST['oop_types']) && in_array('intellect', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_intellect">
                                                                            <i class="bi bi-brain"></i> Нарушение интеллекта
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_speech" name="oop_types[]" value="speech" <?php echo (isset($_POST['oop_types']) && in_array('speech', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_speech">
                                                                            <i class="bi bi-mic-mute"></i> Нарушение речи
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_emotional" name="oop_types[]" value="emotional" <?php echo (isset($_POST['oop_types']) && in_array('emotional', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_emotional">
                                                                            <i class="bi bi-emoji-expressionless"></i> Эмоционально-волевые расстройства
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_complex" name="oop_types[]" value="complex" <?php echo (isset($_POST['oop_types']) && in_array('complex', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary mb-1" for="oop_complex">
                                                                            <i class="bi bi-puzzle"></i> Сложные (сочетанные) нарушения
                                                                        </label>
                                                                        <input type="checkbox" class="btn-check" id="oop_disability" name="oop_types[]" value="disability" <?php echo (isset($_POST['oop_types']) && in_array('disability', (array)$_POST['oop_types'])) ? 'checked' : ''; ?>>
                                                                        <label class="btn btn-outline-secondary" for="oop_disability">
                                                                            <i class="bi bi-person-wheelchair"></i> Дети с инвалидностью
                                                                        </label>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-lg-6">
                                                            <div class="p-3 rounded-3 border bg-white h-100 shadow-sm">
                                                                <h6 class="mb-3 text-danger fw-bold"><i class="bi bi-exclamation-triangle"></i> Неблагополучная семья</h6>
                                                                <div class="form-switch mb-2">
                                                                    <input class="form-check-input" type="checkbox" id="dysfunctional_family" name="dysfunctional_family" <?php echo isChecked('dysfunctional_family'); ?>>
                                                                    <label class="form-check-label ms-2" for="dysfunctional_family">
                                                                        Студент из неблагополучной семьи
                                                                    </label>
                                                                </div>
                                                                <div id="dysfunctional_family_options" class="ps-3 mt-2" style="display: <?php echo isset($_POST['dysfunctional_family']) ? 'block' : 'none'; ?>;">
                                                                    <div class="text-muted small mb-2">Причины:</div>
                                                                    <div class="row row-cols-2 g-2">
                                                                        <div class="col">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" id="alcohol_abuse" name="dysfunctional_reasons[]" value="alcohol_abuse" <?php echo (isset($_POST['dysfunctional_reasons']) && in_array('alcohol_abuse', (array)$_POST['dysfunctional_reasons'])) ? 'checked' : ''; ?>>
                                                                                <label class="form-check-label" for="alcohol_abuse">
                                                                                    <i class="bi bi-cup-straw"></i> Алкоголизм
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" id="drug_abuse" name="dysfunctional_reasons[]" value="drug_abuse" <?php echo (isset($_POST['dysfunctional_reasons']) && in_array('drug_abuse', (array)$_POST['dysfunctional_reasons'])) ? 'checked' : ''; ?>>
                                                                                <label class="form-check-label" for="drug_abuse">
                                                                                    <i class="bi bi-capsule"></i> Наркомания
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" id="domestic_violence" name="dysfunctional_reasons[]" value="domestic_violence" <?php echo (isset($_POST['dysfunctional_reasons']) && in_array('domestic_violence', (array)$_POST['dysfunctional_reasons'])) ? 'checked' : ''; ?>>
                                                                                <label class="form-check-label" for="domestic_violence">
                                                                                    <i class="bi bi-exclamation-diamond"></i> Насилие в семье
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" id="neglect" name="dysfunctional_reasons[]" value="neglect" <?php echo (isset($_POST['dysfunctional_reasons']) && in_array('neglect', (array)$_POST['dysfunctional_reasons'])) ? 'checked' : ''; ?>>
                                                                                <label class="form-check-label" for="neglect">
                                                                                    <i class="bi bi-slash-circle"></i> Пренебрежение нуждами ребенка
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                        <div class="col-12">
                                                                            <div class="form-check">
                                                                                <input class="form-check-input" type="checkbox" id="other_dysfunction" name="dysfunctional_reasons[]" value="other" <?php echo (isset($_POST['dysfunctional_reasons']) && in_array('other', (array)$_POST['dysfunctional_reasons'])) ? 'checked' : ''; ?>>
                                                                                <label class="form-check-label" for="other_dysfunction">
                                                                                    <i class="bi bi-three-dots"></i> Другое
                                                                                </label>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <div class="row g-2">
                                                                    <div class="col-md-4">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="juvenile_inspection" name="juvenile_inspection" <?php echo isChecked('juvenile_inspection'); ?>>
                                                                            <label class="form-check-label" for="juvenile_inspection">
                                                                                <i class="bi bi-shield-exclamation"></i> На учете в ИДН
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="internal_control" name="internal_control" <?php echo isChecked('internal_control'); ?>>
                                                                            <label class="form-check-label" for="internal_control">
                                                                                <i class="bi bi-eye"></i> На внутреннем контроле
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-4">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="religious_uniform" name="religious_uniform" <?php echo isChecked('religious_uniform'); ?>>
                                                                            <label class="form-check-label" for="religious_uniform">
                                                                                <i class="bi bi-person-lines-fill"></i> Несоблюдение формы (религия)
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <div class="row g-2">
                                                                    <div class="col-md-6">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="sports_section" name="sports_section" <?php echo isChecked('sports_section'); ?>>
                                                                            <label class="form-check-label" for="sports_section">
                                                                                <i class="bi bi-trophy"></i> Спортивные секции
                                                                            </label>
                                                                        </div>
                                                                        <div class="mt-2" id="sports_section_type_container" style="<?php echo (isset($_POST['sports_section'])) ? '' : 'display:none;'; ?>">
                                                                            <label for="sports_section_type" class="form-label">
                                                                                <i class="bi bi-list"></i> Выберите секцию
                                                                            </label>
                                                                            <select class="form-select" id="sports_section_type" name="sports_section_type">
                                                                                <option value="">-- Выберите --</option>
                                                                                <option value="football" <?php echo (isset($_POST['sports_section_type']) && $_POST['sports_section_type'] == 'football') ? 'selected' : ''; ?>>⚽ Футбол</option>
                                                                                <option value="basketball" <?php echo (isset($_POST['sports_section_type']) && $_POST['sports_section_type'] == 'basketball') ? 'selected' : ''; ?>>🏀 Баскетбол</option>
                                                                                <option value="volleyball" <?php echo (isset($_POST['sports_section_type']) && $_POST['sports_section_type'] == 'volleyball') ? 'selected' : ''; ?>>🏐 Волейбол</option>
                                                                                <option value="athletics" <?php echo (isset($_POST['sports_section_type']) && $_POST['sports_section_type'] == 'athletics') ? 'selected' : ''; ?>>🏃 Лёгкая атлетика</option>
                                                                                <option value="other" <?php echo (isset($_POST['sports_section_type']) && $_POST['sports_section_type'] == 'other') ? 'selected' : ''; ?>>Другое</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                    <div class="col-md-6">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="art_activity" name="art_activity" <?php echo isChecked('art_activity'); ?>>
                                                                            <label class="form-check-label" for="art_activity">
                                                                                <i class="bi bi-music-note-beamed"></i> Художественная самодеятельность
                                                                            </label>
                                                                        </div>
                                                                        <div class="mt-2" id="art_activity_type_container" style="<?php echo (isset($_POST['art_activity'])) ? '' : 'display:none;'; ?>">
                                                                            <label for="art_activity_type" class="form-label">
                                                                                <i class="bi bi-list"></i> Выберите направление
                                                                            </label>
                                                                            <select class="form-select" id="art_activity_type" name="art_activity_type">
                                                                                <option value="">-- Выберите --</option>
                                                                                <option value="vocal" <?php echo (isset($_POST['art_activity_type']) && $_POST['art_activity_type'] == 'vocal') ? 'selected' : ''; ?>>🎤 Вокал</option>
                                                                                <option value="dance" <?php echo (isset($_POST['art_activity_type']) && $_POST['art_activity_type'] == 'dance') ? 'selected' : ''; ?>>💃 Танцы</option>
                                                                                <option value="theater" <?php echo (isset($_POST['art_activity_type']) && $_POST['art_activity_type'] == 'theater') ? 'selected' : ''; ?>>🎭 Театр</option>
                                                                                <option value="instrumental" <?php echo (isset($_POST['art_activity_type']) && $_POST['art_activity_type'] == 'instrumental') ? 'selected' : ''; ?>>🎸 Инструментальная музыка</option>
                                                                                <option value="other" <?php echo (isset($_POST['art_activity_type']) && $_POST['art_activity_type'] == 'other') ? 'selected' : ''; ?>>Другое</option>
                                                                            </select>
                                                                        </div>
                                                                        <div class="form-check mt-2">
                                                                            <input class="form-check-input" type="checkbox" id="tech_club" name="tech_club" <?php echo isChecked('tech_club'); ?>>
                                                                            <label class="form-check-label" for="tech_club">
                                                                                <i class="bi bi-cpu"></i> Кружки технического творчества
                                                                            </label>
                                                                        </div>
                                                                        <div class="mt-2" id="tech_club_type_container" style="<?php echo (isset($_POST['tech_club'])) ? '' : 'display:none;'; ?>">
                                                                            <label for="tech_club_type" class="form-label">
                                                                                <i class="bi bi-list"></i> Выберите направление
                                                                            </label>
                                                                            <select class="form-select" id="tech_club_type" name="tech_club_type">
                                                                                <option value="">-- Выберите --</option>
                                                                                <option value="robotics" <?php echo (isset($_POST['tech_club_type']) && $_POST['tech_club_type'] == 'robotics') ? 'selected' : ''; ?>>🤖 Робототехника</option>
                                                                                <option value="programming" <?php echo (isset($_POST['tech_club_type']) && $_POST['tech_club_type'] == 'programming') ? 'selected' : ''; ?>>💻 Программирование</option>
                                                                                <option value="engineering" <?php echo (isset($_POST['tech_club_type']) && $_POST['tech_club_type'] == 'engineering') ? 'selected' : ''; ?>>🛠️ Инженерное дело</option>
                                                                                <option value="modeling" <?php echo (isset($_POST['tech_club_type']) && $_POST['tech_club_type'] == 'modeling') ? 'selected' : ''; ?>>📐 Моделирование</option>
                                                                                <option value="other" <?php echo (isset($_POST['tech_club_type']) && $_POST['tech_club_type'] == 'other') ? 'selected' : ''; ?>>Другое</option>
                                                                            </select>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                                <hr class="my-4">
                                                                <div class="row g-2">
                                                                    <div class="col-md-6">
                                                                        <label for="residence_type" class="form-label fw-bold">
                                                                            <i class="bi bi-house-door"></i> Где проживает
                                                                        </label>
                                                                        <select class="form-select" id="residence_type" name="residence_type" required>
                                                                            <option value="">-- Выберите --</option>
                                                                            <option value="family" <?php echo (isset($_POST['residence_type']) && $_POST['residence_type'] == 'family') ? 'selected' : ''; ?>>🏠 В семье</option>
                                                                            <option value="relatives" <?php echo (isset($_POST['residence_type']) && $_POST['residence_type'] == 'relatives') ? 'selected' : ''; ?>>👨‍👩‍👧‍👦 У родственников</option>
                                                                            <option value="dormitory" <?php echo (isset($_POST['residence_type']) && $_POST['residence_type'] == 'dormitory') ? 'selected' : ''; ?>>🏢 В общежитии</option>
                                                                            <option value="rented" <?php echo (isset($_POST['residence_type']) && $_POST['residence_type'] == 'rented') ? 'selected' : ''; ?>>🏬 На съемной квартире</option>
                                                                        </select>
                                                                    </div>
                                                                    <div class="col-md-6 d-flex align-items-end">
                                                                        <div class="form-check">
                                                                            <input class="form-check-input" type="checkbox" id="at_risk" name="at_risk" <?php echo isChecked('at_risk'); ?>>
                                                                            <label class="form-check-label" for="at_risk">
                                                                                <i class="bi bi-exclamation-circle text-danger"></i> Группа риска (особое внимание)
                                                                            </label>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                                <script>
                                                    function toggleOrphanOptions() {
                                                        var orphanCheckbox = document.getElementById('orphan');
                                                        var orphanOptions = document.getElementById('orphan-options');
                                                        if (orphanCheckbox.checked) {
                                                            orphanOptions.style.display = 'block';
                                                            var radios = orphanOptions.querySelectorAll('input[type="radio"]');
                                                            radios.forEach(function(radio) {
                                                                radio.required = true;
                                                            });
                                                        } else {
                                                            orphanOptions.style.display = 'none';
                                                            var radios = orphanOptions.querySelectorAll('input[type="radio"]');
                                                            radios.forEach(function(radio) {
                                                                radio.checked = false;
                                                                radio.required = false;
                                                            });
                                                        }
                                                    }

                                                    function toggleSingleParentOptions() {
                                                        var checkbox = document.getElementById('single_parent_family');
                                                        var options = document.getElementById('single_parent_options');
                                                        if (checkbox.checked) {
                                                            options.style.display = 'block';
                                                            var radios = options.querySelectorAll('input[type="radio"]');
                                                            radios.forEach(function(radio) {
                                                                radio.required = true;
                                                            });
                                                        } else {
                                                            options.style.display = 'none';
                                                            var radios = options.querySelectorAll('input[type="radio"]');
                                                            radios.forEach(function(radio) {
                                                                radio.checked = false;
                                                                radio.required = false;
                                                            });
                                                        }
                                                    }

                                                    function toggleOOPOptions() {
                                                        var oopCheckbox = document.getElementById('oop');
                                                        var oopOptions = document.getElementById('oop-options');
                                                        if (oopCheckbox.checked) {
                                                            oopOptions.style.display = 'block';
                                                        } else {
                                                            oopOptions.style.display = 'none';
                                                            var checks = oopOptions.querySelectorAll('input[type="checkbox"]');
                                                            checks.forEach(function(check) {
                                                                check.checked = false;
                                                            });
                                                        }
                                                    }

                                                    function toggleDysfunctionalFamilyOptions() {
                                                        var checkbox = document.getElementById('dysfunctional_family');
                                                        var options = document.getElementById('dysfunctional_family_options');
                                                        if (checkbox.checked) {
                                                            options.style.display = 'block';
                                                        } else {
                                                            options.style.display = 'none';
                                                            var checks = options.querySelectorAll('input[type="checkbox"]');
                                                            checks.forEach(function(check) {
                                                                check.checked = false;
                                                            });
                                                        }
                                                    }
                                                    document.addEventListener('DOMContentLoaded', function() {
                                                        toggleOrphanOptions();
                                                        toggleSingleParentOptions();
                                                        toggleOOPOptions();
                                                        toggleDysfunctionalFamilyOptions();
                                                        // Sports section
                                                        var sportsCheckbox = document.getElementById('sports_section');
                                                        var sectionTypeContainer = document.getElementById('sports_section_type_container');
                                                        if (sportsCheckbox) {
                                                            sportsCheckbox.addEventListener('change', function() {
                                                                if (this.checked) {
                                                                    sectionTypeContainer.style.display = '';
                                                                    var select = document.getElementById('sports_section_type');
                                                                    if (select) select.required = true;
                                                                } else {
                                                                    sectionTypeContainer.style.display = 'none';
                                                                    var select = document.getElementById('sports_section_type');
                                                                    if (select) {
                                                                        select.value = '';
                                                                        select.required = false;
                                                                    }
                                                                }
                                                            });
                                                            // init on load
                                                            var selectInit = document.getElementById('sports_section_type');
                                                            if (sportsCheckbox.checked && selectInit) selectInit.required = true;
                                                        }
                                                        // Art activity
                                                        var artCheckbox = document.getElementById('art_activity');
                                                        var artTypeContainer = document.getElementById('art_activity_type_container');
                                                        if (artCheckbox) {
                                                            artCheckbox.addEventListener('change', function() {
                                                                if (this.checked) {
                                                                    artTypeContainer.style.display = '';
                                                                    var select = document.getElementById('art_activity_type');
                                                                    if (select) select.required = true;
                                                                } else {
                                                                    artTypeContainer.style.display = 'none';
                                                                    var select = document.getElementById('art_activity_type');
                                                                    if (select) {
                                                                        select.value = '';
                                                                        select.required = false;
                                                                    }
                                                                }
                                                            });
                                                            // init on load
                                                            var artSelectInit = document.getElementById('art_activity_type');
                                                            if (artCheckbox.checked && artSelectInit) artSelectInit.required = true;
                                                        }
                                                        // Tech club
                                                        var techCheckbox = document.getElementById('tech_club');
                                                        var techTypeContainer = document.getElementById('tech_club_type_container');
                                                        if (techCheckbox) {
                                                            techCheckbox.addEventListener('change', function() {
                                                                if (this.checked) {
                                                                    techTypeContainer.style.display = '';
                                                                } else {
                                                                    techTypeContainer.style.display = 'none';
                                                                    var select = document.getElementById('tech_club_type');
                                                                    if (select) select.value = '';
                                                                }
                                                            });
                                                        }
                                                    });
                                                    document.getElementById('dysfunctional_family').addEventListener('change', toggleDysfunctionalFamilyOptions);
                                                </script>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Питание -->
                                    <div class="accordion" id="accordionMeals">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingMeals">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseMeals" aria-expanded="false" aria-controls="collapseMeals">
                                                    <i class="bi bi-cup-hot-fill section-icon"></i>Питание
                                                    <span class="badge bg-primary section-badge" id="meals-badge">0/4</span>
                                                </button>
                                            </h2>
                                            <div id="collapseMeals" class="accordion-collapse collapse" aria-labelledby="headingMeals" data-bs-parent="#accordionMeals">
                                                <div class="accordion-body">
                                                    <div class="row g-3 align-items-center">
                                                        <div class="col-md-6">
                                                            <div class="card shadow-sm border-0 h-100">
                                                                <div class="card-body d-flex align-items-center">
                                                                    <input class="form-check-input me-2" type="checkbox" id="buffet_covered" name="buffet_covered">
                                                                    <label class="form-check-label fw-semibold d-flex align-items-center" for="buffet_covered">
                                                                        <i class="bi bi-cup-hot-fill me-2 text-primary"></i>
                                                                        Охвачен буфетным питанием
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6">
                                                            <div class="card shadow-sm border-0 h-100">
                                                                <div class="card-body d-flex align-items-center">
                                                                    <input class="form-check-input me-2" type="checkbox" id="buffet_meal" name="buffet_meal">
                                                                    <label class="form-check-label fw-semibold d-flex align-items-center" for="buffet_meal">
                                                                        <i class="bi bi-emoji-smile-fill me-2 text-success"></i>
                                                                        Бесплатное буфетное питание
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Внеучебная деятельность -->
                                    <div class="accordion" id="accordionActivity">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingActivity">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseActivity" aria-expanded="false" aria-controls="collapseActivity">
                                                    <i class="bi bi-activity section-icon"></i>Внеучебная деятельность
                                                    <span class="badge bg-primary section-badge" id="activity-badge">0/4</span>
                                                </button>
                                            </h2>
                                            <div id="collapseActivity" class="accordion-collapse collapse" aria-labelledby="headingActivity" data-bs-parent="#accordionActivity">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="col-md-6 mb-3">
                                                            <div class="form-check d-flex align-items-center mb-2">
                                                                <input class="form-check-input me-2" type="checkbox" id="youth_committee" name="youth_committee">
                                                                <label class="form-check-label d-flex align-items-center" for="youth_committee">
                                                                    <i class="bi bi-people-fill text-primary me-2"></i>
                                                                    Комитет по делам молодежи
                                                                </label>
                                                            </div>
                                                            <div class="form-check d-flex align-items-center mb-2">
                                                                <input class="form-check-input me-2" type="checkbox" id="student_parliament" name="student_parliament">
                                                                <label class="form-check-label d-flex align-items-center" for="student_parliament">
                                                                    <i class="bi bi-bank2 text-success me-2"></i>
                                                                    Студенческий парламент
                                                                </label>
                                                            </div>
                                                            <div class="form-check d-flex align-items-center mb-2">
                                                                <input class="form-check-input me-2" type="checkbox" id="jas_sarbaz" name="jas_sarbaz">
                                                                <label class="form-check-label d-flex align-items-center" for="jas_sarbaz">
                                                                    <i class="bi bi-shield-lock-fill text-warning me-2"></i>
                                                                    Военно-патриотический клуб "Жас Сарбаз"
                                                                </label>
                                                            </div>
                                                            <div class="form-check d-flex align-items-center mb-2">
                                                                <input class="form-check-input me-2" type="checkbox" id="paid_practice" name="paid_practice">
                                                                <label class="form-check-label d-flex align-items-center" for="paid_practice">
                                                                    <i class="bi bi-cash-coin text-info me-2"></i>
                                                                    Оплачиваемая практика
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-6 mb-3">
                                                            <label for="practice_type" class="form-label fw-semibold">
                                                                <i class="bi bi-briefcase-fill text-secondary me-1"></i>
                                                                Тип практики
                                                            </label>
                                                            <select class="form-select" id="practice_type" name="practice_type">
                                                                <option value="не проходит" <?php echo isSelected('practice_type', 'не проходит'); ?>>Не проходит</option>
                                                                <option value="производственная" <?php echo isSelected('practice_type', 'производственная'); ?>>Производственная</option>
                                                                <option value="учебная" <?php echo isSelected('practice_type', 'учебная'); ?>>Учебная</option>
                                                                <option value="преддипломная" <?php echo isSelected('practice_type', 'преддипломная'); ?>>Преддипломная</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Академический отпуск -->
                                    <div class="accordion" id="accordionAcademic">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingAcademic">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseAcademic" aria-expanded="false" aria-controls="collapseAcademic">
                                                    <i class="bi bi-pause-circle-fill section-icon"></i>Академический отпуск
                                                    <span class="badge bg-primary section-badge" id="academic-badge">0/3</span>
                                                </button>
                                            </h2>
                                            <div id="collapseAcademic" class="accordion-collapse collapse" aria-labelledby="headingAcademic" data-bs-parent="#accordionAcademic">
                                                <div class="accordion-body">
                                                    <div class="row align-items-end">
                                                        <div class="col-md-4 mb-3">
                                                            <label class="form-label d-block mb-2">
                                                                <i class="bi bi-pause-circle-fill text-primary me-2"></i>
                                                                Академический отпуск
                                                            </label>
                                                            <div class="btn-group w-100" role="group" aria-label="Академический отпуск">
                                                                <input type="radio" class="btn-check" name="academic_leave" id="academic_leave_yes" value="1" autocomplete="off" <?php echo isChecked('academic_leave', '1'); ?>>
                                                                <label class="btn btn-outline-success d-flex align-items-center justify-content-center" for="academic_leave_yes">
                                                                    <i class="bi bi-check-circle-fill me-1"></i> Да
                                                                </label>
                                                                <input type="radio" class="btn-check" name="academic_leave" id="academic_leave_no" value="0" autocomplete="off" <?php echo isChecked('academic_leave', '0'); ?>>
                                                                <label class="btn btn-outline-danger d-flex align-items-center justify-content-center" for="academic_leave_no">
                                                                    <i class="bi bi-x-circle-fill me-1"></i> Нет
                                                                </label>
                                                            </div>
                                                        </div>
                                                        <div class="col-md-4 mb-3" id="academic_leave_reason_block" style="display: none;">
                                                            <label for="academic_leave_reason" class="form-label">
                                                                <i class="bi bi-question-circle-fill text-warning me-2"></i>
                                                                Причина отпуска
                                                            </label>
                                                            <select class="form-select" id="academic_leave_reason" name="academic_leave_reason">
                                                                <option value="">
                                                                    <i class="bi bi-dash-circle"></i> Выберите причину
                                                                </option>
                                                                <option value="по состоянию здоровья" <?php echo isSelected('academic_leave_reason', 'по состоянию здоровья'); ?>>
                                                                    <i class="bi bi-heart-pulse-fill text-danger"></i> По состоянию здоровья
                                                                </option>
                                                                <option value="беременность и роды" <?php echo isSelected('academic_leave_reason', 'беременность и роды'); ?>>
                                                                    <i class="bi bi-gender-female text-pink"></i> Беременность и роды
                                                                </option>
                                                                <option value="военная служба" <?php echo isSelected('academic_leave_reason', 'военная служба'); ?>>
                                                                    <i class="bi bi-shield-lock-fill text-info"></i> Военная служба
                                                                </option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4 mb-3" id="academic_leave_order_date_block" style="display: none;">
                                                            <label for="academic_leave_order_date" class="form-label">
                                                                <i class="bi bi-calendar-event-fill text-secondary me-2"></i>
                                                                Дата приказа
                                                            </label>
                                                            <div class="input-group">
                                                                <span class="input-group-text"><i class="bi bi-calendar-date"></i></span>
                                                                <input type="date" class="form-control" id="academic_leave_order_date" name="academic_leave_order_date" value="<?php echo getFieldValue('academic_leave_order_date'); ?>">
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <script>
                                                        function toggleAcademicLeaveFields() {
                                                            var yes = document.getElementById('academic_leave_yes');
                                                            var reasonBlock = document.getElementById('academic_leave_reason_block');
                                                            var dateBlock = document.getElementById('academic_leave_order_date_block');
                                                            if (yes.checked) {
                                                                reasonBlock.style.display = '';
                                                                dateBlock.style.display = '';
                                                            } else {
                                                                reasonBlock.style.display = 'none';
                                                                dateBlock.style.display = 'none';
                                                            }
                                                        }
                                                        document.getElementById('academic_leave_yes').addEventListener('change', toggleAcademicLeaveFields);
                                                        document.getElementById('academic_leave_no').addEventListener('change', toggleAcademicLeaveFields);
                                                        // On page load, set correct visibility
                                                        window.addEventListener('DOMContentLoaded', function() {
                                                            toggleAcademicLeaveFields();
                                                        });
                                                    </script>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Нарушения (для инвалидов) -->
                                    <div class="accordion" id="accordionDisability" style="display: none;">
                                        <div class="accordion-item">
                                            <h2 class="accordion-header" id="headingDisability">
                                                <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDisability" aria-expanded="false" aria-controls="collapseDisability">
                                                    <i class="bi bi-heart-pulse-fill section-icon"></i>Нарушения
                                                    <span class="badge bg-primary section-badge" id="disability-badge">0/3</span>
                                                </button>
                                            </h2>
                                            <div id="collapseDisability" class="accordion-collapse collapse" aria-labelledby="headingDisability" data-bs-parent="#accordionDisability">
                                                <div class="accordion-body">
                                                    <div class="row">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="primary_violation" class="form-label">Первичное нарушение</label>
                                                            <select class="form-select" id="primary_violation" name="primary_violation">
                                                                <option value="">Выберите нарушение</option>
                                                                <option value="НОДА">Нарушения опорно-двигательного аппарата</option>
                                                                <option value="ЗПР">Задержка психического развития</option>
                                                                <option value="нарушения речи">Нарушения речи</option>
                                                                <option value="нарушения общения">Нарушения общения</option>
                                                                <option value="нарушения поведения">Нарушения поведения</option>
                                                                <option value="сложные нарушения">Сложные нарушения</option>
                                                                <option value="кохлеарный имплант">Кохлеарный имплант</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="hearing_impairment" class="form-label">Нарушения слуха</label>
                                                            <select class="form-select" id="hearing_impairment" name="hearing_impairment">
                                                                <option value="">Нет нарушений</option>
                                                                <option value="слабослышащие">Слабослышащие</option>
                                                                <option value="неслышащие">Неслышащие</option>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4 mb-3">
                                                            <label for="vision_impairment" class="form-label">Нарушения зрения</label>
                                                            <select class="form-select" id="vision_impairment" name="vision_impairment">
                                                                <option value="">Нет нарушений</option>
                                                                <option value="слабовидящий">Слабовидящий</option>
                                                                <option value="незрячий">Незрячий</option>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <div class="row" id="intellectual_field" style="display: none;">
                                                        <div class="col-md-4 mb-3">
                                                            <label for="intellectual_impairment" class="form-label">Нарушения интеллекта</label>
                                                            <select class="form-select" id="intellectual_impairment" name="intellectual_impairment">
                                                                <option value="">Нет нарушений</option>
                                                                <option value="легкие">Легкие нарушения</option>
                                                                <option value="умеренные">Умеренные нарушения</option>
                                                                <option value="тяжелые">Тяжелые нарушения</option>
                                                                <option value="глубокие">Глубокие нарушения</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Динамические поля -->
                                    <?php if (!empty($dynamic_fields)): ?>
                                        <div class="accordion" id="accordionDynamic">
                                            <div class="accordion-item">
                                                <h2 class="accordion-header" id="headingDynamic">
                                                    <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#collapseDynamic" aria-expanded="false" aria-controls="collapseDynamic">
                                                        <i class="bi bi-gear-fill section-icon"></i>Дополнительная информация
                                                        <span class="badge bg-primary section-badge" id="dynamic-badge">0/<?php echo count($dynamic_fields); ?></span>
                                                    </button>
                                                </h2>
                                                <div id="collapseDynamic" class="accordion-collapse collapse" aria-labelledby="headingDynamic" data-bs-parent="#accordionDynamic">
                                                    <div class="accordion-body py-4 px-3 bg-light rounded-3">
                                                        <div class="row g-4">
                                                            <?php foreach ($dynamic_fields as $field): ?>
                                                                <div class="col-md-6 col-12">
                                                                    <div class="card shadow-sm border-0 h-100">
                                                                        <div class="card-body d-flex align-items-start">
                                                                            <span class="me-3">
                                                                                <!-- Новая иконка "добавить" (Bootstrap icon plus-circle) -->
                                                                                <svg xmlns="http://www.w3.org/2000/svg" width="28" height="28" fill="#0d6efd" class="bi bi-plus-circle-fill" viewBox="0 0 16 16">
                                                                                    <circle cx="8" cy="8" r="8" />
                                                                                    <path d="M8 4a.5.5 0 0 1 .5.5v3h3a.5.5 0 0 1 0 1h-3v3a.5.5 0 0 1-1 0v-3h-3a.5.5 0 0 1 0-1h3v-3A.5.5 0 0 1 8 4z" fill="#fff" />
                                                                                </svg>
                                                                            </span>
                                                                            <div class="flex-grow-1">
                                                                                <?php echo $dynamicField->generateFieldHTML($field); ?>
                                                                            </div>
                                                                        </div>
                                                                    </div>
                                                                </div>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                                        <a href="my_students.php" class="btn btn-secondary me-md-2">Отмена</a>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-person-plus-fill me-1"></i>Добавить студента
                                        </button>
                                    </div>
                                </form>
                                <script>
                                    (function() {
                                        function openAccordionFor(element) {
                                            var accordionItem = element.closest('.accordion-item');
                                            if (!accordionItem) return;
                                            var collapse = accordionItem.querySelector('.accordion-collapse');
                                            if (collapse && !collapse.classList.contains('show')) {
                                                var button = accordionItem.querySelector('.accordion-button');
                                                if (button) button.click();
                                            }
                                        }

                                        function validateRequiredFields(form) {
                                            var requiredFields = form.querySelectorAll('[required]');
                                            var firstInvalid = null;

                                            // Clear previous invalids
                                            requiredFields.forEach(function(field) {
                                                field.classList.remove('is-invalid');
                                            });

                                            requiredFields.forEach(function(field) {
                                                if (field.type === 'radio') {
                                                    var groupName = field.name;
                                                    var group = form.querySelectorAll('input[type="radio"][name="' + groupName + '"]');
                                                    var anyChecked = Array.prototype.some.call(group, function(r) {
                                                        return r.checked;
                                                    });
                                                    if (!anyChecked) {
                                                        group[0].classList.add('is-invalid');
                                                        if (!firstInvalid) firstInvalid = group[0];
                                                        openAccordionFor(group[0]);
                                                    }
                                                    return;
                                                }
                                                if (field.type === 'checkbox') {
                                                    return;
                                                }
                                                var value = (field.value || '').trim();
                                                if (!value) {
                                                    field.classList.add('is-invalid');
                                                    if (!firstInvalid) firstInvalid = field;
                                                    openAccordionFor(field);
                                                }
                                            });

                                            if (firstInvalid) firstInvalid.focus();
                                            return !firstInvalid;
                                        }

                                        var form = document.getElementById('add-student-form');
                                        if (form) {
                                            form.addEventListener('submit', function(e) {
                                                var ok = validateRequiredFields(form);
                                                if (!ok) {
                                                    e.preventDefault();
                                                }
                                            });
                                        }
                                    })();
                                </script>
                            </div>
                        </div>
                    <?php endif; ?>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
    <script src="../assets/js/main.js"></script>
    <script src="../assets/js/error-handler.js"></script>
    <script src="assets/js/curator-ui.js"></script>
    <script>
        // Показать/скрыть поля для инвалидов
        document.getElementById('disability').addEventListener('change', function() {
            const disabilitySection = document.getElementById('disability_section');
            const disabilityFields = document.getElementById('disability_fields');
            const intellectualField = document.getElementById('intellectual_field');

            if (this.checked) {
                disabilitySection.style.display = 'block';
                disabilityFields.style.display = 'block';
                intellectualField.style.display = 'block';
            } else {
                disabilitySection.style.display = 'none';
                disabilityFields.style.display = 'none';
                intellectualField.style.display = 'none';
            }
        });

        // Данные о группах для автозаполнения
        const groupsData = <?php echo json_encode($curator_groups); ?>;

        // Автозаполнение полей группы при выборе
        document.getElementById('group_id').addEventListener('change', function() {
            const selectedGroupId = this.value;
            if (selectedGroupId) {
                // Находим данные выбранной группы
                const selectedGroup = groupsData.find(group => group.id == selectedGroupId);

                if (selectedGroup) {
                    // Автозаполняем поля на основе данных группы
                    if (selectedGroup.course) {
                        document.getElementById('course').value = selectedGroup.course;
                    }

                    if (selectedGroup.language) {
                        document.getElementById('language').value = selectedGroup.language;
                    }

                    if (selectedGroup.study_form) {
                        document.getElementById('study_form').value = selectedGroup.study_form;
                    }

                    if (selectedGroup.study_duration) {
                        document.getElementById('study_duration').value = selectedGroup.study_duration;
                    }

                    if (selectedGroup.specialty) {
                        document.getElementById('specialty').value = selectedGroup.specialty;
                    }

                    if (selectedGroup.code) {
                        document.getElementById('group_code').value = selectedGroup.code;
                    }

                    if (selectedGroup.start_date) {
                        document.getElementById('course_start_date').value = selectedGroup.start_date;
                    }

                    if (selectedGroup.end_date) {
                        document.getElementById('course_end_date').value = selectedGroup.end_date;
                    }

                    if (selectedGroup.arrival_date) {
                        document.getElementById('arrival_date').value = selectedGroup.arrival_date;
                    }

                    if (selectedGroup.enrollment_order_number) {
                        document.getElementById('enrollment_order_number').value = selectedGroup.enrollment_order_number;
                    }


                    // Обновляем индикаторы статуса для автозаполненных полей
                    const autoFilledFields = ['course', 'language', 'study_form', 'study_duration', 'specialty', 'course_start_date', 'course_end_date', 'arrival_date', 'enrollment_order_number'];
                    autoFilledFields.forEach(fieldId => {
                        validateField(fieldId);
                    });

                    // Показываем уведомление об автозаполнении
                    showAutoFillNotification(selectedGroup.name);
                }
            } else {
                // Очищаем поля при снятии выбора группы
                clearGroupFields();

                // Обновляем индикаторы статуса для очищенных полей
                const clearedFields = ['course', 'language', 'study_form', 'study_duration', 'specialty', 'course_start_date', 'course_end_date', 'arrival_date', 'enrollment_order_number'];
                clearedFields.forEach(fieldId => {
                    validateField(fieldId);
                });
            }
        });


        // Функция для валидации контрольной суммы ИИН
        function validateIINChecksum(iin) {
            if (iin.length !== 12) return false;

            const weights1 = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11];
            const weights2 = [3, 4, 5, 6, 7, 8, 9, 10, 11, 1, 2];

            let sum1 = 0;
            for (let i = 0; i < 11; i++) {
                sum1 += parseInt(iin[i]) * weights1[i];
            }

            let checkDigit = sum1 % 11;

            if (checkDigit === 10) {
                let sum2 = 0;
                for (let i = 0; i < 11; i++) {
                    sum2 += parseInt(iin[i]) * weights2[i];
                }
                checkDigit = sum2 % 11;

                if (checkDigit === 10) {
                    return false; // Недопустимый ИИН
                }
            }

            return checkDigit === parseInt(iin[11]);
        }

        // Функция для автоматического заполнения данных по ИИН
        function autoFillDataFromIIN() {
            console.log('=== autoFillDataFromIIN вызвана ===');
            const iinField = document.getElementById('iin');
            const birthDateField = document.getElementById('birth_date');
            const genderField = document.getElementById('gender');

            console.log('Поля найдены:', {
                iin: !!iinField,
                birthDate: !!birthDateField,
                gender: !!genderField
            });

            if (!iinField) {
                console.log('Поле ИИН не найдено');
                return;
            }

            const iin = iinField.value.trim();
            console.log('Обрабатываем ИИН:', iin, 'длина:', iin.length);

            // Проверяем, что ИИН содержит 12 цифр
            if (iin.length === 12 && /^\d{12}$/.test(iin)) {
                // Проверяем контрольную сумму, но не блокируем если она неверная
                const isChecksumValid = validateIINChecksum(iin);
                if (!isChecksumValid) {
                    console.warn('Предупреждение: контрольная сумма ИИН может быть неверной');
                }
                try {
                    // Извлекаем дату рождения из ИИН
                    const year = iin.substring(0, 2);
                    const month = iin.substring(2, 4);
                    const day = iin.substring(4, 6);
                    const genderDigit = parseInt(iin.substring(6, 7)); // 7-я цифра определяет пол

                    // Определяем полный год
                    let fullYear;
                    const iinYear = parseInt(year);
                    const centuryDigit = parseInt(iin.substring(6, 7)); // 7-я цифра также определяет век

                    // Логика определения века для казахстанского ИИН
                    // 1-2: 1800-1899, 3-4: 1900-1999, 5-6: 2000-2099
                    if (centuryDigit >= 1 && centuryDigit <= 2) {
                        fullYear = 1800 + iinYear;
                    } else if (centuryDigit >= 3 && centuryDigit <= 4) {
                        fullYear = 1900 + iinYear;
                    } else if (centuryDigit >= 5 && centuryDigit <= 6) {
                        fullYear = 2000 + iinYear;
                    } else {
                        // Fallback для старой логики
                        if (iinYear <= 30) {
                            fullYear = 2000 + iinYear;
                        } else {
                            fullYear = 1900 + iinYear;
                        }
                    }

                    // Проверяем валидность даты
                    const monthNum = parseInt(month);
                    const dayNum = parseInt(day);

                    if (monthNum >= 1 && monthNum <= 12 && dayNum >= 1 && dayNum <= 31) {
                        // Форматируем дату в формат YYYY-MM-DD
                        const formattedDate = `${fullYear}-${month.padStart(2, '0')}-${day.padStart(2, '0')}`;

                        // Проверяем, что дата валидна
                        const dateObj = new Date(formattedDate);

                        if (dateObj.getFullYear() == fullYear &&
                            dateObj.getMonth() + 1 == monthNum &&
                            dateObj.getDate() == dayNum) {

                            // Заполняем поле даты рождения
                            if (birthDateField) {
                                birthDateField.value = formattedDate;
                            }

                            // Определяем пол по 7-й цифре ИИН
                            if (genderField) {
                                const gender = genderDigit % 2 === 0 ? 'женский' : 'мужской';
                                genderField.value = gender;
                            }

                            // Обновляем индикаторы статуса
                            console.log('Пропускаем валидацию полей');
                            // validateField('iin');
                            // validateField('birth_date');
                            // validateField('gender');

                            // Показываем уведомление
                            console.log('Автозаполнение завершено успешно!');
                            // Временно отключаем уведомление
                            // showIINAutoFillNotification();
                        }
                    }
                } catch (error) {
                    console.error('Ошибка при парсинге ИИН:', error);
                    console.error('Тип ошибки:', error.name);
                    console.error('Сообщение ошибки:', error.message);
                    console.error('Stack trace:', error.stack);
                    console.error('ИИН, который обрабатывался:', iin);
                    // Временно отключаем показ ошибки пользователю
                    // showIINErrorNotification('Ошибка при обработке ИИН');
                }
            } else if (iin.length > 0 && iin.length < 12) {
                // Если ИИН неполный, очищаем поля
                if (birthDateField) {
                    birthDateField.value = '';
                }
                if (genderField) {
                    genderField.value = '';
                }
                validateField('birth_date');
                validateField('gender');
            }
        }

        // Функция для показа уведомления об автозаполнении ИИН
        function showIINAutoFillNotification() {
            // Создаем уведомление
            const notification = document.createElement('div');
            notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="bi bi-person-check me-2"></i>
                <strong>Данные автоматически заполнены</strong><br>
                <small>Дата рождения и пол извлечены из ИИН</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // Добавляем уведомление на страницу
            document.body.appendChild(notification);

            // Автоматически удаляем уведомление через 3 секунды
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Функция для показа ошибки ИИН
        function showIINErrorNotification(message) {
            // Создаем уведомление об ошибке
            const notification = document.createElement('div');
            notification.className = 'alert alert-danger alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="bi bi-exclamation-triangle me-2"></i>
                <strong>Ошибка ИИН</strong><br>
                <small>${message}</small>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            // Добавляем уведомление на страницу
            document.body.appendChild(notification);

            // Автоматически удаляем уведомление через 5 секунд
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Функция для показа уведомления об автозаполнении
        function showAutoFillNotification(groupName) {
            // Создаем уведомление
            const notification = document.createElement('div');
            notification.className = 'alert alert-info alert-dismissible fade show position-fixed';
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <i class="bi bi-info-circle me-2"></i>
                <strong>Автозаполнение:</strong> Поля заполнены данными группы "${groupName}"
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(notification);

            // Автоматически скрываем уведомление через 3 секунды
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 3000);
        }

        // Функция для очистки полей группы
        function clearGroupFields() {
            document.getElementById('course').value = '';
            document.getElementById('language').value = '';
            document.getElementById('study_form').value = '';
            document.getElementById('study_duration').value = '';
            document.getElementById('specialty').value = '';
            document.getElementById('group_code').value = '';
            document.getElementById('course_start_date').value = '';
            document.getElementById('course_end_date').value = '';
            document.getElementById('arrival_date').value = '';
            document.getElementById('enrollment_order_number').value = '';
        }

        // Функция для обновления статуса поля
        function updateFieldStatus(fieldId, isValid, message = '') {
            const field = document.getElementById(fieldId);
            const statusElement = document.getElementById(fieldId + '-status');
            const hintElement = document.getElementById(fieldId + '-hint');

            if (!field || !statusElement || !hintElement) return;

            if (isValid) {
                statusElement.innerHTML = '<i class="bi bi-check-circle-fill"></i>';
                statusElement.className = 'field-status valid';
                hintElement.textContent = 'Заполнено корректно';
                hintElement.className = 'field-hint valid';
                field.classList.remove('is-invalid');
            } else {
                statusElement.innerHTML = '<i class="bi bi-exclamation-circle-fill"></i>';
                statusElement.className = 'field-status invalid';
                hintElement.textContent = message || 'Заполните поле';
                hintElement.className = 'field-hint invalid';
                field.classList.add('is-invalid');
            }
        }

        // Функция для проверки отдельного поля
        function validateField(fieldId) {
            const field = document.getElementById(fieldId);
            if (!field) return;

            const value = field.value.trim();
            let isValid = false;
            let message = '';

            switch (fieldId) {
                case 'iin':
                    isValid = /^\d{12}$/.test(value);
                    message = isValid ? '' : 'ИИН должен содержать 12 цифр';
                    break;

                case 'first_name':
                case 'middle_name':
                case 'nationality':
                case 'specialty':
                    isValid = value.length > 0;
                    message = isValid ? '' : 'Поле обязательно для заполнения';
                    break;

                case 'birth_date':
                    if (value) {
                        const birthDate = new Date(value);
                        const today = new Date();
                        const age = today.getFullYear() - birthDate.getFullYear();
                        isValid = age >= 16 && age <= 65;
                        message = isValid ? '' : 'Возраст должен быть от 16 до 65 лет';
                    } else {
                        isValid = false;
                        message = 'Поле обязательно для заполнения';
                    }
                    break;

                case 'gender':
                case 'course':
                case 'language':
                case 'study_form':
                case 'residence_type':
                    isValid = value !== '';
                    message = isValid ? '' : 'Выберите значение из списка';
                    break;

                case 'phone':
                    if (value) {
                        isValid = /^[\+]?[0-9\s\-\(\)]{10,}$/.test(value);
                        message = isValid ? '' : 'Неверный формат телефона';
                    } else {
                        isValid = false;
                        message = 'Поле обязательно для заполнения';
                    }
                    break;

                case 'email':
                    if (value) {
                        isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
                        message = isValid ? '' : 'Неверный формат email';
                    } else {
                        isValid = false;
                        message = 'Поле обязательно для заполнения';
                    }
                    break;

                case 'permanent_address_ru':
                    isValid = value.length > 0;
                    message = isValid ? '' : 'Поле обязательно для заполнения';
                    break;

                case 'course_start_date':
                    isValid = value !== '';
                    message = isValid ? '' : 'Поле обязательно для заполнения';
                    break;

                default:
                    isValid = value.length > 0;
                    message = isValid ? '' : 'Заполните поле';
            }

            updateFieldStatus(fieldId, isValid, message);

            // Обновляем счетчики в аккордеоне
            updateAccordionBadges();
        }

        // Функция для обновления счетчиков в бейджах аккордеона
        function updateAccordionBadges() {
            // Основная информация
            const mainFields = ['iin', 'first_name', 'middle_name', 'birth_date', 'gender', 'nationality', 'phone', 'email'];
            const mainValid = mainFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.value.trim() !== '';
            }).length;
            const mainBadge = document.getElementById('main-badge');
            if (mainBadge) {
                mainBadge.textContent = `${mainValid}/${mainFields.length}`;
                mainBadge.className = mainValid === mainFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Адреса
            const addressFields = ['permanent_address_ru'];
            const addressValid = addressFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.value.trim() !== '';
            }).length;
            const addressBadge = document.getElementById('address-badge');
            if (addressBadge) {
                addressBadge.textContent = `${addressValid}/${addressFields.length}`;
                addressBadge.className = addressValid === addressFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Информация о зачислении
            const enrollmentFields = ['group_id', 'course', 'language', 'study_form', 'course_start_date', 'specialty'];
            const enrollmentValid = enrollmentFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.value.trim() !== '';
            }).length;
            const enrollmentBadge = document.getElementById('enrollment-badge');
            if (enrollmentBadge) {
                enrollmentBadge.textContent = `${enrollmentValid}/${enrollmentFields.length}`;
                enrollmentBadge.className = enrollmentValid === enrollmentFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Социальные категории
            const quotaFields = ['quota_category'];
            const quotaValid = quotaFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.value.trim() !== '';
            }).length;
            const quotaBadge = document.getElementById('quota-badge');
            if (quotaBadge) {
                quotaBadge.textContent = `${quotaValid}/${quotaFields.length}`;
                quotaBadge.className = quotaValid === quotaFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Питание
            const mealsFields = ['hot_meal', 'free_hot_meal', 'buffet_meal', 'free_buffet_meal'];
            const mealsValid = mealsFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.checked;
            }).length;
            const mealsBadge = document.getElementById('meals-badge');
            if (mealsBadge) {
                mealsBadge.textContent = `${mealsValid}/${mealsFields.length}`;
                mealsBadge.className = mealsValid === mealsFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Внеучебная деятельность
            const activityFields = ['youth_committee', 'student_parliament', 'practice_type', 'competitions'];
            const activityValid = activityFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                if (field.type === 'checkbox') {
                    return field.checked;
                } else {
                    return field && field.value.trim() !== '';
                }
            }).length;
            const activityBadge = document.getElementById('activity-badge');
            if (activityBadge) {
                activityBadge.textContent = `${activityValid}/${activityFields.length}`;
                activityBadge.className = activityValid === activityFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Академический отпуск
            const academicFields = ['academic_leave', 'academic_leave_reason', 'academic_leave_order_date'];
            const academicValid = academicFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                if (field.type === 'checkbox') {
                    return field.checked;
                } else {
                    return field && field.value.trim() !== '';
                }
            }).length;
            const academicBadge = document.getElementById('academic-badge');
            if (academicBadge) {
                academicBadge.textContent = `${academicValid}/${academicFields.length}`;
                academicBadge.className = academicValid === academicFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }

            // Нарушения
            const disabilityFields = ['primary_violation', 'hearing_impairment', 'vision_impairment'];
            const disabilityValid = disabilityFields.filter(fieldId => {
                const field = document.getElementById(fieldId);
                return field && field.value.trim() !== '';
            }).length;
            const disabilityBadge = document.getElementById('disability-badge');
            if (disabilityBadge) {
                disabilityBadge.textContent = `${disabilityValid}/${disabilityFields.length}`;
                disabilityBadge.className = disabilityValid === disabilityFields.length ? 'badge bg-success section-badge' : 'badge bg-primary section-badge';
            }
        }

        // Валидация формы
        function validateForm() {
            const requiredFields = {
                'iin': 'ИИН',
                'first_name': 'Имя',
                'middle_name': 'Фамилия',
                'birth_date': 'Дата рождения',
                'gender': 'Пол',
                'nationality': 'Национальность',
                'permanent_address_ru': 'Постоянный адрес (русский)',
                'phone': 'Телефон',
                'email': 'Email',
                'course': 'Курс',
                'course_start_date': 'Дата начала курса',
                'language': 'Язык обучения',
                'study_form': 'Форма обучения',
                'specialty': 'Специальность',
                'residence_type': 'Тип местности'
            };

            const errors = [];

            // Проверка обязательных полей
            for (const [fieldId, fieldLabel] of Object.entries(requiredFields)) {
                const field = document.getElementById(fieldId);
                if (!field || field.value.trim() === '') {
                    errors.push(`Поле "${fieldLabel}" обязательно для заполнения!`);
                    if (field) {
                        field.classList.add('is-invalid');
                    }
                } else {
                    if (field) {
                        field.classList.remove('is-invalid');
                    }
                }
            }

            // Дополнительные проверки
            const iinField = document.getElementById('iin');
            if (iinField && iinField.value && !/^\d{12}$/.test(iinField.value)) {
                errors.push('ИИН должен содержать ровно 12 цифр!');
                iinField.classList.add('is-invalid');
            }

            const phoneField = document.getElementById('phone');
            if (phoneField && phoneField.value && !/^[\+]?[0-9\s\-\(\)]{10,}$/.test(phoneField.value)) {
                errors.push('Неверный формат телефона!');
                phoneField.classList.add('is-invalid');
            }

            const emailField = document.getElementById('email');
            if (emailField && emailField.value && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailField.value)) {
                errors.push('Неверный формат email!');
                emailField.classList.add('is-invalid');
            }

            const birthDateField = document.getElementById('birth_date');
            if (birthDateField && birthDateField.value) {
                const birthDate = new Date(birthDateField.value);
                const today = new Date();
                const age = today.getFullYear() - birthDate.getFullYear();

                if (age < 16 || age > 65) {
                    errors.push('Возраст должен быть от 16 до 65 лет!');
                    birthDateField.classList.add('is-invalid');
                }
            }

            const startDateField = document.getElementById('course_start_date');
            const endDateField = document.getElementById('course_end_date');
            if (startDateField && endDateField && startDateField.value && endDateField.value) {
                const startDate = new Date(startDateField.value);
                const endDate = new Date(endDateField.value);

                if (endDate <= startDate) {
                    errors.push('Дата окончания курса должна быть позже даты начала!');
                    endDateField.classList.add('is-invalid');
                }
            }

            // Показать ошибки
            if (errors.length > 0) {
                showValidationErrors(errors);
                return false;
            }

            return true;
        }

        // Показать ошибки валидации
        function showValidationErrors(errors) {
            const errorHtml = errors.map(error => `<li>${error}</li>`).join('');
            const errorDiv = document.createElement('div');
            errorDiv.className = 'alert alert-danger';
            errorDiv.innerHTML = `
                <h6><i class="bi bi-exclamation-triangle"></i> Ошибки валидации:</h6>
                <ul class="mb-0">${errorHtml}</ul>
            `;

            // Удалить предыдущие ошибки
            const existingErrors = document.querySelectorAll('.alert-danger');
            existingErrors.forEach(error => error.remove());

            // Добавить новые ошибки в начало формы
            const form = document.querySelector('form');
            form.insertBefore(errorDiv, form.firstChild);

            // Прокрутить к ошибкам
            errorDiv.scrollIntoView({
                behavior: 'smooth'
            });
        }

        // Добавить валидацию при отправке формы
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form');
            if (form) {
                form.addEventListener('submit', function(e) {
                    if (!validateForm()) {
                        e.preventDefault();
                        return false;
                    }
                });
            }

            // Добавить обработчики для индикаторов статуса
            const requiredFields = [
                'iin', 'first_name', 'middle_name', 'birth_date', 'gender',
                'nationality', 'phone', 'email', 'permanent_address_ru',
                'course', 'course_start_date',
                'language', 'study_form', 'specialty', 'residence_type'
            ];

            requiredFields.forEach(fieldId => {
                const field = document.getElementById(fieldId);
                if (field) {
                    // Проверка при вводе
                    field.addEventListener('input', function() {
                        validateField(fieldId);
                    });

                    // Проверка при изменении (для select)
                    field.addEventListener('change', function() {
                        validateField(fieldId);
                    });

                    // Проверка при потере фокуса
                    field.addEventListener('blur', function() {
                        validateField(fieldId);
                    });
                }
            });

            // Специальный обработчик для поля ИИН
            const iinField = document.getElementById('iin');
            if (iinField) {
                console.log('Добавляем обработчики для ИИН');

                // Множественные обработчики для надежности
                const handleIINInput = function(e) {
                    console.log('ИИН событие:', e.type, 'значение:', e.target.value, 'длина:', e.target.value.length);
                    validateField('iin');

                    // Показываем/скрываем кнопку поиска
                    const searchBtn = document.getElementById('searchImportedDataBtn');
                    if (searchBtn) {
                        const iinValue = e.target.value.replace(/\D/g, '');
                        if (iinValue.length === 12) {
                            searchBtn.style.display = 'block';
                            searchBtn.title = 'Поиск в импортированных данных для ИИН: ' + iinValue;
                        } else {
                            searchBtn.style.display = 'none';
                        }
                    }

                    if (e.target.value.length === 12 && /^\d{12}$/.test(e.target.value)) {
                        console.log('Запускаем автозаполнение через 200ms');
                        setTimeout(() => {
                            autoFillDataFromIIN();
                        }, 200);
                    }
                };

                // Добавляем несколько типов событий
                iinField.addEventListener('input', handleIINInput);
                iinField.addEventListener('keyup', handleIINInput);
                iinField.addEventListener('change', handleIINInput);
                iinField.addEventListener('blur', handleIINInput);
                iinField.addEventListener('paste', function(e) {
                    setTimeout(() => {
                        handleIINInput(e);
                    }, 50);
                });

                // Дополнительно добавляем наблюдатель за изменениями
                let lastIINValue = iinField.value;
                const checkIINChange = function() {
                    const currentValue = iinField.value;
                    if (currentValue !== lastIINValue) {
                        console.log('Обнаружено изменение ИИН:', lastIINValue, '->', currentValue);
                        lastIINValue = currentValue;
                        if (currentValue.length === 12 && /^\d{12}$/.test(currentValue)) {
                            console.log('Запускаем автозаполнение через наблюдатель');
                            setTimeout(() => {
                                autoFillDataFromIIN();
                            }, 300);
                        }
                    }
                };

                // Проверяем изменения каждые 500ms
                setInterval(checkIINChange, 500);

                console.log('Обработчики ИИН добавлены');
            } else {
                console.log('Поле ИИН не найдено!');
            }

            // Добавить обработчики для чекбоксов
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    updateAccordionBadges();
                });
            });

            // Убрать класс is-invalid при вводе
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    this.classList.remove('is-invalid');
                });
            });

            // Инициализация счетчиков при загрузке страницы
            updateAccordionBadges();

            // Инициализация кнопки поиска
            const searchBtn = document.getElementById('searchImportedDataBtn');
            if (searchBtn) {
                searchBtn.style.display = 'none'; // Скрываем кнопку по умолчанию
            }

            // Автоматическая прокрутка к форме при ошибке
            <?php if ($error): ?>
                setTimeout(() => {
                    scrollToForm();
                }, 500);
            <?php endif; ?>
        });

        // Функция для прокрутки к форме при нажатии "Попробовать еще раз"
        function scrollToForm() {
            const form = document.querySelector('form');
            if (form) {
                form.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });

                // Добавляем небольшое выделение формы
                form.style.border = '2px solid #dc3545';
                form.style.borderRadius = '8px';
                form.style.transition = 'border 0.3s ease';

                // Убираем выделение через 3 секунды
                setTimeout(() => {
                    form.style.border = '';
                    form.style.borderRadius = '';
                }, 3000);
            }
        }

        // Функция для поиска импортированных данных по ИИН
        function searchImportedData() {
            const iinField = document.getElementById('iin');
            const searchBtn = document.getElementById('searchImportedDataBtn');

            if (!iinField || !iinField.value) {
                alert('Сначала введите ИИН');
                return;
            }

            const iin = iinField.value.replace(/\D/g, '');
            if (iin.length !== 12) {
                alert('ИИН должен содержать 12 цифр');
                return;
            }

            // Показываем загрузку
            searchBtn.innerHTML = '<i class="bi bi-hourglass-split"></i>';
            searchBtn.disabled = true;

            const formData = new FormData();
            formData.append('action', 'get_student_data');
            formData.append('iin', iin);

            fetch('../api/simple_save_data.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => {
                    console.log('Search response status:', response.status);
                    return response.text().then(text => {
                        console.log('Search raw response:', text);
                        try {
                            return JSON.parse(text);
                        } catch (e) {
                            console.error('Search JSON parse error:', e);
                            throw new Error('Invalid JSON response: ' + text);
                        }
                    });
                })
                .then(data => {
                    console.log('=== ПОЛНЫЙ ОТВЕТ API ===');
                    console.log('API ответ:', data);
                    console.log('Успех:', data.success);
                    console.log('Данные студента:', data.data);

                    if (data.success) {
                        const studentData = data.data;

                        // Заполняем поля формы
                        if (studentData.first_name && document.getElementById('first_name')) {
                            document.getElementById('first_name').value = studentData.first_name;
                        }
                        if (studentData.last_name && document.getElementById('last_name')) {
                            document.getElementById('last_name').value = studentData.last_name;
                        }
                        if (studentData.middle_name && document.getElementById('middle_name')) {
                            document.getElementById('middle_name').value = studentData.middle_name;
                        }
                        // Обработка национальности (теперь input поле)
                        if (studentData.nationality && document.getElementById('nationality')) {
                            console.log('=== ДИАГНОСТИКА НАЦИОНАЛЬНОСТИ ===');
                            console.log('studentData.nationality:', studentData.nationality);

                            const nationalityInput = document.getElementById('nationality');
                            console.log('Элемент nationality найден:', !!nationalityInput);
                            console.log('Тип элемента:', nationalityInput.tagName);

                            // Простая установка значения в input
                            nationalityInput.value = studentData.nationality;
                            console.log('✅ Значение установлено в input:', nationalityInput.value);

                            // Отправляем событие input для обновления интерфейса
                            nationalityInput.dispatchEvent(new Event('input', {
                                bubbles: true
                            }));
                            console.log('🔄 Событие input отправлено');
                        }

                        if (studentData.phone && document.getElementById('phone')) {
                            document.getElementById('phone').value = studentData.phone;
                        }
                        if (studentData.email && document.getElementById('email')) {
                            document.getElementById('email').value = studentData.email;
                        }
                        if (studentData.permanent_address_ru && document.getElementById('permanent_address_ru')) {
                            document.getElementById('permanent_address_ru').value = studentData.permanent_address_ru;
                        }

                        // Запускаем автозаполнение по ИИН для даты рождения и пола
                        autoFillDataFromIIN();

                        // Показываем уведомление
                        showNotification('success', 'Данные найдены и заполнены!', 'Информация из импортированного файла успешно загружена.');

                    } else {
                        showNotification('warning', 'Данные не найдены', 'В импортированных данных нет информации для этого ИИН.');

                        // Отправляем уведомление администратору
                        sendMissingStudentNotification(iin);
                    }
                })
                .catch(error => {
                    console.error('Ошибка поиска:', error);
                    showNotification('error', 'Ошибка поиска', 'Произошла ошибка при поиске данных.');
                })
                .finally(() => {
                    // Восстанавливаем кнопку
                    searchBtn.innerHTML = '<i class="bi bi-search"></i>';
                    searchBtn.disabled = false;
                });
        }

        // Функция для показа уведомлений
        function showNotification(type, title, message) {
            const alertClass = type === 'success' ? 'alert-success' :
                type === 'warning' ? 'alert-warning' : 'alert-danger';
            const iconClass = type === 'success' ? 'bi-check-circle' :
                type === 'warning' ? 'bi-exclamation-triangle' : 'bi-x-circle';

            const notification = document.createElement('div');
            notification.className = `alert ${alertClass} alert-dismissible fade show position-fixed`;
            notification.style.cssText = 'top: 20px; right: 20px; z-index: 9999; min-width: 300px;';
            notification.innerHTML = `
                <h6><i class="bi ${iconClass} me-2"></i>${title}</h6>
                <p class="mb-0">${message}</p>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            `;

            document.body.appendChild(notification);

            // Автоматически убираем уведомление через 5 секунд
            setTimeout(() => {
                if (notification.parentNode) {
                    notification.remove();
                }
            }, 5000);
        }

        // Функция для отправки уведомления администратору о ненайденном студенте
        function sendMissingStudentNotification(iin) {
            const formData = new FormData();
            formData.append('action', 'send_missing_student_notification');
            formData.append('iin', iin);

            // Получаем информацию о кураторе из сессии PHP
            <?php if (isset($_SESSION['user_id']) && isset($_SESSION['user_name'])): ?>
                formData.append('curator_id', '<?php echo $_SESSION['user_id']; ?>');
                formData.append('curator_name', '<?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES, 'UTF-8'); ?>');
            <?php endif; ?>

            fetch('../api/send_notification.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        console.log('Уведомление администратору отправлено:', data.message);
                    } else {
                        console.warn('Ошибка отправки уведомления:', data.error);
                    }
                })
                .catch(error => {
                    console.error('Ошибка при отправке уведомления:', error);
                });
        }
    </script>

</body>

</html>

</html>