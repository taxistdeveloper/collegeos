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

// Получение ID группы из параметра
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$group_id) {
    header('Location: groups.php');
    exit;
}

// Получение информации о группе
$group_info = $group->getGroupById($group_id);
if (!$group_info) {
    header('Location: groups.php');
    exit;
}

$db = getDB();

// Получение студентов группы
$graduated = sqlGraduatedCondition('s');
$graduating = sqlGraduatingStudentCondition('s');
$sql = "SELECT s.*, 
               CASE WHEN s.academic_leave = 1 THEN 'academic_leave'
                    WHEN $graduated THEN 'graduated'
                    WHEN $graduating THEN 'graduating'
                    ELSE 'active' END as status
        FROM students s 
        WHERE s.group_id = ? 
        ORDER BY s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

// Статистика студентов
$stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0,
    'disabled' => 0,
    'orphan' => 0,
    'without_parental_care' => 0,
    'minors' => 0
];

foreach ($students as $student) {
    switch ($student['status']) {
        case 'active':
        case 'graduating':
            $stats['active']++;
            break;
        case 'academic_leave':
            $stats['academic_leave']++;
            break;
        case 'graduated':
            $stats['graduated']++;
            break;
    }

    if ($student['gender'] === 'мужской') {
        $stats['male']++;
    } elseif ($student['gender'] === 'женский') {
        $stats['female']++;
    }

    if ($student['disability']) {
        $stats['disabled']++;
    }

    if ($student['orphan']) {
        $stats['orphan']++;
    }

    if ($student['without_parental_care']) {
        $stats['without_parental_care']++;
    }

    // Подсчет несовершеннолетних
    if ($student['birth_date']) {
        $birth_year = date('Y', strtotime($student['birth_date']));
        $current_year = date('Y');
        $age = $current_year - $birth_year;
        if ($age < 18) {
            $stats['minors']++;
        }
    }
}

// Заполненность группы
$occupancy_percentage = $group_info['max_students'] > 0 ? round(($stats['total'] / $group_info['max_students']) * 100, 1) : 0;

// Получение списка несовершеннолетних студентов
$minors = [];
foreach ($students as $student) {
    if ($student['birth_date']) {
        $birth_year = date('Y', strtotime($student['birth_date']));
        $current_year = date('Y');
        $age = $current_year - $birth_year;
        if ($age < 18) {
            $student['age'] = $age;
            $minors[] = $student;
        }
    }
}

// Получение информации о кураторе
$curator_info = null;
if ($group_info['curator_id']) {
    $curator_info = $user->getUserById($group_info['curator_id']);
}

