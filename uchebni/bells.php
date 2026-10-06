<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
requirePermission('manage_schedule');

$uchebni = new Uchebni();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $slots = $_POST['slots'] ?? [];
        $ok = 0;
        $fail = 0;
        foreach ($slots as $id => $row) {
            if ($uchebni->updateBellSlot((int)$id, [
                'label' => sanitize($row['label'] ?? ''),
                'start_time' => $row['start_time'] ?? '',
                'end_time' => $row['end_time'] ?? '',
                'break_after' => (int)($row['break_after'] ?? 0),
            ])) {
                $ok++;
            } else {
                $fail++;
            }
        }
        if ($fail === 0) {
            $message = 'Расписание звонков сохранено (' . $ok . ')';
        } else {
            $error = 'Сохранено: ' . $ok . ', ошибок: ' . $fail;
        }
    }

    if ($action === 'reset') {
        $uchebni->resetBellScheduleToDefaults();
        $message = 'Восстановлено эталонное расписание звонков колледжа';
    }
}

$grouped = $uchebni->getAllBellScheduleGrouped();

$page_title = 'Расписание звонков';
$page_subtitle = 'Пн–Пт · I и II смены · особый вторник';
require_once 'includes/header.php';

$blocks = [
    ['weekday', 1],
    ['weekday', 2],
    ['tuesday', 1],
    ['tuesday', 2],
];
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <p class="text-muted mb-0 small">
        Эти звонки используются в сетке расписания и при добавлении пар (время подставляется автоматически).
    </p>
    <form method="post" onsubmit="return confirm('Сбросить все звонки к эталону колледжа?');">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Сбросить к эталону
        </button>
    </form>
</div>

<form method="post">
    <input type="hidden" name="action" value="save">
    <div class="row g-3">
        <?php foreach ($blocks as [$dayType, $shift]):
            $slots = $grouped[$dayType][$shift] ?? [];
            $title = (Uchebni::BELL_DAY_TYPES[$dayType] ?? $dayType) . ' · ' . (Uchebni::SHIFT_NAMES[$shift] ?? $shift);
        ?>
        <div class="col-lg-6">
            <div class="card h-100">
                <div class="card-header"><strong><?php echo htmlspecialchars($title); ?></strong></div>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Слот</th>
                                <th style="width:7rem">Начало</th>
                                <th style="width:7rem">Конец</th>
                                <th style="width:5rem">Перерыв</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($slots)): ?>
                            <tr><td colspan="4" class="text-muted text-center py-3">Нет слотов</td></tr>
                            <?php else: ?>
                            <?php foreach ($slots as $slot):
                                $id = (int)$slot['id'];
                                $start = substr($slot['start_time'], 0, 5);
                                $end = substr($slot['end_time'], 0, 5);
                            ?>
                            <tr class="<?php echo (int)$slot['pair_number'] === 0 ? 'table-warning' : ''; ?>">
                                <td>
                                    <input type="text" name="slots[<?php echo $id; ?>][label]" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($slot['label']); ?>" required>
                                    <div class="form-text">№ <?php echo (int)$slot['pair_number']; ?></div>
                                </td>
                                <td>
                                    <input type="time" name="slots[<?php echo $id; ?>][start_time]" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($start); ?>" required>
                                </td>
                                <td>
                                    <input type="time" name="slots[<?php echo $id; ?>][end_time]" class="form-control form-control-sm"
                                           value="<?php echo htmlspecialchars($end); ?>" required>
                                </td>
                                <td>
                                    <input type="number" name="slots[<?php echo $id; ?>][break_after]" class="form-control form-control-sm"
                                           value="<?php echo (int)$slot['break_after']; ?>" min="0" max="60" title="Минут после слота">
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-3 text-end">
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-save me-1"></i>Сохранить звонки
        </button>
    </div>
</form>

<?php require_once 'includes/footer.php'; ?>
