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

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: students.php');
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
    header('Location: students.php');
    exit;
}

// Проверяем доступ
$can_edit_all = $permissionChecker->hasPermission($current_user['id'], 'edit_students')
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
                            $update_sql = "UPDATE students SET 
                                iin = ?, first_name = ?, middle_name = ?, last_name = ?, 
                                birth_date = ?, gender = ?, nationality = ?, phone = ?, email = ?,
                                permanent_address_ru = ?, temporary_address_ru = ?,
                                specialty = ?, course = ?, language = ?, study_form = ?, study_duration = ?,
                                group_code = ?, enrollment_order_number = ?, arrival_date = ?, arrival_from = ?,
                                education_type = ?, residence_type = ?,
                                orphan = ?, without_parental_care = ?, disability = ?, large_family = ?, social_assistance = ?,
                                hot_meal = ?, free_hot_meal = ?, youth_committee = ?, student_parliament = ?, jas_sarbaz = ?,
                                practice_type = ?, competitions = ?,
                                updated_at = NOW()
                                WHERE id = ?";

                            $update_stmt = $db->prepare($update_sql);

                            // Подготавливаем значения для чекбоксов
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
                                'ssssssssssssssssssssssiiiiiiiiiissi',
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
                            $_SESSION['success_message'] = '✅ Данные студента ' . $student_name . ' успешно обновлены!';
                            $_SESSION['success_details'] = 'Все изменения сохранены в базе данных. Студент: ИИН ' . htmlspecialchars($student['iin']);

                            // Перенаправляем на страницу списка студентов
                            header('Location: students.php');
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

function isChecked($field)
{
    global $student;
    return isset($student[$field]) && $student[$field] ? 'checked' : '';
}

