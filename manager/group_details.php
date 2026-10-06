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

function gd_age(?string $birth_date): ?int
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

$stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'graduating' => 0,
    'male' => 0,
    'female' => 0,
    'disabled' => 0,
    'orphan' => 0,
    'without_parental_care' => 0,
    'minors' => 0,
];

$minors = [];

foreach ($students as &$student) {
    $status = $student['status'];
    if ($status === 'active') {
        $stats['active']++;
    } elseif ($status === 'graduating') {
        $stats['graduating']++;
        $stats['active']++;
    } elseif ($status === 'academic_leave') {
        $stats['academic_leave']++;
    } elseif ($status === 'graduated') {
        $stats['graduated']++;
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

    $age = gd_age($student['birth_date'] ?? null);
    $student['age'] = $age;
    if ($age !== null && $age < 18) {
        $stats['minors']++;
        $minors[] = $student;
    }
}
unset($student);

$max_students = (int)($group_info['max_students'] ?? 0);
$occupancy_percentage = $max_students > 0 ? round(($stats['total'] / $max_students) * 100, 1) : 0;
$free_seats = max(0, $max_students - $stats['total']);
$occupancy_class = $occupancy_percentage > 80 ? 'success' : ($occupancy_percentage > 60 ? 'warning' : 'danger');

$curator_info = null;
if (!empty($group_info['curator_id'])) {
    $curator_info = $user->getUserById($group_info['curator_id']);
}

$is_active = !empty($group_info['is_active']);
$is_graduating_group = function_exists('isGraduatingGroup') && isGraduatingGroup($group_info);

function gd_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function gd_date($value): string
{
    if (empty($value)) {
        return '—';
    }
    $ts = strtotime($value);
    return $ts ? date('d.m.Y', $ts) : '—';
}

function gd_row(string $label, $value, bool $force = false): string
{
    $filled = $value !== null && $value !== '' && $value !== false;
    if (!$filled && !$force) {
        return '';
    }
    $display = $filled ? (string)$value : '<span class="vs-priority-empty">Не указано</span>';
    return '<div class="vs-row">'
        . '<div class="vs-row-label">' . gd_h($label) . '</div>'
        . '<div class="vs-row-value">' . $display . '</div>'
        . '</div>';
}

function gd_student_flags(array $student): string
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
    return $parts ? '<span class="gd-flags">' . implode('', $parts) . '</span>' : '<span class="text-muted">—</span>';
}

function gd_student_contacts(array $student): string
{
    $parts = [];
    if (!empty($student['phone'])) {
        $parts[] = '<a href="tel:' . gd_h($student['phone']) . '" class="text-decoration-none" title="' . gd_h($student['phone']) . '"><i class="bi bi-telephone"></i></a>';
    }
    if (!empty($student['email'])) {
        $parts[] = '<a href="mailto:' . gd_h($student['email']) . '" class="text-decoration-none" title="' . gd_h($student['email']) . '"><i class="bi bi-envelope"></i></a>';
    }
    return $parts ? '<span class="gd-flags">' . implode('', $parts) . '</span>' : '<span class="text-muted">—</span>';
}

$page_title = $group_info['name'] ?? 'Группа';
$page_subtitle = 'Карточка группы';
$document_title = 'Группа: ' . ($group_info['name'] ?? '');
$extra_head = '<link href="assets/css/view-student.css" rel="stylesheet">'
    . '<link href="assets/css/group-details.css" rel="stylesheet">';

include 'includes/layout_start.php';
?>

