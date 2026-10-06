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

// Получение всех групп с детальной статистикой
$db = getDB();

// Параметры фильтрации
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$language_filter = isset($_GET['language']) ? $_GET['language'] : '';
$study_form_filter = isset($_GET['study_form']) ? $_GET['study_form'] : '';
$curator_filter = isset($_GET['curator']) ? (int)$_GET['curator'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$not_graduated = sqlNotGraduatedCondition('s');

// Построение SQL запроса с фильтрами
$sql = "SELECT g.*, 
               COUNT(s.id) as current_students,
               SUM(CASE WHEN s.academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
               SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
               SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
               SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
               u.first_name as curator_first_name, u.last_name as curator_last_name,
               ROUND((COUNT(s.id) / g.max_students) * 100, 1) as occupancy_percentage
        FROM `groups` g 
        LEFT JOIN students s ON g.id = s.group_id 
        LEFT JOIN users u ON g.curator_id = u.id
        WHERE 1=1";

$params = [];
$types = '';

// Фильтр по статусу
if ($status_filter) {
    switch ($status_filter) {
        case 'active':
            $sql .= " AND g.is_active = 1";
            break;
        case 'inactive':
            $sql .= " AND g.is_active = 0";
            break;
        case 'full':
            $sql .= " AND COUNT(s.id) >= g.max_students";
            break;
        case 'available':
            $sql .= " AND COUNT(s.id) < g.max_students";
            break;
    }
}

// Фильтр по курсу
if ($course_filter) {
    $sql .= " AND g.course = ?";
    $params[] = $course_filter;
    $types .= 's';
}

// Фильтр по языку
if ($language_filter) {
    $sql .= " AND g.language = ?";
    $params[] = $language_filter;
    $types .= 's';
}

// Фильтр по форме обучения
if ($study_form_filter) {
    $sql .= " AND g.study_form = ?";
    $params[] = $study_form_filter;
    $types .= 's';
}

// Фильтр по куратору
if ($curator_filter > 0) {
    $sql .= " AND g.curator_id = ?";
    $params[] = $curator_filter;
    $types .= 'i';
}

// Поиск
if ($search) {
    $sql .= " AND (g.name LIKE ? OR g.code LIKE ? OR g.specialty LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$sql .= " GROUP BY g.id ORDER BY g.course, g.name";

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result->fetch_all(MYSQLI_ASSOC);
} elseif ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result->fetch_all(MYSQLI_ASSOC);
} else {
    // Fallback запрос без фильтров
    $sql = "SELECT g.*, 
                   COUNT(s.id) as current_students,
                   SUM(CASE WHEN s.academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
                   SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
                   SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
                   SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
                   u.first_name as curator_first_name, u.last_name as curator_last_name,
                   ROUND((COUNT(s.id) / g.max_students) * 100, 1) as occupancy_percentage
            FROM `groups` g 
            LEFT JOIN students s ON g.id = s.group_id 
            LEFT JOIN users u ON g.curator_id = u.id
            GROUP BY g.id 
            ORDER BY g.course, g.name";
    $result = $db->query($sql);
    $groups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Получение всех кураторов для фильтра
$curators = $user->getUsersByRole(4); // ID роли куратора

// Общая статистика
$total_groups = count($groups);
$total_students = array_sum(array_column($groups, 'current_students'));
$active_groups = count(array_filter($groups, function ($g) {
    return $g['is_active'];
}));
$avg_occupancy = $total_groups > 0 ? round(array_sum(array_column($groups, 'occupancy_percentage')) / $total_groups, 1) : 0;
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Все группы - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

        :root {
            --primary-color: #2563eb;
            --secondary-color: #10b981;
            --accent-color: #f59e0b;
            --danger-color: #ef4444;
            --dark-bg: #1e293b;
            --light-bg: #f8fafc;
            --card-bg: #ffffff;
            --text-primary: #0f172a;
            --text-secondary: #64748b;
            --border-color: #e2e8f0;
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.05);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.07);
            --shadow-lg: 0 10px 15px rgba(0, 0, 0, 0.1);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.15);
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            background: var(--light-bg);
            font-family: 'Poppins', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            color: var(--text-primary);
            line-height: 1.6;
        }

        /* Modern Navbar - Flat Style */
        .navbar-modern {
            background: var(--card-bg) !important;
            box-shadow: var(--shadow-md);
            border-bottom: 3px solid var(--primary-color);
            padding: 1rem 0;
        }

        .navbar-brand {
            font-weight: 700;
            font-size: 1.4rem;
            color: var(--primary-color) !important;
            letter-spacing: -0.5px;
        }

        .navbar-brand i {
            color: var(--secondary-color);
        }

        .nav-link {
            color: var(--text-primary) !important;
            border-radius: 8px;
            padding: 0.6rem 1.2rem !important;
            margin: 0 0.2rem;
            font-weight: 500;
            position: relative;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--light-bg);
            color: var(--primary-color) !important;
            transform: translateY(-1px);
        }

        .nav-link.active {
            background: var(--primary-color);
            color: white !important;
            box-shadow: var(--shadow-md);
        }

        .nav-link.active i {
            color: white !important;
        }

        /* Page Header - Clean Flat Design */
        .page-header {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border-left: 6px solid var(--primary-color);
            animation: slideIn 0.4s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateX(-20px);
            }

            to {
                opacity: 1;
                transform: translateX(0);
            }
        }

        .page-title {
            color: var(--text-primary);
            font-weight: 700;
            font-size: 2rem;
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .page-title i {
            color: var(--primary-color);
        }

        /* Stats Cards - Flat Colorful Design */
        .stat-card {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 2rem 1.5rem;
            box-shadow: var(--shadow-md);
            border-top: 4px solid;
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            animation: fadeInScale 0.5s ease-out backwards;
        }

        @keyframes fadeInScale {
            from {
                opacity: 0;
                transform: scale(0.9);
            }

            to {
                opacity: 1;
                transform: scale(1);
            }
        }

        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }

        .stat-card.primary {
            border-top-color: var(--primary-color);
            animation-delay: 0.1s;
        }

        .stat-card.primary .stat-icon-bg {
            background: rgba(37, 99, 235, 0.1);
            color: var(--primary-color);
        }

        .stat-card.success {
            border-top-color: var(--secondary-color);
            animation-delay: 0.2s;
        }

        .stat-card.success .stat-icon-bg {
            background: rgba(16, 185, 129, 0.1);
            color: var(--secondary-color);
        }

        .stat-card.info {
            border-top-color: #0ea5e9;
            animation-delay: 0.3s;
        }

        .stat-card.info .stat-icon-bg {
            background: rgba(14, 165, 233, 0.1);
            color: #0ea5e9;
        }

        .stat-card.warning {
            border-top-color: var(--accent-color);
            animation-delay: 0.4s;
        }

        .stat-card.warning .stat-icon-bg {
            background: rgba(245, 158, 11, 0.1);
            color: var(--accent-color);
        }

        .stat-icon-bg {
            width: 60px;
            height: 60px;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            margin-bottom: 1rem;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            color: var(--text-primary);
            margin: 0.5rem 0;
            line-height: 1;
        }

        .stat-label {
            font-size: 0.95rem;
            color: var(--text-secondary);
            font-weight: 500;
            margin: 0;
        }

        /* Modern Cards */
        .modern-card {
            background: var(--card-bg);
            border-radius: 16px;
            box-shadow: var(--shadow-md);
            overflow: hidden;
            animation: fadeIn 0.6s ease-out;
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(10px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInForwards {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .card-header-modern {
            background: var(--light-bg);
            border-bottom: 2px solid var(--border-color);
            padding: 1.5rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .card-title-modern {
            font-weight: 700;
            font-size: 1.25rem;
            color: var(--text-primary);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .card-title-modern i {
            color: var(--primary-color);
        }

        /* Filter Section */
        .filter-section {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 2rem;
            box-shadow: var(--shadow-md);
            margin-bottom: 2rem;
            border-left: 6px solid var(--secondary-color);
        }

        .filter-section h6 {
            font-weight: 700;
            color: var(--text-primary);
            margin-bottom: 1.5rem;
            font-size: 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .filter-section h6 i {
            color: var(--secondary-color);
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .form-select,
        .form-control {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 0.65rem 1rem;
            background: white;
            color: var(--text-primary);
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .form-select:focus,
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .form-select:hover,
        .form-control:hover {
            border-color: var(--primary-color);
        }

        /* Flat Buttons */
        .btn-simple {
            border-radius: 8px;
            padding: 0.7rem 1.5rem;
            font-weight: 600;
            border: none;
            transition: all 0.3s ease;
            box-shadow: var(--shadow-sm);
        }

        .btn-primary {
            background: var(--primary-color) !important;
            color: white;
        }

        .btn-primary:hover {
            background: #1d4ed8 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--secondary-color) !important;
            color: white;
        }

        .btn-success:hover {
            background: #059669 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--text-secondary) !important;
            color: white;
        }

        .btn-secondary:hover {
            background: #475569 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        /* Clean Table Design */
        .table-simple {
            margin: 0;
        }

        .table-simple thead {
            background: var(--dark-bg);
            color: white;
        }

        .table-simple thead th {
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table-simple tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }

        .table-simple tbody tr:hover {
            background: var(--light-bg);
            box-shadow: inset 4px 0 0 var(--primary-color);
        }

        .table-simple tbody td {
            padding: 1rem 1.5rem;
            vertical-align: middle;
            color: var(--text-primary);
        }

        /* Flat Badges */
        .badge {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.75rem;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }

        .badge.bg-info {
            background: #0ea5e9 !important;
        }

        .badge.bg-secondary {
            background: var(--text-secondary) !important;
        }

        .badge.bg-warning {
            background: var(--accent-color) !important;
            color: white !important;
        }

        .badge.bg-primary {
            background: var(--primary-color) !important;
        }

        .badge.bg-success {
            background: var(--secondary-color) !important;
        }

        .badge.bg-danger {
            background: var(--danger-color) !important;
        }

        /* Flat Progress Bar */
        .progress-simple {
            height: 24px;
            border-radius: 8px;
            background: var(--border-color);
            overflow: hidden;
        }

        .progress-bar-simple {
            font-size: 0.75rem;
            font-weight: 700;
            line-height: 24px;
            transition: width 0.6s ease;
        }

        .progress-bar-simple.bg-success {
            background: var(--secondary-color) !important;
        }

        .progress-bar-simple.bg-warning {
            background: var(--accent-color) !important;
        }

        .progress-bar-simple.bg-danger {
            background: var(--danger-color) !important;
        }

        /* Action Buttons */
        .btn-group .btn {
            border-radius: 6px;
            margin: 0 3px;
            transition: all 0.3s ease;
        }

        .btn-group .btn:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color) !important;
            border-color: var(--primary-color);
            color: white !important;
        }

        .btn-outline-info {
            border-color: #0ea5e9;
            color: #0ea5e9;
        }

        .btn-outline-info:hover {
            background: #0ea5e9 !important;
            border-color: #0ea5e9;
            color: white !important;
        }

        .btn-outline-success {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline-success:hover {
            background: var(--secondary-color) !important;
            border-color: var(--secondary-color);
            color: white !important;
        }

        /* Empty State */
        .empty-state {
            padding: 4rem 2rem;
            text-align: center;
        }

        .empty-state-icon {
            font-size: 5rem;
            color: var(--text-secondary);
            opacity: 0.5;
        }

        /* Dropdown Menu */
        .dropdown-menu {
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
            padding: 0.5rem;
        }

        .dropdown-item {
            border-radius: 8px;
            margin: 0.2rem 0;
            padding: 0.7rem 1rem;
            font-weight: 500;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: var(--light-bg);
            color: var(--primary-color);
            transform: translateX(5px);
        }

        .dropdown-item i {
            color: var(--primary-color);
        }

        /* Scrollbar */
        ::-webkit-scrollbar {
            width: 12px;
            height: 12px;
        }

        ::-webkit-scrollbar-track {
            background: var(--light-bg);
        }

        ::-webkit-scrollbar-thumb {
            background: var(--text-secondary);
            border-radius: 6px;
        }

        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-color);
        }

        /* Responsive */
        @media (max-width: 768px) {
            .page-title {
                font-size: 1.5rem;
            }

            .stat-value {
                font-size: 2rem;
            }

            .stat-card {
                margin-bottom: 1rem;
            }
        }

        /* Utility Classes */
        .text-muted {
            color: var(--text-secondary) !important;
        }
    </style>
</head>

<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-modern">
        <div class="container-fluid px-4">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-person-gear me-2"></i>
                Панель менеджера
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">

                    <li class="nav-item">
                        <a class="nav-link active" href="groups.php">
                            <i class="bi bi-collection-fill me-1"></i>Группы
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i><?php echo $current_user['name']; ?>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="profile.php"><i class="bi bi-person me-2"></i>Профиль</a></li>
                            <li><a class="dropdown-item" href="settings.php"><i class="bi bi-gear me-2"></i>Настройки</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Выход</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container-fluid px-4 py-4">
        <!-- Page Header -->
        <div class="page-header">
            <div class="d-flex justify-content-between align-items-center flex-wrap">
                <div>
                    <h1 class="page-title">
                        <i class="bi bi-collection-fill me-2"></i>Все группы
                    </h1>
                    <p class="text-muted mt-1 mb-0">Управление учебными группами</p>
                </div>
                <div class="d-flex gap-2 mt-3 mt-md-0">
                    <button class="btn btn-success btn-simple" onclick="exportGroups()">
                        <i class="bi bi-download me-1"></i>Экспорт
                    </button>
                    <button class="btn btn-secondary btn-simple" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Печать
                    </button>
                </div>
            </div>
        </div>

        <!-- Общая статистика -->
        <div class="row mb-4">
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card primary">
                    <div class="stat-icon-bg">
                        <i class="bi bi-collection-fill"></i>
                    </div>
                    <h2 class="stat-value"><?php echo $total_groups; ?></h2>
                    <p class="stat-label mb-0">Всего групп</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card success">
                    <div class="stat-icon-bg">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <h2 class="stat-value"><?php echo $active_groups; ?></h2>
                    <p class="stat-label mb-0">Активных групп</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card info">
                    <div class="stat-icon-bg">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <h2 class="stat-value"><?php echo $total_students; ?></h2>
                    <p class="stat-label mb-0">Всего студентов</p>
                </div>
            </div>
            <div class="col-lg-3 col-md-6 mb-3">
                <div class="stat-card warning">
                    <div class="stat-icon-bg">
                        <i class="bi bi-speedometer2"></i>
                    </div>
                    <h2 class="stat-value"><?php echo $avg_occupancy; ?>%</h2>
                    <p class="stat-label mb-0">Средняя заполненность</p>
                </div>
            </div>
        </div>

        <!-- Фильтры -->
        <div class="filter-section">
            <h6 class="mb-3"><i class="bi bi-funnel me-2"></i>Фильтры и поиск</h6>
            <form method="GET" action="groups.php">
                <div class="row g-2">
                    <div class="col-md-2">
                        <label class="form-label">Статус</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="">Все статусы</option>
                            <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                            <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Неактивные</option>
                            <option value="full" <?php echo $status_filter === 'full' ? 'selected' : ''; ?>>Заполненные</option>
                            <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Есть места</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Курс</label>
                        <select class="form-select form-select-sm" name="course">
                            <option value="">Все курсы</option>
                            <option value="1 курс" <?php echo $course_filter === '1 курс' ? 'selected' : ''; ?>>1 курс</option>
                            <option value="2 курс" <?php echo $course_filter === '2 курс' ? 'selected' : ''; ?>>2 курс</option>
                            <option value="3 курс" <?php echo $course_filter === '3 курс' ? 'selected' : ''; ?>>3 курс</option>
                            <option value="4 курс" <?php echo $course_filter === '4 курс' ? 'selected' : ''; ?>>4 курс</option>
                            <option value="5 курс" <?php echo $course_filter === '5 курс' ? 'selected' : ''; ?>>5 курс</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Язык</label>
                        <select class="form-select form-select-sm" name="language">
                            <option value="">Все языки</option>
                            <option value="казахский" <?php echo $language_filter === 'казахский' ? 'selected' : ''; ?>>Казахский</option>
                            <option value="русский" <?php echo $language_filter === 'русский' ? 'selected' : ''; ?>>Русский</option>
                            <option value="английский" <?php echo $language_filter === 'английский' ? 'selected' : ''; ?>>Английский</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Форма обучения</label>
                        <select class="form-select form-select-sm" name="study_form">
                            <option value="">Все формы</option>
                            <option value="очная" <?php echo $study_form_filter === 'очная' ? 'selected' : ''; ?>>Очная</option>
                            <option value="заочная" <?php echo $study_form_filter === 'заочная' ? 'selected' : ''; ?>>Заочная</option>
                            <option value="вечерняя" <?php echo $study_form_filter === 'вечерняя' ? 'selected' : ''; ?>>Вечерняя</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Куратор</label>
                        <select class="form-select form-select-sm" name="curator">
                            <option value="">Все кураторы</option>
                            <?php foreach ($curators as $curator): ?>
                                <option value="<?php echo $curator['id']; ?>" <?php echo $curator_filter == $curator['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($curator['first_name'] . ' ' . $curator['last_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Поиск</label>
                        <input type="text" class="form-control form-control-sm" name="search" placeholder="Название..." value="<?php echo htmlspecialchars($search); ?>">
                    </div>
                </div>
                <div class="mt-3">
                    <button type="submit" class="btn btn-primary btn-sm btn-simple">
                        <i class="bi bi-search me-1"></i>Применить
                    </button>
                    <a href="groups.php" class="btn btn-secondary btn-sm btn-simple">
                        <i class="bi bi-x-circle me-1"></i>Сбросить
                    </a>
                </div>
            </form>
        </div>

        <?php if (empty($groups)): ?>
            <div class="modern-card">
                <div class="empty-state">
                    <i class="bi bi-inbox empty-state-icon"></i>
                    <h5 class="mt-3">Группы не найдены</h5>
                    <p class="text-muted">Попробуйте изменить параметры фильтрации</p>
                    <a href="groups.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-arrow-clockwise me-1"></i>Сбросить фильтры
                    </a>
                </div>
            </div>
        <?php else: ?>
            <!-- Таблица групп -->
            <div class="modern-card">
                <div class="card-header-modern d-flex justify-content-between align-items-center">
                    <h6 class="card-title-modern mb-0">
                        <i class="bi bi-table me-2"></i>Список групп
                    </h6>
                    <span class="badge bg-primary">
                        <?php echo count($groups); ?> из <?php echo $total_groups; ?>
                    </span>
                </div>
                <div class="table-responsive">
                    <table class="table table-simple table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Группа</th>
                                <th>Специальность</th>
                                <th>Курс</th>
                                <th>Язык</th>
                                <th>Форма</th>
                                <th>Студенты</th>
                                <th>Заполненность</th>
                                <th>Куратор</th>
                                <th>Статус</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($groups as $group_item): ?>
                                <tr>
                                    <td>
                                        <div>
                                            <strong><?php echo htmlspecialchars($group_item['name']); ?></strong>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($group_item['code']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <div style="max-width: 250px;">
                                            <?php echo htmlspecialchars($group_item['specialty']); ?>
                                            <br>
                                            <small class="text-muted"><?php echo htmlspecialchars($group_item['qualification']); ?></small>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge bg-info">
                                            <?php echo htmlspecialchars($group_item['course']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo htmlspecialchars($group_item['language']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-warning text-dark">
                                            <?php echo htmlspecialchars($group_item['study_form']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge bg-primary">
                                            <?php echo $group_item['current_students']; ?> / <?php echo $group_item['max_students']; ?>
                                        </span>
                                        <?php if ($group_item['disabled_students'] > 0 || $group_item['orphan_students'] > 0): ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php if ($group_item['disabled_students'] > 0): ?>
                                                    <i class="bi bi-wheelchair" title="С инвалидностью"></i> <?php echo $group_item['disabled_students']; ?>
                                                <?php endif; ?>
                                                <?php if ($group_item['orphan_students'] > 0): ?>
                                                    <i class="bi bi-heart" title="Сироты"></i> <?php echo $group_item['orphan_students']; ?>
                                                <?php endif; ?>
                                            </small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="progress progress-simple" style="width: 100px;">
                                            <div class="progress-bar progress-bar-simple bg-<?php echo $group_item['occupancy_percentage'] > 80 ? 'success' : ($group_item['occupancy_percentage'] > 50 ? 'warning' : 'danger'); ?>"
                                                style="width: <?php echo $group_item['occupancy_percentage']; ?>%">
                                                <?php echo $group_item['occupancy_percentage']; ?>%
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($group_item['curator_first_name']): ?>
                                            <small><?php echo htmlspecialchars($group_item['curator_first_name'] . ' ' . $group_item['curator_last_name']); ?></small>
                                        <?php else: ?>
                                            <small class="text-muted">Не назначен</small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo $group_item['is_active'] ? 'success' : 'danger'; ?>">
                                            <?php echo $group_item['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="group_details.php?id=<?php echo $group_item['id']; ?>"
                                                class="btn btn-outline-primary btn-sm" title="Детали">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="students.php?group=<?php echo $group_item['id']; ?>"
                                                class="btn btn-outline-info btn-sm" title="Студенты">
                                                <i class="bi bi-people"></i>
                                            </a>
                                            <a href="group_reports.php?id=<?php echo $group_item['id']; ?>"
                                                class="btn btn-outline-success btn-sm" title="Отчеты">
                                                <i class="bi bi-graph-up"></i>
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <footer class="mt-5 pb-4">
        <div class="container-fluid px-4">
            <div class="text-center" style="color: #64748b; font-weight: 500;">
                <small>&copy; 2024 <?php echo APP_NAME; ?>. Все права защищены.</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // Smooth appearance on page load
        document.addEventListener('DOMContentLoaded', function() {
            // Add simple fade-in animation to table rows
            const tableRows = document.querySelectorAll('tbody tr');
            tableRows.forEach((row, index) => {
                row.style.opacity = '0';
                row.style.animation = `fadeInForwards 0.3s ease-out ${0.03 * index}s forwards`;
            });
        });

        function exportGroups() {
            const table = document.querySelector('table');
            if (!table) {
                alert('Нет данных для экспорта');
                return;
            }

            const rows = table.querySelectorAll('tbody tr');
            let csv = '\uFEFF'; // UTF-8 BOM
            csv += 'Группа,Код,Специальность,Квалификация,Курс,Язык,Форма обучения,Студенты,Максимум,Заполненность%,Куратор,Статус\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = [];

                cells.forEach((cell, index) => {
                    if (index < 9) {
                        let text = cell.textContent.trim().replace(/\s+/g, ' ').trim();
                        text = text.replace(/"/g, '""');
                        rowData.push('"' + text + '"');
                    }
                });

                csv += rowData.join(',') + '\n';
            });

            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'groups_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }

        // Print styles
        window.addEventListener('beforeprint', function() {
            document.querySelectorAll('.btn, .navbar, .filter-section').forEach(el => {
                el.style.display = 'none';
            });
        });

        window.addEventListener('afterprint', function() {
            document.querySelectorAll('.btn, .navbar, .filter-section').forEach(el => {
                el.style.display = '';
            });
        });
    </script>
</body>

</html>