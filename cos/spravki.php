<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Spravka.php';
require_once '../classes/SpravkaTemplate.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['cos']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_spravki');

$spravka = new Spravka();
$template = new SpravkaTemplate();
$db = getDB();
$current_user = getCurrentUser();

$message = '';
$error = '';

$can_add = hasPermission('add_spravki') || hasPermission('issue_spravki');
$can_edit = hasPermission('edit_spravki');
$can_delete = hasPermission('delete_spravki');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add':
            if (!$can_add) {
                header('Location: ../unauthorized.php');
                exit;
            }
            $data = [
                'student_id' => (int)$_POST['student_id'],
                'type' => sanitize($_POST['type']),
                'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
                'issued_at' => sanitize($_POST['issued_at']),
                'issued_by' => $_SESSION['user_id'] ?? null,
                'note' => !empty($_POST['note']) ? sanitize($_POST['note']) : null,
            ];

            if ($spravka->create($data)) {
                $message = 'Справка успешно создана!';
            } else {
                $error = 'Ошибка при создании справки';
            }
            break;

        case 'edit':
            if (!$can_edit) {
                header('Location: ../unauthorized.php');
                exit;
            }
            $id = (int)$_POST['spravka_id'];
            $data = [
                'student_id' => (int)$_POST['student_id'],
                'type' => sanitize($_POST['type']),
                'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
                'issued_at' => sanitize($_POST['issued_at']),
                'note' => !empty($_POST['note']) ? sanitize($_POST['note']) : null,
            ];

            if ($spravka->update($id, $data)) {
                $message = 'Справка успешно обновлена!';
            } else {
                $error = 'Ошибка при обновлении справки';
            }
            break;

        case 'delete':
            if (!$can_delete) {
                header('Location: ../unauthorized.php');
                exit;
            }
            $id = (int)$_POST['spravka_id'];
            if ($spravka->delete($id)) {
                $message = 'Справка успешно удалена!';
            } else {
                $error = 'Ошибка при удалении справки';
            }
            break;
    }
}

$filters = [
    'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
    'type' => isset($_GET['type']) ? $_GET['type'] : '',
    'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : '',
    'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : '',
];

$spravki = $spravka->getList($filters);
$types = Spravka::getTypes();
$templates = $template->getAll(null, true);

$all_students = [];
$students_sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin, g.name as group_name 
                 FROM students s 
                 LEFT JOIN `groups` g ON s.group_id = g.id 
                 ORDER BY s.last_name, s.first_name, s.middle_name 
                 LIMIT 1000";
$students_result = $db->query($students_sql);
if ($students_result) {
    $all_students = $students_result->fetch_all(MYSQLI_ASSOC);
}

