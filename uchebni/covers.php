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

$linked = $uchebni->getTeacherByUserId((int)$current_user['id']);
$linked_teacher_id = $linked ? (int)$linked['id'] : 0;

$message = '';
$error = '';

$teacher_id = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;
if ($is_teacher_session) {
    $teacher_id = $linked_teacher_id > 0 ? $linked_teacher_id : -1;
} elseif ($teacher_id <= 0 && $is_methodist) {
    $teachersTmp = $uchebni->getTeachers();
    if (!empty($teachersTmp)) {
        $teacher_id = (int)$teachersTmp[0]['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $period_id) {
    $action = $_POST['action'] ?? '';
    $post_teacher = !empty($_POST['cover_teacher_id']) ? (int)$_POST['cover_teacher_id'] : $teacher_id;
    if ($is_teacher_session) {
        $post_teacher = $linked_teacher_id;
    }

    if ($action === 'create') {
        if ($post_teacher <= 0) {
            $error = 'Не выбран преподаватель';
        } else {
            $result = $uchebni->createScheduleCover([
                'period_id' => $period_id,
                'cover_teacher_id' => $post_teacher,
                'lesson_date' => $_POST['lesson_date'] ?? '',
                'shift' => (int)($_POST['shift'] ?? 1),
                'pair_number' => (int)($_POST['pair_number'] ?? 0),
                'group_id' => (int)($_POST['group_id'] ?? 0),
                'subject_id' => (int)($_POST['subject_id'] ?? 0),
                'replaced_teacher_name' => sanitize($_POST['replaced_teacher_name'] ?? ''),
                'replaced_subject_name' => sanitize($_POST['replaced_subject_name'] ?? ''),
                'note' => sanitize($_POST['note'] ?? ''),
                'created_by' => (int)$current_user['id'],
            ]);
            if (!empty($result['ok'])) {
                $message = 'Входящая замена добавлена — часы пойдут вам';
                $teacher_id = $post_teacher;
            } else {
                $error = $result['error'] ?? 'Ошибка сохранения';
            }
        }
    }

    if ($action === 'delete') {
        $delId = (int)($_POST['id'] ?? 0);
        $ownerFilter = $is_methodist ? null : $linked_teacher_id;
        if ($uchebni->deleteScheduleCover($delId, $ownerFilter)) {
            $message = 'Замена удалена';
        } else {
            $error = 'Не удалось удалить';
        }
    }
}

$teachers = $uchebni->getTeachers();
$groups = $uchebni->getActiveGroups();
$subjects = $uchebni->getSubjects(true);

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-14 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-14 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d', strtotime('+30 days'));
}

$covers = [];
if ($period_id && $teacher_id > 0) {
    $covers = $uchebni->getScheduleCovers([
        'period_id' => $period_id,
        'teacher_id' => $teacher_id,
        'date_from' => $date_from,
        'date_to' => $date_to,
    ]);
}

$selected_teacher_name = '';
foreach ($teachers as $t) {
    if ((int)$t['id'] === $teacher_id) {
        $selected_teacher_name = Uchebni::formatFio($t);
        break;
    }
}

$page_title = 'Входящие замены';
$page_subtitle = 'Чужая пара, которую вы взяли — часы по вашей дисциплине';
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
<?php elseif ($teacher_id <= 0): ?>
    <div class="alert alert-warning">Аккаунт не привязан к карточке преподавателя</div>
<?php else: ?>

    <form method="get" class="card mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <?php if ($is_methodist): ?>
                    <div class="col-md-4">
                        <label class="form-label">Преподаватель</label>
                        <select name="teacher_id" class="form-select" onchange="this.form.submit()">
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?php echo (int)$t['id']; ?>" <?php echo $teacher_id === (int)$t['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(Uchebni::formatFio($t)); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php else: ?>
                    <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
                    <div class="col-md-4">
                        <label class="form-label">Преподаватель</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($selected_teacher_name); ?>" readonly>
                    </div>
                <?php endif; ?>
                <div class="col-md-3">
                    <label class="form-label">С</label>
                    <input type="date" name="date_from" class="form-control" value="<?php echo htmlspecialchars($date_from); ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-3">
                    <label class="form-label">По</label>
                    <input type="date" name="date_to" class="form-control" value="<?php echo htmlspecialchars($date_to); ?>" onchange="this.form.submit()">
                </div>
                <div class="col-md-2 d-grid">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#modalCover">
                        <i class="bi bi-plus-lg me-1"></i>Добавить
                    </button>
                </div>
            </div>
            <div class="form-text mt-2">
                Это не обычная замена из расписания, а разовая «чужая» пара в ваш факт часов (как covers в нагрузке).
            </div>
        </div>
    </form>

    <div class="card">
        <div class="card-header d-flex justify-content-between">
            <span><i class="bi bi-person-plus me-2"></i>Список</span>
            <span class="text-muted small"><?php echo count($covers); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Дата</th>
                        <th>Пара</th>
                        <th>Группа</th>
                        <th>Ваша дисциплина</th>
                        <th>Вместо кого</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($covers as $c): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($c['lesson_date']); ?></td>
                            <td>
                                <?php echo (int)$c['pair_number']; ?> пара
                                <div class="form-text mb-0"><?php echo htmlspecialchars(Uchebni::SHIFT_NAMES[(int)$c['shift']] ?? ''); ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($c['group_code'] ?: ($c['group_name'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars($c['subject_name'] ?? ''); ?></td>
                            <td>
                                <?php echo htmlspecialchars($c['replaced_teacher_name'] ?: '—'); ?>
                                <?php if (!empty($c['replaced_subject_name'])): ?>
                                    <div class="form-text mb-0"><?php echo htmlspecialchars($c['replaced_subject_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <form method="post" class="d-inline" onsubmit="return confirm('Удалить входящую замену?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?php echo (int)$c['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Удалить</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($covers)): ?>
                        <tr>
                            <td colspan="6" class="text-center text-muted py-4">Записей нет</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="modal fade" id="modalCover" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered modal-lg">
            <form class="modal-content" method="post">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="cover_teacher_id" value="<?php echo (int)$teacher_id; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Входящая замена</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Дата</label>
                            <input type="date" name="lesson_date" class="form-control" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Смена</label>
                            <select name="shift" class="form-select">
                                <option value="1">I смена</option>
                                <option value="2">II смена</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Пара</label>
                            <select name="pair_number" class="form-select" required>
                                <?php for ($p = 1; $p <= 6; $p++): ?>
                                    <option value="<?php echo $p; ?>"><?php echo $p; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Группа</label>
                            <select name="group_id" class="form-select" required>
                                <option value="">—</option>
                                <?php foreach ($groups as $g): ?>
                                    <option value="<?php echo (int)$g['id']; ?>">
                                        <?php echo htmlspecialchars($g['name'] ?: ($g['code'] ?? ('#' . $g['id']))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ваша дисциплина (часы вам)</label>
                            <select name="subject_id" class="form-select" required>
                                <option value="">—</option>
                                <?php foreach ($subjects as $s): ?>
                                    <option value="<?php echo (int)$s['id']; ?>">
                                        <?php echo htmlspecialchars($s['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Вместо кого (ФИО)</label>
                            <input name="replaced_teacher_name" class="form-control" placeholder="необязательно">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Вместо какой дисциплины</label>
                            <input name="replaced_subject_name" class="form-control" placeholder="необязательно">
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

<?php require_once 'includes/footer.php'; ?>
