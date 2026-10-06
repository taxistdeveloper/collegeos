<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkAuth();

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_reports');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$group_id) {
    header('Location: groups.php');
    exit;
}

$group_info = $group->getGroupById($group_id);
if (!$group_info) {
    header('Location: groups.php');
    exit;
}

$db = getDB();

$graduated = sqlGraduatedCondition('s');
$graduating = sqlGraduatingStudentCondition('s');
$sql = "SELECT s.*,
               CASE WHEN s.academic_leave = 1 THEN 'academic_leave'
                    WHEN $graduated THEN 'graduated'
                    WHEN $graduating THEN 'graduating'
                    ELSE 'active' END as status
        FROM students s
        WHERE s.group_id = ?
        ORDER BY s.last_name, s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
$stmt->bind_param('i', $group_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

function gr_age(?string $birth_date): ?int
{
    if (!$birth_date) {
        return null;
    }
    try {
        $birth = new DateTime($birth_date);
        $now = new DateTime('today');
        return (int)$birth->diff($now)->y;
    } catch (Exception $e) {
        return null;
    }
}

function gr_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function gr_date($value): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d.m.Y', $ts) : '—';
}

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
    'minors' => 0,
];

$age_stats = [
    'До 18 лет' => 0,
    '18-24 года' => 0,
    '25-29 лет' => 0,
    '30-34 года' => 0,
    '35+ лет' => 0,
];
$nationality_stats = [];
$residence_stats = [];

foreach ($students as &$student) {
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

    if (($student['gender'] ?? '') === 'мужской') {
        $stats['male']++;
    } elseif (($student['gender'] ?? '') === 'женский') {
        $stats['female']++;
    }

    if (!empty($student['disability'])) {
        $stats['disabled']++;
    }
    if (!empty($student['orphan'])) {
        $stats['orphan']++;
    }
    if (!empty($student['without_parental_care'])) {
        $stats['without_parental_care']++;
    }

    $age = gr_age($student['birth_date'] ?? null);
    $student['age'] = $age;
    if ($age !== null) {
        if ($age < 18) {
            $stats['minors']++;
            $age_stats['До 18 лет']++;
        } elseif ($age < 25) {
            $age_stats['18-24 года']++;
        } elseif ($age < 30) {
            $age_stats['25-29 лет']++;
        } elseif ($age < 35) {
            $age_stats['30-34 года']++;
        } else {
            $age_stats['35+ лет']++;
        }
    }

    if (!empty($student['nationality'])) {
        $key = $student['nationality'];
        $nationality_stats[$key] = ($nationality_stats[$key] ?? 0) + 1;
    }
    if (!empty($student['residence_type'])) {
        $key = $student['residence_type'];
        $residence_stats[$key] = ($residence_stats[$key] ?? 0) + 1;
    }
}
unset($student);

$age_stats = array_filter($age_stats);
arsort($nationality_stats);
arsort($residence_stats);

$max_students = (int)($group_info['max_students'] ?? 0);
$occupancy_percentage = $max_students > 0 ? round(($stats['total'] / $max_students) * 100, 1) : 0;

$curator_name = '';
if (!empty($group_info['curator_first_name']) || !empty($group_info['curator_last_name'])) {
    $curator_name = trim(($group_info['curator_last_name'] ?? '') . ' ' . ($group_info['curator_first_name'] ?? '') . ' ' . ($group_info['curator_middle_name'] ?? ''));
}

$is_active = !empty($group_info['is_active']);

function gr_flags(array $student): string
{
    $parts = [];
    if (!empty($student['disability'])) {
        $parts[] = '<i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>';
    }
    if (!empty($student['orphan'])) {
        $parts[] = '<i class="bi bi-heart text-info" title="Сирота"></i>';
    }
    if (!empty($student['without_parental_care'])) {
        $parts[] = '<i class="bi bi-exclamation-triangle text-danger" title="Без попечения родителей"></i>';
    }
    return $parts ? '<span class="gr-flags">' . implode('', $parts) . '</span>' : '<span class="text-muted">—</span>';
}

function gr_breakdown(array $items, int $total): string
{
    if (empty($items) || $total <= 0) {
        return '<p class="text-muted mb-0">Нет данных</p>';
    }
    $html = '<div class="gr-breakdown">';
    foreach ($items as $name => $count) {
        $pct = round(($count / $total) * 100, 1);
        $html .= '<div class="gr-breakdown-row">'
            . '<span class="name">' . gr_h($name) . '</span>'
            . '<div class="gr-bar"><span style="width:' . min(100, $pct) . '%"></span></div>'
            . '<span class="val">' . (int)$count . '</span>'
            . '</div>';
    }
    $html .= '</div>';
    return $html;
}

