<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_uchebni');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$is_methodist = ($role === 'methodist');
$is_teacher_session = ($role === 'teacher');

$linked_teacher = $uchebni->getTeacherByUserId((int)$current_user['id']);
$linked_teacher_id = $linked_teacher ? (int)$linked_teacher['id'] : 0;

// --- AJAX: сохранить / синхронизировать часы ---
$action = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($action === 'save_hour' || $action === 'sync_hours') {
    header('Content-Type: application/json; charset=utf-8');

    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
    if ($is_teacher_session) {
        if ($linked_teacher_id <= 0) {
            echo json_encode(['ok' => false, 'error' => 'Аккаунт не привязан к преподавателю'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $teacher_id = $linked_teacher_id;
    } elseif (!$is_methodist || $teacher_id <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Недостаточно прав'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $req_period = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : $period_id;
    if ($req_period <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Нет активного периода'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'sync_hours') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            echo json_encode(['ok' => false, 'error' => 'POST only'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $n = $uchebni->syncTeacherHoursFromSchedule($teacher_id, $req_period);
        echo json_encode(['ok' => true, 'synced' => $n], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $result = $uchebni->upsertHourEntry([
        'period_id' => $req_period,
        'teacher_id' => $teacher_id,
        'subject_id' => (int)($_POST['subject_id'] ?? 0),
        'group_id' => (int)($_POST['group_id'] ?? 0),
        'entry_date' => $_POST['entry_date'] ?? '',
        'hours' => (int)($_POST['hours'] ?? 0),
        'week_part' => $_POST['week_part'] ?? 'both',
        'note' => Uchebni::HOUR_NOTE_MANUAL,
    ]);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);
    exit;
}

// POST sync через форму (редирект)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'sync_hours') {
    $teacher_id = !empty($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
    if ($is_teacher_session) {
        $teacher_id = $linked_teacher_id;
    }
    $req_period = !empty($_POST['period_id']) ? (int)$_POST['period_id'] : $period_id;
    $n = 0;
    if ($teacher_id > 0 && $req_period > 0 && ($is_methodist || $is_teacher_session)) {
        $n = $uchebni->syncTeacherHoursFromSchedule($teacher_id, $req_period);
    }
    $q = http_build_query([
        'view' => 'mine',
        'teacher_id' => $teacher_id > 0 ? $teacher_id : '',
        'synced' => $n,
    ]);
    header('Location: hours.php?' . $q);
    exit;
}

$view = $_GET['view'] ?? '';
if (!in_array($view, ['group', 'summary', 'mine'], true)) {
    $view = $is_teacher_session ? 'mine' : 'group';
}

$groups = $uchebni->getActiveGroups();
$group_id = !empty($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
if ($group_id <= 0 && !empty($groups)) {
    $group_id = (int)$groups[0]['id'];
}

$year_month = $_GET['year_month'] ?? date('Y-m');
if (!preg_match('/^\d{4}-\d{2}$/', $year_month)) {
    $year_month = date('Y-m');
}
if ($period && !empty($period['start_date'])) {
    $pm = $uchebni->getPeriodMonths($period);
    if (!empty($pm) && !in_array($year_month, $pm, true)) {
        $year_month = $pm[0];
    }
}

$half = $_GET['half'] ?? 'all';
if (!in_array($half, ['all', '1', '2'], true)) {
    $half = 'all';
}

$default_from = date('Y-m-01');
$default_to = date('Y-m-t');
if ($period && !empty($period['start_date']) && !empty($period['end_date'])) {
    $month_start = strtotime($default_from);
    $period_start = strtotime($period['start_date']);
    $period_end = strtotime($period['end_date']);
    if ($month_start < $period_start) {
        $default_from = $period['start_date'];
    }
    if (strtotime($default_to) > $period_end) {
        $default_to = $period['end_date'];
    }
}

$date_from = $_GET['date_from'] ?? $default_from;
$date_to = $_GET['date_to'] ?? $default_to;
$teacher_id = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
$export = $_GET['export'] ?? '';
$synced = isset($_GET['synced']) ? (int)$_GET['synced'] : 0;

if ($is_teacher_session) {
    $teacher_id = $linked_teacher_id > 0 ? $linked_teacher_id : -1;
    if ($view !== 'summary') {
        $view = 'mine';
    }
}

$teachers = $uchebni->getTeachers(false);
$report = $uchebni->getTeacherHourReport($date_from, $date_to, $teacher_id > 0 ? $teacher_id : null, $period_id ?: null);
$details = ($teacher_id > 0)
    ? $uchebni->getTeacherHourDetails($teacher_id, $date_from, $date_to, $period_id ?: null)
    : [];

$mine_grid = null;
if ($view === 'mine') {
    if ($teacher_id <= 0 && $is_methodist && !empty($teachers)) {
        $teacher_id = (int)$teachers[0]['id'];
    }
    $mine_grid = $uchebni->getTeacherEditableHourGrid($teacher_id, $period_id ?: null);
}

$grid = ($view === 'group' && $group_id > 0)
    ? $uchebni->getGroupDailyHourGrid($period_id, $group_id, $year_month)
    : ['days' => [], 'rows' => [], 'totals' => ['hours' => 0]];

if ($half !== 'all' && !empty($grid['days'])) {
    $filteredDays = [];
    foreach ($grid['days'] as $d) {
        $dayNum = (int)$d['day'];
        if ($half === '1' && $dayNum > 15) {
            continue;
        }
        if ($half === '2' && $dayNum <= 15) {
            continue;
        }
        $filteredDays[] = $d;
    }
    $grid['days'] = $filteredDays;
    foreach ($grid['rows'] as &$r) {
        $sum = 0;
        foreach ($filteredDays as $d) {
            $sum += (int)($r['hours_by_date'][$d['date']] ?? 0);
        }
        $r['total'] = $sum;
    }
    unset($r);
    $grand = 0;
    foreach ($grid['rows'] as $r) {
        $grand += (int)$r['total'];
    }
    $grid['totals']['hours'] = $grand;
}

$role_labels = [
    'own' => 'Свои',
    'substitute_in' => 'Замена (взял)',
    'substitute_out' => 'Замена (отдал)',
    'cancelled' => 'Отменено',
];

$qs = http_build_query([
    'view' => 'summary',
    'date_from' => $date_from,
    'date_to' => $date_to,
    'teacher_id' => $teacher_id > 0 ? $teacher_id : '',
]);

if ($export === 'csv' || $export === 'csv_details') {
    $filename = $export === 'csv_details'
        ? 'uchebni_hours_details_' . $date_from . '_' . $date_to . '.csv'
        : 'uchebni_hours_' . $date_from . '_' . $date_to . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');
    if ($export === 'csv_details' && $teacher_id > 0) {
        fputcsv($out, ['Дата', 'День', 'Пара', 'Смена', 'Группа', 'Дисциплина', 'Тип часа', 'Вёл', 'Основной', 'В факт'], ';');
        foreach ($details as $row) {
            $pairLabel = ((int)$row['pair_number'] === 0) ? 'Кур. час' : ((int)$row['pair_number'] . ' пара');
            fputcsv($out, [
                $row['lesson_date'],
                Uchebni::DAY_NAMES[$row['day_of_week']] ?? '',
                $pairLabel,
                Uchebni::SHIFT_NAMES[$row['shift']] ?? '',
                $row['group_name'],
                $row['subject_name'],
                $role_labels[$row['role']] ?? $row['role'],
                $row['taught_by_teacher_name'],
                $row['original_teacher_name'],
                (int)$row['counts_as_fact'],
            ], ';');
        }
    } else {
        fputcsv($out, [
            'ФИО', 'Активен', 'План ч/нед', 'Свои', 'Замены взятые', 'Замены отданные', 'Отменено', 'Итого факт',
            'Период с', 'Период по',
        ], ';');
        foreach ($report as $row) {
            fputcsv($out, [
                $row['teacher_name'],
                !empty($row['is_active']) ? 'да' : 'нет',
                (int)$row['planned_weekly'],
                (int)$row['own_hours'],
                (int)$row['substitute_in'],
                (int)$row['substitute_out'],
                (int)$row['cancelled'],
                (int)$row['total_fact'],
                $date_from,
                $date_to,
            ], ';');
        }
    }
    fclose($out);
    exit;
}

$selected_teacher_name = '';
if ($teacher_id > 0) {
    foreach ($teachers as $t) {
        if ((int)$t['id'] === $teacher_id) {
            $selected_teacher_name = Uchebni::formatFio($t);
            break;
        }
    }
    if ($selected_teacher_name === '' && $linked_teacher && $teacher_id === $linked_teacher_id) {
        $selected_teacher_name = Uchebni::formatFio($linked_teacher);
    }
}

$selected_group_name = '';
foreach ($groups as $g) {
    if ((int)$g['id'] === $group_id) {
        $selected_group_name = $g['name'] ?: ($g['code'] ?? '');
        break;
    }
}

$totals = [
    'own_hours' => 0,
    'substitute_in' => 0,
    'substitute_out' => 0,
    'cancelled' => 0,
    'total_fact' => 0,
];
foreach ($report as $row) {
    $totals['own_hours'] += (int)$row['own_hours'];
    $totals['substitute_in'] += (int)$row['substitute_in'];
    $totals['substitute_out'] += (int)$row['substitute_out'];
    $totals['cancelled'] += (int)$row['cancelled'];
    $totals['total_fact'] += (int)$row['total_fact'];
}

$month_label = Uchebni::MONTH_NAMES_RU[(int)substr($year_month, 5, 2)] ?? $year_month;
$half_label = $half === '1' ? '1-я пол.' : ($half === '2' ? '2-я пол.' : 'весь месяц');

$page_title = 'Учёт часов';
if ($view === 'mine') {
    $page_subtitle = ($selected_teacher_name ?: 'Преподаватель')
        . ($mine_grid && !empty($mine_grid['fact_end']) ? ' · до ' . date('d.m.Y', strtotime($mine_grid['fact_end'])) : '');
} elseif ($view === 'group') {
    $page_subtitle = $selected_group_name . ' · ' . $month_label . ' · ' . $half_label;
} else {
    $page_subtitle = $date_from . ' — ' . $date_to;
}
require_once 'includes/header.php';
?>

<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'mine' ? 'active' : ''; ?>"
           href="hours.php?view=mine<?php echo $is_methodist && $teacher_id > 0 ? '&amp;teacher_id=' . (int)$teacher_id : ''; ?>">
            Моя сетка
        </a>
    </li>
    <?php if ($is_methodist): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $view === 'group' ? 'active' : ''; ?>"
               href="hours.php?view=group&amp;group_id=<?php echo (int)$group_id; ?>&amp;year_month=<?php echo urlencode($year_month); ?>&amp;half=<?php echo urlencode($half); ?>">
                По группе (дни)
            </a>
        </li>
    <?php endif; ?>
    <li class="nav-item">
        <a class="nav-link <?php echo $view === 'summary' ? 'active' : ''; ?>"
           href="hours.php?view=summary&amp;date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>&amp;teacher_id=<?php echo $teacher_id > 0 ? (int)$teacher_id : ''; ?>">
            Сводка
        </a>
    </li>
</ul>

<?php if ($view === 'mine'): ?>

    <?php if ($is_methodist): ?>
        <form method="get" class="card mb-3">
            <input type="hidden" name="view" value="mine">
            <div class="card-body">
                <div class="row g-3 align-items-end">
                    <div class="col-md-6">
                        <label class="form-label">Преподаватель</label>
                        <select name="teacher_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $teacher_id === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(Uchebni::formatFio($t)); ?>
                                    <?php echo empty($t['is_active']) ? ' (неактивен)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <div class="form-text mb-0">
                            Клик по 2 / 4 ставит часы. Повтор или × снимает. Ручные правки сохраняются при синхронизации из расписания.
                        </div>
                    </div>
                </div>
            </div>
        </form>
    <?php endif; ?>

    <?php if ($teacher_id <= 0): ?>
        <div class="alert alert-warning">
            Аккаунт не привязан к карточке преподавателя. Обратитесь к методисту.
        </div>
    <?php elseif (!empty($mine_grid['error'])): ?>
        <div class="alert alert-warning"><?php echo htmlspecialchars($mine_grid['error']); ?></div>
    <?php else: ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <div class="fw-semibold"><?php echo htmlspecialchars($mine_grid['period']['name'] ?? 'Период'); ?></div>
                <div class="text-muted small">
                    1 пара = <?php echo (int)($mine_grid['hours_per_pair'] ?? Uchebni::HOURS_PER_PAIR); ?> ч
                    <?php if (!empty($mine_grid['fact_end'])): ?>
                        · до <?php echo date('d.m.Y', strtotime($mine_grid['fact_end'])); ?>
                    <?php endif; ?>
                </div>
            </div>
            <form method="post" class="m-0"
                  onsubmit="return confirm('Подставить часы из расписания во все дни? Ваши ручные правки сохранятся.');">
                <input type="hidden" name="form_action" value="sync_hours">
                <input type="hidden" name="period_id" value="<?php echo (int)$mine_grid['period_id']; ?>">
                <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
                <button class="btn btn-outline-primary" type="submit">
                    <i class="bi bi-magic me-1"></i>Из расписания
                </button>
            </form>
        </div>

        <?php if ($synced > 0): ?>
            <div class="alert alert-success py-2 small">Обновлено из расписания: <?php echo (int)$synced; ?></div>
        <?php endif; ?>

        <?php if (empty($mine_grid['days'])): ?>
            <div class="alert alert-light border text-muted">
                До сегодня нет дней с парами по вашему расписанию. Сначала заполните расписание.
            </div>
        <?php else: ?>
            <div id="hoursDayApp"
                 data-period="<?php echo (int)$mine_grid['period_id']; ?>"
                 data-teacher="<?php echo (int)$teacher_id; ?>"
                 data-save-url="hours.php"
                 data-default-day="<?php echo htmlspecialchars($mine_grid['default_day'] ?? ''); ?>">

                <div class="day-picker-wrap mb-3">
                    <div class="day-picker" id="dayPicker"></div>
                </div>

                <div class="day-hero mb-3">
                    <div>
                        <div class="fw-bold" id="selectedDayTitle">—</div>
                        <div class="small text-muted" id="selectedDayMeta"></div>
                    </div>
                    <div class="day-hero-hint">2 или 4 часа · повтор или × снимает</div>
                </div>

                <div id="subjectList" class="subject-day-list"></div>
                <div id="emptyDay" class="alert alert-light border text-muted d-none">В этот день пар по расписанию нет</div>
            </div>

            <script type="application/json" id="hoursBootstrap"><?php
                echo json_encode([
                    'days' => $mine_grid['days'],
                    'totals' => $mine_grid['totals'],
                    'cells' => $mine_grid['cells'],
                ], JSON_UNESCAPED_UNICODE);
            ?></script>
            <script src="assets/js/hours-grid.js"></script>
        <?php endif; ?>
    <?php endif; ?>

<?php elseif ($view === 'group' && $is_methodist): ?>
    <form method="get" class="card mb-3">
        <input type="hidden" name="view" value="group">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Группа</label>
                    <select name="group_id" class="form-select" onchange="this.form.submit()">
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $group_id ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name'] ?: ($g['code'] ?? ('#' . $g['id']))); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Месяц</label>
                    <input type="month" name="year_month" class="form-control" value="<?php echo htmlspecialchars($year_month); ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Половина</label>
                    <select name="half" class="form-select" onchange="this.form.submit()">
                        <option value="all" <?php echo $half === 'all' ? 'selected' : ''; ?>>Весь месяц</option>
                        <option value="1" <?php echo $half === '1' ? 'selected' : ''; ?>>1-я (1–15)</option>
                        <option value="2" <?php echo $half === '2' ? 'selected' : ''; ?>>2-я (16–конец)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <div class="text-muted small">Всего</div>
                    <div class="fs-4 fw-semibold"><?php echo (int)$grid['totals']['hours']; ?></div>
                </div>
            </div>
            <div class="form-text mt-2">
                1 пара = <?php echo Uchebni::HOURS_PER_PAIR; ?> ч. Кураторский час не учитывается. Сб/Вс не учитываются. Факт из расписания (без отменённых).
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>
                <i class="bi bi-table me-2"></i>
                <?php echo htmlspecialchars($selected_group_name ?: 'Группа'); ?>
                · мес — [<?php echo htmlspecialchars($month_label); ?><?php echo $half === '1' ? '-1' : ($half === '2' ? '-2' : ''); ?>]
            </span>
            <span class="text-muted small">Всего: <strong><?php echo (int)$grid['totals']['hours']; ?></strong></span>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-sm mb-0 align-middle uchebni-daily-hours">
                <thead class="table-light">
                    <tr>
                        <th class="text-nowrap">Ф.И.О. преп.</th>
                        <?php foreach ($grid['days'] as $d): ?>
                            <th class="text-center <?php echo !empty($d['is_weekend']) ? 'uchebni-weekend-col' : ''; ?>">
                                <?php if (!empty($d['is_weekend'])): ?>
                                    <?php echo $d['dow'] === 6 ? 'С' : 'В'; ?>
                                <?php else: ?>
                                    <?php echo (int)$d['day']; ?>
                                <?php endif; ?>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center">Всего</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($grid['rows'] as $row): ?>
                        <tr>
                            <td class="text-nowrap"><strong><?php echo htmlspecialchars($row['teacher_name']); ?></strong></td>
                            <?php foreach ($grid['days'] as $d): ?>
                                <td class="text-center <?php echo !empty($d['is_weekend']) ? 'uchebni-weekend-col' : ''; ?>">
                                    <?php if (!empty($d['is_weekend'])): ?>
                                        <span class="uchebni-weekend-wave">〰</span>
                                    <?php else:
                                        $h = (int)($row['hours_by_date'][$d['date']] ?? 0);
                                        echo $h > 0 ? $h : '';
                                    endif; ?>
                                </td>
                            <?php endforeach; ?>
                            <td class="text-center fw-semibold"><?php echo (int)$row['total']; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($grid['rows'])): ?>
                        <tr>
                            <td colspan="<?php echo 2 + count($grid['days']); ?>" class="text-center text-muted py-4">
                                Нет проведённых пар по этой группе за месяц (нужно расписание).
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($grid['rows'])): ?>
                    <tfoot class="table-light">
                        <tr>
                            <th>Всего</th>
                            <?php foreach ($grid['days'] as $d): ?>
                                <th class="text-center <?php echo !empty($d['is_weekend']) ? 'uchebni-weekend-col' : ''; ?>">
                                    <?php
                                    if (!empty($d['is_weekend'])) {
                                        echo '';
                                    } else {
                                        $sum = 0;
                                        foreach ($grid['rows'] as $r) {
                                            $sum += (int)($r['hours_by_date'][$d['date']] ?? 0);
                                        }
                                        echo $sum > 0 ? $sum : '';
                                    }
                                    ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center"><?php echo (int)$grid['totals']['hours']; ?></th>
                        </tr>
                    </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

<?php else: ?>

    <form method="get" class="card mb-3">
        <input type="hidden" name="view" value="summary">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">С даты</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">По дату</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>" required>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Преподаватель</label>
                    <?php if ($is_methodist): ?>
                        <select name="teacher_id" class="form-select">
                            <option value="">Все</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $teacher_id === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(Uchebni::formatFio($t)); ?>
                                    <?php echo empty($t['is_active']) ? ' (неактивен)' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($selected_teacher_name); ?>" readonly>
                    <?php endif; ?>
                </div>
                <div class="col-md-2 d-grid">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-funnel me-1"></i>Показать
                    </button>
                </div>
            </div>
            <div class="form-text mt-2">
                В сводке: свои часы + замены взятые = факт. При замене у исходного часы в «отдано», у заменяющего — в «взято» и в факте.
                Пара = <?php echo Uchebni::HOURS_PER_PAIR; ?> ч. Кураторский час не учитывается.
            </div>
        </div>
    </form>

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a class="btn btn-outline-success" href="hours.php?<?php echo htmlspecialchars($qs); ?>&amp;export=csv">
            <i class="bi bi-filetype-csv me-1"></i>Экспорт сводки (CSV)
        </a>
        <?php if ($teacher_id > 0): ?>
            <a class="btn btn-outline-success" href="hours.php?<?php echo htmlspecialchars($qs); ?>&amp;export=csv_details">
                <i class="bi bi-list-ul me-1"></i>Экспорт детализации (CSV)
            </a>
        <?php endif; ?>
    </div>

    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-clock-history me-2"></i>Сводка по преподавателям</span>
            <span class="text-muted small">Итого факт: <strong><?php echo (int)$totals['total_fact']; ?></strong></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ФИО</th>
                        <th class="text-end">План ч/нед</th>
                        <th class="text-end">Свои</th>
                        <th class="text-end">Замены+</th>
                        <th class="text-end">Замены−</th>
                        <th class="text-end">Отменено</th>
                        <th class="text-end">Итого факт</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report as $row):
                        if ($teacher_id <= 0 && (int)$row['total_fact'] === 0
                            && (int)$row['substitute_out'] === 0
                            && (int)$row['cancelled'] === 0
                            && (int)$row['planned_weekly'] === 0) {
                            continue;
                        }
                        ?>
                        <tr class="<?php echo empty($row['is_active']) ? 'table-secondary' : ''; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($row['teacher_name']); ?></strong>
                                <?php if (empty($row['is_active'])): ?>
                                    <span class="badge bg-secondary ms-1">неактивен</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int)$row['planned_weekly']; ?></td>
                            <td class="text-end"><?php echo (int)$row['own_hours']; ?></td>
                            <td class="text-end"><?php echo (int)$row['substitute_in']; ?></td>
                            <td class="text-end"><?php echo (int)$row['substitute_out']; ?></td>
                            <td class="text-end"><?php echo (int)$row['cancelled']; ?></td>
                            <td class="text-end"><strong><?php echo (int)$row['total_fact']; ?></strong></td>
                            <td class="text-end">
                                <a class="btn btn-sm btn-outline-primary"
                                   href="hours.php?view=mine&amp;teacher_id=<?php echo (int)$row['id']; ?>">
                                    Сетка
                                </a>
                                <a class="btn btn-sm btn-outline-secondary"
                                   href="hours.php?view=summary&amp;date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>&amp;teacher_id=<?php echo (int)$row['id']; ?>">
                                    Подробнее
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($report)): ?>
                        <tr><td colspan="8" class="text-center text-muted py-4">Нет данных за выбранный период</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($teacher_id > 0): ?>
        <div class="card mb-4">
            <div class="card-header">
                <i class="bi bi-list-check me-2"></i>Детализация:
                <?php echo htmlspecialchars($selected_teacher_name ?: ('#' . $teacher_id)); ?>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0 align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Дата</th>
                            <th>Пара</th>
                            <th>Группа</th>
                            <th>Дисциплина</th>
                            <th>Тип</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($details as $row):
                            $pairLabel = ((int)$row['pair_number'] === 0) ? 'Кур. час' : ((int)$row['pair_number'] . ' пара');
                            $badge = [
                                'own' => 'bg-primary',
                                'substitute_in' => 'bg-success',
                                'substitute_out' => 'bg-warning text-dark',
                                'cancelled' => 'bg-secondary',
                            ][$row['role']] ?? 'bg-light text-dark';
                            ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($row['lesson_date']); ?>
                                    <div class="form-text mb-0"><?php echo htmlspecialchars(Uchebni::DAY_NAMES[$row['day_of_week']] ?? ''); ?></div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($pairLabel); ?>
                                    <div class="form-text mb-0"><?php echo htmlspecialchars(Uchebni::SHIFT_NAMES[$row['shift']] ?? ''); ?></div>
                                </td>
                                <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                                <td><span class="badge <?php echo $badge; ?>"><?php echo htmlspecialchars($role_labels[$row['role']] ?? $row['role']); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($details)): ?>
                            <tr><td colspan="5" class="text-center text-muted py-4">Нет занятий у этого преподавателя за период</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