// Функция для перевода ошибок базы данных на русский язык
function translateDatabaseError($error_message)
{
    return ErrorTranslator::translate($error_message);
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать студента: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?> - <?php echo APP_NAME; ?></title>

    <!-- Favicon -->
    <link rel="icon" type="image/svg+xml" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>✏️</text></svg>">

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">

    <style>
        :root {
            /* Современная цветовая система для редактирования */
            --primary: #6366f1;
            --primary-dark: #4f46e5;
            --primary-light: #a5b4fc;
            --secondary: #64748b;
            --accent: #f59e0b;
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --info: #06b6d4;

            /* Нейтральные цвета */
            --white: #ffffff;
            --gray-50: #f8fafc;
            --gray-100: #f1f5f9;
            --gray-200: #e2e8f0;
            --gray-300: #cbd5e1;
            --gray-400: #94a3b8;
            --gray-500: #64748b;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1e293b;
            --gray-900: #0f172a;

            /* Spacing */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 3rem;

            /* Typography */
            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;

            /* Shadows */
            --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);

            /* Border radius */
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
            --radius-2xl: 2rem;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--gray-100) 100%);
            color: var(--gray-900);
            line-height: 1.6;
        }

        /* Новая структура: Form Layout */
        .form-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: var(--space-xl);
        }

        /* Hero Header для редактирования */
        .edit-hero {
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            border-radius: var(--radius-2xl);
            padding: var(--space-2xl);
            margin-bottom: var(--space-2xl);
            color: var(--white);
            position: relative;
            overflow: hidden;
        }

        .edit-hero::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background:
                radial-gradient(circle at 20% 80%, rgba(255, 255, 255, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 20%, rgba(255, 255, 255, 0.05) 0%, transparent 50%);
            pointer-events: none;
        }

        .hero-content {
            position: relative;
            z-index: 2;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: var(--space-lg);
        }

        .hero-info h1 {
            font-size: var(--text-3xl);
            font-weight: 800;
            margin-bottom: var(--space-sm);
        }

        .hero-meta {
            display: flex;
            gap: var(--space-md);
            flex-wrap: wrap;
        }

        .meta-badge {
            background: rgba(255, 255, 255, 0.15);
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-lg);
            font-size: var(--text-sm);
            font-weight: 600;
            backdrop-filter: blur(10px);
        }

        .hero-actions {
            display: flex;
            gap: var(--space-md);
        }

        .btn-hero {
            padding: var(--space-md) var(--space-xl);
            border-radius: var(--radius-lg);
            font-weight: 600;
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            text-decoration: none;
        }

        .btn-primary-hero {
            background: var(--white);
            color: var(--primary);
        }

        .btn-primary-hero:hover {
            background: var(--gray-100);
            transform: translateY(-2px);
            color: var(--primary-dark);
        }

        .btn-secondary-hero {
            background: rgba(255, 255, 255, 0.1);
            color: var(--white);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }

        .btn-secondary-hero:hover {
            background: rgba(255, 255, 255, 0.2);
            color: var(--white);
        }

        /* Современные секции формы */
        .form-section {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-2xl);
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        .form-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary), var(--accent));
        }

        .section-header {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-md);
            border-bottom: 2px solid var(--gray-100);
        }

        .section-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary), var(--primary-dark));
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-xl);
        }

        .section-title {
            font-size: var(--text-xl);
            font-weight: 700;
            color: var(--gray-800);
            margin: 0;
        }

        .section-subtitle {
            font-size: var(--text-sm);
            color: var(--gray-600);
            margin: 0;
        }

        /* Современные поля формы */
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-lg);
        }

        .form-group-new {
            position: relative;
        }

        .form-label-new {
            font-weight: 600;
            color: var(--gray-700);
            margin-bottom: var(--space-sm);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--text-sm);
        }

        .form-label-new.required::after {
            content: '*';
            color: var(--danger);
            font-weight: 700;
        }

        .form-control-new,
        .form-select-new {
            width: 100%;
            padding: var(--space-md);
            border: 2px solid var(--gray-200);
            border-radius: var(--radius-lg);
            font-size: var(--text-base);
            transition: all 0.3s ease;
            background: var(--white);
        }

        .form-control-new:focus,
        .form-select-new:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1);
            transform: translateY(-1px);
        }

        .form-control-new.is-valid {
            border-color: var(--success);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 8'%3e%3cpath fill='%2328a745' d='m2.3 6.73.94-.94 2.94-2.94.94.94-3.88 3.88z'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        .form-control-new.is-invalid {
            border-color: var(--danger);
            background-image: url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 12 12' width='12' height='12' fill='none' stroke='%23dc3545'%3e%3ccircle cx='6' cy='6' r='4.5'/%3e%3cpath d='m5.8 4.6 1.4 1.4m0-1.4-1.4 1.4'/%3e%3c/svg%3e");
            background-repeat: no-repeat;
            background-position: right 12px center;
            background-size: 16px;
        }

        /* Современные чекбоксы */
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: var(--space-md);
            margin-top: var(--space-md);
        }

        .checkbox-item {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            padding: var(--space-md);
            background: var(--gray-50);
            border-radius: var(--radius-lg);
            border: 2px solid var(--gray-200);
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .checkbox-item:hover {
            background: var(--primary-light);
            border-color: var(--primary);
            transform: translateY(-2px);
        }

        .checkbox-item input[type="checkbox"] {
            width: 20px;
            height: 20px;
            accent-color: var(--primary);
        }

        .checkbox-label {
            font-weight: 500;
            color: var(--gray-700);
            cursor: pointer;
        }

        /* Кнопки действий */
        .form-actions {
            background: var(--white);
            padding: var(--space-xl);
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: var(--space-lg);
            margin-top: var(--space-2xl);
        }

        .btn-form {
            padding: var(--space-md) var(--space-xl);
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: var(--text-base);
            border: none;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            text-decoration: none;
            min-width: 140px;
            justify-content: center;
        }

        .btn-save {
            background: linear-gradient(135deg, var(--success), #059669);
            color: var(--white);
            box-shadow: var(--shadow-md);
        }

        .btn-save:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            color: var(--white);
        }

        .btn-cancel {
            background: var(--gray-200);
            color: var(--gray-700);
        }

        .btn-cancel:hover {
            background: var(--gray-300);
            color: var(--gray-800);
        }

        .btn-reset {
            background: linear-gradient(135deg, var(--warning), #d97706);
            color: var(--white);
        }

        .btn-reset:hover {
            transform: translateY(-2px);
            color: var(--white);
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .form-container {
                padding: var(--space-lg);
            }

            .edit-hero {
                padding: var(--space-xl);
                text-align: center;
            }

            .hero-content {
                flex-direction: column;
                text-align: center;
            }

            .form-grid {
                grid-template-columns: 1fr;
            }

            .checkbox-group {
                grid-template-columns: 1fr;
            }

            .form-actions {
                flex-direction: column;
                text-align: center;
            }

            .btn-form {
                width: 100%;
            }
        }

        /* Плавающая кнопка сохранения */
        .floating-save-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            z-index: 1000;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 50px;
            padding: 15px 25px;
            font-size: 16px;
            font-weight: 600;
            box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .floating-save-btn:hover {
            background: #218838;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(40, 167, 69, 0.4);
            color: white;
        }

        .floating-save-btn:active {
            transform: translateY(0);
        }

        /* Анимации */
        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes pulse {
            0% {
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            }

            50% {
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.6);
            }

            100% {
                box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
            }
        }

        .floating-save-btn.pulse {
            animation: pulse 2s infinite;
        }

        .form-section {
            animation: slideInUp 0.6s ease-out;
        }

        .form-section:nth-child(1) {
            animation-delay: 0.1s;
        }

        .form-section:nth-child(2) {
            animation-delay: 0.2s;
        }

        .form-section:nth-child(3) {
            animation-delay: 0.3s;
        }

        .form-section:nth-child(4) {
            animation-delay: 0.4s;
        }

        .form-section:nth-child(5) {
            animation-delay: 0.5s;
        }

        /* ============================================
           MODERN FLAT DESIGN OVERRIDES
           ============================================ */
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary-color: #2563eb !important;
            --secondary-color: #10b981 !important;
            --accent-color: #f59e0b !important;
            --dark-bg: #1e293b !important;
            --light-bg: #f8fafc !important;
            --card-bg: #ffffff !important;
            --text-primary: #0f172a !important;
            --text-secondary: #64748b !important;
            --border-color: #e2e8f0 !important;
        }

        body {
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif !important;
            background: var(--light-bg) !important;
        }

        .navbar {
            background: var(--card-bg) !important;
            border-bottom: 3px solid var(--primary-color) !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07) !important;
        }

        .navbar-brand {
            color: var(--primary-color) !important;
            font-weight: 700 !important;
        }

        .nav-link {
            color: var(--text-primary) !important;
            border-radius: 8px !important;
            font-weight: 500 !important;
        }

        .nav-link:hover {
            background: var(--light-bg) !important;
            color: var(--primary-color) !important;
        }

        .nav-link.active {
            background: var(--primary-color) !important;
            color: white !important;
        }

        .card,
        .form-section {
            border-radius: 16px !important;
            border: none !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07) !important;
            background: var(--card-bg) !important;
        }

        .card-header {
            background: var(--light-bg) !important;
            border-bottom: 2px solid var(--border-color) !important;
            border-radius: 16px 16px 0 0 !important;
        }

        .btn-primary {
            background: var(--primary-color) !important;
            border: none !important;
            border-radius: 8px !important;
            font-weight: 600 !important;
        }

        .btn-primary:hover {
            background: #1d4ed8 !important;
            transform: translateY(-2px) !important;
        }

        .btn-success {
            background: var(--secondary-color) !important;
            border: none !important;
        }

        .btn-success:hover {
            background: #059669 !important;
        }

        .form-control,
        .form-select {
            border-radius: 8px !important;
            border: 2px solid var(--border-color) !important;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color) !important;
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1) !important;
        }

        .form-label {
            font-weight: 600 !important;
            color: var(--text-primary) !important;
        }

        .badge {
            border-radius: 6px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
        }
    </style>
