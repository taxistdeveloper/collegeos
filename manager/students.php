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

// Получение всех студентов с информацией о группах
$db = getDB();

// Параметры фильтрации
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$group_filter = isset($_GET['group']) ? (int)$_GET['group'] : 0;
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Построение SQL запроса с фильтрами
$sql = "SELECT s.*, g.name as group_name, g.course, g.specialty, g.language, g.study_form,
               g.is_active as group_is_active,
               u.first_name as curator_first_name, u.last_name as curator_last_name
        FROM students s 
        LEFT JOIN `groups` g ON s.group_id = g.id 
        LEFT JOIN users u ON g.curator_id = u.id
        WHERE 1=1";

$params = [];
$types = '';

// Фильтр по статусу
if ($status_filter) {
    switch ($status_filter) {
        case 'active':
            $sql .= " AND s.academic_leave = 0 AND " . sqlNotGraduatedCondition('s');
            break;
        case 'academic_leave':
            $sql .= " AND s.academic_leave = 1";
            break;
        case 'graduated':
            $sql .= " AND " . sqlGraduatedCondition('s');
            break;
    }
}

// Фильтр по группе
if ($group_filter > 0) {
    $sql .= " AND s.group_id = ?";
    $params[] = $group_filter;
    $types .= 'i';
}

// Фильтр по курсу
if ($course_filter) {
    $sql .= " AND g.course = ?";
    $params[] = $course_filter;
    $types .= 's';
}

// Фильтр по полу
if ($gender_filter) {
    $sql .= " AND s.gender = ?";
    $params[] = $gender_filter;
    $types .= 's';
}

// Поиск
if ($search) {
    $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

$sql .= " ORDER BY s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
} elseif ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
} else {
    // Fallback запрос без фильтров
    $sql = "SELECT s.*, g.name as group_name, g.course, g.specialty, g.language, g.study_form,
                   u.first_name as curator_first_name, u.last_name as curator_last_name
            FROM students s 
            LEFT JOIN `groups` g ON s.group_id = g.id 
            LEFT JOIN users u ON g.curator_id = u.id
            ORDER BY s.first_name, s.middle_name";
    $result = $db->query($sql);
    $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Получение всех групп для фильтра
$all_groups = $group->getAllGroups();

// Статистика студентов
$student_stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0,
    'disabled' => 0,
    'orphan' => 0
];

