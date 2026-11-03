<?php
require_once '../config/config.php';
require_once '../classes/Practice.php';
require_once '../includes/auth.php';

checkAuth();

$practice = new Practice();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$isAdminOrMethodist = in_array($role, ['admin', 'methodist', 'director', 'manager', 'department_head', 'practicant'], true);

if (!$isAdminOrMethodist) {
    header('Location: index.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'return_workload') {
    $workloadId = (int)($_POST['id'] ?? 0);
    $comment = sanitize($_POST['return_comment'] ?? 'Возвращено на доработку');
    if ($workloadId > 0) {
        $db = getDB();
        $stmt = $db->prepare("UPDATE workloads SET status = 'in_progress', comment = ? WHERE id = ?");
        $stmt->bind_param('si', $comment, $workloadId);
        if ($stmt->execute()) {
            $message = 'График практики возвращён преподавателю на доработку.';
        } else {
            $error = 'Не удалось вернуть график.';
        }
    }
}

$search = trim($_GET['search'] ?? '');
$submissions = $practice->getSubmissions($search);

$page_title = 'Сданные графики практики';
$page_subtitle = 'Контроль сдачи и проверка заполненных графиков';

require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <form method="get" class="d-flex align-items-center gap-2">
        <input type="text" name="search" class="form-control form-control-sm" style="min-width: 250px;"
               placeholder="Поиск по модулю, группе, ФИО..." value="<?php echo htmlspecialchars($search); ?>">
        <button type="submit" class="btn btn-secondary btn-sm"><i class="bi bi-search"></i></button>
        <?php if ($search): ?>
            <a href="submissions.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-x"></i></a>
        <?php endif; ?>
    </form>
</div>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>№</th>
                    <th>Преподаватель</th>
                    <th>Модуль практики</th>
                    <th>Группа</th>
                    <th>Часы</th>
                    <th>Дата сдачи</th>
                    <th>Комментарий</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($submissions)): ?>
                    <tr>
                        <td colspan="8" class="text-center py-4 text-muted">Сданных графиков практики пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($submissions as $idx => $s): ?>
                        <tr>
                            <td class="text-muted"><?php echo $idx + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($s['teacher_name'] ?: '—'); ?></strong>
                                <?php if (!empty($s['department'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($s['department']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($s['module_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($s['study_group'] ?: '—'); ?></span></td>
                            <td><strong><?php echo (int)$s['practice_hours']; ?> ч.</strong></td>
                            <td>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <i class="bi bi-calendar-check me-1"></i><?php echo $s['submitted_at'] ? date('d.m.Y H:i', strtotime($s['submitted_at'])) : '—'; ?>
                                </span>
                            </td>
                            <td class="small text-muted" style="max-width: 200px;">
                                <?php echo htmlspecialchars($s['comment'] ?: '—'); ?>
                            </td>
                            <td class="text-end">
                                <a href="schedule.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-light btn-sm" title="Просмотр графика">
                                    <i class="bi bi-eye"></i>
                                </a>
                                <a href="export_docx.php?id=<?php echo (int)$s['id']; ?>" class="btn btn-primary btn-sm" title="Скачать DOCX">
                                    <i class="bi bi-file-earmark-word me-1"></i>Word
                                </a>
                                <button type="button" class="btn btn-outline-warning btn-sm" title="Вернуть на доработку"
                                        data-bs-toggle="modal" data-bs-target="#modalReturn<?php echo (int)$s['id']; ?>">
                                    <i class="bi bi-arrow-counterclockwise"></i>
                                </button>
                            </td>
                        </tr>

                        <!-- Модальное окно возврата на доработку -->
                        <div class="modal fade" id="modalReturn<?php echo (int)$s['id']; ?>" tabindex="-1">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <form method="post">
                                        <input type="hidden" name="action" value="return_workload">
                                        <input type="hidden" name="id" value="<?php echo (int)$s['id']; ?>">
                                        <div class="modal-header">
                                            <h5 class="modal-title">Вернуть график на доработку</h5>
                                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                                        </div>
                                        <div class="modal-body">
                                            <p>Преподаватель: <strong><?php echo htmlspecialchars($s['teacher_name']); ?></strong></p>
                                            <p>Модуль: <strong><?php echo htmlspecialchars($s['module_name']); ?></strong></p>
                                            <div class="mb-3">
                                                <label class="form-label">Причина возврата / замечания</label>
                                                <textarea name="return_comment" class="form-control" rows="3" required placeholder="Укажите, что нужно исправить в графике..."></textarea>
                                            </div>
                                        </div>
                                        <div class="modal-footer">
                                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                                            <button type="submit" class="btn btn-warning">Вернуть на доработку</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