$page_title = 'Справки';
require_once 'includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-0"><i class="bi bi-file-earmark-text me-2"></i>Справки</h1>
        <p class="text-muted small mb-0">Учёт выданных справок</p>
    </div>
    <?php if ($can_add): ?>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSpravkaModal">
            <i class="bi bi-plus-lg me-1"></i>Добавить
        </button>
    <?php endif; ?>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="spravki.php" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small text-muted">Поиск</label>
                <input type="text" class="form-control" name="search" placeholder="ФИО, ИИН…"
                    value="<?php echo htmlspecialchars($filters['search']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">Тип</label>
                <select class="form-select" name="type">
                    <option value="">Все</option>
                    <?php foreach ($types as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $filters['type'] === $k ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">С даты</label>
                <input type="date" class="form-control" name="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small text-muted">По дату</label>
                <input type="date" class="form-control" name="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
            </div>
            <div class="col-md-2 d-flex gap-2">
                <button type="submit" class="btn btn-primary flex-grow-1">Найти</button>
                <?php if ($filters['search'] || $filters['type'] || $filters['date_from'] || $filters['date_to']): ?>
                    <a href="spravki.php" class="btn btn-outline-secondary">Сброс</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span>Список</span>
        <span class="badge bg-primary"><?php echo count($spravki); ?></span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>Студент</th>
                        <th>ИИН</th>
                        <th>Группа</th>
                        <th>Тип</th>
                        <th>Дата</th>
                        <th>Выдал</th>
                        <th>Примечание</th>
                        <th style="width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spravki as $row):
                        $fio = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
                        $issuer_name = trim(($row['issuer_first'] ?? '') . ' ' . ($row['issuer_last'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fio); ?></td>
                            <td><code><?php echo htmlspecialchars($row['iin'] ?? '—'); ?></code></td>
                            <td><?php echo htmlspecialchars($row['group_name'] ?? '—'); ?></td>
                            <td><span class="badge text-bg-secondary"><?php echo htmlspecialchars($types[$row['type']] ?? $row['type']); ?></span></td>
                            <td><?php echo $row['issued_at'] ? date('d.m.Y', strtotime($row['issued_at'])) : '—'; ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars($issuer_name ?: '—'); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars(mb_substr($row['note'] ?? '', 0, 50)); ?><?php echo mb_strlen($row['note'] ?? '') > 50 ? '…' : ''; ?></td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <a href="spravki_print.php?id=<?php echo (int)$row['id']; ?>" class="btn btn-outline-secondary" title="Печать" target="_blank" rel="noopener">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <?php if ($can_edit): ?>
                                        <button type="button" class="btn btn-outline-primary" title="Редактировать"
                                            onclick="editSpravka(<?php echo htmlspecialchars(json_encode($row), ENT_NOQUOTES, 'UTF-8'); ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                    <?php endif; ?>
                                    <?php if ($can_delete): ?>
                                        <button type="button" class="btn btn-outline-danger" title="Удалить"
                                            onclick="deleteSpravka(<?php echo (int)$row['id']; ?>, '<?php echo htmlspecialchars(addslashes($fio), ENT_QUOTES); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($spravki)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-5">Справки не найдены</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php if ($can_add): ?>
<div class="modal fade" id="addSpravkaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить справку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Студент <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" id="add_student_id" required>
                                <option value="">Выберите студента</option>
                                <?php foreach ($all_students as $student):
                                    $student_fio = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                                    ?>
                                    <option value="<?php echo (int)$student['id']; ?>">
                                        <?php echo htmlspecialchars($student_fio); ?>
                                        <?php if (!empty($student['iin'])): ?>(<?php echo htmlspecialchars($student['iin']); ?>)<?php endif; ?>
                                        <?php if (!empty($student['group_name'])): ?> — <?php echo htmlspecialchars($student['group_name']); ?><?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип справки <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="add_type" required>
                                <option value="">Выберите тип</option>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Шаблон</label>
                            <select class="form-select" name="template_id" id="add_template_id">
                                <option value="">Без шаблона</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo (int)$tpl['id']; ?>" data-type="<?php echo htmlspecialchars($tpl['type']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Дата выдачи <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="issued_at" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Примечание</label>
                            <textarea class="form-control" name="note" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_edit): ?>
<div class="modal fade" id="editSpravkaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="spravka_id" id="edit_spravka_id">
                <div class="modal-header">
                    <h5 class="modal-title">Редактировать справку</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">Студент <span class="text-danger">*</span></label>
                            <select class="form-select" name="student_id" id="edit_student_id" required>
                                <option value="">Выберите студента</option>
                                <?php foreach ($all_students as $student):
                                    $student_fio = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                                    ?>
                                    <option value="<?php echo (int)$student['id']; ?>">
                                        <?php echo htmlspecialchars($student_fio); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип справки <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="edit_type" required>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>"><?php echo htmlspecialchars($v); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Шаблон</label>
                            <select class="form-select" name="template_id" id="edit_template_id">
                                <option value="">Без шаблона</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo (int)$tpl['id']; ?>" data-type="<?php echo htmlspecialchars($tpl['type']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Дата выдачи <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="issued_at" id="edit_issued_at" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Примечание</label>
                            <textarea class="form-control" name="note" id="edit_note" rows="3"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_delete): ?>
<form id="deleteForm" method="POST" class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="spravka_id" id="delete_spravka_id">
</form>
<?php endif; ?>

<script>
    <?php if ($can_edit): ?>
    function editSpravka(spravka) {
        document.getElementById('edit_spravka_id').value = spravka.id;
        document.getElementById('edit_student_id').value = spravka.student_id;
        document.getElementById('edit_type').value = spravka.type;
        document.getElementById('edit_template_id').value = spravka.template_id || '';
        document.getElementById('edit_issued_at').value = (spravka.issued_at || '').substring(0, 10);
        document.getElementById('edit_note').value = spravka.note || '';
        new bootstrap.Modal(document.getElementById('editSpravkaModal')).show();
    }
    <?php endif; ?>

    <?php if ($can_delete): ?>
    function deleteSpravka(spravkaId, fio) {
        if (confirm('Удалить справку для «' + fio + '»?')) {
            document.getElementById('delete_spravka_id').value = spravkaId;
            document.getElementById('deleteForm').submit();
        }
    }
    <?php endif; ?>

    <?php if ($can_add): ?>
    document.getElementById('add_type')?.addEventListener('change', function() {
        const type = this.value;
        const templateSelect = document.getElementById('add_template_id');
        if (templateSelect) {
            Array.from(templateSelect.options).forEach(option => {
                if (option.value === '' || option.dataset.type === type) {
                    option.hidden = false;
                } else {
                    option.hidden = true;
                }
            });
        }
    });
    <?php endif; ?>

    <?php if ($can_edit): ?>
    document.getElementById('edit_type')?.addEventListener('change', function() {
        const type = this.value;
        const templateSelect = document.getElementById('edit_template_id');
        if (templateSelect) {
            Array.from(templateSelect.options).forEach(option => {
                if (option.value === '' || option.dataset.type === type) {
                    option.hidden = false;
                } else {
                    option.hidden = true;
                }
            });
        }
    });
    <?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
