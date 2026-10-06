<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../classes/ErrorTranslator.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_own_students');

$user = new User();
$group = new Group();
$dynamicField = new DynamicField();
$current_user = getCurrentUser();

// Инициализация базы данных
$db = getDB();

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);

// Получение динамических полей
$dynamic_fields = $dynamicField->getActiveFields();

$message = '';
$error = '';

// Проверяем сообщения из сессии
if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Функция для получения сохраненного значения поля
function getFieldValue($field_name, $default = '')
{
    return isset($_POST[$field_name]) ? htmlspecialchars($_POST[$field_name]) : $default;
}

// Функция для проверки, выбрано ли значение в select
function isSelected($field_name, $value)
{
    return isset($_POST[$field_name]) && $_POST[$field_name] == $value ? 'selected' : '';
}

// Функция для проверки, отмечен ли checkbox
function isChecked($field_name)
{
    return isset($_POST[$field_name]) ? 'checked' : '';
}

// Функция для перевода ошибок базы данных на русский язык (использует ErrorTranslator)
function translateDatabaseError($error_message)
{
    return ErrorTranslator::translate($error_message);
}

// Получаем ID студента
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: students.php');
    exit;
}

// Загружаем студента вместе с группой
$sql = "SELECT s.*, g.name AS group_name, g.id AS group_id
        FROM students s
        LEFT JOIN `groups` g ON s.group_id = g.id
        WHERE s.id = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    header('Location: students.php');
    exit;
}
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
if (!$student) {
    header('Location: students.php');
    exit;
}

// Определяем статус выпускника
$student_status = getStudentStatus($student);
$is_graduated = ($student_status === 'graduated');
$is_graduating = ($student_status === 'graduating');
$is_academic_leave = ($student_status === 'academic_leave');

// Проверяем, что студент относится к группе куратора (если не админ/все)
$can_view_all = $permissionChecker->hasPermission($current_user['id'], 'view_all_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all');
if (!$can_view_all) {
    $curator_groups = $group->getGroupsByCurator($current_user['id']);
    $allowed_group_ids = array_column($curator_groups, 'id');
    // Разрешаем просмотр, если у студента нет привязки к группе
    if (!empty($student['group_id']) && !in_array((int)$student['group_id'], $allowed_group_ids, true)) {
        header('Location: ../unauthorized.php');
        exit;
    }
}

// Динамические поля: загружаем определения и значения студента
$all_dynamic_fields = $dynamicField->getAllFields();
$student_dynamic_values = $dynamicField->getStudentDynamicFields($student_id);

