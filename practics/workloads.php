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

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $res = $practice->createWorkload([
            'teacher_id'     => (int)($_POST['teacher_id'] ?? 0),
            'module_name'    => sanitize($_POST['module_name'] ?? ''),
            'study_group'    => sanitize($_POST['study_group'] ?? ''),
            'practice_hours' => (int)($_POST['practice_hours'] ?? 0),
            'deadline'       => $_POST['deadline'] ?? '',
            'assigned_by'    => (int)$current_user['id'],
        ]);
        if ($res['ok']) {
            $message = 'Нагрузка практики успешно назначена!';
        } else {
            $error = $res['error'] ?? 'Ошибка сохранения';
        }
    }

    if ($action === 'delete') {
        $deleteId = (int)($_POST['id'] ?? 0);
        if ($deleteId > 0 && $practice->deleteWorkload($deleteId)) {
            $message = 'Нагрузка практики удалена.';
        } else {
            $error = 'Не удалось удалить нагрузку.';
        }
    }
}

// Фильтры
$filterStatus = $_GET['status'] ?? '';
$search = trim($_GET['search'] ?? '');
$filterTeacher = (int)($_GET['teacher_id'] ?? 0);

$workloads = $practice->getWorkloads([
    'status'     => $filterStatus,
    'search'     => $search,
    'teacher_id' => $filterTeacher,
]);

$teachersList = $practice->getTeachers();
$groupsList = $practice->getGroups();

$page_title = 'Нагрузки практики';
$page_subtitle = 'Управление распределением практики преподавателей';

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

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div class="d-flex flex-wrap align-items-center gap-2">
        <form method="get" class="d-flex align-items-center gap-2">
            <input type="text" name="search" class="form-control form-control-sm" style="min-width: 220px;"
                   placeholder="Поиск по модулю, группе, ФИО..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="status" class="form-select form-select-sm" style="width: auto;">
                <option value="">Все статусы</option>
                <option value="assigned" <?php echo $filterStatus === 'assigned' ? 'selected' : ''; ?>>Назначена</option>
                <option value="in_progress" <?php echo $filterStatus === 'in_progress' ? 'selected' : ''; ?>>В процессе</option>
                <option value="submitted" <?php echo $filterStatus === 'submitted' ? 'selected' : ''; ?>>Сдана</option>
                <option value="overdue" <?php echo $filterStatus === 'overdue' ? 'selected' : ''; ?>>Просрочена</option>
            </select>
            <button type="submit" class="btn btn-secondary btn-sm"><i class="bi bi-search"></i></button>
            <?php if ($search || $filterStatus): ?>
                <a href="workloads.php" class="btn btn-outline-secondary btn-sm" title="Сбросить"><i class="bi bi-x"></i></a>
            <?php endif; ?>
        </form>
    </div>

    <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#modalCreateWorkload">
        <i class="bi bi-plus-lg me-1"></i>Назначить практику
    </button>
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
                    <th>Часов</th>
                    <th>Срок сдачи</th>
                    <th>Статус</th>
                    <th>Прогресс</th>
                    <th class="text-end">Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($workloads)): ?>
                    <tr>
                        <td colspan="9" class="text-center py-4 text-muted">
                            <?php echo ($search || $filterStatus) ? 'По вашему фильтру ничего не найдено' : 'Нагрузки практики пока не добавлены'; ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($workloads as $idx => $w): 
                        $statusMap = [
                            'assigned'    => ['Назначена', 'practice-badge-assigned'],
                            'in_progress' => ['В процессе', 'practice-badge-in_progress'],
                            'submitted'   => ['Сдана', 'practice-badge-submitted'],
                            'overdue'     => ['Просрочена', 'practice-badge-overdue'],
                        ];
                        [$stText, $stBadge] = $statusMap[$w['status']] ?? [$w['status'], 'badge-secondary'];
                    ?>
                        <tr>
                            <td class="text-muted"><?php echo $idx + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($w['teacher_name'] ?: '—'); ?></strong>
                                <?php if (!empty($w['department'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($w['department']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><strong><?php echo htmlspecialchars($w['module_name']); ?></strong></td>
                            <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($w['study_group'] ?: '—'); ?></span></td>
                            <td><strong><?php echo (int)$w['practice_hours']; ?> ч.</strong></td>
                            <td><?php echo date('d.m.Y', strtotime($w['deadline'])); ?></td>
                            <td><span class="badge <?php echo $stBadge; ?>"><?php echo $stText; ?></span></td>
                            <td style="width: 110px;">
                                <div class="small text-muted mb-1"><?php echo (int)$w['progress_percent']; ?>%</div>
                                <div class="progress" style="height: 4px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo (int)$w['progress_percent']; ?>%"></div>
                                </div>
                            </td>
                            <td class="text-end">
                                <a href="schedule.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-light btn-sm" title="График">
                                    <i class="bi bi-calendar3"></i>
                                </a>
                                <a href="export_docx.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-light btn-sm" title="Скачать DOCX">
                                    <i class="bi bi-file-earmark-word"></i>
                                </a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Удалить эту нагрузку практики?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$w['id']; ?>">
                                    <button type="submit" class="btn btn-light btn-sm text-danger" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Модальное окно создания нагрузки -->
<div class="modal fade" id="modalCreateWorkload" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="post">
                <input type="hidden" name="action" value="create">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-plus-circle me-2"></i>Назначить практику</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Преподаватель <span class="text-danger">*</span></label>
                        <select name="teacher_id" class="form-select" required>
                            <option value="">-- Выберите преподавателя --</option>
                            <?php foreach ($teachersList as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>">
                                    <?php echo htmlspecialchars($t['fullname']); ?>
                                    <?php if (!empty($t['department'])): ?>(<?php echo htmlspecialchars($t['department']); ?>)<?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Наименование модуля / практики <span class="text-danger">*</span></label>
                        <input type="text" name="module_name" class="form-control" placeholder="Например: ПМ 01 Производственная практика" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Учебная группа</label>
                        <input type="text" name="study_group" list="groups_datalist" class="form-control" placeholder="Выберите или введите группу">
                        <datalist id="groups_datalist">
                            <?php foreach ($groupsList as $g): ?>
                                <option value="<?php echo htmlspecialchars($g['name']); ?>">
                            <?php endforeach; ?>
                        </datalist>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Часов практики <span class="text-danger">*</span></label>
                            <input type="number" name="practice_hours" class="form-control" min="1" max="1000" value="72" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Срок сдачи графика <span class="text-danger">*</span></label>
                            <input type="date" name="deadline" class="form-control" value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Назначить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

