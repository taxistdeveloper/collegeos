<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_subjects');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $period_id) {
    $action = $_POST['action'] ?? '';
    if ($action === 'save') {
        $ok = $uchebni->upsertSection([
            'period_id' => $period_id,
            'subject_id' => (int)($_POST['subject_id'] ?? 0),
            'stream' => (int)($_POST['stream'] ?? 1),
            'section_count' => (int)($_POST['section_count'] ?? 1),
            'headcount' => (int)($_POST['headcount'] ?? 0),
            'status' => 'active',
            'note' => sanitize($_POST['note'] ?? ''),
        ]);
        $message = $ok ? 'Секция сохранена' : 'Ошибка сохранения';
        if (!$ok) {
            $error = $message;
            $message = '';
        }
    }
    if ($action === 'disband') {
        $ok = $uchebni->disbandSection((int)($_POST['section_id'] ?? 0), (int)($_POST['new_section_count'] ?? 2));
        $message = $ok ? 'Секция расформирована' : 'Ошибка';
        if (!$ok) {
            $error = $message;
            $message = '';
        }
    }
    if ($action === 'delete') {
        $ok = $uchebni->deleteSection((int)($_POST['section_id'] ?? 0));
        $message = $ok ? 'Запись удалена' : 'Ошибка удаления';
        if (!$ok) {
            $error = $message;
            $message = '';
        }
    }
}

$overview = $period_id ? $uchebni->getContentOverview($period_id) : [];
$subjects = $uchebni->getSubjects(true);

$current_user = getCurrentUser();
$page_title = 'Содержание';
$page_subtitle = ($period ? $period['name'] . ' · ' : '') . 'I сем. ' . Uchebni::WEEKS_SEM1 . ' нед. · II сем. ' . Uchebni::WEEKS_SEM2 . ' нед.';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Семестр I</div>
                <div class="fs-3 fw-semibold"><?php echo Uchebni::WEEKS_SEM1; ?> <span class="fs-6 text-muted">нед.</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Семестр II</div>
                <div class="fs-3 fw-semibold"><?php echo Uchebni::WEEKS_SEM2; ?> <span class="fs-6 text-muted">нед.</span></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="text-muted small">Дисциплин с часами</div>
                <div class="fs-3 fw-semibold"><?php echo count($overview); ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-list-columns me-2"></i>Содерж. — часы из дисциплин (ООД и др.), секции по потокам</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover mb-0 align-middle">
            <thead class="table-light">
                <tr>
                    <th>Дисциплина</th>
                    <th>Блок</th>
                    <th class="text-center">I · часы</th>
                    <th class="text-center">I · секции</th>
                    <th class="text-center">II · часы</th>
                    <th class="text-center">II · секции</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($overview as $row):
                    $s1 = $row['streams'][1] ?? null;
                    $s2 = $row['streams'][2] ?? null;
                    ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($row['subject_name']); ?></strong></td>
                        <td>
                            <?php if (!empty($row['category_code'])): ?>
                                <span class="badge bg-secondary"><?php echo htmlspecialchars($row['category_code']); ?></span>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php echo (int)$row['hours_sem1']; ?>
                            <?php if ($s1): ?>
                                <div class="small text-muted"><?php echo (int)$s1['headcount']; ?> чел.</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s1): ?>
                                <span class="<?php echo $s1['status'] === 'disbanded' ? 'text-decoration-line-through text-muted' : ''; ?>">
                                    <?php echo (int)$s1['section_count']; ?> сек.
                                </span>
                                <?php if ($s1['status'] === 'disbanded'): ?>
                                    <div class="small text-warning">расформ.</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php echo (int)$row['hours_sem2']; ?>
                            <?php if ($s2): ?>
                                <div class="small text-muted"><?php echo (int)$s2['headcount']; ?> чел.</div>
                            <?php endif; ?>
                        </td>
                        <td class="text-center">
                            <?php if ($s2): ?>
                                <span class="<?php echo $s2['status'] === 'disbanded' ? 'text-decoration-line-through text-muted' : ''; ?>">
                                    <?php echo (int)$s2['section_count']; ?> сек.
                                </span>
                                <?php if ($s2['status'] === 'disbanded'): ?>
                                    <div class="small text-warning">расформ.</div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-muted">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end text-nowrap">
                            <?php if ($s1 && $s1['status'] === 'active'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Расформировать секции потока I?');">
                                    <input type="hidden" name="action" value="disband">
                                    <input type="hidden" name="section_id" value="<?php echo (int)$s1['id']; ?>">
                                    <input type="hidden" name="new_section_count" value="2">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Расформировать I">I</button>
                                </form>
                            <?php endif; ?>
                            <?php if ($s2 && $s2['status'] === 'active'): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Расформировать секции потока II?');">
                                    <input type="hidden" name="action" value="disband">
                                    <input type="hidden" name="section_id" value="<?php echo (int)$s2['id']; ?>">
                                    <input type="hidden" name="new_section_count" value="2">
                                    <button type="submit" class="btn btn-sm btn-outline-warning" title="Расформировать II">II</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($overview)): ?>
                    <tr>
                        <td colspan="7" class="text-center text-muted py-4">
                            Нет дисциплин с часами. Заполните часы сем. 1/2 в разделе «Дисциплины» (блок ООД и др.).
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><i class="bi bi-plus-circle me-2"></i>Задать / обновить секции</div>
    <div class="card-body">
        <?php if (!$period_id): ?>
            <div class="alert alert-warning mb-0">Нет текущего учебного периода.</div>
        <?php else: ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="save">
                <div class="col-md-5">
                    <label class="form-label">Дисциплина</label>
                    <select name="subject_id" class="form-select" required>
                        <option value="">—</option>
                        <?php foreach ($subjects as $s): ?>
                            <option value="<?php echo (int)$s['id']; ?>">
                                <?php
                                $code = trim((string)($s['category_code'] ?? ''));
                                echo htmlspecialchars(($code !== '' ? $code . ' · ' : '') . $s['name']
                                    . ' (' . (int)$s['hours_sem1'] . '/' . (int)$s['hours_sem2'] . ')');
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Поток</label>
                    <select name="stream" class="form-select">
                        <option value="1">I</option>
                        <option value="2">II</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Секций</label>
                    <input type="number" name="section_count" class="form-control" value="3" min="0" max="20">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Контингент</label>
                    <input type="number" name="headcount" class="form-control" value="0" min="0">
                </div>
                <div class="col-md-12">
                    <label class="form-label">Примечание</label>
                    <input type="text" name="note" class="form-control" maxlength="500">
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
