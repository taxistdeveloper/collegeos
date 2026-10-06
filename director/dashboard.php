<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_reports');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение статистики по студентам
$db = getDB();

$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');

// Общая статистика студентов
$sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
    SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
    SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated_students,
    SUM(CASE WHEN disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
    SUM(CASE WHEN without_parental_care = 1 THEN 1 ELSE 0 END) as without_parental_care_students,
    SUM(CASE WHEN large_family = 1 THEN 1 ELSE 0 END) as large_family_students,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 18 THEN 1 ELSE 0 END) as minor_students
    FROM students";
$result = $db->query($sql);
$stats = $result->fetch_assoc();

// Статистика по группам с кураторами
$sql = "SELECT 
    g.id as group_id,
    g.name as group_name,
    g.code as group_code,
    g.course,
    g.specialty,
    CONCAT(u.last_name, ' ', u.first_name, ' ', IFNULL(u.middle_name, '')) as curator_name,
    u.email as curator_email,
    COUNT(s.id) as student_count,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_count,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_count,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_count
    FROM `groups` g
    LEFT JOIN students s ON g.id = s.group_id
    LEFT JOIN users u ON g.curator_id = u.id
    GROUP BY g.id, g.name, g.code, g.course, g.specialty, u.id, u.first_name, u.last_name, u.middle_name, u.email
    ORDER BY g.course, g.name";
$result = $db->query($sql);
$groups_stats = $result->fetch_all(MYSQLI_ASSOC);

// Получение списка всех студентов с подробной информацией, сгруппированных по группам
$sql = "SELECT 
    s.id,
    s.first_name,
    s.last_name,
    s.middle_name,
    s.course,
    s.phone,
    s.email,
    s.birth_date,
    TIMESTAMPDIFF(YEAR, s.birth_date, CURDATE()) as age,
    s.disability,
    s.orphan,
    s.academic_leave,
    s.without_parental_care,
    s.large_family,
    s.primary_violation,
    g.id as group_id,
    g.name as group_name,
    g.code as group_code,
    g.specialty as group_specialty,
    CONCAT(u.last_name, ' ', u.first_name, ' ', IFNULL(u.middle_name, '')) as curator_name
    FROM students s
    LEFT JOIN `groups` g ON s.group_id = g.id
    LEFT JOIN users u ON g.curator_id = u.id
    ORDER BY g.course, g.name, s.last_name, s.first_name";
$result = $db->query($sql);
$all_students = $result->fetch_all(MYSQLI_ASSOC);

// Группируем студентов по группам
$students_by_group = [];
foreach ($all_students as $student) {
    $group_id = $student['group_id'] ?? 'no_group';
    if (!isset($students_by_group[$group_id])) {
        $students_by_group[$group_id] = [
            'group_name' => $student['group_name'] ?? 'Без группы',
            'group_code' => $student['group_code'] ?? '',
            'group_specialty' => $student['group_specialty'] ?? '',
            'course' => $student['course'] ?? '',
            'curator_name' => $student['curator_name'] ?? '',
            'students' => []
        ];
    }
    $students_by_group[$group_id]['students'][] = $student;
}

// Статистика по курсам
$sql = "SELECT 
    course,
    COUNT(DISTINCT s.id) as total_students,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students
    FROM students s
    GROUP BY course
    ORDER BY course";
$result = $db->query($sql);
$courses_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по инвалидности
$sql = "SELECT 
    primary_violation,
    COUNT(*) as count
    FROM students 
    WHERE disability = 1 AND primary_violation IS NOT NULL
    GROUP BY primary_violation
    ORDER BY count DESC";
$result = $db->query($sql);
$disability_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по социальным категориям
$sql = "SELECT 
    'Дети-сироты' as category,
    COUNT(*) as count
    FROM students WHERE orphan = 1
    UNION ALL
    SELECT 
    'Дети без попечения родителей' as category,
    COUNT(*) as count
    FROM students WHERE without_parental_care = 1
    UNION ALL
    SELECT 
    'Из многодетных семей' as category,
    COUNT(*) as count
    FROM students WHERE large_family = 1
    UNION ALL
    SELECT 
    'С инвалидностью' as category,
    COUNT(*) as count
    FROM students WHERE disability = 1";
