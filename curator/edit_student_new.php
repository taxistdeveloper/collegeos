<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../classes/ErrorTranslator.php';
require_once '../includes/auth.php';

checkAuth();
$current_user = getCurrentUser();
$permissionChecker = new PermissionChecker();
$group = new Group();
$dynamicField = new DynamicField();
$db = getDB();

$from_admin = (isset($_GET['from']) && $_GET['from'] === 'admin')
    || (isset($_POST['from']) && $_POST['from'] === 'admin')
    || !empty($_SESSION['admin_logged_in']);
$students_list_url = $from_admin ? '../admin/students.php' : 'my_students.php';
$view_student_url = function ($id) use ($from_admin) {
    return 'view_student.php?id=' . (int)$id . ($from_admin ? '&from=admin' : '');
};

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: ' . $students_list_url);
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
    header('Location: ' . $students_list_url);
    exit;
}

// Проверяем доступ
$can_edit_all = !empty($_SESSION['admin_logged_in'])
    || $permissionChecker->hasPermission($current_user['id'], 'edit_students')
    || $permissionChecker->hasPermission($current_user['id'], 'edit_all_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all');
if (!$can_edit_all) {
    $curator_groups = $group->getGroupsByCurator($current_user['id']);
    $allowed_group_ids = array_column($curator_groups, 'id');
    if (!in_array((int)$student['group_id'], $allowed_group_ids, true)) {
        header('Location: ../unauthorized.php');
        exit;
    }
}

$message = '';
$error = '';

