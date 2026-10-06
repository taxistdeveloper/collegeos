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

// Получение студентов группы с детальной статистикой
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

// Статистика по возрасту
$age_stats = [];
$current_year = date('Y');
foreach ($students as $student) {
    if ($student['birth_date']) {
        $birth_year = date('Y', strtotime($student['birth_date']));
        $age = $current_year - $birth_year;
        $age_group = '';

        if ($age < 18) $age_group = 'До 18 лет';
        elseif ($age < 25) $age_group = '18-24 года';
        elseif ($age < 30) $age_group = '25-29 лет';
        elseif ($age < 35) $age_group = '30-34 года';
        else $age_group = '35+ лет';

        $age_stats[$age_group] = ($age_stats[$age_group] ?? 0) + 1;
    }
}

// Статистика по национальности
$nationality_stats = [];
foreach ($students as $student) {
    if ($student['nationality']) {
        $nationality_stats[$student['nationality']] = ($nationality_stats[$student['nationality']] ?? 0) + 1;
    }
}

// Статистика по месту жительства
$residence_stats = [];
foreach ($students as $student) {
    if ($student['residence_type']) {
        $residence_stats[$student['residence_type']] = ($residence_stats[$student['residence_type']] ?? 0) + 1;
    }
}

// Заполненность группы
$occupancy_percentage = $group_info['max_students'] > 0 ? round(($stats['total'] / $group_info['max_students']) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчет по группе <?php echo htmlspecialchars($group_info['name']); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
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

        .dropdown-menu {
            border-radius: 12px;
            box-shadow: var(--shadow-lg);
            border: 1px solid var(--border-color);
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

                    <li class="nav-item active">
                        <a class="nav-link" href="reports.php">
                            <i class="bi bi-graph-up me-1"></i>Отчеты
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
                    <div>
                        <h1 class="h3 mb-0">
                            <i class="bi bi-graph-up text-primary me-2"></i>
                            Отчет по группе "<?php echo htmlspecialchars($group_info['name']); ?>"
                        </h1>
                        <p class="text-muted mb-0">
                            <code><?php echo htmlspecialchars($group_info['code']); ?></code> •
                            <?php echo htmlspecialchars($group_info['specialty']); ?> •
                            <?php echo htmlspecialchars($group_info['course']); ?>
                        </p>
                    </div>
                    <div class="btn-group">
                        <button class="btn btn-success" onclick="exportReport()">
                            <i class="bi bi-download me-1"></i>Экспорт
                        </button>
                        <button class="btn btn-primary" onclick="window.print()">
                            <i class="bi bi-printer me-1"></i>Печать
                        </button>
                        <a href="groups.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left me-1"></i>Назад к группам
                        </a>
                    </div>
                </div>

                <!-- Информация о группе -->
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Информация о группе
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Название:</strong> <?php echo htmlspecialchars($group_info['name']); ?></p>
                                <p><strong>Код:</strong> <code><?php echo htmlspecialchars($group_info['code']); ?></code></p>
                                <p><strong>Специальность:</strong> <?php echo htmlspecialchars($group_info['specialty']); ?></p>
                                <p><strong>Квалификация:</strong> <?php echo htmlspecialchars($group_info['qualification']); ?></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Курс:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($group_info['course']); ?></span></p>
                                <p><strong>Язык обучения:</strong> <?php echo htmlspecialchars($group_info['language']); ?></p>
                                <p><strong>Форма обучения:</strong> <?php echo htmlspecialchars($group_info['study_form']); ?></p>
                                <p><strong>Куратор:</strong>
                                    <?php if ($group_info['curator_first_name']): ?>
                                        <?php echo htmlspecialchars($group_info['curator_first_name'] . ' ' . $group_info['curator_last_name']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">Не назначен</span>
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Основная статистика -->
                <div class="row mb-4">
                    <div class="col-md-2 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $stats['total']; ?></h4>
                                <p class="card-text">Всего студентов</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $stats['active']; ?></h4>
                                <p class="card-text">Активных</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-warning text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $stats['academic_leave']; ?></h4>
                                <p class="card-text">Академ. отпуск</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-info text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $stats['male']; ?></h4>
                                <p class="card-text">Мужчин</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-secondary text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $stats['female']; ?></h4>
                                <p class="card-text">Женщин</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2 mb-3">
                        <div class="card bg-dark text-white">
                            <div class="card-body text-center">
                                <h4 class="card-title"><?php echo $occupancy_percentage; ?>%</h4>
                                <p class="card-text">Заполненность</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Специальная статистика -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="bi bi-wheelchair text-warning fs-1 mb-2"></i>
                                <h5 class="card-title"><?php echo $stats['disabled']; ?></h5>
                                <p class="card-text">С инвалидностью</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-info">
                            <div class="card-body text-center">
                                <i class="bi bi-heart text-info fs-1 mb-2"></i>
                                <h5 class="card-title"><?php echo $stats['orphan']; ?></h5>
                                <p class="card-text">Дети-сироты</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-danger">
                            <div class="card-body text-center">
                                <i class="bi bi-exclamation-triangle text-danger fs-1 mb-2"></i>
                                <h5 class="card-title"><?php echo $stats['without_parental_care']; ?></h5>
                                <p class="card-text">Без попечения родителей</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3 mb-3">
                        <div class="card border-primary">
                            <div class="card-body text-center">
                                <i class="bi bi-award text-primary fs-1 mb-2"></i>
                                <h5 class="card-title"><?php echo $stats['graduated']; ?></h5>
                                <p class="card-text">Выпускников</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Дополнительная статистика -->
                <div class="row mb-4">
                    <div class="col-md-3 mb-3">
                        <div class="card border-warning">
                            <div class="card-body text-center">
                                <i class="bi bi-calendar-minus text-warning fs-1 mb-2"></i>
                                <h5 class="card-title"><?php echo $stats['minors']; ?></h5>
                                <p class="card-text">Несовершеннолетних</p>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Графики -->
                <div class="row mb-4">
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pie-chart me-2"></i>
                                    Распределение по статусу
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="statusChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-bar-chart me-2"></i>
                                    Распределение по полу
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="genderChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row mb-4">
                    <?php if (!empty($age_stats)): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-calendar me-2"></i>
                                        Распределение по возрасту
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="ageChart" width="400" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($nationality_stats)): ?>
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-globe me-2"></i>
                                        Национальный состав
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <canvas id="nationalityChart" width="400" height="200"></canvas>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Детальная таблица студентов -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-list me-2"></i>
                            Детальная информация о студентах
                        </h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ФИО</th>
                                        <th>ИИН</th>
                                        <th>Пол</th>
                                        <th>Дата рождения</th>
                                        <th>Национальность</th>
                                        <th>Статус</th>
                                        <th>Особенности</th>
                                        <th>Телефон</th>
                                        <th>Email</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $student): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['middle_name']); ?></strong>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($student['iin']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($student['gender']); ?></span>
                                            </td>
                                            <td>
                                                <?php echo $student['birth_date'] ? date('d.m.Y', strtotime($student['birth_date'])) : '—'; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($student['nationality'] ?: '—'); ?></td>
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
                                                        <?php echo htmlspecialchars($student['phone']); ?>
                                                    </a>
                                                <?php else: ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($student['email']): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </a>
                                                <?php else: ?>
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
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // График по статусам
        const statusData = <?php echo json_encode([
                                'active' => $stats['active'],
                                'academic_leave' => $stats['academic_leave'],
                                'graduated' => $stats['graduated']
                            ]); ?>;
        const statusCtx = document.getElementById('statusChart').getContext('2d');

        new Chart(statusCtx, {
            type: 'doughnut',
            data: {
                labels: ['Активные', 'Академ. отпуск', 'Выпускники'],
                datasets: [{
                    data: [statusData.active, statusData.academic_leave, statusData.graduated],
                    backgroundColor: ['#28a745', '#ffc107', '#17a2b8'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                plugins: {
                    legend: {
                        position: 'bottom'
                    }
                }
            }
        });

        // График по полу
        const genderData = <?php echo json_encode([
                                'male' => $stats['male'],
                                'female' => $stats['female']
                            ]); ?>;
        const genderCtx = document.getElementById('genderChart').getContext('2d');

        new Chart(genderCtx, {
            type: 'bar',
            data: {
                labels: ['Мужчины', 'Женщины'],
                datasets: [{
                    label: 'Количество',
                    data: [genderData.male, genderData.female],
                    backgroundColor: ['#007bff', '#dc3545'],
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });

        <?php if (!empty($age_stats)): ?>
            // График по возрасту
            const ageData = <?php echo json_encode($age_stats); ?>;
            const ageCtx = document.getElementById('ageChart').getContext('2d');

            new Chart(ageCtx, {
                type: 'bar',
                data: {
                    labels: Object.keys(ageData),
                    datasets: [{
                        label: 'Количество',
                        data: Object.values(ageData),
                        backgroundColor: '#6f42c1',
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        <?php endif; ?>

        <?php if (!empty($nationality_stats)): ?>
            // График по национальности
            const nationalityData = <?php echo json_encode($nationality_stats); ?>;
            const nationalityCtx = document.getElementById('nationalityChart').getContext('2d');

            new Chart(nationalityCtx, {
                type: 'pie',
                data: {
                    labels: Object.keys(nationalityData),
                    datasets: [{
                        data: Object.values(nationalityData),
                        backgroundColor: ['#28a745', '#17a2b8', '#ffc107', '#dc3545', '#6f42c1'],
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        <?php endif; ?>

        function exportReport() {
            // Создание отчета в текстовом формате
            let report = `ОТЧЕТ ПО ГРУППЕ "${<?php echo json_encode($group_info['name']); ?>"}\n`;
            report += `Код: ${<?php echo json_encode($group_info['code']); ?>}\n`;
            report += `Специальность: ${<?php echo json_encode($group_info['specialty']); ?>}\n`;
            report += `Курс: ${<?php echo json_encode($group_info['course']); ?>}\n\n`;

            report += `СТАТИСТИКА:\n`;
            report += `Всего студентов: ${<?php echo $stats['total']; ?>}\n`;
            report += `Активных: ${<?php echo $stats['active']; ?>}\n`;
            report += `В академ. отпуске: ${<?php echo $stats['academic_leave']; ?>}\n`;
            report += `Выпускников: ${<?php echo $stats['graduated']; ?>}\n`;
            report += `Мужчин: ${<?php echo $stats['male']; ?>}\n`;
            report += `Женщин: ${<?php echo $stats['female']; ?>}\n`;
            report += `Заполненность: ${<?php echo $occupancy_percentage; ?>}%\n\n`;

            report += `СПЕЦИАЛЬНЫЕ КАТЕГОРИИ:\n`;
            report += `С инвалидностью: ${<?php echo $stats['disabled']; ?>}\n`;
            report += `Дети-сироты: ${<?php echo $stats['orphan']; ?>}\n`;
            report += `Без попечения родителей: ${<?php echo $stats['without_parental_care']; ?>}\n`;
            report += `Несовершеннолетних: ${<?php echo $stats['minors']; ?>}\n\n`;

            // Создание и скачивание файла
            const blob = new Blob([report], {
                type: 'text/plain;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'group_report_<?php echo $group_info['code']; ?>_' + new Date().toISOString().slice(0, 10) + '.txt');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
</body>

</html>