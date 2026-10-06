<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка роли куратора
checkRole(['curator']);

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение ID группы из параметра
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$curator_groups = $group->getGroupsByCurator($current_user['id']);

if (!$group_id) {
    if (!empty($curator_groups)) {
        header('Location: group_reports.php?id=' . (int)$curator_groups[0]['id']);
        exit;
    }
    header('Location: my_groups.php');
    exit;
}

// Получение информации о группе
$group_info = $group->getGroupById($group_id);

// Проверка, что группа принадлежит куратору
if (!$group->isCuratorGroup($group_id, $current_user['id'])) {
    header('Location: my_groups.php');
    exit;
}

// Получение детальной статистики студентов группы
$db = getDB();

// Общая статистика
$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');
$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave,
            SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated,
            SUM(CASE WHEN gender = 'мужской' THEN 1 ELSE 0 END) as male,
            SUM(CASE WHEN gender = 'женский' THEN 1 ELSE 0 END) as female
        FROM students 
        WHERE group_id = ?";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $stats = $result->fetch_assoc();
}

// Статистика по социальным категориям
$sql = "SELECT 
            SUM(CASE WHEN orphan = 1 THEN 1 ELSE 0 END) as orphans,
            SUM(CASE WHEN without_parental_care = 1 THEN 1 ELSE 0 END) as without_care,
            SUM(CASE WHEN disability = 1 THEN 1 ELSE 0 END) as disabled,
            SUM(CASE WHEN social_assistance = 1 THEN 1 ELSE 0 END) as social_assistance,
            SUM(CASE WHEN large_family = 1 THEN 1 ELSE 0 END) as large_family
        FROM students 
        WHERE group_id = ?";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $social_stats = $result->fetch_assoc();
}

// Статистика по питанию
$sql = "SELECT 
            SUM(CASE WHEN hot_meal = 1 THEN 1 ELSE 0 END) as hot_meal,
            SUM(CASE WHEN free_hot_meal = 1 THEN 1 ELSE 0 END) as free_hot_meal,
            SUM(CASE WHEN buffet_meal = 1 THEN 1 ELSE 0 END) as buffet_meal,
            SUM(CASE WHEN free_buffet_meal = 1 THEN 1 ELSE 0 END) as free_buffet_meal
        FROM students 
        WHERE group_id = ?";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $meal_stats = $result->fetch_assoc();
}

// Статистика по внеучебной деятельности
$sql = "SELECT 
            SUM(CASE WHEN youth_committee = 1 THEN 1 ELSE 0 END) as youth_committee,
            SUM(CASE WHEN student_parliament = 1 THEN 1 ELSE 0 END) as student_parliament,
            SUM(CASE WHEN jas_sarbaz = 1 THEN 1 ELSE 0 END) as jas_sarbaz,
            SUM(CASE WHEN paid_practice = 1 THEN 1 ELSE 0 END) as paid_practice
        FROM students 
        WHERE group_id = ?";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $activity_stats = $result->fetch_assoc();
}

// Статистика по квотам
$sql = "SELECT quota_category, COUNT(*) as count 
        FROM students 
        WHERE group_id = ? AND quota_category IS NOT NULL AND quota_category != ''
        GROUP BY quota_category";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $quota_stats = $result->fetch_all(MYSQLI_ASSOC);
}

