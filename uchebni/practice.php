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
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $period_id) {
    $action = $_POST['action'] ?? '';

    if ($action === 'create' && $is_methodist) {
        $result = $uchebni->createPracticePeriod([
            'period_id' => $period_id,
            'title' => sanitize($_POST['title'] ?? 'практика'),
            'start_date' => $_POST['start_date'] ?? '',
            'end_date' => $_POST['end_date'] ?? '',
            'group_id' => !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null,
        ]);
        if (!empty($result['ok'])) {
            $message = 'Период практики добавлен';
        } else {
            $error = $result['error'] ?? 'Ошибка сохранения';
        }
    }

    if ($action === 'delete' && $is_methodist) {
        if ($uchebni->deletePracticePeriod((int)($_POST['id'] ?? 0))) {
            $message = 'Период удалён';
        } else {
            $error = 'Не удалось удалить';
        }
    }
}

$items = $period_id ? $uchebni->getPracticePeriods($period_id) : [];
$groups = $uchebni->getActiveGroups();

$page_title = 'Практика';
$page_subtitle = $period
    ? ($period['name'] . ' · в эти даты сетка часов помечается «П»')
    : 'Нет активного периода';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (!$period): ?>
    <div class="alert alert-warning">Нет активного учебного периода</div>
<?php else: ?>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <p class="text-muted small mb-0">
            В дни практики пары в сетке часов не ставятся (метка «П»).
            <?php if (!$is_methodist): ?>Только просмотр — правит методист.<?php endif; ?>
        </p>
        <?php if ($is_methodist): ?>
            <button class="btn btn-primary" type="button" data-bs-toggle="modal" data-bs-target="#modalPractice">
                <i class="bi bi-plus-lg me-1"></i>Добавить
            </button>
        <?php endif; ?>
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-briefcase me-2"></i>Периоды практики</div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Название</th>
                        <th>Группа</th>
                        <th>С</th>
                        <th>По</th>
                        <?php if ($is_methodist): ?><th></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['title']); ?></strong></td>
                            <td>
                                <?php
                                if (!empty($item['group_id'])) {
                                    echo htmlspecialchars($item['group_code'] ?: ($item['group_name'] ?? '#' . $item['group_id']));
                                } else {
                                    echo '<span class="text-muted">Все группы</span>';
                                }
                                ?>
                            </td>
                            <td><?php echo date('d.m.Y', strtotime($item['start_date'])); ?></td>
                            <td><?php echo date('d.m.Y', strtotime($item['end_date'])); ?></td>
                            <?php if ($is_methodist): ?>
                                <td class="text-end">
                                    <form method="post" class="d-inline" onsubmit="return confirm('Удалить период практики?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?php echo (int)$item['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">Удалить</button>
                                    </form>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="<?php echo $is_methodist ? 5 : 4; ?>" class="text-center text-muted py-4">
                                Периодов пока нет
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($is_methodist): ?>
        <div class="modal fade" id="modalPractice" tabindex="-1">
            <div class="modal-dialog modal-dialog-centered">
                <form class="modal-content" method="post">
                    <input type="hidden" name="action" value="create">
                    <div class="modal-header">
                        <h5 class="modal-title">Период практики</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label">Название</label>
                            <input name="title" class="form-control" value="практика">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Группа</label>
                            <select name="group_id" class="form-select">
                                <option value="">Все группы</option>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>">
                                        <?php echo htmlspecialchars($g['name'] ?: ($g['code'] ?? ('#' . $g['id']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="row g-2">
                            <div class="col-6">
                                <label class="form-label">С</label>
                                <input type="date" name="start_date" class="form-control" required
                                       value="<?php echo htmlspecialchars($period['start_date'] ?? ''); ?>">
                            </div>
                            <div class="col-6">
                                <label class="form-label">По</label>
                                <input type="date" name="end_date" class="form-control" required
                                       value="<?php echo htmlspecialchars($period['end_date'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                        <button class="btn btn-primary" type="submit">Сохранить</button>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