// Преобразуем в map: field_name => label
$dynamic_field_labels = [];
foreach ($all_dynamic_fields as $field_def) {
    $dynamic_field_labels[$field_def['field_name']] = $field_def['field_label'];
}
// Преобразуем значения динамических полей в удобную карту
// $student_dynamic_values уже в формате field_name => field_value
$dynamic_values_map = is_array($student_dynamic_values) ? $student_dynamic_values : [];
?>
<!DOCTYPE html>
<html lang="ru" data-density="compact">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Полный профиль студента <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?>">
    <title>Студент: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?> - <?php echo APP_NAME; ?></title>

    <!-- Favicon --><text y='.9em' font-size='90'>🎓</text></svg>">

    <!-- Исправление ошибок браузерных расширений -->
    <script>
        (function() {
            'use strict';
            window.addEventListener('error', function(e) {
                if (e.message && e.message.includes('message channel closed')) {
                    e.preventDefault();
                    return false;
                }
            });
            window.addEventListener('unhandledrejection', function(e) {
                if (e.reason && e.reason.message && e.reason.message.includes('message channel closed')) {
                    e.preventDefault();
                    return false;
                }
            });
        })();
    </script>

    <!-- Bootstrap 5 и шрифты -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/manager-ui.css" rel="stylesheet">

    <style>
        body.manager-app .magazine-layout {
            max-width: none;
            margin: 0;
            padding: 0;
        }
        body.manager-app .content-area {
            padding: 0;
        }

        /* ============================================
           ПЕРЕМЕННЫЕ ЦВЕТОВ И СТИЛЕЙ
           Спокойная, образовательная палитра
           ============================================ */
        :root {
            /* Основная палитра - мягкие образовательные цвета */
            --primary: #4F46E5;
            /* Индиго - основной цвет */
            --primary-light: #818CF8;
            /* Светлый индиго */
            --primary-dark: #3730A3;
            /* Темный индиго */
            --secondary: #64748B;
            /* Slate - вторичный */
            --accent: #F59E0B;
            /* Amber - акцент */

            /* Семантические цвета */
            --success: #10B981;
            /* Emerald */
            --success-light: #D1FAE5;
            --warning: #F59E0B;
            /* Amber */
            --warning-light: #FEF3C7;
            --danger: #EF4444;
            /* Red */
            --danger-light: #FEE2E2;
            --info: #3B82F6;
            /* Blue */
            --info-light: #DBEAFE;

            /* Нейтральные цвета */
            --white: #FFFFFF;
            --gray-50: #F8FAFC;
            --gray-100: #F1F5F9;
            --gray-200: #E2E8F0;
            --gray-300: #CBD5E1;
            --gray-400: #94A3B8;
            --gray-500: #64748B;
            --gray-600: #475569;
            --gray-700: #334155;
            --gray-800: #1E293B;
            --gray-900: #0F172A;

            /* Отступы */
            --space-xs: 0.5rem;
            --space-sm: 0.75rem;
            --space-md: 1rem;
            --space-lg: 1.5rem;
            --space-xl: 2rem;
            --space-2xl: 2.5rem;
            --space-3xl: 3rem;
            --space-4xl: 4rem;

            /* Типографика */
            --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            --font-display: 'IBM Plex Sans', 'Inter', sans-serif;

            --text-xs: 0.75rem;
            --text-sm: 0.875rem;
            --text-base: 1rem;
            --text-lg: 1.125rem;
            --text-xl: 1.25rem;
            --text-2xl: 1.5rem;
            --text-3xl: 1.875rem;
            --text-4xl: 2.25rem;

            /* Тени */
            --shadow-xs: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px -1px rgba(0, 0, 0, 0.1);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -2px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -4px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 8px 10px -6px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 25px 50px -12px rgba(0, 0, 0, 0.25);

            /* Радиусы скругления */
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
            --radius-2xl: 2rem;
            --radius-full: 9999px;

            /* Переходы */
            --transition: all 0.2s ease-in-out;
        }

        /* ============================================
           БАЗОВЫЕ СТИЛИ
           Современная типографика и отступы
           ============================================ */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: var(--font-sans);
            background: linear-gradient(135deg, var(--gray-50) 0%, var(--white) 50%, var(--gray-50) 100%);
            color: var(--gray-900);
            line-height: 1.6;
            font-size: var(--text-base);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Улучшенная читабельность заголовков */
        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: var(--font-display);
            font-weight: 600;
            line-height: 1.2;
            color: var(--gray-900);
        }

        /* ============================================
           LAYOUT - Адаптивная структура страницы
           ============================================ */
        .magazine-layout {
            display: grid;

            min-height: 100vh;
            gap: 0;
        }

        .content-area {
            padding: var(--space-2xl) var(--space-xl);
            max-width: auto;
            margin: 0 auto;
            width: 100%;
        }

        /* ============================================
           BREADCRUMB - Навигационная цепочка
           Показывает текущее положение пользователя
           ============================================ */
        .breadcrumb-nav {
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: var(--space-md) var(--space-lg);
            margin-bottom: var(--space-xl);
            box-shadow: var(--shadow-sm);
            border: 1px solid var(--gray-200);
        }

        .breadcrumb-nav ol {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            list-style: none;
            margin: 0;
            padding: 0;
            gap: var(--space-sm);
        }

        .breadcrumb-nav li {
            display: flex;
            align-items: center;
            gap: var(--space-sm);
            font-size: var(--text-sm);
            color: var(--gray-600);
        }

        .breadcrumb-nav li:not(:last-child)::after {
            content: '/';
            color: var(--gray-400);
            margin-left: var(--space-sm);
        }

        .breadcrumb-nav a {
            color: var(--primary);
            text-decoration: none;
            transition: var(--transition);
            font-weight: 500;
        }

        .breadcrumb-nav a:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }

        .breadcrumb-nav .active {
            color: var(--gray-900);
            font-weight: 600;
        }

        /* ============================================
           HERO CARD - Главная карточка профиля студента
           Визуально выделенный блок с основной информацией
           ============================================ */
        .student-hero-card {
            background: linear-gradient(135deg, var(--white) 0%, var(--gray-50) 100%);
            border-radius: var(--radius-2xl);
            padding: var(--space-3xl);
            margin-bottom: var(--space-2xl);
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--gray-200);
            position: relative;
            overflow: hidden;
        }

        /* Декоративный акцент сверху карточки */
        .student-hero-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--primary) 0%, var(--primary-light) 100%);
        }

        .hero-content {
            display: grid;
            grid-template-columns: auto 1fr auto;
            gap: var(--space-2xl);
            align-items: start;
        }

        /* Аватар студента с gradient border */
        .student-avatar-hero {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3.5rem;
            color: var(--white);
            box-shadow: var(--shadow-md);
            transition: var(--transition);
            flex-shrink: 0;
        }

        .student-avatar-hero:hover {
            transform: translateY(-4px) scale(1.05);
            box-shadow: var(--shadow-xl);
        }

        .hero-info {
            flex: 1;
        }

        .hero-info h1 {
            font-size: var(--text-4xl);
            font-weight: 700;
            margin-bottom: var(--space-md);
            color: var(--gray-900);
            letter-spacing: -0.02em;
        }

        /* Мета-информация под именем */
        .hero-meta {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-sm);
            margin-top: var(--space-md);
        }

        .meta-badge {
            background: var(--white);
            padding: var(--space-sm) var(--space-md);
            border-radius: var(--radius-full);
            font-size: var(--text-sm);
            font-weight: 500;
            border: 1px solid var(--gray-300);
            color: var(--gray-700);
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            transition: var(--transition);
        }

        .meta-badge:hover {
            border-color: var(--primary);
            color: var(--primary);
            transform: translateY(-2px);
            box-shadow: var(--shadow-sm);
        }

        /* Кнопки действий */
        .hero-actions {
            display: flex;
            flex-direction: column;
            gap: var(--space-sm);
            flex-shrink: 0;
        }

        /* ============================================
           ИНФОРМАЦИОННЫЕ КАРТОЧКИ
           Адаптивная сетка с визуальной иерархией
           ============================================ */
        .info-masonry {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: var(--space-xl);
            margin-bottom: var(--space-2xl);
        }

        .info-card-new {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-2xl);
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: var(--transition);
            position: relative;
            overflow: hidden;
        }

        /* Цветной акцент слева от карточки для визуальной категоризации */
        .info-card-new::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--primary);
            opacity: 0.6;
            transition: var(--transition);
        }

        .info-card-new:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary);
        }

        .info-card-new:hover::before {
            opacity: 1;
            width: 6px;
        }

        /* Заголовок карточки с иконкой */
        .card-header-new {
            display: flex;
            align-items: center;
            gap: var(--space-md);
            margin-bottom: var(--space-xl);
            padding-bottom: var(--space-md);
            border-bottom: 2px solid var(--gray-100);
        }

        .card-icon {
            width: 48px;
            height: 48px;
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            border-radius: var(--radius-lg);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-xl);
            box-shadow: var(--shadow-sm);
            flex-shrink: 0;
        }

        .card-title-new {
            font-size: var(--text-xl);
            font-weight: 600;
            color: var(--gray-900);
            margin: 0;
        }

        /* ============================================
           ТАБЛИЦА ДАННЫХ
           Строки данных с улучшенной читаемостью
           ============================================ */
        .data-table {
            width: 100%;
        }

        .data-row-new {
            display: grid;
            grid-template-columns: 2fr 3fr;
            gap: var(--space-lg);
            padding: var(--space-md) 0;
            border-bottom: 1px solid var(--gray-100);
            transition: var(--transition);
            align-items: center;
        }

        .data-row-new:last-child {
            border-bottom: none;
        }

        .data-row-new:hover {
            background: var(--gray-50);
            margin: 0 calc(-1 * var(--space-2xl));
            padding-left: var(--space-2xl);
            padding-right: var(--space-2xl);
            border-radius: var(--radius-md);
        }

        /* Метки (labels) данных */
        .data-label-new {
            font-weight: 500;
            color: var(--gray-600);
            font-size: var(--text-sm);
            display: flex;
            align-items: center;
            gap: var(--space-sm);
        }

        .data-label-new i {
            color: var(--primary);
            font-size: 1.1em;
        }

        /* Значения данных */
        .data-value-new {
            font-weight: 500;
            color: var(--gray-900);
            font-size: var(--text-sm);
            text-align: right;
            word-break: break-word;
        }

        /* ============================================
           СТАТУС-ИНДИКАТОРЫ
           Цветные бейджи для визуализации статуса
           ============================================ */
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 0.375rem 0.875rem;
            border-radius: var(--radius-full);
            font-size: var(--text-xs);
            font-weight: 600;
            letter-spacing: 0.025em;
            text-transform: uppercase;
            border: 1.5px solid;
            transition: var(--transition);
        }

        .status-pill::before {
            content: '';
            width: 6px;
            height: 6px;
            border-radius: 50%;
            background: currentColor;
            animation: pulse 2s infinite;
        }

        .status-success {
            background: var(--success-light);
            color: var(--success);
            border-color: var(--success);
        }

        .status-success:hover {
            background: var(--success);
            color: var(--white);
        }

        .status-warning {
            background: var(--warning-light);
            color: var(--warning);
            border-color: var(--warning);
        }

        .status-warning:hover {
            background: var(--warning);
            color: var(--white);
        }

        .status-info {
            background: var(--info-light);
            color: var(--info);
            border-color: var(--info);
        }

        .status-info:hover {
            background: var(--info);
            color: var(--white);
        }

        .status-danger {
            background: var(--danger-light);
            color: var(--danger);
            border-color: var(--danger);
        }

        .status-danger:hover {
            background: var(--danger);
            color: var(--white);
        }

        /* ============================================
           КОНТАКТНЫЕ ССЫЛКИ
           Интерактивные ссылки для телефонов и email
           ============================================ */
        .contact-link-new {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
            display: inline-flex;
            align-items: center;
            gap: var(--space-xs);
            padding: 0.375rem 0.75rem;
            border-radius: var(--radius-md);
            transition: var(--transition);
            border: 1.5px solid var(--gray-300);
            background: var(--white);
            font-size: var(--text-sm);
        }

        .contact-link-new:hover {
            background: var(--primary);
            color: var(--white);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            border-color: var(--primary);
        }

        .contact-link-new i {
            font-size: 1.1em;
        }

        /* ============================================
           КНОПКИ ДЕЙСТВИЙ
           Современный дизайн с четкой визуальной иерархией
           ============================================ */
        .btn-hero {
            padding: 0.75rem 1.5rem;
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: var(--text-sm);
            border: 1.5px solid;
            cursor: pointer;
            transition: var(--transition);
            display: inline-flex;
            align-items: center;
            gap: var(--space-sm);
            text-decoration: none;
            min-width: 140px;
            justify-content: center;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary-hero {
            background: var(--primary);
            color: var(--white);
            border-color: var(--primary);
        }

        .btn-primary-hero:hover {
            background: var(--primary-dark);
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            border-color: var(--primary-dark);
        }

        .btn-secondary-hero {
            background: var(--white);
            color: var(--gray-700);
            border-color: var(--gray-300);
        }

        .btn-secondary-hero:hover {
            background: var(--gray-50);
            color: var(--gray-900);
            border-color: var(--gray-400);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }


        /* ============================================
           QUICK STATS - Карточки статистики
           Визуальное отображение ключевых показателей
           ============================================ */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: var(--space-lg);
            margin-bottom: var(--space-2xl);
        }

        .stat-card {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: var(--space-xl);
            text-align: center;
            box-shadow: var(--shadow);
            border: 1px solid var(--gray-200);
            transition: var(--transition);
        }

        .stat-card:hover {
            transform: translateY(-6px);
            box-shadow: var(--shadow-xl);
            border-color: var(--primary);
        }

        .stat-icon {
            width: 64px;
            height: 64px;
            margin: 0 auto var(--space-md);
            background: linear-gradient(135deg, var(--primary-light) 0%, var(--primary) 100%);
            border-radius: var(--radius-full);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--white);
            font-size: var(--text-2xl);
            box-shadow: var(--shadow-md);
        }

        .stat-label {
            font-size: var(--text-sm);
            color: var(--gray-600);
            font-weight: 500;
            margin-bottom: var(--space-xs);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .stat-value {
            font-size: var(--text-xl);
            font-weight: 700;
            color: var(--gray-900);
        }

        /* ============================================
           ПОИСК И ФИЛЬТРАЦИЯ
           Инструменты для управления данными
           ============================================ */
        .tab-search {
            position: relative;
            flex: 1;
        }

        .tab-search input {
            width: 100%;
            border-radius: var(--radius-lg);
            border: 1.5px solid var(--gray-300);
            padding: 0.75rem 1rem 0.75rem 2.75rem;
            background: var(--white);
            outline: none;
            transition: var(--transition);
            font-size: var(--text-sm);
        }

        .tab-search input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
        }

        .tab-search .bi-search {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-400);
            font-size: 1.1em;
        }

        .density-toggle {
            border: 1.5px solid var(--gray-300);
            background: var(--white);
            border-radius: var(--radius-lg);
            padding: 0.75rem 1.25rem;
            cursor: pointer;
            font-weight: 600;
            font-size: var(--text-sm);
            transition: var(--transition);
            color: var(--gray-700);
        }

        .density-toggle:hover {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--gray-50);
        }


        /* ============================================
           РЕЖИМЫ ПЛОТНОСТИ
           Compact/Comfortable переключение
           ============================================ */
        :root[data-density="compact"] .info-card-new {
            padding: var(--space-lg);
        }

        :root[data-density="compact"] .data-row-new {
            padding: 0.5rem 0;
        }

        :root[data-density="compact"] .student-hero-card {
            padding: var(--space-xl);
        }

        /* ============================================
           АДАПТИВНОСТЬ
           Оптимизация для разных размеров экрана
           ============================================ */
        @media (max-width: 1024px) {
            .hero-content {
                grid-template-columns: 1fr;
                text-align: center;
                gap: var(--space-lg);
            }

            .hero-actions {
                flex-direction: row;
                justify-content: center;
            }
        }

        @media (max-width: 768px) {
            .content-area {
                padding: var(--space-xl) var(--space-md);
            }

            .student-hero-card {
                padding: var(--space-xl);
            }

            .hero-info h1 {
                font-size: var(--text-3xl);
            }

            .info-masonry {
                grid-template-columns: 1fr;
            }

            .quick-stats {
                grid-template-columns: repeat(2, 1fr);
            }

            .data-row-new {
                grid-template-columns: 1fr;
                gap: var(--space-sm);
            }

            .data-value-new {
                text-align: left;
            }
        }

        @media (max-width: 480px) {
            .content-area {
                padding: var(--space-lg) var(--space-sm);
            }

            .student-hero-card {
                padding: var(--space-lg);
            }

            .hero-info h1 {
                font-size: var(--text-2xl);
            }

            .quick-stats {
                grid-template-columns: 1fr;
            }

            .hero-actions {
                width: 100%;
                flex-direction: column;
            }

            .btn-hero {
                width: 100%;
            }

            .breadcrumb-nav {
                padding: var(--space-sm) var(--space-md);
            }
        }

        /* ============================================
           АНИМАЦИИ
           Плавное появление и переходы
           ============================================ */
        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        @keyframes slideInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        /* Применение анимаций к карточкам */
        .info-card-new {
            animation: slideInUp 0.5s ease-out backwards;
        }

        .info-card-new:nth-child(1) {
            animation-delay: 0.05s;
        }

        .info-card-new:nth-child(2) {
            animation-delay: 0.1s;
        }

        .info-card-new:nth-child(3) {
            animation-delay: 0.15s;
        }

        .info-card-new:nth-child(4) {
            animation-delay: 0.2s;
        }

        .info-card-new:nth-child(5) {
            animation-delay: 0.25s;
        }

        .info-card-new:nth-child(6) {
            animation-delay: 0.3s;
        }

        /* ============================================
           ПЕЧАТЬ
           Оптимизация для печатной версии
           ============================================ */
        @media print {

            .hero-actions,
            .breadcrumb-nav,
            .tab-search,
            .density-toggle {
                display: none !important;
            }

            .student-hero-card,
            .info-card-new,
            .stat-card {
                background: var(--white) !important;
                border: 1px solid var(--gray-300) !important;
                box-shadow: none !important;
                break-inside: avoid;
            }

            body {
                background: var(--white) !important;
            }
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

        .card {
            border-radius: 16px !important;
            border: none !important;
            box-shadow: 0 4px 6px rgba(0, 0, 0, 0.07) !important;
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

        .badge {
            border-radius: 6px !important;
            font-weight: 600 !important;
            text-transform: uppercase !important;
        }

        .table thead {
            background: var(--dark-bg) !important;
        }

        .table tbody tr:hover {
            background: var(--light-bg) !important;
            box-shadow: inset 4px 0 0 var(--primary-color) !important;
        }
    </style>
</head>

<body class="manager-app" id="top">
<div class="manager-shell">
    <?php
    $page_title = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
    $page_subtitle = 'Профиль студента';
    include 'includes/sidebar.php';
    ?>
    <div class="manager-main">
        <?php include 'includes/header.php'; ?>
        <div class="manager-content">
    <div class="magazine-layout">
        <main class="content-area" role="main" aria-label="Профиль студента">

            <nav class="breadcrumb-nav no-print" aria-label="Навигация">
                <ol>
                    <li>
                        <a href="dashboard.php">
                            <i class="bi bi-house-door"></i>
                            Главная
                        </a>
                    </li>
                    <li>
                        <a href="students.php">
                            <i class="bi bi-people"></i>
                            Студенты
                        </a>
                    </li>
                    <li class="active">
                        <i class="bi bi-person"></i>
                        <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?>
                    </li>
                </ol>
            </nav>

            <!-- Сообщения об успехе/ошибке -->
            <?php if (!empty($message)): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert" style="margin-bottom: var(--space-xl); border-radius: var(--radius-lg); border: 1.5px solid var(--success); background: var(--success-light);">
                    <i class="bi bi-check-circle-fill me-2"></i>
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert" style="margin-bottom: var(--space-xl); border-radius: var(--radius-lg); border: 1.5px solid var(--danger); background: var(--danger-light);">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    <?php echo $error; ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>

            <!-- ============================================
                 HERO CARD - Главная карточка профиля студента
                 Основная информация: ФИО, группа, статус
                 ============================================ -->
            <article class="student-hero-card" id="hero">
                <div class="hero-content">
                    <!-- Аватар студента -->
                    <div class="student-avatar-hero" role="img" aria-label="Аватар студента">
                        <i class="bi bi-person-fill"></i>
                    </div>

                    <!-- Информация о студенте -->
                    <div class="hero-info">
                        <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?></h1>

                        <!-- Мета-информация: ИИН, группа, дата рождения, статус -->
                        <div class="hero-meta">
                            <span class="meta-badge">
                                <i class="bi bi-credit-card"></i>
                                ИИН: <?php echo htmlspecialchars($student['iin']); ?>
                            </span>
                            <span class="meta-badge">
                                <i class="bi bi-people"></i>
                                <?php echo htmlspecialchars($student['group_name'] ?? 'Группа не назначена'); ?>
                            </span>
                            <span class="meta-badge">
                                <i class="bi bi-calendar"></i>
                                <?php echo htmlspecialchars($student['birth_date']); ?>
                            </span>

                            <!-- Статус студента с визуальной индикацией -->
                            <span class="status-pill <?php
                                                        if ($is_academic_leave || $is_graduating) {
                                                            echo 'status-warning';
                                                        } elseif ($is_graduated) {
                                                            echo 'status-info';
                                                        } else {
                                                            echo 'status-success';
                                                        }
                                                        ?>">
                                <?php echo htmlspecialchars(getStudentStatusLabel($student_status)); ?>
                            </span>
                        </div>
                    </div>

                    <!-- Кнопки действий -->
                    <div class="hero-actions">
                        <a href="students.php" class="btn-hero btn-secondary-hero" aria-label="Вернуться к списку студентов">
                            <i class="bi bi-arrow-left"></i>
                            Назад
                        </a>
                        <?php if ($permissionChecker->hasPermission($current_user['id'], 'edit_students') || $permissionChecker->hasPermission($current_user['id'], 'edit_own_students')): ?>
                            <a href="edit_student.php?id=<?php echo (int)$student['id']; ?>" class="btn-hero btn-primary-hero" aria-label="Редактировать профиль студента">
                                <i class="bi bi-pencil"></i>
                                Редактировать
                            </a>
                        <?php endif; ?>
                        <button class="btn-hero btn-secondary-hero" onclick="window.print()" aria-label="Распечатать профиль">
                            <i class="bi bi-printer"></i>
                            Печать
                        </button>
                    </div>
                </div>
            </article>

            <!-- ============================================
                 QUICK STATS - Ключевые показатели обучения
                 Визуализация основных параметров образования
                 ============================================ -->
            <section class="quick-stats" id="stats" aria-label="Ключевые показатели">
                <article class="stat-card">
                    <div class="stat-icon" aria-hidden="true">
                        <i class="bi bi-mortarboard"></i>
                    </div>
                    <div class="stat-label">Курс обучения</div>
                    <div class="stat-value"><?php echo htmlspecialchars($student['course']); ?></div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon" aria-hidden="true">
                        <i class="bi bi-translate"></i>
                    </div>
                    <div class="stat-label">Язык обучения</div>
                    <div class="stat-value"><?php echo htmlspecialchars($student['language']); ?></div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon" aria-hidden="true">
                        <i class="bi bi-calendar-check"></i>
                    </div>
                    <div class="stat-label">Форма обучения</div>
                    <div class="stat-value"><?php echo htmlspecialchars($student['study_form']); ?></div>
                </article>

                <article class="stat-card">
                    <div class="stat-icon" aria-hidden="true">
                        <i class="bi bi-clock"></i>
                    </div>
                    <div class="stat-label">Срок обучения</div>
                    <div class="stat-value"><?php echo htmlspecialchars($student['study_duration']); ?></div>
                </article>
            </section>

            <!-- ============================================
                 ПАНЕЛЬ ПОИСКА И УПРАВЛЕНИЯ
                 Инструменты для фильтрации и управления отображением
                 ============================================ -->
            <aside class="info-card-new" style="margin-bottom: var(--space-xl);">
                <header class="card-header-new">
                    <div class="card-icon" aria-hidden="true">
                        <i class="bi bi-sliders"></i>
                    </div>
                    <h2 class="card-title-new">Поиск и управление</h2>
                </header>
                <div style="display: flex; gap: var(--space-md); align-items: center; flex-wrap: wrap;">
                    <!-- Поле поиска -->
                    <div class="tab-search" style="flex: 1; min-width: 300px;">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input
                            type="search"
                            id="rowFilter"
                            placeholder="Быстрый поиск по всей информации..."
                            aria-label="Поиск по информации студента">
                    </div>
                    <!-- Переключатель плотности отображения -->
                    <button
                        class="density-toggle"
                        id="densityToggle"
                        title="Переключить плотность отображения"
                        aria-label="Переключить плотность отображения">
                        <i class="bi bi-layout-three-columns"></i>
                        Плотнее
                    </button>
                </div>
            </aside>

            <!-- ============================================
                 ИНФОРМАЦИОННЫЕ КАРТОЧКИ
                 Детальная информация о студенте по категориям
                 ============================================ -->
            <section id="info" class="info-masonry" aria-label="Детальная информация">

                <!-- КАРТОЧКА: Основная информация -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-person-badge"></i>
                        </div>
                        <h2 class="card-title-new">Основная информация</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-calendar3"></i>
                                Дата рождения
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['birth_date']); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-<?php echo $student['gender'] === 'мужской' ? 'person' : 'person-dress'; ?>"></i>
                                Пол
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['gender']); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-globe"></i>
                                Национальность
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['nationality']); ?></span>
                        </div>
                        <?php if (!empty($student['group_code'])): ?>
                            <div class="data-row-new">
                                <span class="data-label-new">
                                    <i class="bi bi-tag"></i>
                                    Код группы
                                </span>
                                <span class="data-value-new">
                                    <code style="background: var(--gray-100); padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-weight: 600;">
                                        <?php echo htmlspecialchars($student['group_code']); ?>
                                    </code>
                                </span>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- КАРТОЧКА: Контактная информация -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-telephone"></i>
                        </div>
                        <h2 class="card-title-new">Контактная информация</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-telephone"></i>
                                Телефон
                            </span>
                            <span class="data-value-new">
                                <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="contact-link-new">
                                    <i class="bi bi-telephone"></i>
                                    <?php echo htmlspecialchars($student['phone']); ?>
                                </a>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-envelope"></i>
                                Email
                            </span>
                            <span class="data-value-new">
                                <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="contact-link-new">
                                    <i class="bi bi-envelope"></i>
                                    <?php echo htmlspecialchars($student['email']); ?>
                                </a>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-geo-alt"></i>
                                Постоянный адрес
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['permanent_address_ru'] ?? 'Не указан'); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-geo"></i>
                                Временный адрес
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['temporary_address_ru'] ?? 'Не указан'); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-house"></i>
                                Тип местности
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['residence_type']); ?></span>
                        </div>
                    </div>
                </article>

                <!-- КАРТОЧКА: Родители и семья -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-people"></i>
                        </div>
                        <h2 class="card-title-new">Родители и семья</h2>
                    </header>
                    <div class="data-table">
                        <!-- Информация о матери -->
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-person-heart"></i>
                                Мать (ФИО)
                            </span>
                            <span class="data-value-new">
                                <?php echo htmlspecialchars($dynamic_values_map['mother_full_name'] ?? 'Не указано'); ?>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-telephone"></i>
                                Телефон матери
                            </span>
                            <span class="data-value-new">
                                <?php if (!empty($dynamic_values_map['mother_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($dynamic_values_map['mother_phone']); ?>" class="contact-link-new">
                                        <i class="bi bi-telephone"></i>
                                        <?php echo htmlspecialchars($dynamic_values_map['mother_phone']); ?>
                                    </a>
                                <?php else: ?>
                                    Не указан
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-building"></i>
                                Место работы матери
                            </span>
                            <span class="data-value-new">
                                <?php echo htmlspecialchars($dynamic_values_map['mother_workplace'] ?? 'Не указано'); ?>
                            </span>
                        </div>

                        <!-- Информация об отце -->
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-person-badge"></i>
                                Отец (ФИО)
                            </span>
                            <span class="data-value-new">
                                <?php echo htmlspecialchars($dynamic_values_map['father_full_name'] ?? 'Не указано'); ?>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-telephone"></i>
                                Телефон отца
                            </span>
                            <span class="data-value-new">
                                <?php if (!empty($dynamic_values_map['father_phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($dynamic_values_map['father_phone']); ?>" class="contact-link-new">
                                        <i class="bi bi-telephone"></i>
                                        <?php echo htmlspecialchars($dynamic_values_map['father_phone']); ?>
                                    </a>
                                <?php else: ?>
                                    Не указан
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-building"></i>
                                Место работы отца
                            </span>
                            <span class="data-value-new">
                                <?php echo htmlspecialchars($dynamic_values_map['father_workplace'] ?? 'Не указано'); ?>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-life-preserver"></i>
                                Экстренный контакт
                            </span>
                            <span class="data-value-new">
                                <?php if (!empty($dynamic_values_map['emergency_contact'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($dynamic_values_map['emergency_contact']); ?>" class="contact-link-new">
                                        <i class="bi bi-telephone"></i>
                                        <?php echo htmlspecialchars($dynamic_values_map['emergency_contact']); ?>
                                    </a>
                                <?php else: ?>
                                    Не указан
                                <?php endif; ?>
                            </span>
                        </div>
                        <div class="data-row-new" style="background: var(--gray-50); border-radius: var(--radius); margin: 0 calc(-1 * var(--space-xl)); padding-left: var(--space-xl); padding-right: var(--space-xl);">
                            <span class="data-label-new">
                                <i class="bi bi-shield-check"></i>
                                Статус семьи
                            </span>
                            <span class="data-value-new" style="display: inline-flex; gap: .5rem; flex-wrap: wrap; justify-content: flex-end;">
                                <?php if ($student['orphan']): ?>
                                    <span class="status-pill status-warning"><i class="bi bi-heart-fill"></i> Сирота</span>
                                <?php endif; ?>
                                <?php if ($student['without_parental_care']): ?>
                                    <span class="status-pill status-warning"><i class="bi bi-person-exclamation"></i> Без попечения</span>
                                <?php endif; ?>
                                <?php if ($student['large_family']): ?>
                                    <span class="status-pill status-info"><i class="bi bi-house-heart"></i> Многодетная семья</span>
                                <?php endif; ?>
                                <?php if (!$student['orphan'] && !$student['without_parental_care'] && !$student['large_family']): ?>
                                    <span class="status-pill status-success"><i class="bi bi-check-circle"></i> Без особых статусов</span>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </article>

                <!-- КАРТОЧКА: Обучение и программа -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-book"></i>
                        </div>
                        <h2 class="card-title-new">Обучение и программа</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-award"></i>
                                Специальность
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['specialty']); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-calendar-event"></i>
                                Начало курса
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['course_start_date']); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-calendar-check"></i>
                                Окончание курса
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['course_end_date']); ?></span>
                        </div>
                        <?php if (!empty($student['graduation_date'])): ?>
                            <div class="data-row-new">
                                <span class="data-label-new">
                                    <i class="bi bi-mortarboard"></i>
                                    Дата выпуска
                                </span>
                                <span class="data-value-new"><?php echo htmlspecialchars($student['graduation_date']); ?></span>
                            </div>
                        <?php endif; ?>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-briefcase"></i>
                                Тип практики
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['practice_type']); ?></span>
                        </div>
                    </div>
                </article>

                <!-- КАРТОЧКА: Документы и зачисление -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-file-earmark-text"></i>
                        </div>
                        <h2 class="card-title-new">Документы и зачисление</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-file-text"></i>
                                Вид зачисления
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['enrollment_type']); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-calendar-plus"></i>
                                Дата зачисления
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['arrival_date'] ?? 'Не указана'); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-file-earmark"></i>
                                Номер приказа
                            </span>
                            <span class="data-value-new">
                                <code style="background: var(--gray-100); padding: 0.25rem 0.5rem; border-radius: 0.375rem; font-weight: 600;">
                                    <?php echo htmlspecialchars($student['enrollment_order_number'] ?? 'Не указан'); ?>
                                </code>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-arrow-right"></i>
                                Прибыл из
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['arrival_from'] ?? 'Не указано'); ?></span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-mortarboard"></i>
                                Тип образования
                            </span>
                            <span class="data-value-new"><?php echo htmlspecialchars($student['education_type']); ?></span>
                        </div>
                    </div>
                </article>

                <!-- КАРТОЧКА: Социальные категории и льготы -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-shield-check"></i>
                        </div>
                        <h2 class="card-title-new">Социальные категории и льготы</h2>
                    </header>
                    <div class="data-table">
                        <?php
                        $has_flags = (int)$student['orphan'] || (int)$student['without_parental_care'] || (int)$student['disability'] || (int)$student['large_family'] || (int)$student['social_assistance'];
                        $quota = trim((string)($student['quota_category'] ?? ''));
                        $is_quota_none = ($quota === '' || mb_strtolower($quota, 'UTF-8') === 'не относится ни к одной из указанных категорий');
                        ?>

                        <div class="data-row-new" style="background: var(--gray-50); border-radius: var(--radius); margin: 0 calc(-1 * var(--space-xl)); padding-left: var(--space-xl); padding-right: var(--space-xl); align-items: start;">
                            <span class="data-label-new" style="gap: 0.5rem;">
                                <i class="bi bi-flag"></i>
                                Итог по соц. статусу
                            </span>
                            <span class="data-value-new" style="text-align: right; display: inline-flex; flex-wrap: wrap; gap: 0.5rem; justify-content: flex-end;">
                                <?php if ($has_flags): ?>
                                    <?php if ($student['orphan']): ?>
                                        <span class="status-pill status-warning" title="Сирота"><i class="bi bi-heart-fill"></i> Сирота</span>
                                    <?php endif; ?>
                                    <?php if ($student['without_parental_care']): ?>
                                        <span class="status-pill status-warning" title="Без попечения родителей"><i class="bi bi-person-exclamation"></i> Без попечения</span>
                                    <?php endif; ?>
                                    <?php if ($student['disability']): ?>
                                        <span class="status-pill status-warning" title="Имеется инвалидность"><i class="bi bi-universal-access"></i> Инвалидность</span>
                                    <?php endif; ?>
                                    <?php if ($student['large_family']): ?>
                                        <span class="status-pill status-info" title="Из многодетной семьи"><i class="bi bi-house-heart"></i> Многодетная семья</span>
                                    <?php endif; ?>
                                    <?php if ($student['social_assistance']): ?>
                                        <span class="status-pill status-info" title="Получатель социальной помощи"><i class="bi bi-life-preserver"></i> Соц. помощь</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="status-pill status-success"><i class="bi bi-check-circle"></i> Особых категорий не выявлено</span>
                                <?php endif; ?>
                            </span>
                        </div>

                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: var(--space-lg); margin-top: var(--space-lg);">
                            <!-- Квота -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-award"></i>
                                    Категория квоты
                                </div>
                                <div>
                                    <?php if ($is_quota_none): ?>
                                        <span class="status-pill status-success" title="Нет льготной квоты">Не указана</span>
                                    <?php else: ?>
                                        <span class="status-pill status-info" title="Льготная квота активна"><i class="bi bi-award"></i> <?php echo htmlspecialchars($quota); ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Сирота -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-heart"></i>
                                    Сирота
                                </div>
                                <div>
                                    <span class="status-pill <?php echo $student['orphan'] ? 'status-warning' : 'status-success'; ?>" title="<?php echo $student['orphan'] ? 'Студент является сиротой' : 'Не относится'; ?>">
                                        <?php echo $student['orphan'] ? 'Да' : 'Нет'; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Без попечения родителей -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-people"></i>
                                    Без попечения родителей
                                </div>
                                <div>
                                    <span class="status-pill <?php echo $student['without_parental_care'] ? 'status-warning' : 'status-success'; ?>" title="<?php echo $student['without_parental_care'] ? 'Есть статус без попечения' : 'Не относится'; ?>">
                                        <?php echo $student['without_parental_care'] ? 'Да' : 'Нет'; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Инвалидность -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-universal-access"></i>
                                    Инвалидность
                                </div>
                                <div>
                                    <span class="status-pill <?php echo $student['disability'] ? 'status-warning' : 'status-success'; ?>" title="<?php echo $student['disability'] ? 'Имеется инвалидность' : 'Не относится'; ?>">
                                        <?php echo $student['disability'] ? 'Да' : 'Нет'; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Многодетная семья -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-house-heart"></i>
                                    Многодетная семья
                                </div>
                                <div>
                                    <span class="status-pill <?php echo $student['large_family'] ? 'status-info' : 'status-success'; ?>" title="<?php echo $student['large_family'] ? 'Из многодетной семьи' : 'Не относится'; ?>">
                                        <?php echo $student['large_family'] ? 'Да' : 'Нет'; ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Социальная помощь -->
                            <div style="background: var(--gray-50); border: 1px solid var(--gray-200); border-radius: var(--radius-lg); padding: var(--space-md); display: flex; flex-direction: column; gap: var(--space-sm);">
                                <div style="display:flex; align-items:center; gap: .5rem; font-weight:600; color: var(--gray-700);">
                                    <i class="bi bi-life-preserver"></i>
                                    Социальная помощь
                                </div>
                                <div>
                                    <span class="status-pill <?php echo $student['social_assistance'] ? 'status-info' : 'status-success'; ?>" title="<?php echo $student['social_assistance'] ? 'Получает адресную соц. помощь' : 'Не получает'; ?>">
                                        <?php echo $student['social_assistance'] ? 'Да' : 'Нет'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </article>

                <!-- КАРТОЧКА: Питание и активности -->
                <article class="info-card-new">
                    <header class="card-header-new">
                        <div class="card-icon" aria-hidden="true">
                            <i class="bi bi-cup-hot"></i>
                        </div>
                        <h2 class="card-title-new">Питание и активности</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-cup-hot"></i>
                                Горячее питание
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill <?php echo $student['hot_meal'] ? 'status-success' : 'status-warning'; ?>">
                                    <?php echo $student['hot_meal'] ? 'Да' : 'Нет'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-gift"></i>
                                Бесплатное горячее питание
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill <?php echo $student['free_hot_meal'] ? 'status-success' : 'status-warning'; ?>">
                                    <?php echo $student['free_hot_meal'] ? 'Да' : 'Нет'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-people"></i>
                                Молодежный комитет
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill <?php echo $student['youth_committee'] ? 'status-success' : 'status-warning'; ?>">
                                    <?php echo $student['youth_committee'] ? 'Участвует' : 'Не участвует'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-building"></i>
                                Студенческий парламент
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill <?php echo $student['student_parliament'] ? 'status-success' : 'status-warning'; ?>">
                                    <?php echo $student['student_parliament'] ? 'Участвует' : 'Не участвует'; ?>
                                </span>
                            </span>
                        </div>
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-shield"></i>
                                Жас Сарбаз
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill <?php echo $student['jas_sarbaz'] ? 'status-success' : 'status-warning'; ?>">
                                    <?php echo $student['jas_sarbaz'] ? 'Да' : 'Нет'; ?>
                                </span>
                            </span>
                        </div>
                        <?php if (!empty($student['competitions'])): ?>
                            <div class="data-row-new">
                                <span class="data-label-new">
                                    <i class="bi bi-trophy"></i>
                                    Соревнования
                                </span>
                                <span class="data-value-new"><?php echo nl2br(htmlspecialchars($student['competitions'])); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>

                <!-- КАРТОЧКА: Дополнительные поля (если есть) -->
                <?php if (!empty($student_dynamic_values) && is_array($student_dynamic_values)): ?>
                    <article class="info-card-new">
                        <header class="card-header-new">
                            <div class="card-icon" aria-hidden="true">
                                <i class="bi bi-gear"></i>
                            </div>
                            <h2 class="card-title-new">Дополнительные поля</h2>
                        </header>
                        <div class="data-table">
                            <?php
                            // Поля родителей, которые не должны отображаться в дополнительных полях
                            $parent_fields = [
                                'father_full_name',
                                'father_phone',
                                'father_workplace',
                                'mother_full_name',
                                'mother_phone',
                                'mother_workplace'
                            ];

                            foreach ($student_dynamic_values as $field_name => $field_value): ?>
                                <?php if (!empty($field_value) && !in_array($field_name, $parent_fields)): ?>
                                    <div class="data-row-new">
                                        <span class="data-label-new">
                                            <i class="bi bi-info-circle"></i>
                                            <?php
                                            $field_label = isset($dynamic_field_labels[$field_name]) ? $dynamic_field_labels[$field_name] : $field_name;
                                            echo htmlspecialchars($field_label);
                                            ?>
                                        </span>
                                        <span class="data-value-new"><?php echo htmlspecialchars($field_value); ?></span>
                                    </div>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endif; ?>
            </section>

            <!-- ============================================
                 АКАДЕМИЧЕСКИЙ ОТПУСК (если применимо)
                 Особое выделение для студентов в академическом отпуске
                 ============================================ -->
            <?php if ($student['academic_leave'] || !empty($student['academic_leave_reason'])): ?>
                <article class="info-card-new" style="border-left: 4px solid var(--warning); background: var(--warning-light);">
                    <header class="card-header-new">
                        <div class="card-icon" style="background: linear-gradient(135deg, var(--warning), #d97706);" aria-hidden="true">
                            <i class="bi bi-pause-circle"></i>
                        </div>
                        <h2 class="card-title-new">Академический отпуск</h2>
                    </header>
                    <div class="data-table">
                        <div class="data-row-new">
                            <span class="data-label-new">
                                <i class="bi bi-exclamation-triangle"></i>
                                Статус
                            </span>
                            <span class="data-value-new">
                                <span class="status-pill status-warning">В академическом отпуске</span>
                            </span>
                        </div>
                        <?php if (!empty($student['academic_leave_reason'])): ?>
                            <div class="data-row-new">
                                <span class="data-label-new">
                                    <i class="bi bi-chat-text"></i>
                                    Причина
                                </span>
                                <span class="data-value-new"><?php echo htmlspecialchars($student['academic_leave_reason']); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($student['academic_leave_order_date'])): ?>
                            <div class="data-row-new">
                                <span class="data-label-new">
                                    <i class="bi bi-calendar-event"></i>
                                    Дата приказа
                                </span>
                                <span class="data-value-new"><?php echo htmlspecialchars($student['academic_leave_order_date']); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </article>
            <?php endif; ?>

            <!-- ============================================
                 ФУТЕР - Метаинформация о записи
                 Системная информация о создании и обновлении
                 ============================================ -->
            <footer class="info-card-new" style="background: var(--gray-50); border: 1px solid var(--gray-200);">
                <div class="data-table">
                    <div class="data-row-new">
                        <span class="data-label-new">
                            <i class="bi bi-clock"></i>
                            Создано
                        </span>
                        <span class="data-value-new"><?php echo htmlspecialchars($student['created_at'] ?? 'Не указано'); ?></span>
                    </div>
                    <div class="data-row-new">
                        <span class="data-label-new">
                            <i class="bi bi-pencil"></i>
                            Обновлено
                        </span>
                        <span class="data-value-new"><?php echo htmlspecialchars($student['updated_at'] ?? 'Не указано'); ?></span>
                    </div>
                </div>
            </footer>
        </main>
    </div>
        </div>
    </div>
</div>

    <!-- Bootstrap JavaScript -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/tooltips.js"></script>
    <script src="assets/js/manager-ui.js"></script>

    <!-- ============================================
         ИНТЕРАКТИВНОСТЬ И ФУНКЦИОНАЛЬНОСТЬ
         JavaScript для улучшения UX
         ============================================ -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✨ Профиль студента загружен успешно');

            // ==========================================
            // ПОИСК И ФИЛЬТРАЦИЯ
            // Фильтрация данных в реальном времени
            // ==========================================
            const rowFilter = document.getElementById('rowFilter');
            if (rowFilter) {
                rowFilter.addEventListener('input', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = document.querySelectorAll('.data-row-new');

                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }

            // ==========================================
            // ПЕРЕКЛЮЧАТЕЛЬ ПЛОТНОСТИ
            // Compact/Comfortable режимы отображения
            // ==========================================
            const densityToggle = document.getElementById('densityToggle');
            if (densityToggle) {
                // Загрузка сохраненных настроек
                const savedDensity = localStorage.getItem('studentViewDensity') || 'compact';
                applyDensity(savedDensity);

                densityToggle.addEventListener('click', function() {
                    const currentDensity = document.documentElement.getAttribute('data-density');
                    const newDensity = currentDensity === 'compact' ? 'comfortable' : 'compact';
                    applyDensity(newDensity);
                    localStorage.setItem('studentViewDensity', newDensity);
                });

                function applyDensity(mode) {
                    document.documentElement.setAttribute('data-density', mode);
                    const icon = mode === 'compact' ?
                        '<i class="bi bi-layout-three-columns"></i>' :
                        '<i class="bi bi-layout-text-sidebar"></i>';
                    const text = mode === 'compact' ? 'Свободнее' : 'Плотнее';
                    densityToggle.innerHTML = icon + ' ' + text;
                }
            }

            // ==========================================
            // ГОРЯЧИЕ КЛАВИШИ
            // Улучшение навигации с клавиатуры
            // ==========================================
            document.addEventListener('keydown', function(e) {
                // Alt + B: Назад к списку студентов
                if (e.altKey && e.key === 'b') {
                    e.preventDefault();
                    window.location.href = 'students.php';
                }

                // Alt + E: Редактировать профиль
                if (e.altKey && e.key === 'e') {
                    e.preventDefault();
                    const editButton = document.querySelector('.btn-primary-hero');
                    if (editButton) editButton.click();
                }

                // Ctrl + P: Печать профиля
                if (e.ctrlKey && e.key === 'p') {
                    e.preventDefault();
                    window.print();
                }

                // Home: Прокрутка наверх
                if (e.key === 'Home') {
                    e.preventDefault();
                    window.scrollTo({
                        top: 0,
                        behavior: 'smooth'
                    });
                }
            });

            // ==========================================
            // АВТОМАТИЧЕСКОЕ СКРЫТИЕ УВЕДОМЛЕНИЙ
            // Закрытие сообщений об успехе через 5 секунд
            // ==========================================
            const successAlert = document.querySelector('.alert-success');
            if (successAlert) {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(successAlert);
                    bsAlert.close();
                }, 5000);
            }

            // ==========================================
            // ПЛАВНАЯ ПРОКРУТКА К ЯКОРЯМ
            // Улучшение навигации по странице
            // ==========================================
            document.querySelectorAll('a[href^="#"]').forEach(anchor => {
                anchor.addEventListener('click', function(e) {
                    const href = this.getAttribute('href');
                    if (href !== '#' && href.length > 1) {
                        e.preventDefault();
                        const target = document.querySelector(href);
                        if (target) {
                            target.scrollIntoView({
                                behavior: 'smooth',
                                block: 'start'
                            });
                        }
                    }
                });
            });

            // ==========================================
            // ИНФОРМАЦИЯ О ГОРЯЧИХ КЛАВИШАХ В КОНСОЛИ
            // ==========================================
            console.log('⌨️ Доступные горячие клавиши:');
            console.log('  Alt + B: Вернуться к списку студентов');
            console.log('  Alt + E: Редактировать профиль');
            console.log('  Ctrl + P: Распечатать профиль');
            console.log('  Home: Прокрутить страницу наверх');
        });
    </script>
</body>

</html>