$page_title = 'Отчёты: ' . ($group_info['name'] ?? '');
$page_subtitle = 'Статистика группы';
$document_title = $page_title;
$extra_head = '<link href="assets/css/view-student.css" rel="stylesheet">'
    . '<link href="assets/css/group-reports.css" rel="stylesheet">'
    . '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>';

include 'includes/layout_start.php';
?>

<div class="vs-page">

    <nav aria-label="breadcrumb" class="vs-breadcrumb no-print">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Главная</a></li>
            <li class="breadcrumb-item"><a href="groups.php">Группы</a></li>
            <li class="breadcrumb-item"><a href="group_details.php?id=<?php echo $group_id; ?>"><?php echo gr_h($group_info['name'] ?? ''); ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Отчёты</li>
        </ol>
    </nav>

    <section class="vs-hero" aria-labelledby="report-title">
        <div class="vs-avatar" aria-hidden="true"><i class="bi bi-graph-up"></i></div>
        <div>
            <h1 id="report-title">Отчёт: <?php echo gr_h($group_info['name'] ?? ''); ?></h1>
            <div class="vs-meta" role="list">
                <?php if (!empty($group_info['code'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-tag" aria-hidden="true"></i><?php echo gr_h($group_info['code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($group_info['course'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo gr_h($group_info['course']); ?></span>
                <?php endif; ?>
                <?php if (!empty($group_info['specialty'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-book" aria-hidden="true"></i><?php echo gr_h($group_info['specialty']); ?></span>
                <?php endif; ?>
                <?php if ($curator_name !== ''): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-person-heart" aria-hidden="true"></i><?php echo gr_h($curator_name); ?></span>
                <?php endif; ?>
                <span class="vs-pill <?php echo $is_active ? 'vs-pill-success' : 'vs-pill-warning'; ?>">
                    <?php echo $is_active ? 'Активна' : 'Неактивна'; ?>
                </span>
            </div>
        </div>
        <div class="vs-actions no-print" role="group" aria-label="Действия с отчётом">
            <button type="button" class="btn btn-success" id="btnExportReport">
                <i class="bi bi-download me-1" aria-hidden="true"></i>Экспорт
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Печать
            </button>
            <a class="btn btn-outline-primary" href="group_details.php?id=<?php echo $group_id; ?>">
                <i class="bi bi-eye me-1" aria-hidden="true"></i>Карточка
            </a>
            <a class="btn btn-outline-secondary" href="groups.php">
                <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Назад
            </a>
        </div>
    </section>

    <div class="manager-stats-grid">
        <div class="manager-stat-card stat-primary">
            <div class="stat-label"><span class="stat-dot"></span>Всего</div>
            <div class="stat-number"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
        </div>
        <div class="manager-stat-card stat-success">
            <div class="stat-label"><span class="stat-dot"></span>Активных</div>
            <div class="stat-number"><?php echo (int)$stats['active']; ?></div>
            <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
        </div>
        <div class="manager-stat-card stat-warning">
            <div class="stat-label"><span class="stat-dot"></span>Академ. отпуск</div>
            <div class="stat-number"><?php echo (int)$stats['academic_leave']; ?></div>
            <div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
        </div>
        <div class="manager-stat-card stat-info">
            <div class="stat-label"><span class="stat-dot"></span>Заполненность</div>
            <div class="stat-number"><?php echo gr_h((string)$occupancy_percentage); ?>%</div>
            <div class="stat-icon"><i class="bi bi-pie-chart-fill"></i></div>
        </div>
    </div>

    <div class="vs-toolbar no-print">
        <div class="vs-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" id="reportFilter" placeholder="Поиск по студентам в таблице…" aria-label="Поиск по студентам" autocomplete="off">
        </div>
        <span class="vs-search-count" id="reportCount" aria-live="polite"></span>
    </div>

    <div class="vs-tabs">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab" aria-controls="pane-overview" aria-selected="true">Обзор</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-social" data-bs-toggle="tab" data-bs-target="#pane-social" type="button" role="tab" aria-controls="pane-social" aria-selected="false">Соц. категории</button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-students" data-bs-toggle="tab" data-bs-target="#pane-students" type="button" role="tab" aria-controls="pane-students" aria-selected="false">
                    Студенты <span class="vs-tab-badge"><?php echo (int)$stats['total']; ?></span>
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <div class="tab-pane fade show active" id="pane-overview" role="tabpanel" aria-labelledby="tab-overview">
                <div class="vs-tab-pane">
                    <div class="vs-section-title">О группе</div>
                    <div class="vs-rows mb-3">
                        <div class="vs-row">
                            <div class="vs-row-label">Специальность</div>
                            <div class="vs-row-value"><?php echo gr_h($group_info['specialty'] ?? '—'); ?></div>
                        </div>
                        <div class="vs-row">
                            <div class="vs-row-label">Квалификация</div>
                            <div class="vs-row-value"><?php echo gr_h($group_info['qualification'] ?? '—'); ?></div>
                        </div>
                        <div class="vs-row">
                            <div class="vs-row-label">Язык / форма</div>
                            <div class="vs-row-value">
                                <?php echo gr_h(trim(($group_info['language'] ?? '') . ' · ' . ($group_info['study_form'] ?? ''), ' ·')); ?>
                            </div>
                        </div>
                        <div class="vs-row">
                            <div class="vs-row-label">Места</div>
                            <div class="vs-row-value"><?php echo (int)$stats['total']; ?> / <?php echo $max_students; ?> (<?php echo gr_h((string)$occupancy_percentage); ?>%)</div>
                        </div>
                        <div class="vs-row">
                            <div class="vs-row-label">Пол</div>
                            <div class="vs-row-value"><?php echo (int)$stats['male']; ?> М · <?php echo (int)$stats['female']; ?> Ж</div>
                        </div>
                    </div>

                    <div class="gr-charts">
                        <div class="gr-chart-card">
                            <h2 class="gr-chart-title"><i class="bi bi-pie-chart" aria-hidden="true"></i>По статусу</h2>
                            <div class="gr-chart-wrap"><canvas id="statusChart"></canvas></div>
                        </div>
                        <div class="gr-chart-card">
                            <h2 class="gr-chart-title"><i class="bi bi-bar-chart" aria-hidden="true"></i>По полу</h2>
                            <div class="gr-chart-wrap"><canvas id="genderChart"></canvas></div>
                        </div>
                        <?php if (!empty($age_stats)): ?>
                            <div class="gr-chart-card">
                                <h2 class="gr-chart-title"><i class="bi bi-calendar3" aria-hidden="true"></i>По возрасту</h2>
                                <div class="gr-chart-wrap"><canvas id="ageChart"></canvas></div>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($nationality_stats)): ?>
                            <div class="gr-chart-card">
                                <h2 class="gr-chart-title"><i class="bi bi-globe" aria-hidden="true"></i>Национальный состав</h2>
                                <div class="gr-chart-wrap"><canvas id="nationalityChart"></canvas></div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($residence_stats)): ?>
                        <div class="vs-section-title mt-4">Тип местности</div>
                        <?php echo gr_breakdown($residence_stats, max(1, $stats['total'])); ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tab-pane fade" id="pane-social" role="tabpanel" aria-labelledby="tab-social">
                <div class="vs-tab-pane">
                    <div class="gr-social-grid">
                        <div class="gr-social-card">
                            <i class="bi bi-wheelchair text-warning" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['disabled']; ?></div>
                            <div class="label">Инвалидность</div>
                        </div>
                        <div class="gr-social-card">
                            <i class="bi bi-heart text-info" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['orphan']; ?></div>
                            <div class="label">Сироты</div>
                        </div>
                        <div class="gr-social-card">
                            <i class="bi bi-exclamation-triangle text-danger" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['without_parental_care']; ?></div>
                            <div class="label">Без попечения</div>
                        </div>
                        <div class="gr-social-card">
                            <i class="bi bi-calendar-minus text-warning" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['minors']; ?></div>
                            <div class="label">Несовершеннолетние</div>
                        </div>
                        <div class="gr-social-card">
                            <i class="bi bi-award text-primary" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['graduated']; ?></div>
                            <div class="label">Выпускники</div>
                        </div>
                        <div class="gr-social-card">
                            <i class="bi bi-pause-circle text-warning" aria-hidden="true"></i>
                            <div class="num"><?php echo (int)$stats['academic_leave']; ?></div>
                            <div class="label">Академ. отпуск</div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="tab-pane fade" id="pane-students" role="tabpanel" aria-labelledby="tab-students">
                <div class="vs-tab-pane">
                    <?php if (empty($students)): ?>
                        <div class="manager-empty-state">
                            <div class="empty-icon"><i class="bi bi-people"></i></div>
                            <div class="empty-title">Нет студентов</div>
                            <div class="empty-description">В группе пока нет данных для отчёта</div>
                        </div>
                    <?php else: ?>
                        <div class="gr-table-wrap">
                            <div class="manager-table-container">
                                <div class="table-responsive">
                                    <table class="table manager-table mb-0" id="reportStudentsTable">
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
                                            <?php foreach ($students as $student):
                                                $full = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                                                $search = mb_strtolower($full . ' ' . ($student['iin'] ?? '') . ' ' . ($student['phone'] ?? '') . ' ' . ($student['email'] ?? '') . ' ' . ($student['nationality'] ?? ''), 'UTF-8');
                                                ?>
                                                <tr class="report-row" data-search="<?php echo gr_h($search); ?>">
                                                    <td>
                                                        <a class="gr-name-link" href="view_student.php?id=<?php echo (int)$student['id']; ?>">
                                                            <?php echo gr_h($full); ?>
                                                        </a>
                                                    </td>
                                                    <td><code><?php echo gr_h($student['iin'] ?? ''); ?></code></td>
                                                    <td>
                                                        <?php if (!empty($student['gender'])): ?>
                                                            <span class="manager-badge badge-primary"><?php echo gr_h($student['gender']); ?></span>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td><?php echo gr_date($student['birth_date'] ?? null); ?></td>
                                                    <td><?php echo gr_h($student['nationality'] ?: '—'); ?></td>
                                                    <td>
                                                        <span class="<?php echo getStudentStatusBadgeClass($student['status']); ?>">
                                                            <?php echo gr_h(getStudentStatusLabel($student['status'])); ?>
                                                        </span>
                                                    </td>
                                                    <td><?php echo gr_flags($student); ?></td>
                                                    <td>
                                                        <?php if (!empty($student['phone'])): ?>
                                                            <a href="tel:<?php echo gr_h($student['phone']); ?>" class="text-decoration-none"><?php echo gr_h($student['phone']); ?></a>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if (!empty($student['email'])): ?>
                                                            <a href="mailto:<?php echo gr_h($student['email']); ?>" class="text-decoration-none"><?php echo gr_h($student['email']); ?></a>
                                                        <?php else: ?>
                                                            <span class="text-muted">—</span>
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                        <p class="text-muted small mt-3 mb-0 gr-hidden" id="noReportMatch">Ничего не найдено</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$chart_payload = [
    'status' => [
        'labels' => ['Активные', 'Академ. отпуск', 'Выпускники'],
        'data' => [(int)$stats['active'], (int)$stats['academic_leave'], (int)$stats['graduated']],
        'colors' => ['#10b981', '#f59e0b', '#06b6d4'],
    ],
    'gender' => [
        'labels' => ['Мужчины', 'Женщины'],
        'data' => [(int)$stats['male'], (int)$stats['female']],
        'colors' => ['#2c5af2', '#ef4444'],
    ],
    'age' => [
        'labels' => array_keys($age_stats),
        'data' => array_values(array_map('intval', $age_stats)),
        'colors' => '#2c5af2',
    ],
    'nationality' => [
        'labels' => array_keys($nationality_stats),
        'data' => array_values(array_map('intval', $nationality_stats)),
        'colors' => ['#10b981', '#06b6d4', '#f59e0b', '#ef4444', '#2c5af2', '#8b5cf6', '#64748b'],
    ],
];

$export_meta = [
    'name' => $group_info['name'] ?? '',
    'code' => $group_info['code'] ?? '',
    'specialty' => $group_info['specialty'] ?? '',
    'course' => $group_info['course'] ?? '',
    'stats' => $stats,
    'occupancy' => $occupancy_percentage,
    'filename' => 'group_report_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($group_info['code'] ?? 'group')) . '_' . date('Y-m-d') . '.txt',
];

$chart_json = json_encode($chart_payload, JSON_UNESCAPED_UNICODE);
$export_json = json_encode($export_meta, JSON_UNESCAPED_UNICODE);

$extra_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const charts = {$chart_json};
    const exportMeta = {$export_json};

    const baseOpts = {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 12 } } }
        }
    };

    function makeChart(id, config) {
        const el = document.getElementById(id);
        if (!el || typeof Chart === 'undefined') return;
        new Chart(el, config);
    }

    makeChart('statusChart', {
        type: 'doughnut',
        data: {
            labels: charts.status.labels,
            datasets: [{ data: charts.status.data, backgroundColor: charts.status.colors, borderWidth: 0 }]
        },
        options: baseOpts
    });

    makeChart('genderChart', {
        type: 'bar',
        data: {
            labels: charts.gender.labels,
            datasets: [{
                label: 'Количество',
                data: charts.gender.data,
                backgroundColor: charts.gender.colors,
                borderRadius: 8,
                borderWidth: 0
            }]
        },
        options: {
            ...baseOpts,
            plugins: { legend: { display: false } },
            scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
        }
    });

    if (charts.age.labels.length) {
        makeChart('ageChart', {
            type: 'bar',
            data: {
                labels: charts.age.labels,
                datasets: [{
                    label: 'Количество',
                    data: charts.age.data,
                    backgroundColor: charts.age.colors,
                    borderRadius: 8,
                    borderWidth: 0
                }]
            },
            options: {
                ...baseOpts,
                plugins: { legend: { display: false } },
                scales: { y: { beginAtZero: true, ticks: { precision: 0 } }, x: { grid: { display: false } } }
            }
        });
    }

    if (charts.nationality.labels.length) {
        makeChart('nationalityChart', {
            type: 'doughnut',
            data: {
                labels: charts.nationality.labels,
                datasets: [{
                    data: charts.nationality.data,
                    backgroundColor: charts.nationality.colors,
                    borderWidth: 0
                }]
            },
            options: baseOpts
        });
    }

    const filterInput = document.getElementById('reportFilter');
    const countEl = document.getElementById('reportCount');
    const emptyEl = document.getElementById('noReportMatch');
    const rows = document.querySelectorAll('#reportStudentsTable .report-row');

    function applyFilter() {
        const term = (filterInput && filterInput.value || '').trim().toLowerCase();
        let visible = 0;
        rows.forEach((row) => {
            const hay = (row.getAttribute('data-search') || row.innerText || '').toLowerCase();
            const show = !term || hay.includes(term);
            row.classList.toggle('gr-hidden', !show);
            if (show) visible += 1;
        });
        if (countEl) {
            if (term) {
                countEl.textContent = 'Найдено: ' + visible;
                countEl.classList.add('is-active');
                const studentsTab = document.getElementById('tab-students');
                if (studentsTab && window.bootstrap) {
                    bootstrap.Tab.getOrCreateInstance(studentsTab).show();
                }
            } else {
                countEl.textContent = '';
                countEl.classList.remove('is-active');
            }
        }
        if (emptyEl) {
            emptyEl.classList.toggle('gr-hidden', visible > 0 || rows.length === 0);
        }
    }

    if (filterInput) {
        filterInput.addEventListener('input', applyFilter);
    }

    const btnExport = document.getElementById('btnExportReport');
    if (btnExport) {
        btnExport.addEventListener('click', () => {
            const s = exportMeta.stats;
            let report = 'ОТЧЁТ ПО ГРУППЕ "' + exportMeta.name + '"\\n';
            report += 'Код: ' + exportMeta.code + '\\n';
            report += 'Специальность: ' + exportMeta.specialty + '\\n';
            report += 'Курс: ' + exportMeta.course + '\\n\\n';
            report += 'СТАТИСТИКА:\\n';
            report += 'Всего студентов: ' + s.total + '\\n';
            report += 'Активных: ' + s.active + '\\n';
            report += 'В академ. отпуске: ' + s.academic_leave + '\\n';
            report += 'Выпускников: ' + s.graduated + '\\n';
            report += 'Мужчин: ' + s.male + '\\n';
            report += 'Женщин: ' + s.female + '\\n';
            report += 'Заполненность: ' + exportMeta.occupancy + '%\\n\\n';
            report += 'СПЕЦИАЛЬНЫЕ КАТЕГОРИИ:\\n';
            report += 'С инвалидностью: ' + s.disabled + '\\n';
            report += 'Дети-сироты: ' + s.orphan + '\\n';
            report += 'Без попечения родителей: ' + s.without_parental_care + '\\n';
            report += 'Несовершеннолетних: ' + s.minors + '\\n';

            const blob = new Blob([report], { type: 'text/plain;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = exportMeta.filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }
});
</script>
HTML;

include 'includes/layout_end.php';
