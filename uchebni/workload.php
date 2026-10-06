<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);
$permissionChecker = new PermissionChecker();
if (!hasPermission('manage_workload') && !hasPermission('manage_schedule') && !hasPermission('view_workload') && !hasPermission('view_schedule')) {
    $permissionChecker->requirePermission('view_uchebni');
}

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
// Режим по роли входа (не по объединённым правам всех ролей)
$is_methodist = ($role === 'methodist');
$is_teacher_session = ($role === 'teacher');

$teachers = $uchebni->getTeachers(true);
$teacher_id = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($is_teacher_session) {
    $linked = $uchebni->getTeacherByUserId((int)$current_user['id']);
    $teacher_id = $linked ? (int)$linked['id'] : 0;
} elseif ($teacher_id <= 0 && !empty($teachers)) {
    $teacher_id = (int)$teachers[0]['id'];
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_methodist && $period_id) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save_month') {
        $ok = $uchebni->setWorkloadMonthHours(
            $period_id,
            (int)($_POST['teacher_id'] ?? 0),
            (int)($_POST['group_id'] ?? 0),
            (int)($_POST['subject_id'] ?? 0),
            sanitize($_POST['year_month'] ?? ''),
            (int)($_POST['hours_plan'] ?? 0)
        );
        $teacher_id = (int)($_POST['teacher_id'] ?? $teacher_id);
        $message = $ok ? 'Часы плана обновлены' : 'Ошибка сохранения';
        if (!$ok) {
            $error = $message;
            $message = '';
        }
    }
    if ($action === 'reseed') {
        $teacher_id = (int)($_POST['teacher_id'] ?? $teacher_id);
        // Удаляем старые и создаём заново
        $db = getDB();
        $del = $db->prepare("DELETE FROM uchebni_workload_months WHERE period_id = ? AND teacher_id = ?");
        $del->bind_param('ii', $period_id, $teacher_id);
        $del->execute();
        $n = $uchebni->seedTeacherWorkloadMonths($period_id, $teacher_id);
        $message = 'План пересобран из часов дисциплин: ' . $n . ' ячеек';
    }
}

$report = ($period_id && $teacher_id)
    ? $uchebni->getTeacherWorkloadReport($period_id, $teacher_id)
    : ['months' => [], 'rows' => [], 'totals' => ['plan' => 0, 'fact' => 0]];

$selected_name = '';
foreach ($teachers as $t) {
    if ((int)$t['id'] === $teacher_id) {
        $selected_name = Uchebni::formatFio($t);
        break;
    }
}

$lesson_labels = [
    'lecture' => 'лекц.',
    'practice' => 'практ.',
    'lab' => 'лаб.',
    'curator' => 'кур.',
];

$page_title = 'Нагрузка';
$page_subtitle = ($period ? $period['name'] . ' · ' : '') . ($selected_name ?: 'преподаватель');
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="get" class="card mb-4">
    <div class="card-body row g-3 align-items-end">
        <?php if ($is_methodist): ?>
            <div class="col-md-6">
                <label class="form-label">Преподаватель (Ф.И.О.)</label>
                <select name="teacher_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$t['id'] === $teacher_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(Uchebni::formatFio($t)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="col-md-6">
                <label class="form-label">Преподаватель</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($selected_name); ?>" readonly>
            </div>
        <?php endif; ?>
        <div class="col-md-6">
            <div class="d-flex flex-wrap gap-3">
                <div>
                    <div class="text-muted small">План всего</div>
                    <div class="fs-4 fw-semibold"><?php echo (int)$report['totals']['plan']; ?></div>
                </div>
                <div>
                    <div class="text-muted small">Факт всего</div>
                    <div class="fs-4 fw-semibold"><?php echo (int)$report['totals']['fact']; ?></div>
                </div>
                <div>
                    <div class="text-muted small">Остаток</div>
                    <div class="fs-4 fw-semibold"><?php echo (int)$report['totals']['plan'] - (int)$report['totals']['fact']; ?></div>
                </div>
            </div>
        </div>
    </div>
</form>

<?php if ($is_methodist && $teacher_id && $period_id): ?>
    <form method="post" class="mb-3" onsubmit="return confirm('Пересобрать помесячный план из часов дисциплин? Ручные правки будут сброшены.');">
        <input type="hidden" name="action" value="reseed">
        <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-repeat me-1"></i>Пересобрать план из дисциплин
        </button>
    </form>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <i class="bi bi-calendar3 me-2"></i>Нагрузка по группам и месяцам
        <span class="text-muted small ms-2">(план из hours_sem дисциплины · факт из расписания × <?php echo Uchebni::HOURS_PER_PAIR; ?> ч)</span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered mb-0 align-middle uchebni-workload-table">
            <thead class="table-light">
                <tr>
                    <th>Гр.</th>
                    <th>Пред. / по плану</th>
                    <?php foreach ($report['months'] as $ym):
                        $m = (int)substr($ym, 5, 2);
                        $label = Uchebni::MONTH_NAMES_RU[$m] ?? $ym;
                        ?>
                        <th class="text-center" colspan="2"><?php echo htmlspecialchars($label); ?></th>
                    <?php endforeach; ?>
                    <th class="text-center">Всего план</th>
                    <th class="text-center">Всего факт</th>
                </tr>
                <tr>
                    <th></th>
                    <th></th>
                    <?php foreach ($report['months'] as $ym): ?>
                        <th class="text-center small">план</th>
                        <th class="text-center small">факт</th>
                    <?php endforeach; ?>
                    <th></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($report['rows'] as $row): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['group_name']); ?></strong></td>
                        <td>
                            <?php echo htmlspecialchars($row['subject_name']); ?>
                            <span class="text-muted small">(<?php echo htmlspecialchars($lesson_labels[$row['lesson_type']] ?? $row['lesson_type']); ?> · дисц. <?php echo (int)$row['plan_total_subject']; ?> ч)</span>
                        </td>
                        <?php foreach ($report['months'] as $ym): ?>
                            <td class="text-center p-1">
                                <?php if ($is_methodist): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="action" value="save_month">
                                        <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
                                        <input type="hidden" name="group_id" value="<?php echo (int)$row['group_id']; ?>">
                                        <input type="hidden" name="subject_id" value="<?php echo (int)$row['subject_id']; ?>">
                                        <input type="hidden" name="year_month" value="<?php echo htmlspecialchars($ym); ?>">
                                        <input type="number" name="hours_plan" class="form-control form-control-sm text-center uchebni-hours-input"
                                               value="<?php echo (int)($row['plan_by_month'][$ym] ?? 0); ?>" min="0"
                                               onchange="this.form.submit()" title="План">
                                    </form>
                                <?php else: ?>
                                    <?php echo (int)($row['plan_by_month'][$ym] ?? 0); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center"><?php echo (int)($row['fact_by_month'][$ym] ?? 0); ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold"><?php echo (int)$row['plan_sum']; ?></td>
                        <td class="text-center fw-semibold"><?php echo (int)$row['fact_sum']; ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($report['rows'])): ?>
                    <tr>
                        <td colspan="<?php echo 4 + count($report['months']) * 2; ?>" class="text-center text-muted py-4">
                            Нет назначений в учебном плане для этого преподавателя. Добавьте дисциплины группе в «Учебный план» и укажите преподавателя.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