$page_title = 'Отчёты группы «' . $group_info['name'] . '»';
$page_subtitle = $group_info['code'] . ' · ' . $group_info['course'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <div class="curator-action-buttons">
                    <?php if (count($curator_groups) > 1): ?>
                        <select class="form-select form-select-sm" style="width: auto; min-width: 180px;"
                                onchange="if (this.value) window.location.href='group_reports.php?id=' + this.value;"
                                aria-label="Выбор группы">
                            <?php foreach ($curator_groups as $g): ?>
                                <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $group_id ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($g['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <a href="my_groups.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-collection me-1"></i>Мои группы
                    </a>
                    <a href="group_details.php?id=<?php echo $group_id; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-info-circle me-1"></i>Подробности
                    </a>
                    <a href="group_students.php?id=<?php echo $group_id; ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-people me-1"></i>Студенты
                    </a>
                </div>
            </div>

                <!-- Информация о группе -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-3">
                                <h6 class="text-primary">Группа</h6>
                                <p><strong><?php echo htmlspecialchars($group_info['name']); ?></strong></p>
                                <p><code><?php echo htmlspecialchars($group_info['code']); ?></code></p>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-primary">Специальность</h6>
                                <p><?php echo htmlspecialchars($group_info['specialty']); ?></p>
                                <p><?php echo htmlspecialchars($group_info['qualification']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-primary">Обучение</h6>
                                <p><span class="badge bg-info"><?php echo htmlspecialchars($group_info['course']); ?></span></p>
                                <p><?php echo htmlspecialchars($group_info['language']); ?> • <?php echo htmlspecialchars($group_info['study_form']); ?></p>
                            </div>
                            <div class="col-md-3">
                                <h6 class="text-primary">Куратор</h6>
                                <p><?php echo $group_info['curator_first_name'] ? htmlspecialchars($group_info['curator_first_name'] . ' ' . $group_info['curator_last_name']) : 'Не назначен'; ?></p>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="row">
                    <!-- Общая статистика -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-bar-chart-fill me-2"></i>
                                    Общая статистика
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="generalChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Статистика по полу -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-pie-chart-fill me-2"></i>
                                    Распределение по полу
                                </h5>
                            </div>
                            <div class="card-body">
                                <canvas id="genderChart" width="400" height="200"></canvas>
                            </div>
                        </div>
                    </div>

                    <!-- Социальные категории -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-people-fill me-2"></i>
                                    Социальные категории
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?php echo $social_stats['orphans']; ?></h4>
                                            <small class="text-muted">Дети-сироты</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-info"><?php echo $social_stats['without_care']; ?></h4>
                                            <small class="text-muted">Без попечения</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-danger"><?php echo $social_stats['disabled']; ?></h4>
                                            <small class="text-muted">С инвалидностью</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-success"><?php echo $social_stats['social_assistance']; ?></h4>
                                            <small class="text-muted">Соц. помощь</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Питание -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-cup-hot-fill me-2"></i>
                                    Охват питанием
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?php echo $meal_stats['hot_meal']; ?></h4>
                                            <small class="text-muted">Горячее питание</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-success"><?php echo $meal_stats['free_hot_meal']; ?></h4>
                                            <small class="text-muted">Бесплатное горячее</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-info"><?php echo $meal_stats['buffet_meal']; ?></h4>
                                            <small class="text-muted">Буфетное питание</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?php echo $meal_stats['free_buffet_meal']; ?></h4>
                                            <small class="text-muted">Бесплатное буфетное</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Внеучебная деятельность -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-activity me-2"></i>
                                    Внеучебная деятельность
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-primary"><?php echo $activity_stats['youth_committee']; ?></h4>
                                            <small class="text-muted">Молодежный комитет</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-success"><?php echo $activity_stats['student_parliament']; ?></h4>
                                            <small class="text-muted">Студ. парламент</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-info"><?php echo $activity_stats['jas_sarbaz']; ?></h4>
                                            <small class="text-muted">"Жас Сарбаз"</small>
                                        </div>
                                    </div>
                                    <div class="col-6 mb-3">
                                        <div class="text-center">
                                            <h4 class="text-warning"><?php echo $activity_stats['paid_practice']; ?></h4>
                                            <small class="text-muted">Оплачиваемая практика</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Квоты при поступлении -->
                    <div class="col-md-6 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-award-fill me-2"></i>
                                    Квоты при поступлении
                                </h5>
                            </div>
                            <div class="card-body">
                                <?php if (empty($quota_stats)): ?>
                                    <p class="text-muted">Нет студентов с квотами</p>
                                <?php else: ?>
                                    <div class="list-group list-group-flush">
                                        <?php foreach ($quota_stats as $quota): ?>
                                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                                <span><?php echo htmlspecialchars($quota['quota_category']); ?></span>
                                                <span class="badge bg-primary"><?php echo $quota['count']; ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- Сводная таблица -->
                    <div class="col-12 mb-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-table me-2"></i>
                                    Сводная таблица
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered">
                                        <thead>
                                            <tr>
                                                <th>Показатель</th>
                                                <th>Количество</th>
                                                <th>Процент</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <tr>
                                                <td>Всего студентов</td>
                                                <td><?php echo $stats['total']; ?></td>
                                                <td>100%</td>
                                            </tr>
                                            <tr>
                                                <td>Активных студентов</td>
                                                <td><?php echo $stats['active']; ?></td>
                                                <td><?php echo $stats['total'] > 0 ? round(($stats['active'] / $stats['total']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>В академическом отпуске</td>
                                                <td><?php echo $stats['academic_leave']; ?></td>
                                                <td><?php echo $stats['total'] > 0 ? round(($stats['academic_leave'] / $stats['total']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>Выпускников</td>
                                                <td><?php echo $stats['graduated']; ?></td>
                                                <td><?php echo $stats['total'] > 0 ? round(($stats['graduated'] / $stats['total']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>Мужчин</td>
                                                <td><?php echo $stats['male']; ?></td>
                                                <td><?php echo $stats['total'] > 0 ? round(($stats['male'] / $stats['total']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                            <tr>
                                                <td>Женщин</td>
                                                <td><?php echo $stats['female']; ?></td>
                                                <td><?php echo $stats['total'] > 0 ? round(($stats['female'] / $stats['total']) * 100, 1) : 0; ?>%</td>
                                            </tr>
                                        </tbody>
                                    </table>
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
    <script src="assets/js/curator-ui.js"></script>
    <script>
        // График общей статистики
        const generalCtx = document.getElementById('generalChart').getContext('2d');
        new Chart(generalCtx, {
            type: 'bar',
            data: {
                labels: ['Активные', 'В академ. отпуске', 'Выпускники'],
                datasets: [{
                    label: 'Количество студентов',
                    data: [
                        <?php echo $stats['active']; ?>,
                        <?php echo $stats['academic_leave']; ?>,
                        <?php echo $stats['graduated']; ?>
                    ],
                    backgroundColor: [
                        'rgba(40, 167, 69, 0.8)',
                        'rgba(255, 193, 7, 0.8)',
                        'rgba(23, 162, 184, 0.8)'
                    ],
                    borderColor: [
                        'rgba(40, 167, 69, 1)',
                        'rgba(255, 193, 7, 1)',
                        'rgba(23, 162, 184, 1)'
                    ],
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

        // График распределения по полу
        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: ['Мужчины', 'Женщины'],
                datasets: [{
                    data: [
                        <?php echo $stats['male']; ?>,
                        <?php echo $stats['female']; ?>
                    ],
                    backgroundColor: [
                        'rgba(54, 162, 235, 0.8)',
                        'rgba(255, 99, 132, 0.8)'
                    ],
                    borderColor: [
                        'rgba(54, 162, 235, 1)',
                        'rgba(255, 99, 132, 1)'
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