// Получение недавней активности (если есть логи)
$recent_activity = []; // Можно расширить в будущем для логирования действий
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Детали группы <?php echo htmlspecialchars($group_info['name']); ?> - <?php echo APP_NAME; ?></title>
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

        .navbar-simple {
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
            padding: 0.6rem 1.2rem !important;
            margin: 0 0.2rem;
            font-weight: 500;
            transition: all 0.3s ease;
        }

        .nav-link:hover {
            background: var(--light-bg);
            color: var(--primary-color) !important;
        }

        .nav-link.active {
            background: var(--primary-color) !important;
            color: white !important;
        }

        .page-header {
            background: var(--card-bg);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: var(--shadow-md);
            border-left: 6px solid var(--primary-color);
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

        .stat-box {
            text-align: center;
            padding: 1.5rem;
            border-radius: 16px;
            background: var(--card-bg);
            border-top: 4px solid var(--primary-color);
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
        }

        .stat-box:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
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
            padding: 1rem;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 0.85rem;
        }

        .table tbody tr {
            transition: all 0.2s ease;
            border-bottom: 1px solid var(--border-color);
        }

        .table tbody tr:hover {
            background: var(--light-bg);
            box-shadow: inset 4px 0 0 var(--primary-color);
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

        .badge.bg-warning {
            background: var(--accent-color) !important;
            color: white !important;
        }

        .badge.bg-danger {
            background: var(--danger-color) !important;
        }

        .btn-primary {
            background: var(--primary-color) !important;
            border: none;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .btn-primary:hover {
            background: #1d4ed8 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
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

        .text-muted {
            color: var(--text-secondary) !important;
        }
    </style>
</head>

<body>
    <!-- Навигация -->
    <nav class="navbar navbar-expand-lg navbar-dark navbar-simple">
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
                    <h1 class="h4 mb-1">
                        <i class="bi bi-collection text-primary me-2"></i>
                        Детали группы "<?php echo htmlspecialchars($group_info['name']); ?>"
                    </h1>
                    <p class="text-muted mb-0">
                        <small>
                            <code><?php echo htmlspecialchars($group_info['code']); ?></code> •
                            <?php echo htmlspecialchars($group_info['specialty']); ?> •
                            <?php echo htmlspecialchars($group_info['course']); ?>
                        </small>
                    </p>
                </div>
                <div class="d-flex gap-2 mt-3 mt-md-0 flex-wrap">
                    <a href="group_reports.php?id=<?php echo $group_id; ?>" class="btn btn-success btn-sm">
                        <i class="bi bi-graph-up me-1"></i>Отчеты
                    </a>
                    <a href="students.php?group=<?php echo $group_id; ?>" class="btn btn-info btn-sm">
                        <i class="bi bi-people me-1"></i>Студенты
                    </a>
                    <button class="btn btn-primary btn-sm" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Печать
                    </button>
                    <a href="groups.php" class="btn btn-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>Назад
                    </a>
                </div>
            </div>
        </div>

        <div class="row">
            <!-- Основная информация о группе -->
            <div class="col-md-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Основная информация
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td class="fw-bold">Название группы:</td>
                                        <td><?php echo htmlspecialchars($group_info['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Код группы:</td>
                                        <td><code><?php echo htmlspecialchars($group_info['code']); ?></code></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Специальность:</td>
                                        <td><?php echo htmlspecialchars($group_info['specialty']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Квалификация:</td>
                                        <td><?php echo htmlspecialchars($group_info['qualification']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Курс:</td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($group_info['course']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Язык обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['language']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td class="fw-bold">Форма обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['study_form']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Срок обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['study_duration']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата начала:</td>
                                        <td><?php echo $group_info['start_date'] ? date('d.m.Y', strtotime($group_info['start_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата окончания:</td>
                                        <td><?php echo $group_info['end_date'] ? date('d.m.Y', strtotime($group_info['end_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата прибытия:</td>
                                        <td><?php echo $group_info['arrival_date'] ? date('d.m.Y', strtotime($group_info['arrival_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Приказ о зачислении:</td>
                                        <td><?php echo htmlspecialchars($group_info['enrollment_order_number'] ?: '—'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($group_info['description']): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Описание:</h6>
                                    <p class="text-muted"><?php echo nl2br(htmlspecialchars($group_info['description'])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Статистика студентов -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-mortarboard me-2"></i>
                            Статистика студентов
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-primary mb-1"><?php echo $stats['total']; ?></h5>
                                    <small>Всего студентов</small>
                                    <div><small class="text-muted">из <?php echo $group_info['max_students']; ?> мест</small></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-success mb-1"><?php echo $stats['active']; ?></h5>
                                    <small>Активных</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-warning mb-1"><?php echo $stats['academic_leave']; ?></h5>
                                    <small>В академ. отпуске</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-info mb-1"><?php echo $occupancy_percentage; ?>%</h5>
                                    <small>Заполненность</small>
                                    <div class="progress mt-2" style="height: 6px;">
                                        <div class="progress-bar bg-<?php echo $occupancy_percentage > 80 ? 'success' : ($occupancy_percentage > 60 ? 'warning' : 'danger'); ?>"
                                            style="width: <?php echo $occupancy_percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Дополнительная статистика -->
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-primary"><?php echo $stats['male']; ?> М</span>
                            <span class="badge bg-secondary"><?php echo $stats['female']; ?> Ж</span>
                            <span class="badge bg-warning text-dark"><?php echo $stats['disabled']; ?> <i class="bi bi-wheelchair"></i></span>
                            <span class="badge bg-info"><?php echo $stats['orphan']; ?> <i class="bi bi-heart"></i></span>
                            <span class="badge bg-danger"><?php echo $stats['without_parental_care']; ?> <i class="bi bi-exclamation-triangle"></i></span>
                            <span class="badge bg-dark"><?php echo $stats['graduated']; ?> <i class="bi bi-award"></i></span>
                            <span class="badge bg-warning text-dark"><?php echo $stats['minors']; ?> <i class="bi bi-calendar-minus"></i></span>
                        </div>
                    </div>
                </div>

                <!-- Несовершеннолетние студенты -->
                <?php if (!empty($minors)): ?>
                    <div class="card mb-3">
                        <div class="card-header" data-bs-toggle="collapse" data-bs-target="#minorsCollapse" style="cursor:pointer;" aria-expanded="false" aria-controls="minorsCollapse">
                            <h6 class="mb-0 d-flex align-items-center justify-content-between">
                                <span>
                                    <i class="bi bi-calendar-minus text-warning me-2"></i>
                                    Несовершеннолетние студенты (<?php echo count($minors); ?>)
                                </span>
                                <span class="ms-2">
                                    <i class="bi bi-caret-down collapse-icon" id="minorsCollapseIcon"></i>
                                </span>
                            </h6>
                        </div>
                        <div class="collapse" id="minorsCollapse">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>ФИО</th>
                                                <th>ИИН</th>
                                                <th>Возраст</th>
                                                <th>Дата рождения</th>
                                                <th>Пол</th>
                                                <th>Статус</th>
                                                <th>Особенности</th>
                                                <th>Контакты</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($minors as $minor): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($minor['last_name'] . ' ' . $minor['first_name'] . ' ' . $minor['middle_name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <code><?php echo htmlspecialchars($minor['iin']); ?></code>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo $minor['age']; ?> лет</span>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d.m.Y', strtotime($minor['birth_date'])); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($minor['gender']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        echo '<span class="' . getStudentStatusBadgeClass($minor['status']) . '">'
                                                            . htmlspecialchars(getStudentStatusLabel($minor['status']))
                                                            . '</span>';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($minor['disability']): ?>
                                                            <i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>
                                                        <?php endif; ?>
                                                        <?php if ($minor['orphan']): ?>
                                                            <i class="bi bi-heart text-info" title="Сирота"></i>
                                                        <?php endif; ?>
                                                        <?php if ($minor['without_parental_care']): ?>
                                                            <i class="bi bi-exclamation-triangle text-danger" title="Без попечения родителей"></i>
                                                        <?php endif; ?>
                                                        <?php if (!$minor['disability'] && !$minor['orphan'] && !$minor['without_parental_care']): ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($minor['phone']): ?>
                                                            <a href="tel:<?php echo htmlspecialchars($minor['phone']); ?>" class="text-decoration-none">
                                                                <i class="bi bi-phone"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($minor['email']): ?>
                                                            <a href="mailto:<?php echo htmlspecialchars($minor['email']); ?>" class="text-decoration-none ms-1">
                                                                <i class="bi bi-envelope"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!$minor['phone'] && !$minor['email']): ?>
                                                            —
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
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var collapseEl = document.getElementById('minorsCollapse');
                            var iconEl = document.getElementById('minorsCollapseIcon');
                            var headerEl = collapseEl.previousElementSibling;

                            // Set default (collapsed)
                            collapseEl.classList.remove('show');
                            if (iconEl) iconEl.classList.remove('bi-caret-up');
                            if (iconEl) iconEl.classList.add('bi-caret-down');

                            // Toggle icon on collapse events
                            collapseEl.addEventListener('show.bs.collapse', function() {
                                if (iconEl) {
                                    iconEl.classList.remove('bi-caret-down');
                                    iconEl.classList.add('bi-caret-up');
                                }
                            });
                            collapseEl.addEventListener('hide.bs.collapse', function() {
                                if (iconEl) {
                                    iconEl.classList.remove('bi-caret-up');
                                    iconEl.classList.add('bi-caret-down');
                                }
                            });
                        });
                    </script>
                <?php endif; ?>

                <!-- Список студентов -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="bi bi-people me-2"></i>
                            Список студентов (<?php echo count($students); ?>)
                        </h6>
                        <a href="students.php?group=<?php echo $group_id; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-right me-1"></i>Подробнее
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ФИО</th>
                                        <th>ИИН</th>
                                        <th>Пол</th>
                                        <th>Статус</th>
                                        <th>Особенности</th>
                                        <th>Контакты</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($students, 0, 10) as $student): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name']); ?></strong>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($student['iin']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($student['gender']); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                echo '<span class="' . getStudentStatusBadgeClass($student['status']) . '">'
                                                    . htmlspecialchars(getStudentStatusLabel($student['status']))
                                                    . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($student['disability']): ?>
                                                    <i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>
                                                <?php endif; ?>
                                                <?php if ($student['orphan']): ?>
                                                    <i class="bi bi-heart text-info" title="Сирота"></i>
                                                <?php endif; ?>
                                                <?php if ($student['without_parental_care']): ?>
                                                    <i class="bi bi-exclamation-triangle text-danger" title="Без попечения родителей"></i>
                                                <?php endif; ?>
                                                <?php if (!$student['disability'] && !$student['orphan'] && !$student['without_parental_care']): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($student['phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                                        <i class="bi bi-phone"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($student['email']): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="text-decoration-none ms-1">
                                                        <i class="bi bi-envelope"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!$student['phone'] && !$student['email']): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (count($students) > 10): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                <em>И еще <?php echo count($students) - 10; ?> студентов...
                                                    <a href="students.php?group=<?php echo $group_id; ?>">Показать всех</a></em>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Боковая панель -->
            <div class="col-md-4">
                <!-- Информация о кураторе -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-person-heart me-2"></i>
                            Куратор группы
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($curator_info): ?>
                            <div class="text-center">
                                <div class="mb-3">
                                    <i class="bi bi-person-circle text-primary" style="font-size: 3rem;"></i>
                                </div>
                                <h6><?php echo htmlspecialchars($curator_info['first_name'] . ' ' . $curator_info['last_name']); ?></h6>
                                <?php if ($curator_info['middle_name']): ?>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($curator_info['middle_name']); ?></p>
                                <?php endif; ?>

                                <?php if ($curator_info['email']): ?>
                                    <p class="mb-1">
                                        <i class="bi bi-envelope me-1"></i>
                                        <a href="mailto:<?php echo htmlspecialchars($curator_info['email']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($curator_info['email']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>

                                <?php if ($curator_info['phone']): ?>
                                    <p class="mb-0">
                                        <i class="bi bi-phone me-1"></i>
                                        <a href="tel:<?php echo htmlspecialchars($curator_info['phone']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($curator_info['phone']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="bi bi-person-x fs-1 mb-3"></i>
                                <p>Куратор не назначен</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>



                <!-- Статус группы -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Статус группы
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Статус:</span>
                                <span class="badge bg-<?php echo $group_info['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $group_info['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Заполненность:</span>
                                <span class="fw-bold"><?php echo $occupancy_percentage; ?>%</span>
                            </div>
                            <div class="progress mt-1" style="height: 8px;">
                                <div class="progress-bar <?php echo $occupancy_percentage > 80 ? 'bg-success' : ($occupancy_percentage > 60 ? 'bg-warning' : 'bg-danger'); ?>"
                                    style="width: <?php echo $occupancy_percentage; ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Студентов:</span>
                                <span class="fw-bold"><?php echo $stats['total']; ?> / <?php echo $group_info['max_students']; ?></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Свободных мест:</span>
                                <span class="fw-bold"><?php echo max(0, $group_info['max_students'] - $stats['total']); ?></span>
                            </div>
                        </div>

                        <?php if ($group_info['start_date']): ?>
                            <div class="mb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Дата создания:</span>
                                    <span class="text-muted"><?php echo date('d.m.Y', strtotime($group_info['start_date'])); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    </div>
    </div>

    <!-- Footer -->
    <footer class="mt-4 pb-4">
        <div class="container-fluid px-4">
            <div class="text-center text-muted">
                <small>&copy; 2024 <?php echo APP_NAME; ?>. Все права защищены.</small>
            </div>
        </div>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        function scrollToMinors() {
            const minorsSection = document.querySelector('.card:has(.bi-calendar-minus)');
            if (minorsSection) {
                minorsSection.scrollIntoView({
                    behavior: 'smooth'
                });
            }
        }

        function exportGroupData() {
            // Простой экспорт таблицы студентов
            const table = document.querySelector('.table');
            if (!table) {
                alert('Нет данных для экспорта');
                return;
            }

            let csv = '\uFEFF'; // UTF-8 BOM
            csv += 'Группа: <?php echo htmlspecialchars($group_info['name']); ?>\n';
            csv += 'Код: <?php echo htmlspecialchars($group_info['code']); ?>\n';
            csv += 'Всего студентов: <?php echo $stats['total']; ?>\n\n';

            const rows = table.querySelectorAll('tbody tr');
            csv += 'ФИО,ИИН,Пол,Статус\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 4) {
                    const rowData = [];
                    for (let i = 0; i < 4; i++) {
                        let text = cells[i].textContent.trim().replace(/\s+/g, ' ').trim();
                        text = text.replace(/"/g, '""');
                        rowData.push('"' + text + '"');
                    }
                    csv += rowData.join(',') + '\n';
                }
            });

            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'group_<?php echo $group_info['code']; ?>_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>

</html>