</head>

<body>
    <div class="container-fluid">
        <div class="sb-layout">
            <!-- Сайдбар -->
            <?php include 'includes/sidebar.php'; ?>

            <!-- Основной контент -->
            <main class="sb-content" role="main">
                <div class="form-container">
                    <!-- Hero Header -->
                    <div class="edit-hero">
                        <div class="hero-content">
                            <div class="hero-info">
                                <h1>Редактировать студента</h1>
                                <div class="hero-meta">
                                    <span class="meta-badge">
                                        <i class="bi bi-person me-1"></i>
                                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?>
                                    </span>
                                    <span class="meta-badge">
                                        <i class="bi bi-credit-card me-1"></i>
                                        ИИН: <?php echo htmlspecialchars($student['iin']); ?>
                                    </span>
                                    <span class="meta-badge">
                                        <i class="bi bi-people me-1"></i>
                                        ID: <?php echo $student['id']; ?>
                                    </span>
                                </div>
                            </div>
                            <div class="hero-actions">
                                <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn-hero btn-secondary-hero">
                                    <i class="bi bi-eye"></i>
                                    Просмотр
                                </a>
                                <a href="students.php" class="btn-hero btn-primary-hero">
                                    <i class="bi bi-arrow-left"></i>
                                    К списку
                                </a>
                            </div>
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
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo $error; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <!-- Форма редактирования -->
                    <form method="POST" id="edit-student-form" novalidate>
                        <input type="hidden" name="action" value="update">
                        <input type="hidden" name="student_id" value="<?php echo $student['id']; ?>">

                        <!-- КНОПКА СОХРАНЕНИЯ ВВЕРХУ -->
                        <div class="text-center mb-4" style="padding: 20px; background: #e8f5e8; border-radius: 10px; border: 2px solid #28a745;">
                            <button type="submit" class="btn btn-success btn-lg px-4 py-2" style="font-size: 16px; font-weight: 600; min-width: 180px;">
                                <i class="bi bi-check-circle me-2"></i>
                                💾 Сохранить изменения
                            </button>
                            <div class="mt-2">
                                <small class="text-success"><strong>Совет:</strong> Используйте Ctrl+S для быстрого сохранения</small>
                            </div>
                        </div>

                        <!-- Основная информация -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-person-badge"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Основная информация</h2>
                                    <p class="section-subtitle">Личные данные и идентификация</p>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group-new">
                                    <label for="iin" class="form-label-new required">
                                        <i class="bi bi-credit-card"></i>
                                        ИИН
                                    </label>
                                    <input type="text" class="form-control-new" id="iin" name="iin"
                                        value="<?php echo htmlspecialchars($student['iin']); ?>"
                                        maxlength="12" pattern="[0-9]{12}" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="first_name" class="form-label-new required">
                                        <i class="bi bi-person"></i>
                                        Имя
                                    </label>
                                    <input type="text" class="form-control-new" id="first_name" name="first_name"
                                        value="<?php echo htmlspecialchars($student['first_name']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="middle_name" class="form-label-new required">
                                        <i class="bi bi-person"></i>
                                        Отчество
                                    </label>
                                    <input type="text" class="form-control-new" id="middle_name" name="middle_name"
                                        value="<?php echo htmlspecialchars($student['middle_name']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="last_name" class="form-label-new">
                                        <i class="bi bi-person"></i>
                                        Фамилия
                                    </label>
                                    <input type="text" class="form-control-new" id="last_name" name="last_name"
                                        value="<?php echo htmlspecialchars($student['last_name'] ?? ''); ?>">
                                </div>
                                <div class="form-group-new">
                                    <label for="birth_date" class="form-label-new required">
                                        <i class="bi bi-calendar"></i>
                                        Дата рождения
                                    </label>
                                    <input type="date" class="form-control-new" id="birth_date" name="birth_date"
                                        value="<?php echo htmlspecialchars($student['birth_date']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="gender" class="form-label-new required">
                                        <i class="bi bi-gender-ambiguous"></i>
                                        Пол
                                    </label>
                                    <select class="form-select-new" id="gender" name="gender" required>
                                        <option value="">Выберите пол</option>
                                        <option value="мужской" <?php echo isSelected('gender', 'мужской'); ?>>Мужской</option>
                                        <option value="женский" <?php echo isSelected('gender', 'женский'); ?>>Женский</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="nationality" class="form-label-new required">
                                        <i class="bi bi-globe"></i>
                                        Национальность
                                    </label>
                                    <input type="text" class="form-control-new" id="nationality" name="nationality"
                                        value="<?php echo htmlspecialchars($student['nationality']); ?>" required>
                                </div>
                            </div>
                        </div>

                        <!-- Контактная информация -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-telephone"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Контактная информация</h2>
                                    <p class="section-subtitle">Телефон, email и адреса</p>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group-new">
                                    <label for="phone" class="form-label-new required">
                                        <i class="bi bi-telephone"></i>
                                        Телефон
                                    </label>
                                    <input type="tel" class="form-control-new" id="phone" name="phone"
                                        value="<?php echo htmlspecialchars($student['phone']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="email" class="form-label-new required">
                                        <i class="bi bi-envelope"></i>
                                        Email
                                    </label>
                                    <input type="email" class="form-control-new" id="email" name="email"
                                        value="<?php echo htmlspecialchars($student['email']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="permanent_address_ru" class="form-label-new">
                                        <i class="bi bi-geo-alt"></i>
                                        Постоянный адрес
                                    </label>
                                    <textarea class="form-control-new" id="permanent_address_ru" name="permanent_address_ru" rows="3"><?php echo htmlspecialchars($student['permanent_address_ru'] ?? ''); ?></textarea>
                                </div>
                                <div class="form-group-new">
                                    <label for="temporary_address_ru" class="form-label-new">
                                        <i class="bi bi-geo"></i>
                                        Временный адрес
                                    </label>
                                    <textarea class="form-control-new" id="temporary_address_ru" name="temporary_address_ru" rows="3"><?php echo htmlspecialchars($student['temporary_address_ru'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Обучение -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-book"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Информация об обучении</h2>
                                    <p class="section-subtitle">Специальность, курс и параметры обучения</p>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group-new">
                                    <label for="specialty" class="form-label-new required">
                                        <i class="bi bi-award"></i>
                                        Специальность
                                    </label>
                                    <input type="text" class="form-control-new" id="specialty" name="specialty"
                                        value="<?php echo htmlspecialchars($student['specialty']); ?>" required>
                                </div>
                                <div class="form-group-new">
                                    <label for="course" class="form-label-new required">
                                        <i class="bi bi-mortarboard"></i>
                                        Курс
                                    </label>
                                    <select class="form-select-new" id="course" name="course" required>
                                        <option value="">Выберите курс</option>
                                        <option value="1 курс" <?php echo isSelected('course', '1 курс'); ?>>1 курс</option>
                                        <option value="2 курс" <?php echo isSelected('course', '2 курс'); ?>>2 курс</option>
                                        <option value="3 курс" <?php echo isSelected('course', '3 курс'); ?>>3 курс</option>
                                        <option value="4 курс" <?php echo isSelected('course', '4 курс'); ?>>4 курс</option>
                                        <option value="5 курс" <?php echo isSelected('course', '5 курс'); ?>>5 курс</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="language" class="form-label-new required">
                                        <i class="bi bi-translate"></i>
                                        Язык обучения
                                    </label>
                                    <select class="form-select-new" id="language" name="language" required>
                                        <option value="">Выберите язык</option>
                                        <option value="казахский" <?php echo isSelected('language', 'казахский'); ?>>Казахский</option>
                                        <option value="русский" <?php echo isSelected('language', 'русский'); ?>>Русский</option>
                                        <option value="английский" <?php echo isSelected('language', 'английский'); ?>>Английский</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="study_form" class="form-label-new required">
                                        <i class="bi bi-calendar-check"></i>
                                        Форма обучения
                                    </label>
                                    <select class="form-select-new" id="study_form" name="study_form" required>
                                        <option value="">Выберите форму</option>
                                        <option value="очная" <?php echo isSelected('study_form', 'очная'); ?>>Очная</option>
                                        <option value="заочная" <?php echo isSelected('study_form', 'заочная'); ?>>Заочная</option>
                                        <option value="вечерняя" <?php echo isSelected('study_form', 'вечерняя'); ?>>Вечерняя</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="study_duration" class="form-label-new required">
                                        <i class="bi bi-clock"></i>
                                        Срок обучения
                                    </label>
                                    <select class="form-select-new" id="study_duration" name="study_duration" required>
                                        <option value="">Выберите срок</option>
                                        <option value="1 год" <?php echo isSelected('study_duration', '1 год'); ?>>1 год</option>
                                        <option value="2 года" <?php echo isSelected('study_duration', '2 года'); ?>>2 года</option>
                                        <option value="3 года" <?php echo isSelected('study_duration', '3 года'); ?>>3 года</option>
                                        <option value="4 года" <?php echo isSelected('study_duration', '4 года'); ?>>4 года</option>
                                        <option value="5 лет" <?php echo isSelected('study_duration', '5 лет'); ?>>5 лет</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="group_code" class="form-label-new">
                                        <i class="bi bi-tag"></i>
                                        Код группы
                                    </label>
                                    <input type="text" class="form-control-new" id="group_code" name="group_code"
                                        value="<?php echo htmlspecialchars($student['group_code']); ?>">
                                </div>
                            </div>
                        </div>

                        <!-- Документы и зачисление -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-file-earmark-text"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Документы и зачисление</h2>
                                    <p class="section-subtitle">Приказы, даты и типы зачисления</p>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group-new">
                                    <label for="enrollment_order_number" class="form-label-new">
                                        <i class="bi bi-file-earmark"></i>
                                        Номер приказа
                                    </label>
                                    <input type="text" class="form-control-new" id="enrollment_order_number" name="enrollment_order_number"
                                        value="<?php echo htmlspecialchars($student['enrollment_order_number'] ?? ''); ?>">
                                </div>
                                <div class="form-group-new">
                                    <label for="arrival_date" class="form-label-new">
                                        <i class="bi bi-calendar-plus"></i>
                                        Дата зачисления
                                    </label>
                                    <input type="date" class="form-control-new" id="arrival_date" name="arrival_date"
                                        value="<?php echo htmlspecialchars($student['arrival_date'] ?? ''); ?>">
                                </div>
                                <div class="form-group-new">
                                    <label for="arrival_from" class="form-label-new">
                                        <i class="bi bi-arrow-right"></i>
                                        Прибыл из
                                    </label>
                                    <select class="form-select-new" id="arrival_from" name="arrival_from">
                                        <option value="">Выберите откуда</option>
                                        <option value="данного района (города, села) данной области" <?php echo isSelected('arrival_from', 'данного района (города, села) данной области'); ?>>Данного района (города, села) данной области</option>
                                        <option value="другого района (города) данной области" <?php echo isSelected('arrival_from', 'другого района (города) данной области'); ?>>Другого района (города) данной области</option>
                                        <option value="другой области" <?php echo isSelected('arrival_from', 'другой области'); ?>>Другой области</option>
                                        <option value="другого государства СНГ" <?php echo isSelected('arrival_from', 'другого государства СНГ'); ?>>Другого государства СНГ</option>
                                        <option value="дальнего зарубежья" <?php echo isSelected('arrival_from', 'дальнего зарубежья'); ?>>Дальнего зарубежья</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="education_type" class="form-label-new">
                                        <i class="bi bi-mortarboard"></i>
                                        Тип образования
                                    </label>
                                    <select class="form-select-new" id="education_type" name="education_type">
                                        <option value="">Выберите тип</option>
                                        <option value="основная школа" <?php echo isSelected('education_type', 'основная школа'); ?>>Основная школа</option>
                                        <option value="средняя школа" <?php echo isSelected('education_type', 'средняя школа'); ?>>Средняя школа</option>
                                        <option value="организация ТиПО" <?php echo isSelected('education_type', 'организация ТиПО'); ?>>Организация ТиПО</option>
                                        <option value="ВУЗ" <?php echo isSelected('education_type', 'ВУЗ'); ?>>ВУЗ</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="residence_type" class="form-label-new">
                                        <i class="bi bi-house"></i>
                                        Тип местности
                                    </label>
                                    <select class="form-select-new" id="residence_type" name="residence_type">
                                        <option value="">Выберите тип</option>
                                        <option value="городская местность" <?php echo isSelected('residence_type', 'городская местность'); ?>>Городская местность</option>
                                        <option value="сельская местность" <?php echo isSelected('residence_type', 'сельская местность'); ?>>Сельская местность</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Социальные категории -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-shield-check"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Социальные категории</h2>
                                    <p class="section-subtitle">Льготы, статусы и категории</p>
                                </div>
                            </div>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="orphan" name="orphan" value="1" <?php echo isChecked('orphan'); ?>>
                                    <label for="orphan" class="checkbox-label">
                                        <i class="bi bi-heart me-1"></i>
                                        Сирота
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="without_parental_care" name="without_parental_care" value="1" <?php echo isChecked('without_parental_care'); ?>>
                                    <label for="without_parental_care" class="checkbox-label">
                                        <i class="bi bi-people me-1"></i>
                                        Без попечения родителей
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="disability" name="disability" value="1" <?php echo isChecked('disability'); ?>>
                                    <label for="disability" class="checkbox-label">
                                        <i class="bi bi-universal-access me-1"></i>
                                        Инвалидность
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="large_family" name="large_family" value="1" <?php echo isChecked('large_family'); ?>>
                                    <label for="large_family" class="checkbox-label">
                                        <i class="bi bi-house-heart me-1"></i>
                                        Многодетная семья
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="social_assistance" name="social_assistance" value="1" <?php echo isChecked('social_assistance'); ?>>
                                    <label for="social_assistance" class="checkbox-label">
                                        <i class="bi bi-life-preserver me-1"></i>
                                        Социальная помощь
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Питание и активности -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-cup-hot"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Питание и активности</h2>
                                    <p class="section-subtitle">Участие в организациях и программах</p>
                                </div>
                            </div>
                            <div class="checkbox-group">
                                <div class="checkbox-item">
                                    <input type="checkbox" id="hot_meal" name="hot_meal" value="1" <?php echo isChecked('hot_meal'); ?>>
                                    <label for="hot_meal" class="checkbox-label">
                                        <i class="bi bi-cup-hot me-1"></i>
                                        Горячее питание
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="free_hot_meal" name="free_hot_meal" value="1" <?php echo isChecked('free_hot_meal'); ?>>
                                    <label for="free_hot_meal" class="checkbox-label">
                                        <i class="bi bi-gift me-1"></i>
                                        Бесплатное горячее питание
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="youth_committee" name="youth_committee" value="1" <?php echo isChecked('youth_committee'); ?>>
                                    <label for="youth_committee" class="checkbox-label">
                                        <i class="bi bi-people me-1"></i>
                                        Молодежный комитет
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="student_parliament" name="student_parliament" value="1" <?php echo isChecked('student_parliament'); ?>>
                                    <label for="student_parliament" class="checkbox-label">
                                        <i class="bi bi-building me-1"></i>
                                        Студенческий парламент
                                    </label>
                                </div>
                                <div class="checkbox-item">
                                    <input type="checkbox" id="jas_sarbaz" name="jas_sarbaz" value="1" <?php echo isChecked('jas_sarbaz'); ?>>
                                    <label for="jas_sarbaz" class="checkbox-label">
                                        <i class="bi bi-shield me-1"></i>
                                        Жас Сарбаз
                                    </label>
                                </div>
                            </div>

                            <div class="form-grid" style="margin-top: var(--space-xl);">
                                <div class="form-group-new">
                                    <label for="practice_type" class="form-label-new">
                                        <i class="bi bi-briefcase"></i>
                                        Тип практики
                                    </label>
                                    <select class="form-select-new" id="practice_type" name="practice_type">
                                        <option value="">Выберите тип</option>
                                        <option value="производственная" <?php echo isSelected('practice_type', 'производственная'); ?>>Производственная</option>
                                        <option value="учебная" <?php echo isSelected('practice_type', 'учебная'); ?>>Учебная</option>
                                        <option value="преддипломная" <?php echo isSelected('practice_type', 'преддипломная'); ?>>Преддипломная</option>
                                        <option value="не проходит" <?php echo isSelected('practice_type', 'не проходит'); ?>>Не проходит</option>
                                    </select>
                                </div>
                                <div class="form-group-new">
                                    <label for="competitions" class="form-label-new">
                                        <i class="bi bi-trophy"></i>
                                        Соревнования/конкурсы
                                    </label>
                                    <textarea class="form-control-new" id="competitions" name="competitions" rows="3" placeholder="Укажите участие в соревнованиях и конкурсах"><?php echo htmlspecialchars($student['competitions'] ?? ''); ?></textarea>
                                </div>
                            </div>
                        </div>

                        <!-- Информация о родителях -->
                        <div class="form-section">
                            <div class="section-header">
                                <div class="section-icon">
                                    <i class="bi bi-people"></i>
                                </div>
                                <div>
                                    <h2 class="section-title">Информация о родителях</h2>
                                    <p class="section-subtitle">Контактные данные и место работы родителей</p>
                                </div>
                            </div>
                            <div class="form-grid">
                                <!-- Информация об отце -->
                                <div class="form-group-new">
                                    <label for="father_full_name" class="form-label-new">
                                        <i class="bi bi-person"></i>
                                        ФИО отца
                                    </label>
                                    <input type="text" class="form-control-new" id="father_full_name" name="dynamic_fields[father_full_name]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['father_full_name'] ?? ''); ?>"
                                        placeholder="Введите ФИО отца">
                                </div>
                                <div class="form-group-new">
                                    <label for="father_phone" class="form-label-new">
                                        <i class="bi bi-telephone"></i>
                                        Телефон отца
                                    </label>
                                    <input type="tel" class="form-control-new" id="father_phone" name="dynamic_fields[father_phone]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['father_phone'] ?? ''); ?>"
                                        placeholder="Введите телефон отца">
                                </div>
                                <div class="form-group-new">
                                    <label for="father_workplace" class="form-label-new">
                                        <i class="bi bi-briefcase"></i>
                                        Место работы отца
                                    </label>
                                    <input type="text" class="form-control-new" id="father_workplace" name="dynamic_fields[father_workplace]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['father_workplace'] ?? ''); ?>"
                                        placeholder="Введите место работы отца">
                                </div>
                                <!-- Информация о матери -->
                                <div class="form-group-new">
                                    <label for="mother_full_name" class="form-label-new">
                                        <i class="bi bi-person"></i>
                                        ФИО матери
                                    </label>
                                    <input type="text" class="form-control-new" id="mother_full_name" name="dynamic_fields[mother_full_name]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['mother_full_name'] ?? ''); ?>"
                                        placeholder="Введите ФИО матери">
                                </div>
                                <div class="form-group-new">
                                    <label for="mother_phone" class="form-label-new">
                                        <i class="bi bi-telephone"></i>
                                        Телефон матери
                                    </label>
                                    <input type="tel" class="form-control-new" id="mother_phone" name="dynamic_fields[mother_phone]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['mother_phone'] ?? ''); ?>"
                                        placeholder="Введите телефон матери">
                                </div>
                                <div class="form-group-new">
                                    <label for="mother_workplace" class="form-label-new">
                                        <i class="bi bi-briefcase"></i>
                                        Место работы матери
                                    </label>
                                    <input type="text" class="form-control-new" id="mother_workplace" name="dynamic_fields[mother_workplace]"
                                        value="<?php echo htmlspecialchars($student_dynamic_values['mother_workplace'] ?? ''); ?>"
                                        placeholder="Введите место работы матери">
                                </div>
                            </div>
                        </div>

                        <!-- Дополнительные поля -->
                        <?php if (!empty($dynamic_fields)): ?>
                            <div class="form-section">
                                <div class="section-header">
                                    <div class="section-icon">
                                        <i class="bi bi-gear"></i>
                                    </div>
                                    <div>
                                        <h2 class="section-title">Дополнительные поля</h2>
                                        <p class="section-subtitle">Пользовательские поля</p>
                                    </div>
                                </div>
                                <div class="form-grid">
                                    <?php foreach ($dynamic_fields as $field): ?>
                                        <div class="form-group-new">
                                            <label for="dynamic_<?php echo $field['field_name']; ?>" class="form-label-new <?php echo $field['is_required'] ? 'required' : ''; ?>">
                                                <i class="bi bi-info-circle"></i>
                                                <?php echo htmlspecialchars($field['field_label']); ?>
                                            </label>
                                            <?php if ($field['field_type'] === 'textarea'): ?>
                                                <textarea class="form-control-new" id="dynamic_<?php echo $field['field_name']; ?>"
                                                    name="dynamic_fields[<?php echo $field['field_name']; ?>]"
                                                    rows="3" <?php echo $field['is_required'] ? 'required' : ''; ?>><?php echo htmlspecialchars($student_dynamic_values[$field['field_name']] ?? ''); ?></textarea>
                                            <?php elseif ($field['field_type'] === 'select'): ?>
                                                <select class="form-select-new" id="dynamic_<?php echo $field['field_name']; ?>"
                                                    name="dynamic_fields[<?php echo $field['field_name']; ?>]"
                                                    <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                                    <option value="">Выберите значение</option>
                                                    <?php
                                                    $options = json_decode($field['field_options'], true) ?? [];
                                                    foreach ($options as $option):
                                                    ?>
                                                        <option value="<?php echo htmlspecialchars($option); ?>"
                                                            <?php echo ($student_dynamic_values[$field['field_name']] ?? '') === $option ? 'selected' : ''; ?>>
                                                            <?php echo htmlspecialchars($option); ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="<?php echo $field['field_type']; ?>" class="form-control-new"
                                                    id="dynamic_<?php echo $field['field_name']; ?>"
                                                    name="dynamic_fields[<?php echo $field['field_name']; ?>]"
                                                    value="<?php echo htmlspecialchars($student_dynamic_values[$field['field_name']] ?? ''); ?>"
                                                    <?php echo $field['is_required'] ? 'required' : ''; ?>>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <!-- Кнопки действий -->
                        <div class="form-actions">
                            <div>
                                <button type="button" class="btn-form btn-reset" onclick="resetForm()">
                                    <i class="bi bi-arrow-clockwise"></i>
                                    Сбросить изменения
                                </button>
                            </div>
                            <div style="display: flex; gap: var(--space-md);">
                                <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn-form btn-cancel">
                                    <i class="bi bi-x-circle"></i>
                                    Отмена
                                </a>
                                <button type="submit" class="btn-form btn-save">
                                    <i class="bi bi-check-circle"></i>
                                    Сохранить изменения
                                </button>
                            </div>
                        </div>

                        <!-- ПРОСТАЯ КНОПКА СОХРАНЕНИЯ -->
                        <div class="text-center mt-4 mb-4" style="padding: 30px; background: #f8f9fa; border-radius: 15px; border: 2px solid #dee2e6;">
                            <button type="submit" class="btn btn-success btn-lg px-5 py-3" style="font-size: 18px; font-weight: 600; min-width: 200px; box-shadow: 0 4px 8px rgba(0,0,0,0.1);">
                                <i class="bi bi-check-circle me-2"></i>
                                💾 Сохранить изменения
                            </button>
                            <div class="mt-3">
                                <small class="text-muted">Нажмите для сохранения всех изменений</small>
                            </div>
                        </div>
                    </form>
                </div>
            </main>
        </div>
    </div>

    <!-- ПЛАВАЮЩАЯ КНОПКА СОХРАНЕНИЯ -->
    <button type="button" class="floating-save-btn" id="floating-save" onclick="document.getElementById('edit-student-form').submit();">
        <i class="bi bi-check-circle me-2"></i>
        💾 Сохранить
    </button>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✅ Форма редактирования студента загружена');

            // Анимация появления секций
            const sections = document.querySelectorAll('.form-section');
            sections.forEach((section, index) => {
                section.style.opacity = '0';
                section.style.transform = 'translateY(30px)';

                setTimeout(() => {
                    section.style.transition = 'all 0.6s ease-out';
                    section.style.opacity = '1';
                    section.style.transform = 'translateY(0)';
                }, index * 150);
            });

            // Валидация формы в реальном времени
            const form = document.getElementById('edit-student-form');
            const inputs = form.querySelectorAll('input, select, textarea');

            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    validateField(this);
                });

                input.addEventListener('blur', function() {
                    validateField(this);
                });
            });

            function validateField(field) {
                const value = field.value.trim();
                const isRequired = field.hasAttribute('required');

                // Убираем предыдущие классы
                field.classList.remove('is-valid', 'is-invalid');

                if (isRequired && !value) {
                    field.classList.add('is-invalid');
                } else if (value) {
                    // Специальная валидация для разных типов полей
                    let isValid = true;

                    if (field.type === 'email') {
                        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                        isValid = emailRegex.test(value);
                    } else if (field.name === 'iin') {
                        isValid = /^\d{12}$/.test(value);
                    } else if (field.type === 'tel') {
                        isValid = /^[\d\s\+\-\(\)]+$/.test(value);
                    }

                    if (isValid) {
                        field.classList.add('is-valid');
                    } else {
                        field.classList.add('is-invalid');
                    }
                }
            }

            // Функция сброса формы
            window.resetForm = function() {
                if (confirm('Вы уверены, что хотите сбросить все изменения?')) {
                    form.reset();
                    inputs.forEach(input => {
                        input.classList.remove('is-valid', 'is-invalid');
                    });
                }
            };

            // Горячие клавиши
            document.addEventListener('keydown', function(e) {
                if (e.ctrlKey && e.key === 's') {
                    e.preventDefault();
                    form.submit();
                    console.log('Горячая клавиша: Сохранить (Ctrl+S)');
                }

                if (e.key === 'Escape') {
                    const cancelBtn = document.querySelector('.btn-cancel');
                    if (cancelBtn) {
                        cancelBtn.click();
                    }
                }

                if (e.ctrlKey && e.key === 'r') {
                    e.preventDefault();
                    resetForm();
                }
            });

            // Предупреждение о несохраненных изменениях
            let formChanged = false;
            const floatingBtn = document.getElementById('floating-save');

            inputs.forEach(input => {
                input.addEventListener('input', function() {
                    formChanged = true;
                    // Добавляем пульсацию к плавающей кнопке при изменениях
                    if (floatingBtn) {
                        floatingBtn.classList.add('pulse');
                    }
                });
            });

            window.addEventListener('beforeunload', function(e) {
                if (formChanged) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            // Убираем предупреждение при отправке формы
            form.addEventListener('submit', function() {
                formChanged = false;
                // Убираем пульсацию при сохранении
                if (floatingBtn) {
                    floatingBtn.classList.remove('pulse');
                }
            });

            console.log('⌨️ Горячие клавиши:');
            console.log('  Ctrl + S: Сохранить');
            console.log('  Ctrl + R: Сбросить');
            console.log('  Escape: Отмена');
        });
    </script>
</body>

</html>