<div class="vs-page">

    <nav aria-label="breadcrumb" class="vs-breadcrumb no-print">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Главная</a></li>
            <li class="breadcrumb-item"><a href="groups.php">Группы</a></li>
            <li class="breadcrumb-item active" aria-current="page"><?php echo gd_h($group_info['name'] ?? ''); ?></li>
        </ol>
    </nav>

    <section class="vs-hero" aria-labelledby="group-name">
        <div class="vs-avatar" aria-hidden="true"><i class="bi bi-people-fill"></i></div>
        <div>
            <h1 id="group-name"><?php echo gd_h($group_info['name'] ?? ''); ?></h1>
            <div class="vs-meta" role="list">
                <?php if (!empty($group_info['code'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-tag" aria-hidden="true"></i><?php echo gd_h($group_info['code']); ?></span>
                <?php endif; ?>
                <?php if (!empty($group_info['course'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo gd_h($group_info['course']); ?></span>
                <?php endif; ?>
                <?php if (!empty($group_info['language'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-translate" aria-hidden="true"></i><?php echo gd_h($group_info['language']); ?></span>
                <?php endif; ?>
                <?php if (!empty($group_info['study_form'])): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-journal-text" aria-hidden="true"></i><?php echo gd_h($group_info['study_form']); ?></span>
                <?php endif; ?>
                <span class="vs-pill <?php echo $is_active ? 'vs-pill-success' : 'vs-pill-warning'; ?>" role="status">
                    <?php echo $is_active ? 'Активна' : 'Неактивна'; ?>
                </span>
                <?php if ($is_graduating_group): ?>
                    <span class="vs-pill vs-pill-warning">Выпускная</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="vs-actions no-print" role="group" aria-label="Действия с группой">
            <a class="btn btn-success" href="group_reports.php?id=<?php echo $group_id; ?>">
                <i class="bi bi-graph-up me-1" aria-hidden="true"></i>Отчёты
            </a>
            <a class="btn btn-outline-primary" href="students.php?group=<?php echo $group_id; ?>">
                <i class="bi bi-people me-1" aria-hidden="true"></i>Все студенты
            </a>
            <button type="button" class="btn btn-outline-secondary" id="btnExportCsv">
                <i class="bi bi-download me-1" aria-hidden="true"></i>CSV
            </button>
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Печать
            </button>
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
            <div class="stat-number"><?php echo gd_h((string)$occupancy_percentage); ?>%</div>
            <div class="stat-icon"><i class="bi bi-pie-chart-fill"></i></div>
        </div>
    </div>

    <div class="gd-layout">
        <div class="gd-main">
            <div class="vs-toolbar no-print">
                <div class="vs-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="studentFilter" placeholder="Поиск по ФИО, ИИН, телефону…" aria-label="Поиск по студентам" autocomplete="off">
                </div>
                <span class="vs-search-count" id="studentCount" aria-live="polite"></span>
            </div>

            <div class="gd-filter-chips no-print mb-3" id="statusFilters" role="group" aria-label="Фильтр по статусу">
                <button type="button" class="gd-chip-btn is-active" data-status="">Все <span class="count"><?php echo (int)$stats['total']; ?></span></button>
                <button type="button" class="gd-chip-btn" data-status="active">Активные <span class="count"><?php echo (int)$stats['active']; ?></span></button>
                <button type="button" class="gd-chip-btn" data-status="academic_leave">Академ. <span class="count"><?php echo (int)$stats['academic_leave']; ?></span></button>
                <button type="button" class="gd-chip-btn" data-status="graduated">Выпускники <span class="count"><?php echo (int)$stats['graduated']; ?></span></button>
                <?php if ($stats['minors'] > 0): ?>
                    <button type="button" class="gd-chip-btn" data-status="minor">Несовершеннолетние <span class="count"><?php echo (int)$stats['minors']; ?></span></button>
                <?php endif; ?>
            </div>

            <div class="vs-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-students" data-bs-toggle="tab" data-bs-target="#pane-students" type="button" role="tab" aria-controls="pane-students" aria-selected="true">
                            Студенты <span class="vs-tab-badge"><?php echo (int)$stats['total']; ?></span>
                        </button>
                    </li>
                    <?php if (!empty($minors)): ?>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-minors" data-bs-toggle="tab" data-bs-target="#pane-minors" type="button" role="tab" aria-controls="pane-minors" aria-selected="false">
                                Несовершеннолетние <span class="vs-tab-badge"><?php echo count($minors); ?></span>
                            </button>
                        </li>
                    <?php endif; ?>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-info" data-bs-toggle="tab" data-bs-target="#pane-info" type="button" role="tab" aria-controls="pane-info" aria-selected="false">
                            О группе
                        </button>
                    </li>
                </ul>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="pane-students" role="tabpanel" aria-labelledby="tab-students">
                        <div class="vs-tab-pane">
                            <?php if (empty($students)): ?>
                                <div class="manager-empty-state">
                                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                                    <div class="empty-title">В группе нет студентов</div>
                                    <div class="empty-description">Студенты появятся здесь после назначения в группу</div>
                                </div>
                            <?php else: ?>
                                <div class="gd-table-wrap">
                                    <div class="manager-table-container">
                                        <div class="table-responsive">
                                            <table class="table manager-table mb-0" id="studentsTable">
                                                <thead>
                                                    <tr>
                                                        <th>ФИО</th>
                                                        <th>ИИН</th>
                                                        <th>Пол</th>
                                                        <th>Возраст</th>
                                                        <th>Статус</th>
                                                        <th>Особенности</th>
                                                        <th>Контакты</th>
                                                        <th class="no-print">Открыть</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($students as $student):
                                                        $full = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                                                        $is_minor = isset($student['age']) && $student['age'] !== null && $student['age'] < 18;
                                                        $search = mb_strtolower($full . ' ' . ($student['iin'] ?? '') . ' ' . ($student['phone'] ?? '') . ' ' . ($student['email'] ?? ''), 'UTF-8');
                                                        ?>
                                                        <tr class="student-row"
                                                            data-status="<?php echo gd_h($student['status']); ?>"
                                                            data-minor="<?php echo $is_minor ? '1' : '0'; ?>"
                                                            data-search="<?php echo gd_h($search); ?>">
                                                            <td>
                                                                <a class="gd-name-link" href="view_student.php?id=<?php echo (int)$student['id']; ?>">
                                                                    <?php echo gd_h($full); ?>
                                                                </a>
                                                            </td>
                                                            <td><code><?php echo gd_h($student['iin'] ?? ''); ?></code></td>
                                                            <td>
                                                                <?php if (!empty($student['gender'])): ?>
                                                                    <span class="manager-badge badge-primary"><?php echo gd_h($student['gender']); ?></span>
                                                                <?php else: ?>
                                                                    <span class="text-muted">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <?php if ($student['age'] !== null): ?>
                                                                    <?php if ($is_minor): ?>
                                                                        <span class="manager-badge badge-warning"><?php echo (int)$student['age']; ?></span>
                                                                    <?php else: ?>
                                                                        <?php echo (int)$student['age']; ?>
                                                                    <?php endif; ?>
                                                                <?php else: ?>
                                                                    <span class="text-muted">—</span>
                                                                <?php endif; ?>
                                                            </td>
                                                            <td>
                                                                <span class="<?php echo getStudentStatusBadgeClass($student['status']); ?>">
                                                                    <?php echo gd_h(getStudentStatusLabel($student['status'])); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo gd_student_flags($student); ?></td>
                                                            <td><?php echo gd_student_contacts($student); ?></td>
                                                            <td class="no-print">
                                                                <div class="manager-table-actions">
                                                                    <a href="view_student.php?id=<?php echo (int)$student['id']; ?>" class="btn btn-outline-primary" title="Профиль">
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
                                <p class="text-muted small mt-3 mb-0 gd-hidden" id="noStudentsMatch">Ничего не найдено по текущему фильтру</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <?php if (!empty($minors)): ?>
                        <div class="tab-pane fade" id="pane-minors" role="tabpanel" aria-labelledby="tab-minors">
                            <div class="vs-tab-pane">
                                <div class="gd-table-wrap">
                                    <div class="manager-table-container">
                                        <div class="table-responsive">
                                            <table class="table manager-table mb-0">
                                                <thead>
                                                    <tr>
                                                        <th>ФИО</th>
                                                        <th>ИИН</th>
                                                        <th>Возраст</th>
                                                        <th>Дата рождения</th>
                                                        <th>Статус</th>
                                                        <th>Особенности</th>
                                                        <th>Контакты</th>
                                                        <th class="no-print">Открыть</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($minors as $minor):
                                                        $full = trim(($minor['last_name'] ?? '') . ' ' . ($minor['first_name'] ?? '') . ' ' . ($minor['middle_name'] ?? ''));
                                                        ?>
                                                        <tr>
                                                            <td>
                                                                <a class="gd-name-link" href="view_student.php?id=<?php echo (int)$minor['id']; ?>">
                                                                    <?php echo gd_h($full); ?>
                                                                </a>
                                                            </td>
                                                            <td><code><?php echo gd_h($minor['iin'] ?? ''); ?></code></td>
                                                            <td><span class="manager-badge badge-warning"><?php echo (int)$minor['age']; ?> лет</span></td>
                                                            <td><?php echo gd_date($minor['birth_date'] ?? null); ?></td>
                                                            <td>
                                                                <span class="<?php echo getStudentStatusBadgeClass($minor['status']); ?>">
                                                                    <?php echo gd_h(getStudentStatusLabel($minor['status'])); ?>
                                                                </span>
                                                            </td>
                                                            <td><?php echo gd_student_flags($minor); ?></td>
                                                            <td><?php echo gd_student_contacts($minor); ?></td>
                                                            <td class="no-print">
                                                                <div class="manager-table-actions">
                                                                    <a href="view_student.php?id=<?php echo (int)$minor['id']; ?>" class="btn btn-outline-primary" title="Профиль">
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
                            </div>
                        </div>
                    <?php endif; ?>

                    <div class="tab-pane fade" id="pane-info" role="tabpanel" aria-labelledby="tab-info">
                        <div class="vs-tab-pane">
                            <div class="vs-section-title">Основные данные</div>
                            <div class="gd-info-grid">
                                <div class="vs-rows">
                                    <?php
                                    echo gd_row('Название', gd_h($group_info['name'] ?? ''), true);
                                    echo gd_row('Код', !empty($group_info['code']) ? '<code>' . gd_h($group_info['code']) . '</code>' : '', true);
                                    echo gd_row('Специальность', gd_h($group_info['specialty'] ?? ''));
                                    echo gd_row('Квалификация', gd_h($group_info['qualification'] ?? ''));
                                    echo gd_row('Курс', !empty($group_info['course']) ? '<span class="manager-badge badge-info">' . gd_h($group_info['course']) . '</span>' : '');
                                    echo gd_row('Язык обучения', gd_h($group_info['language'] ?? ''));
                                    ?>
                                </div>
                                <div class="vs-rows">
                                    <?php
                                    echo gd_row('Форма обучения', gd_h($group_info['study_form'] ?? ''));
                                    echo gd_row('Срок обучения', gd_h($group_info['study_duration'] ?? ''));
                                    echo gd_row('Дата начала', gd_date($group_info['start_date'] ?? null), true);
                                    echo gd_row('Дата окончания', gd_date($group_info['end_date'] ?? null), true);
                                    echo gd_row('Дата прибытия', gd_date($group_info['arrival_date'] ?? null));
                                    echo gd_row('Приказ о зачислении', gd_h($group_info['enrollment_order_number'] ?? ''));
                                    ?>
                                </div>
                            </div>

                            <?php if (!empty($group_info['description'])): ?>
                                <div class="vs-section-title">Описание</div>
                                <p class="text-muted mb-0"><?php echo nl2br(gd_h($group_info['description'])); ?></p>
                            <?php endif; ?>

                            <div class="vs-section-title">Сводка по составу</div>
                            <div class="vs-rows">
                                <?php
                                echo gd_row('Мужчин / Женщин', (int)$stats['male'] . ' / ' . (int)$stats['female'], true);
                                echo gd_row('Инвалидность', (string)(int)$stats['disabled'], true);
                                echo gd_row('Сироты', (string)(int)$stats['orphan'], true);
                                echo gd_row('Без попечения', (string)(int)$stats['without_parental_care'], true);
                                echo gd_row('Несовершеннолетние', (string)(int)$stats['minors'], true);
                                echo gd_row('Выпускники', (string)(int)$stats['graduated'], true);
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <aside class="gd-side no-print" aria-label="Сводка по группе">
            <div class="gd-panel">
                <h2 class="gd-panel-title">Куратор</h2>
                <?php if ($curator_info): ?>
                    <div class="gd-curator">
                        <div class="gd-curator-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></div>
                        <div>
                            <p class="gd-curator-name">
                                <?php echo gd_h(trim(($curator_info['last_name'] ?? '') . ' ' . ($curator_info['first_name'] ?? '') . ' ' . ($curator_info['middle_name'] ?? ''))); ?>
                            </p>
                            <?php if (!empty($curator_info['email'])): ?>
                                <p class="gd-curator-meta">
                                    <a href="mailto:<?php echo gd_h($curator_info['email']); ?>"><?php echo gd_h($curator_info['email']); ?></a>
                                </p>
                            <?php endif; ?>
                            <?php if (!empty($curator_info['phone'])): ?>
                                <p class="gd-curator-meta">
                                    <a href="tel:<?php echo gd_h($curator_info['phone']); ?>"><?php echo gd_h($curator_info['phone']); ?></a>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="gd-curator-empty">
                        <i class="bi bi-person-x" aria-hidden="true"></i>
                        Куратор не назначен
                    </div>
                <?php endif; ?>
            </div>

            <div class="gd-panel">
                <h2 class="gd-panel-title">Заполненность</h2>
                <div class="gd-kv">
                    <div class="gd-kv-row">
                        <span>Статус</span>
                        <strong>
                            <span class="manager-badge badge-<?php echo $is_active ? 'success' : 'danger'; ?>">
                                <?php echo $is_active ? 'Активна' : 'Неактивна'; ?>
                            </span>
                        </strong>
                    </div>
                    <div class="gd-kv-row">
                        <span>Студентов</span>
                        <strong><?php echo (int)$stats['total']; ?> / <?php echo $max_students; ?></strong>
                    </div>
                    <div class="gd-kv-row">
                        <span>Свободно</span>
                        <strong><?php echo $free_seats; ?></strong>
                    </div>
                    <div>
                        <div class="d-flex justify-content-between small mb-1">
                            <span class="text-muted">Заполненность</span>
                            <strong><?php echo gd_h((string)$occupancy_percentage); ?>%</strong>
                        </div>
                        <div class="manager-progress">
                            <div class="manager-progress-bar bg-<?php echo $occupancy_class; ?>"
                                 style="width: <?php echo min(100, max(0, $occupancy_percentage)); ?>%"></div>
                        </div>
                    </div>
                    <?php if (!empty($group_info['specialty'])): ?>
                        <div class="gd-kv-row">
                            <span>Специальность</span>
                            <strong style="font-size: 0.8rem;"><?php echo gd_h($group_info['specialty']); ?></strong>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </aside>
    </div>
</div>

<?php
$export_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', (string)($group_info['code'] ?? 'group'));
$export_group = json_encode([
    'name' => $group_info['name'] ?? '',
    'code' => $group_info['code'] ?? '',
    'total' => $stats['total'],
    'filename' => 'group_' . $export_name . '_' . date('Y-m-d') . '.csv',
], JSON_UNESCAPED_UNICODE);

$extra_scripts = <<<HTML
<script>
document.addEventListener('DOMContentLoaded', function () {
    const filterInput = document.getElementById('studentFilter');
    const countEl = document.getElementById('studentCount');
    const emptyEl = document.getElementById('noStudentsMatch');
    const chips = document.querySelectorAll('#statusFilters .gd-chip-btn');
    const rows = document.querySelectorAll('#studentsTable .student-row');
    let statusFilter = '';

    function applyFilters() {
        const term = (filterInput && filterInput.value || '').trim().toLowerCase();
        let visible = 0;

        rows.forEach((row) => {
            const status = row.getAttribute('data-status') || '';
            const minor = row.getAttribute('data-minor') === '1';
            const hay = (row.getAttribute('data-search') || row.innerText || '').toLowerCase();

            let statusOk = true;
            if (statusFilter === 'minor') {
                statusOk = minor;
            } else if (statusFilter === 'active') {
                statusOk = status === 'active' || status === 'graduating';
            } else if (statusFilter) {
                statusOk = status === statusFilter;
            }

            const textOk = !term || hay.includes(term);
            const show = statusOk && textOk;
            row.classList.toggle('gd-hidden', !show);
            if (show) visible += 1;
        });

        if (countEl) {
            if (term || statusFilter) {
                countEl.textContent = 'Показано: ' + visible;
                countEl.classList.add('is-active');
            } else {
                countEl.textContent = '';
                countEl.classList.remove('is-active');
            }
        }
        if (emptyEl) {
            emptyEl.classList.toggle('gd-hidden', visible > 0 || rows.length === 0);
        }
    }

    if (filterInput) {
        filterInput.addEventListener('input', applyFilters);
    }

    chips.forEach((chip) => {
        chip.addEventListener('click', () => {
            chips.forEach((c) => c.classList.remove('is-active'));
            chip.classList.add('is-active');
            statusFilter = chip.getAttribute('data-status') || '';
            applyFilters();

            if (statusFilter === 'minor') {
                const minorsTab = document.getElementById('tab-minors');
                if (minorsTab && window.bootstrap) {
                    bootstrap.Tab.getOrCreateInstance(minorsTab).show();
                }
            } else {
                const studentsTab = document.getElementById('tab-students');
                if (studentsTab && window.bootstrap) {
                    bootstrap.Tab.getOrCreateInstance(studentsTab).show();
                }
            }
        });
    });

    const btnExport = document.getElementById('btnExportCsv');
    if (btnExport) {
        const meta = {$export_group};
        btnExport.addEventListener('click', () => {
            const table = document.getElementById('studentsTable');
            if (!table) {
                alert('Нет данных для экспорта');
                return;
            }

            let csv = '\\uFEFF';
            csv += 'Группа: ' + meta.name + '\\n';
            csv += 'Код: ' + meta.code + '\\n';
            csv += 'Всего студентов: ' + meta.total + '\\n\\n';
            csv += 'ФИО,ИИН,Пол,Возраст,Статус\\n';

            table.querySelectorAll('tbody tr.student-row').forEach((row) => {
                if (row.classList.contains('gd-hidden')) return;
                const cells = row.querySelectorAll('td');
                if (cells.length < 5) return;
                const cols = [];
                for (let i = 0; i < 5; i++) {
                    let text = (cells[i].textContent || '').replace(/\\s+/g, ' ').trim().replace(/"/g, '""');
                    cols.push('"' + text + '"');
                }
                csv += cols.join(',') + '\\n';
            });

            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            link.href = URL.createObjectURL(blob);
            link.download = meta.filename;
            link.style.display = 'none';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        });
    }

    document.addEventListener('keydown', function (e) {
        if ((e.ctrlKey || e.metaKey) && e.key === 'f' && filterInput) {
            e.preventDefault();
            filterInput.focus();
        }
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            window.location.href = 'groups.php';
        }
    });
});
</script>
HTML;

include 'includes/layout_end.php';
