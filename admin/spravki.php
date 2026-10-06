<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/Spravka.php';
require_once '../classes/SpravkaTemplate.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$spravka = new Spravka();
$template = new SpravkaTemplate();
$user = new User();
$group = new Group();
$db = getDB();

$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $data = [
                'student_id' => (int)$_POST['student_id'],
                'type' => sanitize($_POST['type']),
                'template_id' => !empty($_POST['template_id']) ? (int)$_POST['template_id'] : null,
                'issued_at' => sanitize($_POST['issued_at']),
                'issued_by' => $_SESSION['admin_id'] ?? null,
                'note' => !empty($_POST['note']) ? sanitize($_POST['note']) : null,
            ];
            
            if ($spravka->create($data)) {
                $message = 'Справка успешно создана!';
            } else {
                $error = 'Ошибка при создании справки';
            }
            break;
            
        case 'edit':
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
            $id = (int)$_POST['spravka_id'];
            if ($spravka->delete($id)) {
                $message = 'Справка успешно удалена!';
            } else {
                $error = 'Ошибка при удалении справки';
            }
            break;
    }
}

// Получение данных
$filters = [
    'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
    'type' => isset($_GET['type']) ? $_GET['type'] : '',
    'date_from' => isset($_GET['date_from']) ? $_GET['date_from'] : '',
    'date_to' => isset($_GET['date_to']) ? $_GET['date_to'] : '',
];

$spravki = $spravka->getList($filters);
$types = Spravka::getTypes();
$templates = $template->getAll(null, true);

// Получение всех студентов для выпадающего списка
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

// Если редактирование
$edit_spravka = null;
if (isset($_GET['edit'])) {
    $edit_spravka = $spravka->getById((int)$_GET['edit']);
}

// Для header
$page_title = 'Управление справками';
$active_page = 'spravki';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Справки</h1>
        <p class="page-subtitle">Управление выданными справками</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSpravkaModal">
            <i class="bi bi-file-earmark-plus-fill"></i>
            <span>Добавить</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Search Card -->