foreach ($students as $student) {
    if ($student['academic_leave']) {
        $student_stats['academic_leave']++;
    } elseif (isStudentGraduated($student)) {
        $student_stats['graduated']++;
    } else {
        $student_stats['active']++;
    }

    if ($student['gender'] === 'мужской') {
        $student_stats['male']++;
    } elseif ($student['gender'] === 'женский') {
        $student_stats['female']++;
    }

    if ($student['disability']) {
        $student_stats['disabled']++;
    }

    if ($student['orphan']) {
        $student_stats['orphan']++;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Все студенты - <?php echo APP_NAME; ?></title>
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

        body {
            background: var(--light-bg);
            font-family: 'Poppins', sans-serif;
            color: var(--text-primary);
        }

        .bg-primary {
            background: var(--card-bg) !important;
            box-shadow: var(--shadow-md);
            border-bottom: 3px solid var(--primary-color);
        }

        .navbar-dark .navbar-brand,
        .navbar-dark .nav-link {
            color: var(--text-primary) !important;
        }

        .navbar-dark .navbar-brand {
            font-weight: 700;
            color: var(--primary-color) !important;
        }

        .nav-link {
            border-radius: 8px;
            transition: all 0.3s ease;
            font-weight: 500;
            padding: 0.6rem 1.2rem !important;
            margin: 0 0.2rem;
        }

        .nav-link:hover {
            background: var(--light-bg);
            color: var(--primary-color) !important;
        }

        .nav-link.active {
            background: var(--primary-color) !important;
            color: white !important;
        }

        .card {
            border-radius: 16px;
            border: none;
            box-shadow: var(--shadow-md);
            background: var(--card-bg);
        }

        .card-header {
            background: var(--light-bg);
            border-bottom: 2px solid var(--border-color);
            border-radius: 16px 16px 0 0 !important;
            padding: 1.25rem;
        }

        .btn-primary {
            background: var(--primary-color) !important;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            padding: 0.7rem 1.5rem;
            box-shadow: var(--shadow-sm);
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #1d4ed8 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .btn-success {
            background: var(--secondary-color) !important;
            border: none;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-success:hover {
            background: #059669 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .btn-secondary {
            background: var(--text-secondary) !important;
            border: none;
            border-radius: 8px;
            font-weight: 600;
        }

        .btn-outline-primary,
        .btn-outline-success,
        .btn-outline-info {
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-outline-primary {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }

        .btn-outline-primary:hover {
            background: var(--primary-color) !important;
            color: white !important;
        }

        .btn-outline-success {
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }

        .btn-outline-success:hover {
            background: var(--secondary-color) !important;
            color: white !important;
        }

        .btn-outline-info {
            border-color: #0ea5e9;
            color: #0ea5e9;
        }

        .btn-outline-info:hover {
            background: #0ea5e9 !important;
            color: white !important;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.75rem;
            text-transform: uppercase;
        }

        .badge.bg-primary {
            background: var(--primary-color) !important;
        }

        .badge.bg-success {
            background: var(--secondary-color) !important;
        }

        .badge.bg-info {
            background: #0ea5e9 !important;
        }

        .badge.bg-warning {
            background: var(--accent-color) !important;
            color: white !important;
        }

        .badge.bg-danger {
            background: var(--danger-color) !important;
        }

        .badge.bg-secondary {
            background: var(--text-secondary) !important;
        }

        .table {
            margin: 0;
        }

        .table thead {
            background: var(--dark-bg);
            color: white;
        }

        .table thead th {
            border: none;
            padding: 1rem 1.5rem;
            font-weight: 600;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:hover {
            background: var(--light-bg);
            box-shadow: inset 4px 0 0 var(--primary-color);
        }

        .table tbody td {
            padding: 1rem 1.5rem;
            vertical-align: middle;
            color: var(--text-primary);
        }

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
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: var(--light-bg);
            color: var(--primary-color);
        }

        .form-control,
        .form-select {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 0.65rem 1rem;
            transition: all 0.3s ease;
        }

        .form-control:focus,
        .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 4px rgba(37, 99, 235, 0.1);
            outline: none;
        }

        .form-label {
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 0.5rem;
            font-size: 0.85rem;
        }

        .text-muted {
            color: var(--text-secondary) !important;
        }

        .alert {
            border-radius: 12px;
            border: none;
        }
    </style>
</head>

<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
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
                        <a class="nav-link active" href="students.php">
                            <i class="bi bi-people-fill me-1"></i>Студенты
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="groups.php">
                            <i class="bi bi-collection-fill me-1"></i>Группы
                        </a>
                    </li>
                </ul>
                <ul class="navbar-nav">
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle me-1"></i><?php echo $current_user['name']; ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="profile.php">Профиль</a></li>
                            <li><a class="dropdown-item" href="settings.php">Настройки</a></li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <li><a class="dropdown-item" href="../logout.php">Выход</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container mt-4">
        <div class="row">
            <div class="col-12">
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-people-fill text-primary me-2"></i>
                        Все студенты
                    </h1>
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="exportStudents()">
                            <i class="bi bi-download me-1"></i>Экспорт
                        </button>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Печать
                        </button>
                    </div>
                </div>

                <!-- Статистика -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['total']; ?></h4>
                                <p class="card-text">Всего</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['active']; ?></h4>
                                <p class="card-text">Активных</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['academic_leave']; ?></h4>
                                <p class="card-text">Академ. отпуск</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['graduated']; ?></h4>
                                <p class="card-text">Выпускников</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['male']; ?></h4>
                                <p class="card-text">Мужчин</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-dark text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $student_stats['female']; ?></h4>
                                <p class="card-text">Женщин</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Фильтры -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-funnel me-2"></i>
                            Фильтры и поиск
                        </h5>
                    </div>
                    <div class="card-body">
                        <form method="GET" action="students.php">
                            <div class="row">
                                <div class="col-md-2">
                                    <label class="form-label">Статус</label>
                                    <select class="form-select" name="status">
                                        <option value="">Все статусы</option>
                                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                                        <option value="academic_leave" <?php echo $status_filter === 'academic_leave' ? 'selected' : ''; ?>>Академ. отпуск</option>
                                        <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Выпускники</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Группа</label>
                                    <select class="form-select" name="group">
                                        <option value="">Все группы</option>
                                        <?php foreach ($all_groups as $group_item): ?>
                                            <option value="<?php echo $group_item['id']; ?>" <?php echo $group_filter == $group_item['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($group_item['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Курс</label>
                                    <select class="form-select" name="course">
                                        <option value="">Все курсы</option>
                                        <option value="1 курс" <?php echo $course_filter === '1 курс' ? 'selected' : ''; ?>>1 курс</option>
                                        <option value="2 курс" <?php echo $course_filter === '2 курс' ? 'selected' : ''; ?>>2 курс</option>
                                        <option value="3 курс" <?php echo $course_filter === '3 курс' ? 'selected' : ''; ?>>3 курс</option>
                                        <option value="4 курс" <?php echo $course_filter === '4 курс' ? 'selected' : ''; ?>>4 курс</option>
                                        <option value="5 курс" <?php echo $course_filter === '5 курс' ? 'selected' : ''; ?>>5 курс</option>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label">Пол</label>
                                    <select class="form-select" name="gender">
                                        <option value="">Все</option>
                                        <option value="мужской" <?php echo $gender_filter === 'мужской' ? 'selected' : ''; ?>>Мужской</option>
                                        <option value="женский" <?php echo $gender_filter === 'женский' ? 'selected' : ''; ?>>Женский</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">Поиск</label>
                                    <input type="text" class="form-control" name="search" placeholder="ФИО или ИИН" value="<?php echo htmlspecialchars($search); ?>">
                                </div>
                                <div class="col-md-1">
                                    <label class="form-label">&nbsp;</label>
                                    <div class="d-grid">
                                        <button type="submit" class="btn btn-primary">
                                            <i class="bi bi-search"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>

                <?php if (empty($students)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-people fs-1 text-muted"></i>
                        <h5 class="mt-3 text-muted">Студенты не найдены</h5>
                        <p class="text-muted">Попробуйте изменить параметры фильтрации</p>
                    </div>
                <?php else: ?>
                    <!-- Таблица студентов -->
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <h5 class="card-title mb-0">
                                <i class="bi bi-list me-2"></i>
                                Список студентов (<?php echo count($students); ?>)
                            </h5>
                            <small class="text-muted">
                                Показано: <?php echo count($students); ?> из <?php echo $student_stats['total']; ?>
                            </small>
                        </div>
                        <div class="card-body p-0">
                            <div class="table-responsive">
                                <table class="table table-hover mb-0">
                                    <thead>
                                        <tr>
                                            <th>ФИО</th>
                                            <th>ИИН</th>
                                            <th>Группа</th>
                                            <th>Специальность</th>
                                            <th>Курс</th>
                                            <th>Пол</th>
                                            <th>Статус</th>
                                            <th>Телефон</th>
                                            <th>Email</th>
                                            <th>Куратор</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student):
                                            $status = getStudentStatus($student);
                                            $is_graduated = ($status === 'graduated');
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name']); ?></strong>
                                                    <?php if ($student['disability']): ?>
                                                        <i class="bi bi-wheelchair text-warning ms-1" title="Инвалидность"></i>
                                                    <?php endif; ?>
                                                    <?php if ($student['orphan']): ?>
                                                        <i class="bi bi-heart text-info ms-1" title="Сирота"></i>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($student['iin']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="badge bg-primary"><?php echo htmlspecialchars($student['group_name']); ?></span>
                                                </td>
                                                <td>
                                                    <div class="text-truncate" style="max-width: 200px;" title="<?php echo htmlspecialchars($student['specialty']); ?>">
                                                        <?php echo htmlspecialchars($student['specialty']); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo htmlspecialchars($student['course']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="badge bg-secondary"><?php echo htmlspecialchars($student['gender']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="<?php echo getStudentStatusBadgeClass($status); ?>">
                                                        <?php echo htmlspecialchars(getStudentStatusLabel($status)); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($student['phone']): ?>
                                                        <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                                            <?php echo htmlspecialchars($student['phone']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($student['email']): ?>
                                                        <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="text-decoration-none">
                                                            <?php echo htmlspecialchars($student['email']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <span class="text-muted">—</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($student['curator_first_name']): ?>
                                                        <?php echo htmlspecialchars($student['curator_first_name'] . ' ' . $student['curator_last_name']); ?>
                                                    <?php else: ?>
                                                        <span class="text-muted">Не назначен</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" style="z-index:9999; position:relative; background: #fff;">
                                                        <a href="view_student.php?id=<?php echo $student['id']; ?>"
                                                            class="btn btn-outline-primary"
                                                            title="Просмотр"
                                                            style="z-index:10000; position:relative; background: #fff;">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        function exportStudents() {
            // Создание CSV файла с данными студентов
            const table = document.querySelector('table');
            const rows = table.querySelectorAll('tbody tr');

            let csv = 'ФИО,ИИН,Группа,Специальность,Курс,Пол,Статус,Телефон,Email,Куратор\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                const rowData = [];

                cells.forEach((cell, index) => {
                    if (index < 10) { // Исключаем колонку "Действия"
                        let text = cell.textContent.trim();
                        // Убираем лишние символы и экранируем кавычки
                        text = text.replace(/"/g, '""');
                        rowData.push('"' + text + '"');
                    }
                });

                csv += rowData.join(',') + '\n';
            });

            // Создание и скачивание файла
            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'students_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>

</html>