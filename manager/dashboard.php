<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_reports');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Перенаправление на страницу отчетов
header('Location: reports.php');
exit;

?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты менеджера - <?php echo APP_NAME; ?></title>
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
            margin-bottom: 1.5rem;
        }

        .card.bg-primary {
            background: var(--card-bg) !important;
            border-top: 4px solid var(--primary-color);
        }

        .card.bg-success {
            background: var(--card-bg) !important;
            border-top: 4px solid var(--secondary-color);
        }

        .card.bg-info {
            background: var(--card-bg) !important;
            border-top: 4px solid #0ea5e9;
        }

        .card.bg-primary .card-title,
        .card.bg-success .card-title,
        .card.bg-info .card-title {
            color: var(--text-primary) !important;
            font-weight: 800;
            font-size: 2.5rem;
        }

        .card.bg-primary .card-text,
        .card.bg-success .card-text,
        .card.bg-info .card-text {
            color: var(--text-secondary) !important;
        }

        .card.bg-primary .bi,
        .card.bg-success .bi,
        .card.bg-info .bi {
            opacity: 0.2;
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
        }

        .btn-primary:hover {
            background: #1d4ed8 !important;
            box-shadow: var(--shadow-lg);
            transform: translateY(-2px);
        }

        .btn-outline-primary,
        .btn-outline-success,
        .btn-outline-info,
        .btn-outline-warning {
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

        .btn-outline-warning {
            border-color: var(--accent-color);
            color: var(--accent-color);
        }

        .btn-outline-warning:hover {
            background: var(--accent-color) !important;
            color: white !important;
        }

        .badge {
            padding: 0.5rem 1rem;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.75rem;
        }

        .badge.bg-primary {
            background: var(--primary-color) !important;
        }

        .badge.bg-info {
            background: #0ea5e9 !important;
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
            font-size: 0.85rem;
            text-transform: uppercase;
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
            margin: 0.2rem;
            transition: all 0.2s ease;
        }

        .dropdown-item:hover {
            background: var(--light-bg);
            color: var(--primary-color);
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
                        <a class="nav-link active" href="dashboard.php">
                            <i class="bi bi-graph-up me-1"></i>Отчеты
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="students.php">
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
                        <i class="bi bi-graph-up text-primary me-2"></i>
                        Отчеты менеджера
                    </h1>
                    <button class="btn btn-primary" onclick="window.print()">
                        <i class="bi bi-printer me-1"></i>Печать
                    </button>
                </div>

                <!-- Статистика -->
                <div class="row mb-4">
                    <div class="col-md-4 mb-3">
                        <div class="card bg-primary text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 class="card-title"><?php echo $stats['active_groups']; ?></h4>
                                        <p class="card-text">Активных групп</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-collection-fill fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-success text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 class="card-title"><?php echo $stats['total_students']; ?></h4>
                                        <p class="card-text">Всего студентов</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-mortarboard fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 mb-3">
                        <div class="card bg-info text-white">
                            <div class="card-body">
                                <div class="d-flex justify-content-between">
                                    <div>
                                        <h4 class="card-title"><?php echo round($stats['total_students'] / max($stats['active_groups'], 1), 1); ?></h4>
                                        <p class="card-text">Среднее в группе</p>
                                    </div>
                                    <div class="align-self-center">
                                        <i class="bi bi-calculator fs-1"></i>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- График по курсам -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-bar-chart-fill me-2"></i>
                                    Группы по курсам
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="coursesChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- График по языкам -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pie-chart-fill me-2"></i>
                                    Языки обучения
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="languageChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Быстрые действия -->
                    <div class="col-md-4 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-lightning-fill me-2"></i>
                                    Быстрые действия
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="d-grid gap-2">
                                    <a href="students.php" class="btn btn-outline-primary">
                                        <i class="bi bi-mortarboard me-2"></i>Все студенты
                                    </a>
                                    <a href="../curator/add_student.php" class="btn btn-outline-success">
                                        <i class="bi bi-person-plus me-2"></i>Добавить студента
                                    </a>
                                    <a href="groups.php" class="btn btn-outline-info">
                                        <i class="bi bi-collection me-2"></i>Управление группами
                                    </a>
                                    <a href="reports.php" class="btn btn-outline-warning">
                                        <i class="bi bi-graph-up me-2"></i>Отчеты
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Последние группы -->
                    <div class="col-md-8 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-clock-fill me-2"></i>
                                    Активные группы
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($recent_groups)): ?>
                                    <p class="text-muted">Нет активных групп</p>
                                <?php else: ?>
                                    <div class="table-responsive">
                                        <table class="table table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Группа</th>
                                                    <th>Специальность</th>
                                                    <th>Курс</th>
                                                    <th>Студенты</th>
                                                    <th>Куратор</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($recent_groups as $group_item): ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($group_item['name']); ?></strong>
                                                            <br>
                                                            <code><?php echo htmlspecialchars($group_item['code']); ?></code>
                                                        </td>
                                                        <td>
                                                            <div class="text-truncate-2" style="max-width: 200px;" title="<?php echo htmlspecialchars($group_item['specialty']); ?>">
                                                                <?php echo htmlspecialchars($group_item['specialty']); ?>
                                                            </div>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-info"><?php echo htmlspecialchars($group_item['course']); ?></span>
                                                        </td>
                                                        <td>
                                                            <span class="badge bg-primary"><?php echo $group_item['current_students']; ?>/<?php echo $group_item['max_students']; ?></span>
                                                        </td>
                                                        <td>
                                                            <?php if ($group_item['curator_first_name']): ?>
                                                                <?php echo htmlspecialchars($group_item['curator_first_name'] . ' ' . $group_item['curator_last_name']); ?>
                                                            <?php else: ?>
                                                                <span class="text-muted">Не назначен</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Статистика по курсам -->
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-list-ol me-2"></i>
                                    Детальная статистика по курсам
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($groups_by_course as $course_data): ?>
                                        <div class="col-md-2 mb-3">
                                            <div class="card border-primary text-center">
                                                <div class="card-body">
                                                    <h6 class="card-title"><?php echo $course_data['course']; ?></h6>
                                                    <h4 class="text-primary"><?php echo $course_data['count']; ?></h4>
                                                    <small class="text-muted">групп</small>
                                                    <br>
                                                    <small class="text-success"><?php echo $course_data['students']; ?> студентов</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
        // График по курсам
        const coursesData = <?php echo json_encode($groups_by_course); ?>;
        const coursesCtx = document.getElementById('coursesChart').getContext('2d');

        new Chart(coursesCtx, {
            type: 'bar',
            data: {
                labels: coursesData.map(item => item.course),
                datasets: [{
                    label: 'Количество групп',
                    data: coursesData.map(item => item.count),
                    backgroundColor: 'rgba(54, 162, 235, 0.8)',
                    borderColor: 'rgba(54, 162, 235, 1)',
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

        // График по языкам
        const languageData = <?php echo json_encode($language_stats); ?>;
        const languageCtx = document.getElementById('languageChart').getContext('2d');

        new Chart(languageCtx, {
            type: 'doughnut',
            data: {
                labels: Object.keys(languageData),
                datasets: [{
                    data: Object.values(languageData),
                    backgroundColor: [
                        'rgba(255, 99, 132, 0.8)',
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 205, 86, 0.8)'
                    ],
                    borderColor: [
                        'rgba(255, 99, 132, 1)',
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 205, 86, 1)'
                    ],
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
    </script>
</body>

</html>