// Обработка формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'update') {
        try {
            // Проверка обязательных полей
            $required_fields = [
                'iin' => 'ИИН',
                'first_name' => 'Имя',
                'middle_name' => 'Отчество',
                'birth_date' => 'Дата рождения',
                'gender' => 'Пол',
                'nationality' => 'Национальность',
                'phone' => 'Телефон',
                'email' => 'Email',
                'course' => 'Курс',
                'language' => 'Язык обучения',
                'study_form' => 'Форма обучения',
                'specialty' => 'Специальность',
                'study_duration' => 'Срок обучения'
            ];

            $validation_errors = [];

            foreach ($required_fields as $field => $label) {
                if (empty($_POST[$field])) {
                    $validation_errors[] = "Поле '$label' обязательно для заполнения!";
                }
            }

            // Валидация ИИН
            if (!empty($_POST['iin']) && !preg_match('/^\d{12}$/', $_POST['iin'])) {
                $validation_errors[] = 'ИИН должен содержать ровно 12 цифр!';
            }

            // Валидация email
            if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
                $validation_errors[] = 'Некорректный формат email!';
            }

            // Валидация даты рождения
            if (!empty($_POST['birth_date'])) {
                $birth_date = DateTime::createFromFormat('Y-m-d', $_POST['birth_date']);
                if (!$birth_date || $birth_date->format('Y-m-d') !== $_POST['birth_date']) {
                    $validation_errors[] = 'Некорректная дата рождения!';
                } else {
                    $today = new DateTime();
                    $age = $today->diff($birth_date)->y;
                    if ($age < 14 || $age > 100) {
                        $validation_errors[] = 'Возраст должен быть от 14 до 100 лет!';
                    }
                }
            }

            if (!empty($validation_errors)) {
                $error = implode('<br>', $validation_errors);
            } else {
                // Проверяем уникальность ИИН (исключая текущего студента)
                $check_iin_sql = "SELECT id FROM students WHERE iin = ? AND id != ?";
                $check_stmt = $db->prepare($check_iin_sql);
                $check_stmt->bind_param('si', $_POST['iin'], $student_id);
                $check_stmt->execute();
                $iin_result = $check_stmt->get_result();

                if ($iin_result->num_rows > 0) {
                    $error = 'Студент с таким ИИН уже существует!';
                } else {
                    // Проверяем уникальность email (исключая текущего студента)
                    $check_email_sql = "SELECT id FROM students WHERE email = ? AND id != ?";
                    $check_email_stmt = $db->prepare($check_email_sql);
                    $check_email_stmt->bind_param('si', $_POST['email'], $student_id);
                    $check_email_stmt->execute();
                    $email_result = $check_email_stmt->get_result();

                    if ($email_result->num_rows > 0) {
                        $error = 'Студент с таким email уже существует!';
                    } else {
                        // Начинаем транзакцию
                        $db->begin_transaction();

                        try {
                            // Обновляем основную информацию студента
                            // Для NULL значений используем COALESCE или отдельную обработку
                            $update_sql = "UPDATE students SET 
                                iin = ?, first_name = ?, middle_name = ?, last_name = ?, 
                                birth_date = ?, gender = ?, nationality = ?, phone = ?, email = ?,
                                permanent_address_ru = ?, temporary_address_ru = ?,
                                specialty = ?, course = ?, language = ?, study_form = ?, study_duration = ?,
                                group_code = ?, enrollment_order_number = ?, arrival_date = ?, arrival_from = ?,
                                education_type = ?, residence_type = ?,
                                academic_leave = ?, 
                                academic_leave_reason = NULLIF(?, ''), 
                                academic_leave_order_date = NULLIF(?, ''),
                                orphan = ?, without_parental_care = ?, disability = ?, large_family = ?, social_assistance = ?,
                                hot_meal = ?, free_hot_meal = ?, youth_committee = ?, student_parliament = ?, jas_sarbaz = ?,
                                practice_type = ?, competitions = ?,
                                updated_at = NOW()
                                WHERE id = ?";

                            $update_stmt = $db->prepare($update_sql);

                            // Подготавливаем значения для чекбоксов и академического отпуска
                            $academic_leave = isset($_POST['academic_leave']) && $_POST['academic_leave'] == '1' ? 1 : 0;
                            // Используем пустую строку для NULL (будет конвертировано через NULLIF в SQL)
                            $academic_leave_reason = !empty($_POST['academic_leave_reason']) ? $_POST['academic_leave_reason'] : '';
                            $academic_leave_order_date = !empty($_POST['academic_leave_order_date']) ? $_POST['academic_leave_order_date'] : '';
                            $orphan = isset($_POST['orphan']) ? 1 : 0;
                            $without_parental_care = isset($_POST['without_parental_care']) ? 1 : 0;
                            $disability = isset($_POST['disability']) ? 1 : 0;
                            $large_family = isset($_POST['large_family']) ? 1 : 0;
                            $social_assistance = isset($_POST['social_assistance']) ? 1 : 0;
                            $hot_meal = isset($_POST['hot_meal']) ? 1 : 0;
                            $free_hot_meal = isset($_POST['free_hot_meal']) ? 1 : 0;
                            $youth_committee = isset($_POST['youth_committee']) ? 1 : 0;
                            $student_parliament = isset($_POST['student_parliament']) ? 1 : 0;
                            $jas_sarbaz = isset($_POST['jas_sarbaz']) ? 1 : 0;

                            $update_stmt->bind_param(
                                'ssssssssssssssssssssssissiiiiiiiiiissi',
                                $_POST['iin'],
                                $_POST['first_name'],
                                $_POST['middle_name'],
                                $_POST['last_name'],
                                $_POST['birth_date'],
                                $_POST['gender'],
                                $_POST['nationality'],
                                $_POST['phone'],
                                $_POST['email'],
                                $_POST['permanent_address_ru'],
                                $_POST['temporary_address_ru'],
                                $_POST['specialty'],
                                $_POST['course'],
                                $_POST['language'],
                                $_POST['study_form'],
                                $_POST['study_duration'],
                                $_POST['group_code'],
                                $_POST['enrollment_order_number'],
                                $_POST['arrival_date'],
                                $_POST['arrival_from'],
                                $_POST['education_type'],
                                $_POST['residence_type'],
                                $academic_leave,
                                $academic_leave_reason,
                                $academic_leave_order_date,
                                $orphan,
                                $without_parental_care,
                                $disability,
                                $large_family,
                                $social_assistance,
                                $hot_meal,
                                $free_hot_meal,
                                $youth_committee,
                                $student_parliament,
                                $jas_sarbaz,
                                $_POST['practice_type'],
                                $_POST['competitions'],
                                $student_id
                            );

                            if (!$update_stmt->execute()) {
                                error_log("SQL Error: " . $update_stmt->error);
                                error_log("SQL State: " . $db->sqlstate);
                                throw new Exception('Ошибка при обновлении данных студента: ' . $update_stmt->error);
                            }

                            // Обновляем динамические поля
                            if (isset($_POST['dynamic_fields']) && is_array($_POST['dynamic_fields'])) {
                                // Удаляем старые значения динамических полей
                                $delete_dynamic_sql = "DELETE FROM student_dynamic_fields WHERE student_id = ?";
                                $delete_dynamic_stmt = $db->prepare($delete_dynamic_sql);
                                $delete_dynamic_stmt->bind_param('i', $student_id);
                                $delete_dynamic_stmt->execute();

                                // Добавляем новые значения
                                $insert_dynamic_sql = "INSERT INTO student_dynamic_fields (student_id, field_name, field_value) VALUES (?, ?, ?)";
                                $insert_dynamic_stmt = $db->prepare($insert_dynamic_sql);

                                foreach ($_POST['dynamic_fields'] as $field_name => $field_value) {
                                    if (!empty($field_value)) {
                                        $insert_dynamic_stmt->bind_param('iss', $student_id, $field_name, $field_value);
                                        $insert_dynamic_stmt->execute();
                                    }
                                }
                            }

                            // Подтверждаем транзакцию
                            $db->commit();

                            // Сохраняем сообщение об успехе в сессии
                            startSessionSafely();
                            $student_name = htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']);
                            $_SESSION['success_message'] = 'Данные студента ' . $student_name . ' успешно обновлены!';

                            header('Location: ' . $view_student_url($student_id));
                            exit;
                        } catch (Exception $e) {
                            // Откатываем транзакцию в случае ошибки
                            $db->rollback();
                            $error = 'Ошибка при обновлении студента: ' . translateDatabaseError($e->getMessage());
                        }
                    }
                }
            }
        } catch (Exception $e) {
            $error = 'Произошла ошибка: ' . translateDatabaseError($e->getMessage());
        }
    }
}

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);

