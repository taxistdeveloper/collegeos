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

$stats = array_merge([
    'total' => 0,
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0,
], $stats ?? []);
$social_stats = array_merge([
    'orphans' => 0,
    'without_care' => 0,
    'disabled' => 0,
    'social_assistance' => 0,
    'large_family' => 0,
], $social_stats ?? []);
$meal_stats = array_merge([
    'hot_meal' => 0,
    'free_hot_meal' => 0,
    'buffet_meal' => 0,
    'free_buffet_meal' => 0,
], $meal_stats ?? []);
$activity_stats = array_merge([
    'youth_committee' => 0,
    'student_parliament' => 0,
    'jas_sarbaz' => 0,
    'paid_practice' => 0,
], $activity_stats ?? []);
$quota_stats = $quota_stats ?? [];

$total = (int)$stats['total'];
$pct = static function ($value) use ($total) {
    if ($total <= 0) {
        return 0;
    }
    return round(((int)$value / $total) * 100, 1);
};
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
    <link href="assets/css/group-reports.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">
            <div class="gr-page curator-animate-fadeInUp">

                <div class="gr-toolbar">
                    <div class="gr-toolbar-actions">
                        <?php if (count($curator_groups) > 1): ?>
                            <select class="form-select form-select-sm"
                                    onchange="if (this.value) window.location.href='group_reports.php?id=' + this.value;"
                                    aria-label="Выбор группы">
                                <?php foreach ($curator_groups as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $group_id ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($g['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>
                    <div class="gr-toolbar-actions">
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

                <div class="gr-hero">
                    <div>
                        <h2 class="gr-hero-title"><i class="bi bi-graph-up-arrow me-2"></i>Отчёт по группе</h2>
                        <p class="gr-hero-sub">
                            Сводка по составу, социальным категориям, питанию и внеучебной активности.
                            Данные обновляются по текущим студентам группы.
                        </p>
                    </div>
                    <div class="gr-hero-meta">
                        <div class="gr-meta-item">
                            <span class="gr-meta-label">Группа</span>
                            <div class="gr-meta-value"><?php echo htmlspecialchars($group_info['name']); ?> · <?php echo htmlspecialchars($group_info['code']); ?></div>
                        </div>
                        <div class="gr-meta-item">
                            <span class="gr-meta-label">Курс / форма</span>
                            <div class="gr-meta-value"><?php echo htmlspecialchars($group_info['course']); ?> · <?php echo htmlspecialchars($group_info['language']); ?> · <?php echo htmlspecialchars($group_info['study_form']); ?></div>
                        </div>
                        <div class="gr-meta-item">
                            <span class="gr-meta-label">Специальность</span>
                            <div class="gr-meta-value"><?php echo htmlspecialchars($group_info['specialty']); ?></div>
                        </div>
                        <div class="gr-meta-item">
                            <span class="gr-meta-label">Куратор</span>
                            <div class="gr-meta-value">
                                <?php echo !empty($group_info['curator_first_name'])
                                    ? htmlspecialchars(trim(($group_info['curator_first_name'] ?? '') . ' ' . ($group_info['curator_last_name'] ?? '')))
                                    : 'Не назначен'; ?>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="gr-kpi-grid">
                    <div class="gr-kpi gr-kpi-total">
                        <div class="gr-kpi-label">Всего студентов</div>
                        <div class="gr-kpi-value"><?php echo (int)$stats['total']; ?></div>
                        <span class="gr-kpi-pct">100%</span>
                    </div>
                    <div class="gr-kpi gr-kpi-active">
                        <div class="gr-kpi-label">Активные</div>
                        <div class="gr-kpi-value"><?php echo (int)$stats['active']; ?></div>
                        <span class="gr-kpi-pct"><?php echo $pct($stats['active']); ?>%</span>
                    </div>
                    <div class="gr-kpi gr-kpi-leave">
                        <div class="gr-kpi-label">Академ. отпуск</div>
                        <div class="gr-kpi-value"><?php echo (int)$stats['academic_leave']; ?></div>
                        <span class="gr-kpi-pct"><?php echo $pct($stats['academic_leave']); ?>%</span>
                    </div>
                    <div class="gr-kpi gr-kpi-grad">
                        <div class="gr-kpi-label">Выпускники</div>
                        <div class="gr-kpi-value"><?php echo (int)$stats['graduated']; ?></div>
                        <span class="gr-kpi-pct"><?php echo $pct($stats['graduated']); ?>%</span>
                    </div>
                </div>

                <div class="gr-grid">
                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-bar-chart-fill"></i></span>
                            <h3 class="gr-panel-title">Состав группы</h3>
                        </div>
                        <div class="gr-panel-body">
                            <div class="gr-chart-wrap">
                                <canvas id="generalChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-pie-chart-fill"></i></span>
                            <h3 class="gr-panel-title">По полу</h3>
                        </div>
                        <div class="gr-panel-body">
                            <div class="gr-chart-wrap">
                                <canvas id="genderChart"></canvas>
                            </div>
                        </div>
                    </div>

                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-heart-fill"></i></span>
                            <h3 class="gr-panel-title">Социальные категории</h3>
                        </div>
                        <div class="gr-panel-body">
                            <div class="gr-metric-grid">
                                <?php
                                $social_items = [
                                    ['label' => 'Дети-сироты', 'value' => $social_stats['orphans'], 'class' => 'is-warn'],
                                    ['label' => 'Без попечения', 'value' => $social_stats['without_care'], 'class' => 'is-info'],
                                    ['label' => 'С инвалидностью', 'value' => $social_stats['disabled'], 'class' => 'is-danger'],
                                    ['label' => 'Соц. помощь', 'value' => $social_stats['social_assistance'], 'class' => 'is-success'],
                                    ['label' => 'Многодетная семья', 'value' => $social_stats['large_family'], 'class' => ''],
                                ];
                                foreach ($social_items as $item):
                                    $p = $pct($item['value']);
                                ?>
                                    <div class="gr-metric <?php echo $item['class']; ?>">
                                        <div class="gr-metric-top">
                                            <span class="gr-metric-name"><?php echo htmlspecialchars($item['label']); ?></span>
                                            <span class="gr-metric-val"><?php echo (int)$item['value']; ?></span>
                                        </div>
                                        <div class="gr-metric-bar"><span style="width: <?php echo min(100, $p); ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-cup-hot-fill"></i></span>
                            <h3 class="gr-panel-title">Охват питанием</h3>
                        </div>
                        <div class="gr-panel-body">
                            <div class="gr-metric-grid">
                                <?php
                                $meal_items = [
                                    ['label' => 'Горячее питание', 'value' => $meal_stats['hot_meal'], 'class' => ''],
                                    ['label' => 'Бесплатное горячее', 'value' => $meal_stats['free_hot_meal'], 'class' => 'is-success'],
                                    ['label' => 'Буфетное питание', 'value' => $meal_stats['buffet_meal'], 'class' => 'is-info'],
                                    ['label' => 'Бесплатный буфет', 'value' => $meal_stats['free_buffet_meal'], 'class' => 'is-warn'],
                                ];
                                foreach ($meal_items as $item):
                                    $p = $pct($item['value']);
                                ?>
                                    <div class="gr-metric <?php echo $item['class']; ?>">
                                        <div class="gr-metric-top">
                                            <span class="gr-metric-name"><?php echo htmlspecialchars($item['label']); ?></span>
                                            <span class="gr-metric-val"><?php echo (int)$item['value']; ?></span>
                                        </div>
                                        <div class="gr-metric-bar"><span style="width: <?php echo min(100, $p); ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-activity"></i></span>
                            <h3 class="gr-panel-title">Внеучебная деятельность</h3>
                        </div>
                        <div class="gr-panel-body">
                            <div class="gr-metric-grid">
                                <?php
                                $activity_items = [
                                    ['label' => 'Молодёжный комитет', 'value' => $activity_stats['youth_committee'], 'class' => ''],
                                    ['label' => 'Студ. парламент', 'value' => $activity_stats['student_parliament'], 'class' => 'is-success'],
                                    ['label' => 'Жас Сарбаз', 'value' => $activity_stats['jas_sarbaz'], 'class' => 'is-info'],
                                    ['label' => 'Оплачиваемая практика', 'value' => $activity_stats['paid_practice'], 'class' => 'is-warn'],
                                ];
                                foreach ($activity_items as $item):
                                    $p = $pct($item['value']);
                                ?>
                                    <div class="gr-metric <?php echo $item['class']; ?>">
                                        <div class="gr-metric-top">
                                            <span class="gr-metric-name"><?php echo htmlspecialchars($item['label']); ?></span>
                                            <span class="gr-metric-val"><?php echo (int)$item['value']; ?></span>
                                        </div>
                                        <div class="gr-metric-bar"><span style="width: <?php echo min(100, $p); ?>%"></span></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="gr-panel">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-award-fill"></i></span>
                            <h3 class="gr-panel-title">Квоты при поступлении</h3>
                        </div>
                        <div class="gr-panel-body">
                            <?php if (empty($quota_stats)): ?>
                                <div class="gr-empty">
                                    <i class="bi bi-inbox"></i>
                                    Нет данных по квотам
                                </div>
                            <?php else: ?>
                                <div class="gr-quota-list">
                                    <?php foreach ($quota_stats as $quota): ?>
                                        <div class="gr-quota-item">
                                            <span class="gr-quota-name"><?php echo htmlspecialchars($quota['quota_category']); ?></span>
                                            <span class="gr-quota-count"><?php echo (int)$quota['count']; ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="gr-panel gr-panel-full">
                        <div class="gr-panel-head">
                            <span class="gr-panel-icon"><i class="bi bi-table"></i></span>
                            <h3 class="gr-panel-title">Сводная таблица</h3>
                        </div>
                        <div class="gr-panel-body" style="padding: 0;">
                            <div class="table-responsive">
                                <table class="gr-table">
                                    <thead>
                                        <tr>
                                            <th>Показатель</th>
                                            <th>Кол-во</th>
                                            <th>Доля</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        $summary_rows = [
                                            ['Всего студентов', $stats['total'], 100],
                                            ['Активные', $stats['active'], $pct($stats['active'])],
                                            ['В академическом отпуске', $stats['academic_leave'], $pct($stats['academic_leave'])],
                                            ['Выпускники', $stats['graduated'], $pct($stats['graduated'])],
                                            ['Мужчины', $stats['male'], $pct($stats['male'])],
                                            ['Женщины', $stats['female'], $pct($stats['female'])],
                                        ];
                                        foreach ($summary_rows as $row):
                                        ?>
                                            <tr>
                                                <td class="gr-table-name"><?php echo htmlspecialchars($row[0]); ?></td>
                                                <td class="gr-table-num"><?php echo (int)$row[1]; ?></td>
                                                <td class="gr-table-pct">
                                                    <div class="gr-inline-bar">
                                                        <div class="gr-inline-bar-track"><span style="width: <?php echo min(100, (float)$row[2]); ?>%"></span></div>
                                                        <span class="gr-inline-bar-label"><?php echo $row[2]; ?>%</span>
                                                    </div>
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
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
    <script>
        const chartDefaults = {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false }
            }
        };

        const generalCtx = document.getElementById('generalChart').getContext('2d');
        new Chart(generalCtx, {
            type: 'bar',
            data: {
                labels: ['Активные', 'Академ. отпуск', 'Выпускники'],
                datasets: [{
                    data: [
                        <?php echo (int)$stats['active']; ?>,
                        <?php echo (int)$stats['academic_leave']; ?>,
                        <?php echo (int)$stats['graduated']; ?>
                    ],
                    backgroundColor: ['#10b981', '#f59e0b', '#06b6d4'],
                    borderRadius: 8,
                    maxBarThickness: 48
                }]
            },
            options: {
                ...chartDefaults,
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 12, weight: '600' }, color: '#64748b' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { precision: 0, color: '#94a3b8' },
                        grid: { color: '#f1f3f9' }
                    }
                }
            }
        });

        const genderCtx = document.getElementById('genderChart').getContext('2d');
        new Chart(genderCtx, {
            type: 'doughnut',
            data: {
                labels: ['Мужчины', 'Женщины'],
                datasets: [{
                    data: [
                        <?php echo (int)$stats['male']; ?>,
                        <?php echo (int)$stats['female']; ?>
                    ],
                    backgroundColor: ['#2c5af2', '#f472b6'],
                    borderWidth: 0,
                    hoverOffset: 4
                }]
            },
            options: {
                ...chartDefaults,
                cutout: '62%',
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom',
                        labels: {
                            boxWidth: 12,
                            usePointStyle: true,
                            pointStyle: 'circle',
                            font: { size: 12, weight: '600' },
                            color: '#475569'
                        }
                    }
                }
            }
        });
    </script>
</body>
</html>

