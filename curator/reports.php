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

// Получение статистики по студентам и группам (только для групп куратора)
$db = getDB();

// Получаем ID групп, которые курирует текущий пользователь
$curator_groups_sql = "SELECT id FROM `groups` WHERE curator_id = ?";
$stmt = $db->prepare($curator_groups_sql);
$stmt->bind_param("i", $current_user['id']);
$stmt->execute();
$curator_groups_result = $stmt->get_result();
$curator_group_ids = [];
while ($row = $curator_groups_result->fetch_assoc()) {
    $curator_group_ids[] = $row['id'];
}

if (empty($curator_group_ids)) {
    $curator_group_ids = [0]; // Если нет групп, используем 0 для пустого результата
}

$group_ids_placeholder = implode(',', array_fill(0, count($curator_group_ids), '?'));

$not_graduated = sqlNotGraduatedCondition('s');

// Общая статистика (только для групп куратора)
$sql = "SELECT 
    COUNT(DISTINCT s.id) as total_students,
    COUNT(DISTINCT g.id) as total_groups,
    SUM(CASE WHEN s.academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students
    FROM students s
    LEFT JOIN `groups` g ON s.group_id = g.id
    WHERE g.id IN ($group_ids_placeholder)";
$stmt = $db->prepare($sql);
$stmt->bind_param(str_repeat('i', count($curator_group_ids)), ...$curator_group_ids);
$stmt->execute();
$result = $stmt->get_result();
$stats = $result->fetch_assoc();

// Статистика по группам с детализацией (только для групп куратора)
$sql = "SELECT 
    g.name as group_name,
    g.course,
    g.specialty,
    g.language,
    g.study_form,
    g.max_students,
    COUNT(s.id) as current_students,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_count,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_count,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_count,
    ROUND((COUNT(s.id) / g.max_students) * 100, 1) as occupancy_percentage
    FROM `groups` g
    LEFT JOIN students s ON g.id = s.group_id
    WHERE g.id IN ($group_ids_placeholder)
    GROUP BY g.id, g.name, g.code, g.course, g.specialty, g.language, g.study_form, g.max_students
    ORDER BY g.course, g.name";
$stmt = $db->prepare($sql);
$stmt->bind_param(str_repeat('i', count($curator_group_ids)), ...$curator_group_ids);
$stmt->execute();
$result = $stmt->get_result();
$groups_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по специальностям (только для групп куратора)
$sql = "SELECT 
    g.specialty,
    COUNT(DISTINCT g.id) as groups_count,
    COUNT(DISTINCT s.id) as students_count,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students
    FROM `groups` g
    LEFT JOIN students s ON g.id = s.group_id
    WHERE g.id IN ($group_ids_placeholder)
    GROUP BY g.specialty
    ORDER BY students_count DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param(str_repeat('i', count($curator_group_ids)), ...$curator_group_ids);
$stmt->execute();
$result = $stmt->get_result();
$specialties_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по языкам обучения (только для групп куратора)
$sql = "SELECT 
    g.language,
    COUNT(DISTINCT g.id) as groups_count,
    COUNT(DISTINCT s.id) as students_count
    FROM `groups` g
    LEFT JOIN students s ON g.id = s.group_id
    WHERE g.id IN ($group_ids_placeholder)
    GROUP BY g.language
    ORDER BY students_count DESC";
$stmt = $db->prepare($sql);
$stmt->bind_param(str_repeat('i', count($curator_group_ids)), ...$curator_group_ids);
$stmt->execute();
$result = $stmt->get_result();
$languages_stats = $result->fetch_all(MYSQLI_ASSOC);

$page_title = 'Отчёты группы';
$page_subtitle = '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <button class="btn btn-primary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Печать
                </button>
            </div>

                <?php if (empty($curator_group_ids) || $curator_group_ids == [0]): ?>
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle me-2"></i>
                        У вас пока нет назначенных групп для кураторства.
                    </div>
                <?php else: ?>
                    <div class="curator-stats-grid">
                        <div class="curator-stat-card stat-primary">
                            <div class="stat-label"><span class="stat-dot"></span>Всего студентов</div>
                            <div class="stat-number"><?php echo (int)($stats['total_students'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
                        </div>
                        <div class="curator-stat-card stat-success">
                            <div class="stat-label"><span class="stat-dot"></span>Активных</div>
                            <div class="stat-number"><?php echo (int)($stats['active_students'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
                        </div>
                        <div class="curator-stat-card stat-warning">
                            <div class="stat-label"><span class="stat-dot"></span>С инвалидностью</div>
                            <div class="stat-number"><?php echo (int)($stats['disabled_students'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-heart-pulse"></i></div>
                        </div>
                        <div class="curator-stat-card stat-info">
                            <div class="stat-label"><span class="stat-dot"></span>Дети-сироты</div>
                            <div class="stat-number"><?php echo (int)($stats['orphan_students'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-people"></i></div>
                        </div>
                        <div class="curator-stat-card stat-warning">
                            <div class="stat-label"><span class="stat-dot"></span>В академ. отпуске</div>
                            <div class="stat-number"><?php echo (int)($stats['academic_leave_students'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
                        </div>
                        <div class="curator-stat-card stat-primary">
                            <div class="stat-label"><span class="stat-dot"></span>Моих групп</div>
                            <div class="stat-number"><?php echo (int)($stats['total_groups'] ?? 0); ?></div>
                            <div class="stat-icon"><i class="bi bi-collection-fill"></i></div>
                        </div>
                    </div>

                    <!-- Статистика по группам -->
                    <?php if (!empty($groups_stats)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-collection me-2"></i>
                                    Детальная статистика по моим группам
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Группа</th>
                                                <th>Курс</th>
                                                <th>Специальность</th>
                                                <th>Язык</th>
                                                <th>Форма</th>
                                                <th>Студентов</th>
                                                <th>Макс.</th>
                                                <th>Заполненность</th>
                                                <th>С инвалидностью</th>
                                                <th>Дети-сироты</th>
                                                <th>В академ. отпуске</th>
                                                <th>Действия</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($groups_stats as $group): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($group['group_name']); ?></strong>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($group['course']); ?></td>
                                                    <td><?php echo htmlspecialchars($group['specialty']); ?></td>
                                                    <td><?php echo htmlspecialchars($group['language']); ?></td>
                                                    <td><?php echo htmlspecialchars($group['study_form']); ?></td>
                                                    <td><?php echo $group['current_students']; ?></td>
                                                    <td><?php echo $group['max_students']; ?></td>
                                                    <td>
                                                        <div class="progress" style="height: 20px;">
                                                            <div class="progress-bar <?php echo $group['occupancy_percentage'] > 80 ? 'bg-success' : ($group['occupancy_percentage'] > 60 ? 'bg-warning' : 'bg-danger'); ?>"
                                                                style="width: <?php echo $group['occupancy_percentage']; ?>%">
                                                                <?php echo $group['occupancy_percentage']; ?>%
                                                            </div>
                                                        </div>
                                                    </td>
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
                                                    <td>
                                                        <a href="group_students.php?group_name=<?php echo urlencode($group['group_name']); ?>"
                                                            class="btn btn-sm btn-outline-success">
                                                            <i class="bi bi-people me-1"></i>Студенты
                                                        </a>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Статистика по специальностям -->
                    <?php if (!empty($specialties_stats)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-mortarboard me-2"></i>
                                    Статистика по специальностям
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-striped">
                                        <thead>
                                            <tr>
                                                <th>Специальность</th>
                                                <th>Количество групп</th>
                                                <th>Количество студентов</th>
                                                <th>С инвалидностью</th>
                                                <th>Дети-сироты</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($specialties_stats as $specialty): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($specialty['specialty']); ?></strong></td>
                                                    <td><?php echo $specialty['groups_count']; ?></td>
                                                    <td><?php echo $specialty['students_count']; ?></td>
                                                    <td>
                                                        <?php if ($specialty['disabled_students'] > 0): ?>
                                                            <span class="badge bg-warning"><?php echo $specialty['disabled_students']; ?></span>
                                                        <?php else: ?>
                                                            <?php echo $specialty['disabled_students']; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($specialty['orphan_students'] > 0): ?>
                                                            <span class="badge bg-info"><?php echo $specialty['orphan_students']; ?></span>
                                                        <?php else: ?>
                                                            <?php echo $specialty['orphan_students']; ?>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Статистика по языкам обучения -->
                    <?php if (!empty($languages_stats)): ?>
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-translate me-2"></i>
                                    Статистика по языкам обучения
                                </h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($languages_stats as $language): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="card border-success">
                                                <div class="card-body text-center">
                                                    <h5 class="card-title text-success"><?php echo $language['students_count']; ?></h5>
                                                    <p class="card-text"><?php echo htmlspecialchars($language['language']); ?></p>
                                                    <small class="text-muted"><?php echo $language['groups_count']; ?> групп</small>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
</body>

</html>