// Получение динамических полей
$dynamic_fields = $dynamicField->getAllFields();
$student_dynamic_values = $dynamicField->getStudentDynamicFields($student_id);

// Функция для проверки выбранного значения
function isSelected($field, $value)
{
    global $student;
    return isset($student[$field]) && $student[$field] === $value ? 'selected' : '';
}

function isChecked($field, $value = null)
{
    global $student;
    if ($value !== null) {
        // Для radio-кнопок с конкретным значением
        return isset($student[$field]) && (string)$student[$field] === (string)$value ? 'checked' : '';
    }
    // Для обычных чекбоксов
    return isset($student[$field]) && $student[$field] ? 'checked' : '';
}

// Функция для перевода ошибок базы данных на русский язык
function translateDatabaseError($error_message)
{
    return ErrorTranslator::translate($error_message);
}
$full_name = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$page_title = 'Редактировать: ' . $full_name;
$page_subtitle = 'ИИН ' . ($student['iin'] ?? '—');

$parent_field_keys = [
    'father_full_name', 'father_phone', 'father_workplace',
    'mother_full_name', 'mother_phone', 'mother_workplace',
    'emergency_contact',
];

$h = static function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать: <?php echo $h($full_name); ?> - <?php echo $h(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <link href="assets/css/view-student.css?v=5" rel="stylesheet">
    <link href="assets/css/edit-student.css?v=5" rel="stylesheet">
    <style>
        .es-pane { display: none !important; }
        .es-pane.is-open { display: block !important; }
        .es-tabbar { display: flex; flex-wrap: wrap; gap: 6px; padding: 10px; background: #f1f3f9; border: 1px solid #e5e9f2; border-bottom: 0; border-radius: 14px 14px 0 0; position: relative; z-index: 5; }
        .es-tab { cursor: pointer; border: 1px solid transparent; background: transparent; padding: 10px 14px; border-radius: 10px; font-weight: 600; color: #475569; position: relative; z-index: 6; }
        .es-tab.is-active { background: #fff; color: #2c5af2; border-color: #e5e9f2; }
        .es-tab-content { border: 1px solid #e5e9f2; border-top: 0; border-radius: 0 0 14px 14px; background: #fff; margin-bottom: 16px; position: relative; z-index: 1; }
        .es-sticky-bar {
            position: fixed !important;
            left: 260px;
            right: 0;
            bottom: 0;
            z-index: 2000 !important;
            background: #fff;
            border-top: 1px solid #e5e9f2;
            box-shadow: 0 -6px 24px rgba(15, 23, 42, 0.08);
            padding: 12px 24px;
            display: flex !important;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .es-sticky-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-left: auto; }
        .es-page { padding-bottom: 88px !important; }
        @media (max-width: 992px) {
            .es-sticky-bar { left: 0 !important; }
        }
    </style>
    <script src="assets/js/edit-student.js?v=5"></script>
</head>
<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content es-page" role="main">

            <nav aria-label="breadcrumb" class="vs-breadcrumb">
                <ol class="breadcrumb">
                    <?php if ($from_admin): ?>
                        <li class="breadcrumb-item"><a href="../admin/index.php">Админ</a></li>
                        <li class="breadcrumb-item"><a href="../admin/students.php">Студенты</a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="dashboard.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="my_students.php">Мои студенты</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item"><a href="<?php echo $h($view_student_url($student['id'])); ?>">Профиль</a></li>
                    <li class="breadcrumb-item active" aria-current="page">Редактирование</li>
                </ol>
            </nav>

            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong>Успешно.</strong> <?php echo $h($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Ошибка.</strong> <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>

            <section class="vs-hero" aria-labelledby="edit-student-name">
                <div class="vs-avatar" aria-hidden="true"><i class="bi bi-pencil-square"></i></div>
                <div>
                    <h1 id="edit-student-name"><?php echo $h($full_name); ?></h1>
                    <div class="vs-meta" role="list">
                        <?php if (!empty($student['iin'])): ?>
                            <span class="vs-chip" role="listitem"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i>ИИН <?php echo $h($student['iin']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($student['course'])): ?>
                            <span class="vs-chip" role="listitem"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo $h($student['course']); ?></span>
                        <?php endif; ?>
                        <span class="vs-pill vs-pill-info" role="status">Редактирование</span>
                    </div>
                </div>
                <div class="vs-actions" role="group" aria-label="Действия">
                    <button type="submit" form="edit-student-form" class="btn btn-primary" id="btnSaveTop">
                        <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Сохранить
                    </button>
                    <a class="btn btn-outline-secondary" href="<?php echo $h($view_student_url($student['id'])); ?>">
                        <i class="bi bi-eye me-1" aria-hidden="true"></i>Просмотр
                    </a>
                    <a class="btn btn-outline-secondary" href="<?php echo $h($students_list_url); ?>">
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Назад
                    </a>
                </div>
            </section>

            <form method="POST" id="edit-student-form" novalidate data-view-url="<?php echo $h($view_student_url($student['id'])); ?>">
                <?php if ($from_admin): ?>
                    <input type="hidden" name="from" value="admin">
                <?php endif; ?>
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="student_id" value="<?php echo (int)$student['id']; ?>">

                <div class="es-editor-tabs">
                    <div class="es-tabbar" id="editStudentTabs" role="tablist">
                        <button class="es-tab is-active" type="button" data-tab-target="pane-contacts" onclick="return window.esSwitchTab(this)">Контакты</button>
                        <button class="es-tab" type="button" data-tab-target="pane-study" onclick="return window.esSwitchTab(this)">Учёба</button>
                        <button class="es-tab" type="button" data-tab-target="pane-docs" onclick="return window.esSwitchTab(this)">Документы</button>
                        <button class="es-tab" type="button" data-tab-target="pane-family" onclick="return window.esSwitchTab(this)">Семья и соц</button>
                        <button class="es-tab" type="button" data-tab-target="pane-activity" onclick="return window.esSwitchTab(this)">Активности</button>
                        <button class="es-tab" type="button" data-tab-target="pane-extra" onclick="return window.esSwitchTab(this)">Ещё</button>
                    </div>

                    <div class="es-tab-content">
                        <div class="es-pane is-open" id="pane-contacts" style="display:block" role="tabpanel" aria-labelledby="tab-contacts">
                            <div class="vs-tab-pane">
                                <div class="es-section-title">Личные данные</div>
                                <div class="es-form-grid">
                                    <div class="es-field">
                                        <label class="es-label required" for="iin">ИИН</label>
                                        <input class="es-input" type="text" id="iin" name="iin" value="<?php echo $h($student['iin']); ?>" maxlength="12" pattern="[0-9]{12}" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="last_name">Фамилия</label>
                                        <input class="es-input" type="text" id="last_name" name="last_name" value="<?php echo $h($student['last_name'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="first_name">Имя</label>
                                        <input class="es-input" type="text" id="first_name" name="first_name" value="<?php echo $h($student['first_name']); ?>" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="middle_name">Отчество</label>
                                        <input class="es-input" type="text" id="middle_name" name="middle_name" value="<?php echo $h($student['middle_name']); ?>" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="birth_date">Дата рождения</label>
                                        <input class="es-input" type="date" id="birth_date" name="birth_date" value="<?php echo $h($student['birth_date']); ?>" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="gender">Пол</label>
                                        <select class="es-select" id="gender" name="gender" required>
                                            <option value="">Выберите пол</option>
                                            <option value="мужской" <?php echo isSelected('gender', 'мужской'); ?>>Мужской</option>
                                            <option value="женский" <?php echo isSelected('gender', 'женский'); ?>>Женский</option>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="nationality">Национальность</label>
                                        <input class="es-input" type="text" id="nationality" name="nationality" value="<?php echo $h($student['nationality']); ?>" required>
                                    </div>
                                </div>

                                <div class="es-section-title">Связь и адреса</div>
                                <div class="es-form-grid">
                                    <div class="es-field">
                                        <label class="es-label required" for="phone">Телефон</label>
                                        <input class="es-input" type="tel" id="phone" name="phone" value="<?php echo $h($student['phone']); ?>" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="email">Email</label>
                                        <input class="es-input" type="email" id="email" name="email" value="<?php echo $h($student['email']); ?>" required>
                                    </div>
                                    <div class="es-field es-field-full">
                                        <label class="es-label" for="permanent_address_ru">Постоянный адрес</label>
                                        <textarea class="es-textarea" id="permanent_address_ru" name="permanent_address_ru" rows="3"><?php echo $h($student['permanent_address_ru'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="es-field es-field-full">
                                        <label class="es-label" for="temporary_address_ru">Временный адрес</label>
                                        <textarea class="es-textarea" id="temporary_address_ru" name="temporary_address_ru" rows="3"><?php echo $h($student['temporary_address_ru'] ?? ''); ?></textarea>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="residence_type">Тип местности</label>
                                        <select class="es-select" id="residence_type" name="residence_type">
                                            <option value="">Выберите тип</option>
                                            <option value="городская местность" <?php echo isSelected('residence_type', 'городская местность'); ?>>Городская местность</option>
                                            <option value="сельская местность" <?php echo isSelected('residence_type', 'сельская местность'); ?>>Сельская местность</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="es-pane" id="pane-study" style="display:none" role="tabpanel" aria-labelledby="tab-study">
                            <div class="vs-tab-pane">
                                <div class="es-form-grid">
                                    <div class="es-field es-field-full">
                                        <label class="es-label required" for="specialty">Специальность</label>
                                        <input class="es-input" type="text" id="specialty" name="specialty" value="<?php echo $h($student['specialty']); ?>" required>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="course">Курс</label>
                                        <select class="es-select" id="course" name="course" required>
                                            <option value="">Выберите курс</option>
                                            <?php foreach (['1 курс','2 курс','3 курс','4 курс','5 курс'] as $opt): ?>
                                                <option value="<?php echo $h($opt); ?>" <?php echo isSelected('course', $opt); ?>><?php echo $h($opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="language">Язык обучения</label>
                                        <select class="es-select" id="language" name="language" required>
                                            <option value="">Выберите язык</option>
                                            <option value="казахский" <?php echo isSelected('language', 'казахский'); ?>>Казахский</option>
                                            <option value="русский" <?php echo isSelected('language', 'русский'); ?>>Русский</option>
                                            <option value="английский" <?php echo isSelected('language', 'английский'); ?>>Английский</option>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="study_form">Форма обучения</label>
                                        <select class="es-select" id="study_form" name="study_form" required>
                                            <option value="">Выберите форму</option>
                                            <option value="очная" <?php echo isSelected('study_form', 'очная'); ?>>Очная</option>
                                            <option value="заочная" <?php echo isSelected('study_form', 'заочная'); ?>>Заочная</option>
                                            <option value="вечерняя" <?php echo isSelected('study_form', 'вечерняя'); ?>>Вечерняя</option>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label required" for="study_duration">Срок обучения</label>
                                        <select class="es-select" id="study_duration" name="study_duration" required>
                                            <option value="">Выберите срок</option>
                                            <?php foreach (['1 год','2 года','3 года','4 года','5 лет'] as $opt): ?>
                                                <option value="<?php echo $h($opt); ?>" <?php echo isSelected('study_duration', $opt); ?>><?php echo $h($opt); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="group_code">Код группы</label>
                                        <input class="es-input" type="text" id="group_code" name="group_code" value="<?php echo $h($student['group_code'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="education_type">Тип образования</label>
                                        <select class="es-select" id="education_type" name="education_type">
                                            <option value="">Выберите тип</option>
                                            <option value="основная школа" <?php echo isSelected('education_type', 'основная школа'); ?>>Основная школа</option>
                                            <option value="средняя школа" <?php echo isSelected('education_type', 'средняя школа'); ?>>Средняя школа</option>
                                            <option value="организация ТиПО" <?php echo isSelected('education_type', 'организация ТиПО'); ?>>Организация ТиПО</option>
                                            <option value="ВУЗ" <?php echo isSelected('education_type', 'ВУЗ'); ?>>ВУЗ</option>
                                        </select>
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="practice_type">Тип практики</label>
                                        <select class="es-select" id="practice_type" name="practice_type">
                                            <option value="">Выберите тип</option>
                                            <option value="производственная" <?php echo isSelected('practice_type', 'производственная'); ?>>Производственная</option>
                                            <option value="учебная" <?php echo isSelected('practice_type', 'учебная'); ?>>Учебная</option>
                                            <option value="преддипломная" <?php echo isSelected('practice_type', 'преддипломная'); ?>>Преддипломная</option>
                                            <option value="не проходит" <?php echo isSelected('practice_type', 'не проходит'); ?>>Не проходит</option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="es-pane" id="pane-docs" style="display:none" role="tabpanel" aria-labelledby="tab-docs">
                            <div class="vs-tab-pane">
                                <div class="es-form-grid">
                                    <div class="es-field">
                                        <label class="es-label" for="enrollment_order_number">Номер приказа</label>
                                        <input class="es-input" type="text" id="enrollment_order_number" name="enrollment_order_number" value="<?php echo $h($student['enrollment_order_number'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="arrival_date">Дата зачисления</label>
                                        <input class="es-input" type="date" id="arrival_date" name="arrival_date" value="<?php echo $h($student['arrival_date'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field es-field-full">
                                        <label class="es-label" for="arrival_from">Прибыл из</label>
                                        <select class="es-select" id="arrival_from" name="arrival_from">
                                            <option value="">Выберите откуда</option>
                                            <?php
                                            $arrival_opts = [
                                                'данного района (города, села) данной области' => 'Данного района (города, села) данной области',
                                                'другого района (города) данной области' => 'Другого района (города) данной области',
                                                'другой области' => 'Другой области',
                                                'другого государства СНГ' => 'Другого государства СНГ',
                                                'дальнего зарубежья' => 'Дальнего зарубежья',
                                            ];
                                            foreach ($arrival_opts as $val => $label):
                                            ?>
                                                <option value="<?php echo $h($val); ?>" <?php echo isSelected('arrival_from', $val); ?>><?php echo $h($label); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>

                                <div class="es-section-title">Академический отпуск</div>
                                <div class="es-form-grid">
                                    <div class="es-field es-field-full">
                                        <span class="es-label">Находится в академическом отпуске</span>
                                        <div class="es-radio-row">
                                            <label class="es-radio">
                                                <input type="radio" name="academic_leave" value="1" id="academic_leave_yes" <?php echo isChecked('academic_leave', '1'); ?>>
                                                Да
                                            </label>
                                            <label class="es-radio">
                                                <input type="radio" name="academic_leave" value="0" id="academic_leave_no" <?php echo isChecked('academic_leave', '0'); ?>>
                                                Нет
                                            </label>
                                        </div>
                                    </div>
                                    <div class="es-field" id="academic_leave_reason_block" style="display: <?php echo (int)($student['academic_leave'] ?? 0) === 1 ? 'block' : 'none'; ?>;">
                                        <label class="es-label" for="academic_leave_reason">Причина отпуска</label>
                                        <select class="es-select" id="academic_leave_reason" name="academic_leave_reason">
                                            <option value="">Выберите причину</option>
                                            <option value="по состоянию здоровья" <?php echo isSelected('academic_leave_reason', 'по состоянию здоровья'); ?>>По состоянию здоровья</option>
                                            <option value="беременность и роды" <?php echo isSelected('academic_leave_reason', 'беременность и роды'); ?>>Беременность и роды</option>
                                            <option value="военная служба" <?php echo isSelected('academic_leave_reason', 'военная служба'); ?>>Военная служба</option>
                                        </select>
                                    </div>
                                    <div class="es-field" id="academic_leave_order_date_block" style="display: <?php echo (int)($student['academic_leave'] ?? 0) === 1 ? 'block' : 'none'; ?>;">
                                        <label class="es-label" for="academic_leave_order_date">Дата приказа</label>
                                        <input class="es-input" type="date" id="academic_leave_order_date" name="academic_leave_order_date" value="<?php echo $h($student['academic_leave_order_date'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="es-pane" id="pane-family" style="display:none" role="tabpanel" aria-labelledby="tab-family">
                            <div class="vs-tab-pane">
                                <div class="es-section-title">Родители</div>
                                <div class="es-form-grid">
                                    <div class="es-field">
                                        <label class="es-label" for="mother_full_name">ФИО матери</label>
                                        <input class="es-input" type="text" id="mother_full_name" name="dynamic_fields[mother_full_name]" value="<?php echo $h($student_dynamic_values['mother_full_name'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="mother_phone">Телефон матери</label>
                                        <input class="es-input" type="tel" id="mother_phone" name="dynamic_fields[mother_phone]" value="<?php echo $h($student_dynamic_values['mother_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="mother_workplace">Место работы матери</label>
                                        <input class="es-input" type="text" id="mother_workplace" name="dynamic_fields[mother_workplace]" value="<?php echo $h($student_dynamic_values['mother_workplace'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="father_full_name">ФИО отца</label>
                                        <input class="es-input" type="text" id="father_full_name" name="dynamic_fields[father_full_name]" value="<?php echo $h($student_dynamic_values['father_full_name'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="father_phone">Телефон отца</label>
                                        <input class="es-input" type="tel" id="father_phone" name="dynamic_fields[father_phone]" value="<?php echo $h($student_dynamic_values['father_phone'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="father_workplace">Место работы отца</label>
                                        <input class="es-input" type="text" id="father_workplace" name="dynamic_fields[father_workplace]" value="<?php echo $h($student_dynamic_values['father_workplace'] ?? ''); ?>">
                                    </div>
                                    <div class="es-field">
                                        <label class="es-label" for="emergency_contact">Экстренный контакт</label>
                                        <input class="es-input" type="tel" id="emergency_contact" name="dynamic_fields[emergency_contact]" value="<?php echo $h($student_dynamic_values['emergency_contact'] ?? ''); ?>">
                                    </div>
                                </div>

                                <div class="es-section-title">Социальные категории</div>
                                <div class="es-check-grid">
                                    <label class="es-check"><input type="checkbox" id="orphan" name="orphan" value="1" <?php echo isChecked('orphan'); ?>> Сирота</label>
                                    <label class="es-check"><input type="checkbox" id="without_parental_care" name="without_parental_care" value="1" <?php echo isChecked('without_parental_care'); ?>> Без попечения родителей</label>
                                    <label class="es-check"><input type="checkbox" id="disability" name="disability" value="1" <?php echo isChecked('disability'); ?>> Инвалидность</label>
                                    <label class="es-check"><input type="checkbox" id="large_family" name="large_family" value="1" <?php echo isChecked('large_family'); ?>> Многодетная семья</label>
                                    <label class="es-check"><input type="checkbox" id="social_assistance" name="social_assistance" value="1" <?php echo isChecked('social_assistance'); ?>> Социальная помощь</label>
                                </div>
                            </div>
                        </div>

                        <div class="es-pane" id="pane-activity" style="display:none" role="tabpanel" aria-labelledby="tab-activity">
                            <div class="vs-tab-pane">
                                <div class="es-check-grid">
                                    <label class="es-check"><input type="checkbox" id="hot_meal" name="hot_meal" value="1" <?php echo isChecked('hot_meal'); ?>> Горячее питание</label>
                                    <label class="es-check"><input type="checkbox" id="free_hot_meal" name="free_hot_meal" value="1" <?php echo isChecked('free_hot_meal'); ?>> Бесплатное горячее питание</label>
                                    <label class="es-check"><input type="checkbox" id="youth_committee" name="youth_committee" value="1" <?php echo isChecked('youth_committee'); ?>> Молодёжный комитет</label>
                                    <label class="es-check"><input type="checkbox" id="student_parliament" name="student_parliament" value="1" <?php echo isChecked('student_parliament'); ?>> Студенческий парламент</label>
                                    <label class="es-check"><input type="checkbox" id="jas_sarbaz" name="jas_sarbaz" value="1" <?php echo isChecked('jas_sarbaz'); ?>> Жас Сарбаз</label>
                                </div>
                                <div class="es-form-grid" style="margin-top: 1.25rem;">
                                    <div class="es-field es-field-full">
                                        <label class="es-label" for="competitions">Соревнования / конкурсы</label>
                                        <textarea class="es-textarea" id="competitions" name="competitions" rows="3" placeholder="Укажите участие в соревнованиях и конкурсах"><?php echo $h($student['competitions'] ?? ''); ?></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="es-pane" id="pane-extra" style="display:none" role="tabpanel" aria-labelledby="tab-extra">
                            <div class="vs-tab-pane">
                                <?php
                                $extra_fields = [];
                                if (!empty($dynamic_fields)) {
                                    foreach ($dynamic_fields as $field) {
                                        if (in_array($field['field_name'], $parent_field_keys, true)) {
                                            continue;
                                        }
                                        $extra_fields[] = $field;
                                    }
                                }
                                ?>
                                <?php if ($extra_fields): ?>
                                    <div class="es-form-grid">
                                        <?php foreach ($extra_fields as $field): ?>
                                            <div class="es-field <?php echo $field['field_type'] === 'textarea' ? 'es-field-full' : ''; ?>">
                                                <label class="es-label <?php echo !empty($field['is_required']) ? 'required' : ''; ?>" for="dynamic_<?php echo $h($field['field_name']); ?>">
                                                    <?php echo $h($field['field_label']); ?>
                                                </label>
                                                <?php if ($field['field_type'] === 'textarea'): ?>
                                                    <textarea class="es-textarea" id="dynamic_<?php echo $h($field['field_name']); ?>" name="dynamic_fields[<?php echo $h($field['field_name']); ?>]" rows="3" <?php echo !empty($field['is_required']) ? 'required' : ''; ?>><?php echo $h($student_dynamic_values[$field['field_name']] ?? ''); ?></textarea>
                                                <?php elseif ($field['field_type'] === 'select'): ?>
                                                    <select class="es-select" id="dynamic_<?php echo $h($field['field_name']); ?>" name="dynamic_fields[<?php echo $h($field['field_name']); ?>]" <?php echo !empty($field['is_required']) ? 'required' : ''; ?>>
                                                        <option value="">Выберите значение</option>
                                                        <?php foreach ((json_decode($field['field_options'], true) ?? []) as $option): ?>
                                                            <option value="<?php echo $h($option); ?>" <?php echo ($student_dynamic_values[$field['field_name']] ?? '') === $option ? 'selected' : ''; ?>><?php echo $h($option); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <input class="es-input" type="<?php echo $h($field['field_type']); ?>" id="dynamic_<?php echo $h($field['field_name']); ?>" name="dynamic_fields[<?php echo $h($field['field_name']); ?>]" value="<?php echo $h($student_dynamic_values[$field['field_name']] ?? ''); ?>" <?php echo !empty($field['is_required']) ? 'required' : ''; ?>>
                                                <?php endif; ?>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Дополнительных полей нет</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="es-sticky-bar" id="editStickyBar">
                    <div class="es-sticky-hint">Ctrl+S — сохранить · Esc — к просмотру</div>
                    <div class="es-sticky-actions">
                        <button type="button" class="btn btn-outline-secondary" id="btnResetForm">Сбросить</button>
                        <a class="btn btn-outline-secondary" href="<?php echo $h($view_student_url($student['id'])); ?>">Отмена</a>
                        <button type="submit" class="btn btn-primary" id="btnSaveForm">
                            <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Сохранить
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="assets/js/curator-ui.js"></script>
</body>
</html>