$result = $db->query($sql);
$social_stats = $result->fetch_all(MYSQLI_ASSOC);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=SF+Pro+Display:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap');

        :root {
            --color-primary: #6366f1;
            --color-secondary: #8b5cf6;
            --color-success: #10b981;
            --color-warning: #f59e0b;
            --color-danger: #ef4444;
            --color-info: #06b6d4;

            --bg-body: #f9fafb;
            --bg-surface: #ffffff;
            --bg-hover: #f3f4f6;

            --text-primary: #111827;
            --text-secondary: #6b7280;
            --text-muted: #9ca3af;

            --border-light: #e5e7eb;
            --border-medium: #d1d5db;

            --shadow-xs: 0 1px 2px rgba(0, 0, 0, 0.05);
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1), 0 1px 2px rgba(0, 0, 0, 0.06);
            --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --shadow-xl: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);

            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 20px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-body);
            color: var(--text-primary);
            line-height: 1.6;
            min-height: 100vh;
            position: relative;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Minimal background */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background-image:
                linear-gradient(rgba(0, 212, 255, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 212, 255, 0.03) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
            z-index: 0;
            animation: gridMove 20s linear infinite;
        }

        @keyframes gridMove {
            0% {
                background-position: 0 0;
            }

            100% {
                background-position: 50px 50px;
            }
        }

        /* Glowing orbs */
        body::after {
            content: '';
            position: fixed;
            width: 600px;
            height: 600px;
            background: radial-gradient(circle, rgba(0, 212, 255, 0.1), transparent 70%);
            top: -300px;
            right: -300px;
            border-radius: 50%;
            pointer-events: none;
            z-index: 0;
            animation: float 25s ease-in-out infinite;
        }

        @keyframes float {

            0%,
            100% {
                transform: translate(0, 0);
            }

            50% {
                transform: translate(-150px, 150px);
            }
        }

        /* Navbar - Clean & Minimal */
        .navbar {
            background: var(--bg-surface) !important;
            backdrop-filter: blur(10px);
            border-bottom: 1px solid var(--border-light);
            box-shadow: var(--shadow-sm);
            padding: 1.25rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.5rem;
            color: var(--text-primary) !important;
            letter-spacing: -0.5px;
        }

        .navbar-brand i {
            color: var(--color-primary);
            margin-right: 0.5rem;
        }

        .nav-link {
            color: var(--text-secondary) !important;
            font-weight: 600;
            border-radius: var(--radius-md);
            margin: 0 0.25rem;
            padding: 0.65rem 1.25rem !important;
            transition: all 0.2s ease;
        }

        .nav-link:hover {
            background: var(--bg-hover);
            color: var(--color-primary) !important;
        }

        .navbar-text {
            color: var(--text-primary) !important;
            font-weight: 500;
        }

        .container,
        .container-fluid {
            padding: 2rem 3rem;
            position: relative;
            z-index: 1;
        }

        /* Stats Cards - Minimal & Clean */
        .stat-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: 2rem;
            position: relative;
            overflow: hidden;
            transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            animation: fadeIn 0.6s ease-out;
        }

        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 3px;
            background: var(--accent-blue);
            box-shadow: 0 0 15px var(--accent-blue);
        }

        .stat-card::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: radial-gradient(circle at top left, rgba(0, 212, 255, 0.05), transparent 60%);
            opacity: 0;
            transition: opacity 0.3s ease;
        }

        .stat-card:hover {
            transform: translateY(-10px);
            border-color: var(--accent-blue);
            box-shadow: 0 20px 60px rgba(0, 212, 255, 0.3),
                0 0 40px rgba(0, 212, 255, 0.2);
        }

        .stat-card:hover::after {
            opacity: 1;
        }

        .stat-card.primary {
            border-color: rgba(0, 212, 255, 0.3);
        }

        .stat-card.primary::before {
            background: var(--accent-blue);
            box-shadow: 0 0 20px var(--accent-blue);
        }

        .stat-card.primary:hover {
            box-shadow: 0 20px 60px rgba(0, 212, 255, 0.4),
                0 0 50px rgba(0, 212, 255, 0.3);
        }

        .stat-card.success {
            border-color: rgba(0, 245, 184, 0.3);
        }

        .stat-card.success::before {
            background: var(--accent-green);
            box-shadow: 0 0 20px var(--accent-green);
        }

        .stat-card.success:hover {
            border-color: var(--accent-green);
            box-shadow: 0 20px 60px rgba(0, 245, 184, 0.4),
                0 0 50px rgba(0, 245, 184, 0.3);
        }

        .stat-card.warning {
            border-color: rgba(255, 107, 53, 0.3);
        }

        .stat-card.warning::before {
            background: var(--accent-orange);
            box-shadow: 0 0 20px var(--accent-orange);
        }

        .stat-card.warning:hover {
            border-color: var(--accent-orange);
            box-shadow: 0 20px 60px rgba(255, 107, 53, 0.4),
                0 0 50px rgba(255, 107, 53, 0.3);
        }

        .stat-card.info {
            border-color: rgba(168, 85, 247, 0.3);
        }

        .stat-card.info::before {
            background: var(--accent-purple);
            box-shadow: 0 0 20px var(--accent-purple);
        }

        .stat-card.info:hover {
            border-color: var(--accent-purple);
            box-shadow: 0 20px 60px rgba(168, 85, 247, 0.4),
                0 0 50px rgba(168, 85, 247, 0.3);
        }

        .stat-card.danger::before {
            background: var(--color-danger);
            box-shadow: 0 0 20px var(--color-danger);
        }

        .stat-card.danger:hover {
            border-color: var(--color-danger);
            box-shadow: 0 20px 60px rgba(239, 68, 68, 0.4),
                0 0 50px rgba(239, 68, 68, 0.3);
        }

        .stat-number {
            font-size: 3rem;
            font-weight: 800;
            line-height: 1;
            margin-bottom: 0.5rem;
            color: var(--text-primary);
        }

        .stat-card.primary .stat-number {
            color: var(--color-primary);
        }

        .stat-card.success .stat-number {
            color: var(--color-success);
        }

        .stat-card.warning .stat-number {
            color: var(--color-warning);
        }

        .stat-card.info .stat-number {
            color: var(--color-info);
        }

        .stat-label {
            font-size: 0.9rem;
            color: var(--text-secondary);
            font-weight: 600;
            letter-spacing: 0.3px;
        }

        .stat-icon {
            position: absolute;
            right: 1.5rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 4rem;
            opacity: 0.06;
            color: currentColor;
        }

        /* Cards - Clean Design */
        .card {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            margin-bottom: 2rem;
            overflow: hidden;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .card:hover {
            box-shadow: var(--shadow-lg);
        }

        .card-header {
            background: var(--bg-hover);
            border-bottom: 1px solid var(--border-light);
            padding: 1.5rem 2rem;
        }

        .card-title {
            color: var(--text-primary) !important;
            font-weight: 700;
            font-size: 1.25rem;
            margin: 0;
        }

        .card-title i {
            color: var(--color-primary) !important;
            margin-right: 0.5rem;
        }

        .card-body {
            padding: 2rem;
        }

        /* Table - Minimal */
        .table {
            margin: 0;
            color: var(--text-primary);
        }

        .table thead th {
            background: var(--bg-hover);
            border: none;
            color: var(--text-primary);
            font-weight: 700;
            padding: 1rem 1.5rem;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border-bottom: 2px solid var(--border-light);
        }

        .table tbody td {
            border: none;
            padding: 1rem 1.5rem;
            border-bottom: 1px solid var(--border-light);
            vertical-align: middle;
            color: var(--text-primary);
        }

        .table tbody tr {
            transition: all 0.2s ease;
        }

        .table tbody tr:hover {
            background: var(--bg-hover);
        }

        /* Badges - Modern */
        .badge {
            border-radius: var(--radius-sm);
            font-weight: 600;
            padding: 0.5rem 1rem;
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .badge.bg-warning {
            background: rgba(245, 158, 11, 0.1) !important;
            color: var(--color-warning) !important;
            border: 1px solid rgba(245, 158, 11, 0.2);
        }

        .badge.bg-info {
            background: rgba(6, 182, 212, 0.1) !important;
            color: var(--color-info) !important;
            border: 1px solid rgba(6, 182, 212, 0.2);
        }

        .badge.bg-secondary {
            background: var(--bg-hover) !important;
            color: var(--text-secondary) !important;
            border: 1px solid var(--border-light);
        }

        .badge.bg-primary {
            background: rgba(99, 102, 241, 0.1) !important;
            color: var(--color-primary) !important;
            border: 1px solid rgba(99, 102, 241, 0.2);
        }

        .badge.bg-danger {
            background: rgba(239, 68, 68, 0.1) !important;
            color: var(--color-danger) !important;
            border: 1px solid rgba(239, 68, 68, 0.2);
        }

        .badge.bg-success {
            background: rgba(16, 185, 129, 0.1) !important;
            color: var(--color-success) !important;
            border: 1px solid rgba(16, 185, 129, 0.2);
        }

        /* Buttons - Clean */
        .btn {
            border-radius: var(--radius-md);
            font-weight: 600;
            padding: 0.75rem 1.5rem;
            transition: all 0.2s ease;
            border: none;
            font-size: 0.9rem;
        }

        .btn-primary {
            background: var(--color-primary) !important;
            color: white;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-lg);
            background: var(--color-secondary) !important;
        }

        /* Social Cards - Minimal */
        .social-card {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .social-card:hover {
            transform: translateY(-4px);
            box-shadow: var(--shadow-lg);
        }

        .social-card .social-number {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--color-primary);
            margin-bottom: 0.5rem;
        }

        .social-card .social-label {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 0.9rem;
        }

        /* Page Header - Minimal */
        .page-header {
            background: var(--bg-surface);
            border: 1px solid var(--border-light);
            border-radius: var(--radius-xl);
            padding: 2rem;
            margin-bottom: 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: var(--shadow-sm);
        }

        .page-header h1 {
            font-size: 2rem;
            font-weight: 700;
            color: var(--text-primary);
            margin: 0;
        }

        .page-header h1 i {
            color: var(--color-primary);
            margin-right: 0.5rem;
        }

        /* Animations */
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        /* User Badge */
        .user-badge {
            background: var(--color-primary);
            color: white !important;
            padding: 0.5rem 1.25rem;
            border-radius: 50px;
            font-weight: 600;
            box-shadow: var(--shadow-sm);
        }

        /* Search Input */
        .form-control {
            border: 1px solid var(--border-light);
            border-radius: var(--radius-md);
            padding: 0.65rem 1rem;
            font-size: 0.9rem;
            transition: all 0.2s ease;
            background: var(--bg-surface);
            color: var(--text-primary);
        }

        .form-control:focus {
            border-color: var(--color-primary);
            box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1);
            outline: none;
        }

        .form-control::placeholder {
            color: var(--text-muted);
        }

        /* Accordion Styles */
        .accordion-button:not(.collapsed) {
            background-color: var(--bg-hover);
            color: var(--text-primary);
            box-shadow: none;
        }

        .accordion-button:focus {
            box-shadow: none;
            border-color: var(--border-light);
        }

        .accordion-button::after {
            color: var(--color-primary);
        }

        .group-section {
            transition: all 0.3s ease;
            animation: fadeIn 0.5s ease-out;
        }

        .group-section:hover {
            box-shadow: var(--shadow-md);
            transform: translateY(-2px);
        }

        /* Button group */
        .d-flex.gap-2 {
            gap: 0.5rem;
        }

        /* Responsive */
        @media (max-width: 768px) {

            .container,
            .container-fluid {
                padding: 1rem 1.5rem;
            }

            .stat-card {
                padding: 1.5rem;
            }

            .stat-number {
                font-size: 2.5rem;
            }

            .stat-icon {
                font-size: 3rem;
            }

            .page-header {
                flex-direction: column;
                gap: 1rem;
            }

            .page-header h1 {
                font-size: 1.8rem;
            }

            .card-body {
                padding: 1.5rem;
            }

            .table {
                font-size: 0.9rem;
            }
        }

        @media print {

            body::before,
            body::after,
            .navbar,
            .btn {
                display: none !important;
            }

            body {
                background: white;
                color: black;
            }

            .card,
            .stat-card {
                box-shadow: none;
                page-break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-person-badge me-2"></i>
                Панель директора
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <div class="navbar-text ms-auto" style="font-size: 1.35rem; font-weight: 600; padding-right: 24px;">
                    <?php
                    // Получаем имя текущего пользователя
                    require_once '../includes/auth.php';
                    $current_user = getCurrentUser();
                    $user_name = htmlspecialchars($current_user['name'] ?? '');
                    // Получаем роль пользователя для большего информирования
                    $user_role = htmlspecialchars($current_user['role'] ?? '');
                    $role_names = [
                        'admin' => 'Администратор',
                        'director' => 'Директор',
                        'manager' => 'Менеджер',
                        'curator' => 'Куратор'
                    ];
                    $role_label = $role_names[$user_role] ?? ucfirst($user_role);
                    echo "<span style='font-size:2rem; vertical-align:middle; margin-right:10px;'>👤</span>";
                    echo "Привет, <span style='color:#007AFF;'>$user_name</span>!";
                    if ($role_label) {
                        echo " <span class='badge bg-primary ms-2' style='font-size:1rem;'>$role_label</span>";
                    }
                    ?>
                </div>
                <ul class="navbar-nav">
                    <li class="nav-item">
                        <a class="nav-link" href="../logout.php">
                            <i class="bi bi-box-arrow-right me-1"></i>Выход
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid mt-4">
        <div class="row">
            <div class="col-12">
                <!-- Page Header -->
                <div class="page-header">
                    <h1>
                        <i class="bi bi-graph-up me-2"></i>
                        Отчеты по студентам
                    </h1>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-2"></i>Печать
                    </button>
                </div>

                <!-- Общая статистика -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary">
                            <div class="stat-number"><?php echo $stats['total_students']; ?></div>
                            <div class="stat-label">Всего студентов</div>
                            <i class="bi bi-people-fill stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card success">
                            <div class="stat-number"><?php echo $stats['active_students']; ?></div>
                            <div class="stat-label">Активных студентов</div>
                            <i class="bi bi-person-check-fill stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning">
                            <div class="stat-number"><?php echo $stats['disabled_students']; ?></div>
                            <div class="stat-label">С инвалидностью</div>
                            <i class="bi bi-person-wheelchair stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card info">
                            <div class="stat-number"><?php echo $stats['orphan_students']; ?></div>
                            <div class="stat-label">Дети-сироты</div>
                            <i class="bi bi-heart-fill stat-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Дополнительная статистика -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="stat-card danger">
                            <div class="stat-number" style="color: var(--color-danger);"><?php echo $stats['minor_students']; ?></div>
                            <div class="stat-label">Несовершеннолетних</div>
                            <i class="bi bi-person-badge stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card warning">
                            <div class="stat-number"><?php echo $stats['academic_leave_students']; ?></div>
                            <div class="stat-label">В академ. отпуске</div>
                            <i class="bi bi-pause-circle stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card info">
                            <div class="stat-number"><?php echo $stats['without_parental_care_students']; ?></div>
                            <div class="stat-label">Без попечения</div>
                            <i class="bi bi-shield-exclamation stat-icon"></i>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="stat-card primary">
                            <div class="stat-number"><?php echo $stats['large_family_students']; ?></div>
                            <div class="stat-label">Из многодетных семей</div>
                            <i class="bi bi-house-heart stat-icon"></i>
                        </div>
                    </div>
                </div>

                <!-- Статистика по курсам -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-mortarboard-fill me-2"></i>
                            Статистика по курсам
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Курс</th>
                                        <th>Всего студентов</th>
                                        <th>С инвалидностью</th>
                                        <th>Дети-сироты</th>
                                        <th>В академ. отпуске</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($courses_stats as $course): ?>
                                        <tr>
                                            <td><strong><?php echo htmlspecialchars($course['course']); ?></strong></td>
                                            <td><?php echo $course['total_students']; ?></td>
                                            <td>
                                                <?php if ($course['disabled_students'] > 0): ?>
                                                    <span class="badge bg-warning"><?php echo $course['disabled_students']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $course['disabled_students']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($course['orphan_students'] > 0): ?>
                                                    <span class="badge bg-info"><?php echo $course['orphan_students']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $course['orphan_students']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($course['academic_leave_students'] > 0): ?>
                                                    <span class="badge bg-secondary"><?php echo $course['academic_leave_students']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $course['academic_leave_students']; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Статистика по группам -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-collection me-2"></i>
                            Статистика по группам и кураторам
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Группа</th>
                                        <th>Код</th>
                                        <th>Курс</th>
                                        <th>Специальность</th>
                                        <th>Куратор</th>
                                        <th>Студентов</th>
                                        <th>С инвалидностью</th>
                                        <th>Дети-сироты</th>
                                        <th>В академ. отпуске</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groups_stats as $group): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                            </td>
                                            <td>
                                                <span class="badge bg-primary"><?php echo htmlspecialchars($group['group_code'] ?? 'N/A'); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($group['course']); ?></td>
                                            <td><?php echo htmlspecialchars($group['specialty']); ?></td>
                                            <td>
                                                <?php if ($group['curator_name']): ?>
                                                    <i class="bi bi-person-badge me-1"></i>
                                                    <?php echo htmlspecialchars($group['curator_name']); ?>
                                                    <?php if ($group['curator_email']): ?>
                                                        <br><small class="text-muted">
                                                            <i class="bi bi-envelope me-1"></i>
                                                            <?php echo htmlspecialchars($group['curator_email']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary">Не назначен</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?php echo $group['student_count']; ?></strong></td>
                                            <td>
                                                <?php if ($group['disabled_count'] > 0): ?>
                                                    <span class="badge bg-warning"><?php echo $group['disabled_count']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $group['disabled_count']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($group['orphan_count'] > 0): ?>
                                                    <span class="badge bg-info"><?php echo $group['orphan_count']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $group['orphan_count']; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($group['academic_leave_count'] > 0): ?>
                                                    <span class="badge bg-secondary"><?php echo $group['academic_leave_count']; ?></span>
                                                <?php else: ?>
                                                    <?php echo $group['academic_leave_count']; ?>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Статистика по инвалидности -->
                <?php if (!empty($disability_stats)): ?>
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-wheelchair me-2"></i>
                                Статистика по инвалидности
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Тип нарушения</th>
                                            <th>Количество студентов</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($disability_stats as $disability): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($disability['primary_violation']); ?></td>
                                                <td>
                                                    <span class="badge bg-warning"><?php echo $disability['count']; ?></span>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Социальные категории -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-heart me-2"></i>
                            Социальные категории
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <?php foreach ($social_stats as $social): ?>
                                <div class="col-md-3 mb-3">
                                    <div class="social-card">
                                        <div class="social-number"><?php echo $social['count']; ?></div>
                                        <div class="social-label"><?php echo htmlspecialchars($social['category']); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Подробный список всех студентов по группам -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-people-fill me-2"></i>
                            Подробная информация о студентах по группам
                        </h5>
                        <div class="d-flex gap-2">
                            <button class="btn btn-sm btn-outline-primary" onclick="expandAllGroups()">
                                <i class="bi bi-arrows-expand me-1"></i>Развернуть все
                            </button>
                            <button class="btn btn-sm btn-outline-primary" onclick="collapseAllGroups()">
                                <i class="bi bi-arrows-collapse me-1"></i>Свернуть все
                            </button>
                            <input type="text" id="studentSearch" class="form-control" placeholder="Поиск..." style="min-width: 250px;">
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="accordion" id="groupsAccordion">
                            <?php
                            $group_counter = 0;
                            foreach ($students_by_group as $group_id => $group_data):
                                $group_counter++;
                                $student_count = count($group_data['students']);
                            ?>
                                <div class="accordion-item group-section" style="border: 1px solid var(--border-light); border-radius: var(--radius-md); margin-bottom: 1rem; overflow: hidden;">
                                    <h2 class="accordion-header" id="heading<?php echo $group_id; ?>">
                                        <button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#collapse<?php echo $group_id; ?>" aria-expanded="true" aria-controls="collapse<?php echo $group_id; ?>" style="background: var(--bg-hover); font-weight: 600;">
                                            <div class="d-flex align-items-center w-100">
                                                <i class="bi bi-collection me-2" style="color: var(--color-primary);"></i>
                                                <div class="flex-grow-1">
                                                    <strong><?php echo htmlspecialchars($group_data['group_name']); ?></strong>
                                                    <?php if ($group_data['group_code']): ?>
                                                        <span class="badge bg-primary ms-2"><?php echo htmlspecialchars($group_data['group_code']); ?></span>
                                                    <?php endif; ?>
                                                    <?php if ($group_data['course']): ?>
                                                        <span class="badge bg-info ms-2"><?php echo htmlspecialchars($group_data['course']); ?></span>
                                                    <?php endif; ?>
                                                    <span class="badge bg-success ms-2"><?php echo $student_count; ?> студентов</span>
                                                </div>
                                                <div class="me-3">
                                                    <?php if ($group_data['curator_name']): ?>
                                                        <small class="text-muted">
                                                            <i class="bi bi-person-badge me-1"></i>
                                                            Куратор: <?php echo htmlspecialchars($group_data['curator_name']); ?>
                                                        </small>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="collapse<?php echo $group_id; ?>" class="accordion-collapse collapse show" aria-labelledby="heading<?php echo $group_id; ?>" data-bs-parent="#groupsAccordion">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table table-striped mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th style="width: 50px;">№</th>
                                                            <th>ФИО</th>
                                                            <th style="width: 100px;">Возраст</th>
                                                            <th>Контакты</th>
                                                            <th>Особые отметки</th>
                                                            <th style="width: 120px;">Статус</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $student_counter = 1;
                                                        foreach ($group_data['students'] as $student):
                                                            $full_name = trim(htmlspecialchars($student['last_name']) . ' ' .
                                                                htmlspecialchars($student['first_name']) . ' ' .
                                                                htmlspecialchars($student['middle_name']));
                                                        ?>
                                                            <tr class="student-row">
                                                                <td><?php echo $student_counter++; ?></td>
                                                                <td>
                                                                    <strong><?php echo $full_name; ?></strong>
                                                                </td>
                                                                <td>
                                                                    <?php
                                                                    $age = $student['age'];
                                                                    $is_minor = $age < 18;
                                                                    ?>
                                                                    <strong><?php echo $age; ?> лет</strong>
                                                                    <?php if ($is_minor): ?>
                                                                        <br><span class="badge bg-danger" style="font-size: 0.7rem;">
                                                                            <i class="bi bi-exclamation-triangle me-1"></i>Несовершеннолетний
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($student['phone']): ?>
                                                                        <i class="bi bi-telephone me-1"></i>
                                                                        <small><?php echo htmlspecialchars($student['phone']); ?></small><br>
                                                                    <?php endif; ?>
                                                                    <?php if ($student['email']): ?>
                                                                        <i class="bi bi-envelope me-1"></i>
                                                                        <small><?php echo htmlspecialchars($student['email']); ?></small>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($student['disability']): ?>
                                                                        <span class="badge bg-warning mb-1">
                                                                            <i class="bi bi-person-wheelchair me-1"></i>Инвалидность
                                                                        </span>
                                                                        <?php if ($student['primary_violation']): ?>
                                                                            <br><small class="text-muted"><?php echo htmlspecialchars($student['primary_violation']); ?></small>
                                                                        <?php endif; ?>
                                                                    <?php endif; ?>
                                                                    <?php if ($student['orphan']): ?>
                                                                        <span class="badge bg-info mb-1">
                                                                            <i class="bi bi-heart me-1"></i>Сирота
                                                                        </span><br>
                                                                    <?php endif; ?>
                                                                    <?php if ($student['without_parental_care']): ?>
                                                                        <span class="badge bg-info mb-1">Без попечения</span><br>
                                                                    <?php endif; ?>
                                                                    <?php if ($student['large_family']): ?>
                                                                        <span class="badge bg-primary mb-1">
                                                                            <i class="bi bi-house-heart me-1"></i>Многодетная семья
                                                                        </span><br>
                                                                    <?php endif; ?>
                                                                    <?php if (!$student['disability'] && !$student['orphan'] && !$student['without_parental_care'] && !$student['large_family']): ?>
                                                                        <span class="text-muted">—</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($student['academic_leave']): ?>
                                                                        <span class="badge bg-secondary">
                                                                            <i class="bi bi-pause-circle me-1"></i>Академ. отпуск
                                                                        </span>
                                                                    <?php else: ?>
                                                                        <span class="badge bg-success">
                                                                            <i class="bi bi-check-circle me-1"></i>Активен
                                                                        </span>
                                                                    <?php endif; ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="mt-3">
                            <p class="text-muted">
                                <i class="bi bi-info-circle me-2"></i>
                                Всего групп: <strong><?php echo count($students_by_group); ?></strong> |
                                Всего студентов: <strong><?php echo count($all_students); ?></strong>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/tooltips.js"></script>
    <script>
        // Smooth scroll animations
        document.addEventListener('DOMContentLoaded', function() {
            // Add stagger animation to stat cards
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });

            // Add stagger animation to regular cards
            const cards = document.querySelectorAll('.card');
            cards.forEach((card, index) => {
                card.style.animationDelay = `${0.4 + index * 0.1}s`;
            });

            // Add stagger animation to social cards
            const socialCards = document.querySelectorAll('.social-card');
            socialCards.forEach((card, index) => {
                card.style.animationDelay = `${index * 0.1}s`;
            });

            // Intersection Observer for scroll animations
            const observerOptions = {
                threshold: 0.1,
                rootMargin: '0px 0px -50px 0px'
            };

            const observer = new IntersectionObserver(function(entries) {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        entry.target.style.opacity = '1';
                        entry.target.style.transform = 'translateY(0)';
                    }
                });
            }, observerOptions);

            // Observe all cards
            document.querySelectorAll('.card, .stat-card, .social-card, .page-header').forEach(el => {
                observer.observe(el);
            });

            // Add smooth hover effects to table rows
            const tableRows = document.querySelectorAll('.table tbody tr');
            tableRows.forEach(row => {
                row.addEventListener('mouseenter', function() {
                    this.style.transform = 'scale(1.01)';
                });
                row.addEventListener('mouseleave', function() {
                    this.style.transform = 'scale(1)';
                });
            });
        });

        // Print optimization
        window.addEventListener('beforeprint', function() {
            document.body.style.background = 'white';
        });

        window.addEventListener('afterprint', function() {
            document.body.style.background = '';
        });

        // Функции для управления группами
        function expandAllGroups() {
            const collapses = document.querySelectorAll('.accordion-collapse');
            collapses.forEach(collapse => {
                const bsCollapse = new bootstrap.Collapse(collapse, {
                    toggle: false
                });
                bsCollapse.show();
            });
        }

        function collapseAllGroups() {
            const collapses = document.querySelectorAll('.accordion-collapse');
            collapses.forEach(collapse => {
                const bsCollapse = new bootstrap.Collapse(collapse, {
                    toggle: false
                });
                bsCollapse.hide();
            });
        }

        // Search functionality for students in groups
        const studentSearchInput = document.getElementById('studentSearch');

        if (studentSearchInput) {
            studentSearchInput.addEventListener('input', function(e) {
                const searchTerm = e.target.value.toLowerCase().trim();
                const groupSections = document.querySelectorAll('.group-section');

                let totalVisible = 0;

                groupSections.forEach(section => {
                    const rows = section.querySelectorAll('.student-row');
                    let hasVisibleStudents = false;

                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        if (text.includes(searchTerm)) {
                            row.style.display = '';
                            hasVisibleStudents = true;
                            totalVisible++;
                        } else {
                            row.style.display = 'none';
                        }
                    });

                    // Показываем/скрываем всю секцию группы
                    if (searchTerm === '') {
                        section.style.display = '';
                    } else if (hasVisibleStudents) {
                        section.style.display = '';
                        // Автоматически разворачиваем группу при поиске
                        const collapse = section.querySelector('.accordion-collapse');
                        if (collapse && !collapse.classList.contains('show')) {
                            const bsCollapse = new bootstrap.Collapse(collapse, {
                                toggle: false
                            });
                            bsCollapse.show();
                        }
                    } else {
                        section.style.display = 'none';
                    }
                });

                // Логирование результатов поиска
                if (searchTerm) {
                    console.log(`Найдено студентов: ${totalVisible}`);
                }
            });
        }

        // Add keyboard shortcut for search (Ctrl+F or Cmd+F)
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                const searchInput = document.getElementById('studentSearch');
                if (searchInput) {
                    e.preventDefault();
                    searchInput.focus();
                    searchInput.select();
                }
            }
        });
    </script>
</body>

</html>