<div class="card">
    <div class="card-body" style="padding: 1rem;">
        <form method="GET" action="spravki.php" class="d-flex gap-3 align-items-center" style="flex-wrap: wrap;">
            <div style="flex: 1; min-width: 200px;">
                <div class="input-group">
                    <span class="input-group-text" style="background: var(--content-bg); border-color: var(--border-color);">
                        <i class="bi bi-search text-secondary"></i>
                    </span>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Поиск по ФИО, ИИН..." 
                           value="<?php echo htmlspecialchars($filters['search']); ?>"
                           style="border-color: var(--border-color);">
                </div>
            </div>
            <div style="min-width: 200px;">
                <select class="form-select" name="type" style="border-color: var(--border-color);">
                    <option value="">Все типы</option>
                    <?php foreach ($types as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $filters['type'] === $k ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div style="min-width: 150px;">
                <input type="date" class="form-control" name="date_from" 
                       placeholder="Дата с" 
                       value="<?php echo htmlspecialchars($filters['date_from']); ?>"
                       style="border-color: var(--border-color);">
            </div>
            <div style="min-width: 150px;">
                <input type="date" class="form-control" name="date_to" 
                       placeholder="Дата по" 
                       value="<?php echo htmlspecialchars($filters['date_to']); ?>"
                       style="border-color: var(--border-color);">
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>
                Найти
            </button>
            <?php if ($filters['search'] || $filters['type'] || $filters['date_from'] || $filters['date_to']): ?>
                <a href="spravki.php" class="btn btn-outline">
                    <i class="bi bi-x-circle"></i>
                    Сбросить
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Spravki Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-file-earmark-text"></i>
            Список справок
        </h2>
        <span class="badge badge-primary"><?php echo count($spravki); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>ИИН</th>
                        <th>Группа</th>
                        <th>Тип справки</th>
                        <th>Дата выдачи</th>
                        <th>Выдал</th>
                        <th>Примечание</th>
                        <th style="width: 180px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($spravki as $row): 
                        $fio = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
                        $issuer_name = trim(($row['issuer_first'] ?? '') . ' ' . ($row['issuer_last'] ?? ''));
                    ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500;">
                                    <?php echo htmlspecialchars($fio); ?>
                                </div>
                            </td>
                            <td>
                                <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                    <?php echo htmlspecialchars($row['iin'] ?? '—'); ?>
                                </code>
                            </td>
                            <td><?php echo htmlspecialchars($row['group_name'] ?? '—'); ?></td>
                            <td>
                                <span class="badge badge-secondary">
                                    <?php echo htmlspecialchars($types[$row['type']] ?? $row['type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php echo $row['issued_at'] ? date('d.m.Y', strtotime($row['issued_at'])) : '—'; ?>
                            </td>
                            <td>
                                <span style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars($issuer_name ?: '—'); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars(mb_substr($row['note'] ?? '', 0, 50)); ?><?php echo mb_strlen($row['note'] ?? '') > 50 ? '…' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a href="../cos/spravki_print.php?id=<?php echo (int)$row['id']; ?>" 
                                       class="btn btn-icon btn-sm btn-outline" 
                                       title="Печать" 
                                       target="_blank">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <button class="btn btn-icon btn-sm btn-outline" 
                                            title="Редактировать" 
                                            onclick="editSpravka(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" 
                                            title="Удалить" 
                                            style="color: var(--danger);"
                                            onclick="deleteSpravka(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($fio)); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($spravki)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Справки не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Spravka Modal -->
<div class="modal fade" id="addSpravkaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-plus-fill me-2"></i>Добавить справку</h5>
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
                                    <option value="<?php echo $student['id']; ?>" 
                                            data-iin="<?php echo htmlspecialchars($student['iin'] ?? ''); ?>"
                                            data-group="<?php echo htmlspecialchars($student['group_name'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($student_fio); ?> 
                                        <?php if ($student['iin']): ?>
                                            (<?php echo htmlspecialchars($student['iin']); ?>)
                                        <?php endif; ?>
                                        <?php if ($student['group_name']): ?>
                                            — <?php echo htmlspecialchars($student['group_name']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип справки <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="add_type" required>
                                <option value="">Выберите тип</option>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>">
                                        <?php echo htmlspecialchars($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Шаблон</label>
                            <select class="form-select" name="template_id" id="add_template_id">
                                <option value="">Без шаблона</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo $tpl['id']; ?>" data-type="<?php echo htmlspecialchars($tpl['type']); ?>">
                                        <?php echo htmlspecialchars($tpl['name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Дата выдачи <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="issued_at" 
                                   value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Примечание</label>
                            <textarea class="form-control" name="note" rows="3" 
                                      placeholder="Дополнительная информация о справке"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Добавить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Spravka Modal -->
<div class="modal fade" id="editSpravkaModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="spravka_id" id="edit_spravka_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать справку</h5>
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
                                    <option value="<?php echo $student['id']; ?>">
                                        <?php echo htmlspecialchars($student_fio); ?> 
                                        <?php if ($student['iin']): ?>
                                            (<?php echo htmlspecialchars($student['iin']); ?>)
                                        <?php endif; ?>
                                        <?php if ($student['group_name']): ?>
                                            — <?php echo htmlspecialchars($student['group_name']); ?>
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип справки <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="edit_type" required>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>">
                                        <?php echo htmlspecialchars($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Шаблон</label>
                            <select class="form-select" name="template_id" id="edit_template_id">
                                <option value="">Без шаблона</option>
                                <?php foreach ($templates as $tpl): ?>
                                    <option value="<?php echo $tpl['id']; ?>" data-type="<?php echo htmlspecialchars($tpl['type']); ?>">
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
                            <textarea class="form-control" name="note" id="edit_note" rows="3" 
                                      placeholder="Дополнительная информация о справке"></textarea>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="spravka_id" id="delete_spravka_id">
</form>

<script>
    function editSpravka(spravka) {
        document.getElementById('edit_spravka_id').value = spravka.id;
        document.getElementById('edit_student_id').value = spravka.student_id;
        document.getElementById('edit_type').value = spravka.type;
        document.getElementById('edit_template_id').value = spravka.template_id || '';
        document.getElementById('edit_issued_at').value = spravka.issued_at || '';
        document.getElementById('edit_note').value = spravka.note || '';
        
        new bootstrap.Modal(document.getElementById('editSpravkaModal')).show();
    }
    
    function deleteSpravka(spravkaId, fio) {
        if (confirm('Вы уверены, что хотите удалить справку для "' + fio + '"?')) {
            document.getElementById('delete_spravka_id').value = spravkaId;
            document.getElementById('deleteForm').submit();
        }
    }
    
    // Фильтрация шаблонов по типу при добавлении
    document.getElementById('add_type')?.addEventListener('change', function() {
        const type = this.value;
        const templateSelect = document.getElementById('add_template_id');
        if (templateSelect) {
            Array.from(templateSelect.options).forEach(option => {
                if (option.value === '' || option.dataset.type === type) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        }
    });
    
    // Фильтрация шаблонов по типу при редактировании
    document.getElementById('edit_type')?.addEventListener('change', function() {
        const type = this.value;
        const templateSelect = document.getElementById('edit_template_id');
        if (templateSelect) {
            Array.from(templateSelect.options).forEach(option => {
                if (option.value === '' || option.dataset.type === type) {
                    option.style.display = '';
                } else {
                    option.style.display = 'none';
                }
            });
        }
    });
</script>

<?php include 'includes/admin_footer.php'; ?>
