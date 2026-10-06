<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Авторизация и права
checkAuth();
$current_user = getCurrentUser();
$permissionChecker = new PermissionChecker();
// Гибкая проверка прав на просмотр
if (!($permissionChecker->hasPermission($current_user['id'], 'view_own_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_all_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all'))) {
    $permissionChecker->requirePermission('view_own_students');
}
$group = new Group();
$db = getDB();

// Получаем ID студента
$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: my_students.php');
    exit;
}

// Загружаем студента вместе с группой
$sql = "SELECT s.*, g.name AS group_name, g.id AS group_id
        FROM students s
        LEFT JOIN `groups` g ON s.group_id = g.id
        WHERE s.id = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    header('Location: my_students.php');
    exit;
}
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
if (!$student) {
    header('Location: my_students.php');
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
$dynamicField = new DynamicField();
$all_dynamic_fields = $dynamicField->getAllFields();
$student_dynamic_values = $dynamicField->getStudentDynamicFields($student_id);

// Преобразуем в map: field_name => label
$dynamic_field_labels = [];
foreach ($all_dynamic_fields as $field_def) {
    $dynamic_field_labels[$field_def['field_name']] = $field_def['field_label'];
}

$page_title = trim(($student['last_name'] ?? '') . ' ' . $student['first_name'] . ' ' . $student['middle_name']);
$page_subtitle = 'Группа: ' . ($student['group_name'] ?? '—') . ' · ИИН ' . $student['iin'];
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Профиль студента <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?>">
    <title>Студент: <?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?> - <?php echo APP_NAME; ?></title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">

    <style>
        :root {
            /* Современная цветовая система */
            --primary-50: #eff6ff;
            --primary-100: #dbeafe;
            --primary-500: #3b82f6;
            --primary-600: #2563eb;
            --primary-700: #1d4ed8;
            --primary-900: #1e3a8a;

            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;

            --success-50: #ecfdf5;
            --success-500: #10b981;
            --success-600: #059669;

            --warning-50: #fffbeb;
            --warning-500: #f59e0b;
            --warning-600: #d97706;

            --red-50: #fef2f2;
            --red-500: #ef4444;
            --red-600: #dc2626;

            /* Spacing system */
            --space-1: 0.25rem;
            --space-2: 0.5rem;
            --space-3: 0.75rem;
            --space-4: 1rem;
            --space-5: 1.25rem;
            --space-6: 1.5rem;
            --space-8: 2rem;
            --space-10: 2.5rem;
            --space-12: 3rem;

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
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            /* Border radius */
            --radius-sm: 0.375rem;
            --radius: 0.5rem;
            --radius-md: 0.75rem;
            --radius-lg: 1rem;
            --radius-xl: 1.5rem;
            --radius-2xl: 2rem;
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            line-height: 1.6;
            font-size: var(--text-base);
            margin: 0;
            padding: 0;
        }

        /* Новая структура: Dashboard Layout */
        .dashboard-container {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }

        .dashboard-header {
            background: white;
            border-bottom: 1px solid var(--gray-200);
            padding: var(--space-4) var(--space-6);
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow-sm);
        }

        .dashboard-content {
            flex: 1;
            padding: var(--space-6);
            max-width: 1400px;
            margin: 0 auto;
            width: 100%;
        }

        /* Hero Section - Профиль студента */
        .student-hero {
            background: linear-gradient(135deg, var(--primary-600) 0%, var(--primary-700) 100%);
            border-radius: var(--radius-2xl);
            padding: var(--space-8);
            margin-bottom: var(--space-8);
            color: white;
            position: relative;
            overflow: hidden;
        }

        .student-hero::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255, 255, 255, 0.1) 0%, transparent 70%);
            animation: float 6s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translateY(0px) rotate(0deg);
            }

            50% {
                transform: translateY(-20px) rotate(180deg);
            }
        }

        .student-profile {
            display: flex;
            align-items: center;
            gap: var(--space-6);
            position: relative;
            z-index: 2;
        }

        .student-avatar-large {
            width: 120px;
            height: 120px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 3rem;
            border: 4px solid rgba(255, 255, 255, 0.3);
            backdrop-filter: blur(10px);
        }

        .student-details h1 {
            font-size: var(--text-3xl);
            font-weight: 800;
            margin-bottom: var(--space-2);
            text-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }

        .student-meta-new {
            display: flex;
            flex-wrap: wrap;
            gap: var(--space-4);
            margin-bottom: var(--space-4);
        }

        .meta-item {
            display: flex;
            align-items: center;
            gap: var(--space-2);
            background: rgba(255, 255, 255, 0.15);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-lg);
            backdrop-filter: blur(10px);
            font-size: var(--text-sm);
            font-weight: 500;
        }

        /* Новая структура: Tabs Layout */
        .info-tabs {
            background: white;
            border-radius: var(--radius-xl);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: var(--space-8);
        }

        .tab-navigation {
            display: flex;
            background: var(--gray-50);
            border-bottom: 1px solid var(--gray-200);
            overflow-x: auto;
        }

        .tab-button {
            flex: 1;
            padding: var(--space-4) var(--space-6);
            border: none;
            background: transparent;
            color: var(--gray-600);
            font-weight: 500;
            font-size: var(--text-sm);
            cursor: pointer;
            transition: all 0.2s ease;
            position: relative;
            white-space: nowrap;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: var(--space-2);
        }

        .tab-button:hover {
            background: var(--gray-100);
            color: var(--gray-800);
        }

        .tab-button.active {
            background: white;
            color: var(--primary-600);
            font-weight: 600;
        }

        .tab-button.active::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--primary-500);
        }

        .tab-content {
            padding: var(--space-8);
        }

        .tab-pane {
            display: none;
        }

        .tab-pane.active {
            display: block;
            animation: fadeInUp 0.3s ease-out;
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* Новая структура: Grid Layout для информации */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: var(--space-6);
        }

        .info-section {
            background: var(--gray-50);
            border-radius: var(--radius-lg);
            padding: var(--space-6);
            border: 1px solid var(--gray-200);
            transition: all 0.2s ease;
        }

        .info-section:hover {
            background: white;
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        .section-title {
            font-size: var(--text-lg);
            font-weight: 600;
            color: var(--gray-800);
            margin-bottom: var(--space-4);
            display: flex;
            align-items: center;
            gap: var(--space-3);
        }

        .section-title i {
            color: var(--primary-500);
            font-size: var(--text-xl);
        }

        /* Новый стиль для данных */
        .data-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: var(--space-3) 0;
            border-bottom: 1px solid var(--gray-200);
        }

        .data-row:last-child {
            border-bottom: none;
        }

        .data-label {
            font-weight: 500;
            color: var(--gray-600);
            font-size: var(--text-sm);
        }

        .data-value {
            font-weight: 600;
            color: var(--gray-900);
            text-align: right;
            font-size: var(--text-sm);
        }

        /* Современные статус-индикаторы */
        .status-indicator {
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            padding: var(--space-2) var(--space-4);
            border-radius: var(--radius-lg);
            font-size: var(--text-xs);
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-indicator::before {
            content: '';
            width: 8px;
            height: 8px;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                opacity: 1;
            }

            50% {
                opacity: 0.5;
            }
        }

        .status-active {
            background: var(--success-50);
            color: var(--success-600);
            border: 1px solid var(--success-500);
        }

        .status-active::before {
            background: var(--success-500);
        }

        .status-warning {
            background: var(--warning-50);
            color: var(--warning-600);
            border: 1px solid var(--warning-500);
        }

        .status-warning::before {
            background: var(--warning-500);
        }

        .status-info {
            background: var(--primary-50);
            color: var(--primary-600);
            border: 1px solid var(--primary-500);
        }

        .status-info::before {
            background: var(--primary-500);
        }

        /* Современные кнопки действий */
        .action-buttons {
            display: flex;
            gap: var(--space-3);
            margin-top: var(--space-6);
        }

        .btn-new {
            padding: var(--space-3) var(--space-6);
            border-radius: var(--radius-lg);
            font-weight: 600;
            font-size: var(--text-sm);
            border: none;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            gap: var(--space-2);
            text-decoration: none;
            position: relative;
            overflow: hidden;
        }

        .btn-primary-new {
            background: var(--primary-500);
            color: white;
            box-shadow: var(--shadow);
        }

        .btn-primary-new:hover {
            background: var(--primary-600);
            transform: translateY(-1px);
            box-shadow: var(--shadow-lg);
            color: white;
        }

        .btn-secondary-new {
            background: white;
            color: var(--gray-700);
            border: 1px solid var(--gray-300);
        }

        .btn-secondary-new:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
            transform: translateY(-1px);
            color: var(--gray-800);
        }

        /* Адаптивность */
        @media (max-width: 768px) {
            .dashboard-content {
                padding: var(--space-4);
            }

            .student-hero {
                padding: var(--space-6);
                text-align: center;
            }

            .student-profile {
                flex-direction: column;
                text-align: center;
            }

            .student-avatar-large {
                width: 100px;
                height: 100px;
                font-size: 2.5rem;
            }

            .student-details h1 {
                font-size: var(--text-2xl);
            }

            .tab-navigation {
                flex-direction: column;
            }

            .tab-button {
                justify-content: flex-start;
                padding: var(--space-3) var(--space-4);
            }

            .info-grid {
                grid-template-columns: 1fr;
            }

            .action-buttons {
                flex-direction: column;
            }
        }

        /* Темная тема */
        @media (prefers-color-scheme: dark) {
            :root {
                --gray-50: #1f2937;
                --gray-100: #374151;
                --gray-200: #4b5563;
                --gray-300: #6b7280;
                --gray-900: #f9fafb;
            }

            body {
                background: var(--gray-800);
                color: var(--gray-100);
            }

            .info-tabs {
                background: var(--gray-700);
            }

            .tab-navigation {
                background: var(--gray-800);
                border-color: var(--gray-600);
            }

            .info-section {
                background: var(--gray-700);
                border-color: var(--gray-600);
            }

            .info-section:hover {
                background: var(--gray-600);
            }
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
                    <a href="my_students.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Назад
                    </a>
                    <?php if ($permissionChecker->hasPermission($current_user['id'], 'edit_students') || $permissionChecker->hasPermission($current_user['id'], 'edit_own_students')): ?>
                        <a href="edit_student_new.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-warning btn-sm">
                            <i class="bi bi-pencil me-1"></i>Редактировать
                        </a>
                    <?php endif; ?>
                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Печать
                    </button>
                </div>
            </div>

        <main class="dashboard-content">
            <!-- Hero секция с основной информацией -->
            <section class="student-hero">
                <div class="student-profile">
                    <div class="student-avatar-large">
                        <i class="bi bi-person-fill"></i>
                    </div>
                    <div class="student-details">
                        <h1><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?></h1>
                        <div class="student-meta-new">
                            <div class="meta-item">
                                <i class="bi bi-credit-card"></i>
                                ИИН: <?php echo htmlspecialchars($student['iin']); ?>
                            </div>
                            <div class="meta-item">
                                <i class="bi bi-people"></i>
                                <?php echo htmlspecialchars($student['group_name'] ?? 'Группа не назначена'); ?>
                            </div>
                            <div class="meta-item">
                                <i class="bi bi-calendar"></i>
                                <?php echo htmlspecialchars($student['birth_date']); ?>
                            </div>
                            <span class="status-indicator <?php
                                                            if ($is_academic_leave || $is_graduating) {
                                                                echo 'status-warning';
                                                            } elseif ($is_graduated) {
                                                                echo 'status-info';
                                                            } else {
                                                                echo 'status-active';
                                                            }
                                                            ?>">
                                <?php echo htmlspecialchars(getStudentStatusLabel($student_status)); ?>
                            </span>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Табы с информацией -->
            <section class="info-tabs">
                <nav class="tab-navigation" role="tablist">
                    <button class="tab-button active" data-tab="personal" role="tab" aria-selected="true">
                        <i class="bi bi-person"></i>
                        Личные данные
                    </button>
                    <button class="tab-button" data-tab="education" role="tab" aria-selected="false">
                        <i class="bi bi-book"></i>
                        Обучение
                    </button>
                    <button class="tab-button" data-tab="contacts" role="tab" aria-selected="false">
                        <i class="bi bi-telephone"></i>
                        Контакты
                    </button>
                    <button class="tab-button" data-tab="social" role="tab" aria-selected="false">
                        <i class="bi bi-shield-check"></i>
                        Социальные данные
                    </button>
                    <button class="tab-button" data-tab="activities" role="tab" aria-selected="false">
                        <i class="bi bi-activity"></i>
                        Активности
                    </button>
                    <?php if (!empty($student_dynamic_values)): ?>
                        <button class="tab-button" data-tab="additional" role="tab" aria-selected="false">
                            <i class="bi bi-gear"></i>
                            Дополнительно
                        </button>
                    <?php endif; ?>
                </nav>

                <div class="tab-content">
                    <!-- Личные данные -->
                    <div class="tab-pane active" id="personal" role="tabpanel">
                        <div class="info-grid">
                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-person-badge"></i>
                                    Основная информация
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Дата рождения</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['birth_date']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Пол</span>
                                    <span class="data-value">
                                        <i class="bi bi-<?php echo $student['gender'] === 'мужской' ? 'person' : 'person-dress'; ?> me-1"></i>
                                        <?php echo htmlspecialchars($student['gender']); ?>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Национальность</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['nationality']); ?></span>
                                </div>
                                <?php if (!empty($student['group_code'])): ?>
                                    <div class="data-row">
                                        <span class="data-label">Код группы</span>
                                        <span class="data-value">
                                            <code><?php echo htmlspecialchars($student['group_code']); ?></code>
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-file-earmark-text"></i>
                                    Документы и зачисление
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Вид зачисления</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['enrollment_type']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Дата зачисления</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['arrival_date'] ?? 'Не указана'); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Номер приказа</span>
                                    <span class="data-value">
                                        <code><?php echo htmlspecialchars($student['enrollment_order_number'] ?? 'Не указан'); ?></code>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Прибыл из</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['arrival_from'] ?? 'Не указано'); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Обучение -->
                    <div class="tab-pane" id="education" role="tabpanel">
                        <div class="info-grid">
                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-mortarboard"></i>
                                    Учебная программа
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Специальность</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['specialty']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Курс</span>
                                    <span class="data-value">
                                        <span class="status-indicator status-info"><?php echo htmlspecialchars($student['course']); ?></span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Язык обучения</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['language']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Форма обучения</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['study_form']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Срок обучения</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['study_duration']); ?></span>
                                </div>
                            </div>

                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-calendar-range"></i>
                                    Даты и сроки
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Начало курса</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['course_start_date']); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Окончание курса</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['course_end_date']); ?></span>
                                </div>
                                <?php if (!empty($student['graduation_date'])): ?>
                                    <div class="data-row">
                                        <span class="data-label">Дата выпуска</span>
                                        <span class="data-value">
                                            <i class="bi bi-mortarboard me-1"></i>
                                            <?php echo htmlspecialchars($student['graduation_date']); ?>
                                        </span>
                                    </div>
                                <?php endif; ?>
                                <div class="data-row">
                                    <span class="data-label">Тип практики</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['practice_type']); ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Контакты -->
                    <div class="tab-pane" id="contacts" role="tabpanel">
                        <div class="info-grid">
                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-telephone"></i>
                                    Контактная информация
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Телефон</span>
                                    <span class="data-value">
                                        <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="contact-link">
                                            <i class="bi bi-telephone"></i>
                                            <?php echo htmlspecialchars($student['phone']); ?>
                                        </a>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Email</span>
                                    <span class="data-value">
                                        <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="contact-link">
                                            <i class="bi bi-envelope"></i>
                                            <?php echo htmlspecialchars($student['email']); ?>
                                        </a>
                                    </span>
                                </div>
                            </div>

                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-geo-alt"></i>
                                    Адреса
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Постоянный адрес</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['permanent_address_ru'] ?? 'Не указан'); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Временный адрес</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['temporary_address_ru'] ?? 'Не указан'); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Тип местности</span>
                                    <span class="data-value">
                                        <i class="bi bi-geo me-1"></i>
                                        <?php echo htmlspecialchars($student['residence_type']); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Социальные данные -->
                    <div class="tab-pane" id="social" role="tabpanel">
                        <div class="info-grid">
                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-shield-check"></i>
                                    Социальные категории
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Категория квоты</span>
                                    <span class="data-value"><?php echo htmlspecialchars($student['quota_category'] ?? 'Не указана'); ?></span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Сирота</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['orphan'] ? 'status-warning' : 'status-active'; ?>">
                                            <?php echo $student['orphan'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Без попечения родителей</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['without_parental_care'] ? 'status-warning' : 'status-active'; ?>">
                                            <?php echo $student['without_parental_care'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Инвалидность</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['disability'] ? 'status-warning' : 'status-active'; ?>">
                                            <?php echo $student['disability'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Многодетная семья</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['large_family'] ? 'status-info' : 'status-active'; ?>">
                                            <?php echo $student['large_family'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Социальная помощь</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['social_assistance'] ? 'status-info' : 'status-active'; ?>">
                                            <?php echo $student['social_assistance'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Активности -->
                    <div class="tab-pane" id="activities" role="tabpanel">
                        <div class="info-grid">
                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-cup-hot"></i>
                                    Питание
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Горячее питание</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['hot_meal'] ? 'status-active' : 'status-warning'; ?>">
                                            <?php echo $student['hot_meal'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Бесплатное горячее питание</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['free_hot_meal'] ? 'status-active' : 'status-warning'; ?>">
                                            <?php echo $student['free_hot_meal'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                            </div>

                            <div class="info-section">
                                <h3 class="section-title">
                                    <i class="bi bi-people"></i>
                                    Участие в организациях
                                </h3>
                                <div class="data-row">
                                    <span class="data-label">Молодежный комитет</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['youth_committee'] ? 'status-active' : 'status-warning'; ?>">
                                            <?php echo $student['youth_committee'] ? 'Участвует' : 'Не участвует'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Студенческий парламент</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['student_parliament'] ? 'status-active' : 'status-warning'; ?>">
                                            <?php echo $student['student_parliament'] ? 'Участвует' : 'Не участвует'; ?>
                                        </span>
                                    </span>
                                </div>
                                <div class="data-row">
                                    <span class="data-label">Жас Сарбаз</span>
                                    <span class="data-value">
                                        <span class="status-indicator <?php echo $student['jas_sarbaz'] ? 'status-active' : 'status-warning'; ?>">
                                            <?php echo $student['jas_sarbaz'] ? 'Да' : 'Нет'; ?>
                                        </span>
                                    </span>
                                </div>
                                <?php if (!empty($student['competitions'])): ?>
                                    <div class="data-row">
                                        <span class="data-label">Соревнования</span>
                                        <span class="data-value"><?php echo nl2br(htmlspecialchars($student['competitions'])); ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Дополнительные поля -->
                    <?php if (!empty($student_dynamic_values)): ?>
                        <div class="tab-pane" id="additional" role="tabpanel">
                            <div class="info-grid">
                                <div class="info-section">
                                    <h3 class="section-title">
                                        <i class="bi bi-gear"></i>
                                        Дополнительные поля
                                    </h3>
                                    <?php foreach ($student_dynamic_values as $field): ?>
                                        <div class="data-row">
                                            <span class="data-label"><?php echo htmlspecialchars($dynamic_field_labels[$field['field_name']] ?? $field['field_name']); ?></span>
                                            <span class="data-value"><?php echo htmlspecialchars($field['field_value']); ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </section>

            <!-- Академический отпуск (если применимо) -->
            <?php if ($student['academic_leave'] || !empty($student['academic_leave_reason'])): ?>
                <section class="info-section" style="background: var(--warning-50); border-color: var(--warning-500);">
                    <h3 class="section-title">
                        <i class="bi bi-pause-circle"></i>
                        Академический отпуск
                    </h3>
                    <div class="data-row">
                        <span class="data-label">Статус</span>
                        <span class="data-value">
                            <span class="status-indicator status-warning">В академическом отпуске</span>
                        </span>
                    </div>
                    <?php if (!empty($student['academic_leave_reason'])): ?>
                        <div class="data-row">
                            <span class="data-label">Причина</span>
                            <span class="data-value"><?php echo htmlspecialchars($student['academic_leave_reason']); ?></span>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($student['academic_leave_order_date'])): ?>
                        <div class="data-row">
                            <span class="data-label">Дата приказа</span>
                            <span class="data-value"><?php echo htmlspecialchars($student['academic_leave_order_date']); ?></span>
                        </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </main>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
    <script>
        // Функциональность табов
        document.addEventListener('DOMContentLoaded', function() {
            const tabButtons = document.querySelectorAll('.tab-button');
            const tabPanes = document.querySelectorAll('.tab-pane');

            tabButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;

                    // Убираем активный класс со всех кнопок и панелей
                    tabButtons.forEach(btn => {
                        btn.classList.remove('active');
                        btn.setAttribute('aria-selected', 'false');
                    });
                    tabPanes.forEach(pane => pane.classList.remove('active'));

                    // Добавляем активный класс к текущей кнопке и панели
                    this.classList.add('active');
                    this.setAttribute('aria-selected', 'true');
                    document.getElementById(targetTab).classList.add('active');
                });
            });

            // Горячие клавиши для табов
            document.addEventListener('keydown', function(e) {
                if (e.altKey && e.key >= '1' && e.key <= '6') {
                    const tabIndex = parseInt(e.key) - 1;
                    if (tabButtons[tabIndex]) {
                        e.preventDefault();
                        tabButtons[tabIndex].click();
                        tabButtons[tabIndex].focus();
                    }
                }
            });

            console.log('🔥 Горячие клавиши:');
            console.log('Alt + 1 - Личные данные');
            console.log('Alt + 2 - Обучение');
            console.log('Alt + 3 - Контакты');
            console.log('Alt + 4 - Социальные данные');
            console.log('Alt + 5 - Активности');
            console.log('Alt + 6 - Дополнительно');
        });
    </script>
</body>

</html>