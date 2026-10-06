<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_schedule');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$shift = !empty($_GET['shift']) ? (int)$_GET['shift'] : 0;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_busy') {
        $tid = (int)($_POST['teacher_id'] ?? 0);
        $dow = (int)($_POST['day_of_week'] ?? 0);
        $pair = (int)($_POST['pair_number'] ?? 0);
        $mode = $_POST['mode'] ?? 'set';
        if ($mode === 'clear') {
            $ok = $uchebni->clearTeacherBusy($tid, $dow, $pair);
            $message = $ok ? 'Слот освобождён' : 'Ошибка';
        } else {
            $ok = $uchebni->setTeacherBusy($tid, $dow, $pair, sanitize($_POST['reason'] ?? 'занят'));
            $message = $ok ? 'Отмечена занятость' : 'Ошибка';
        }
        if (!$ok) {
            $error = $message;
            $message = '';
        }
        $shift = !empty($_POST['shift']) ? (int)$_POST['shift'] : $shift;
    }
}

$matrix = $period_id
    ? $uchebni->getOccupancyMatrix($period_id, $shift ?: null)
    : ['teachers' => [], 'rooms' => []];

$day_short = [1 => 'Пн.', 2 => 'Вт.', 3 => 'Ср.', 4 => 'Четв.', 5 => 'Пятн.'];

$current_user = getCurrentUser();
$page_title = 'Занятость препод.';
$page_subtitle = ($period ? $period['name'] . ' · ' : '') . ($shift ? (Uchebni::SHIFT_NAMES[$shift] ?? '') : 'обе смены');
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<form method="get" class="mb-3 d-flex flex-wrap gap-2 align-items-center">
    <label class="form-label mb-0 me-2">Смена</label>
    <select name="shift" class="form-select form-select-sm w-auto" onchange="this.form.submit()">
        <option value="0" <?php echo $shift === 0 ? 'selected' : ''; ?>>Все</option>
        <option value="1" <?php echo $shift === 1 ? 'selected' : ''; ?>>I см.</option>
        <option value="2" <?php echo $shift === 2 ? 'selected' : ''; ?>>II см.</option>
    </select>
    <?php if (!empty($matrix['rooms'])): ?>
        <span class="text-muted small ms-3">Ауд.: <?php echo htmlspecialchars(implode(', ', $matrix['rooms'])); ?></span>
    <?php endif; ?>
</form>

<div class="card mb-3">
    <div class="card-header"><i class="bi bi-grid-3x3-gap me-2"></i>Занятость преподавателей</div>
    <div class="table-responsive">
        <table class="table table-bordered table-sm mb-0 uchebni-occupancy-table">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" class="align-middle">Ф.И.О.</th>
                    <?php foreach ($day_short as $dow => $label): ?>
                        <th colspan="3" class="text-center"><?php echo $label; ?><?php echo $dow === 3 ? ' · I см.' : ''; ?></th>
                    <?php endforeach; ?>
                </tr>
                <tr>
                    <?php for ($d = 1; $d <= 5; $d++): ?>
                        <th class="text-center small">1</th>
                        <th class="text-center small">2</th>
                        <th class="text-center small">3</th>
                    <?php endfor; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($matrix['teachers'] as $idx => $row): ?>
                    <tr>
                        <td class="text-nowrap">
                            <span class="text-muted small me-1"><?php echo $idx + 1; ?>.</span>
                            <?php echo htmlspecialchars($row['teacher_name']); ?>
                        </td>
                        <?php for ($dow = 1; $dow <= 5; $dow++): ?>
                            <?php for ($pair = 1; $pair <= 3; $pair++):
                                $cell = $row['cells'][$dow][$pair] ?? ['occupied' => false, 'busy' => false, 'lesson' => null];
                                $cls = 'uchebni-occ-cell';
                                if (!empty($cell['occupied'])) {
                                    $cls .= ' is-occupied';
                                }
                                if (!empty($cell['busy'])) {
                                    $cls .= ' is-busy';
                                }
                                if (!empty($cell['lesson'])) {
                                    $cls .= ' has-lesson';
                                }
                                $title = '';
                                if (!empty($cell['lesson'])) {
                                    $title = trim(($cell['lesson']['subject_name'] ?? '') . ' · ' . ($cell['lesson']['group_name'] ?? '') . ' · ауд. ' . ($cell['lesson']['classroom'] ?? ''));
                                } elseif (!empty($cell['busy'])) {
                                    $title = 'Недоступен: ' . ($cell['busy_reason'] ?: 'занят');
                                }
                                ?>
                                <td class="<?php echo $cls; ?>" title="<?php echo htmlspecialchars($title); ?>">
                                    <?php if (!empty($cell['occupied'])): ?>
                                        <span class="uchebni-occ-hatch"></span>
                                    <?php endif; ?>
                                    <?php if (empty($cell['lesson'])): ?>
                                        <form method="post" class="uchebni-occ-toggle">
                                            <input type="hidden" name="action" value="toggle_busy">
                                            <input type="hidden" name="teacher_id" value="<?php echo (int)$row['teacher_id']; ?>">
                                            <input type="hidden" name="day_of_week" value="<?php echo $dow; ?>">
                                            <input type="hidden" name="pair_number" value="<?php echo $pair; ?>">
                                            <input type="hidden" name="shift" value="<?php echo (int)$shift; ?>">
                                            <?php if (!empty($cell['busy'])): ?>
                                                <input type="hidden" name="mode" value="clear">
                                                <button type="submit" class="btn btn-link btn-sm p-0" title="Снять занятость">×</button>
                                            <?php else: ?>
                                                <input type="hidden" name="mode" value="set">
                                                <button type="submit" class="btn btn-link btn-sm p-0 text-muted" title="Отметить занятость">+</button>
                                            <?php endif; ?>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            <?php endfor; ?>
                        <?php endfor; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($matrix['teachers'])): ?>
                    <tr>
                        <td colspan="16" class="text-center text-muted py-4">Нет преподавателей или периода</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if ($shift !== 2): ?>
        <div class="card-footer text-center text-muted small">II см. — переключите фильтр смены выше</div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-body text-muted small">
        Штриховка: занят по расписанию или отмечен как недоступный.
        Кнопка «+» / «×» — ручная занятость (не путать с парой в расписании).
        Аудитории берутся из текущего расписания.
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
