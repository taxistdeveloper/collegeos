<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_subjects');

$uchebni = new Uchebni();
$message = '';
$error = '';
$import_linked_teachers = [];
$import_missing_teachers = [];

function subjectPayloadFromPost()
{
    $category_code = sanitize($_POST['category_code'] ?? '');
    return [
        'name' => sanitize($_POST['name']),
        'code' => $category_code,
        'category_code' => $category_code,
        'category_name' => sanitize($_POST['category_name'] ?? ''),
        'item_number' => (int)($_POST['item_number'] ?? 1),
        'assessment' => sanitize($_POST['assessment'] ?? ''),
        'hours_sem1' => (int)($_POST['hours_sem1'] ?? 0),
        'hours_sem2' => (int)($_POST['hours_sem2'] ?? 0),
        'description' => sanitize($_POST['description'] ?? ''),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $teacher_ids = array_values(array_unique(array_map('intval', $_POST['teacher_ids'] ?? [])));
    $teacher_ids = array_filter($teacher_ids, function ($id) { return $id > 0; });

    if ($action === 'import_docx') {
        if (empty($_FILES['docx_file']['tmp_name']) || !is_uploaded_file($_FILES['docx_file']['tmp_name'])) {
            $error = 'Выберите файл .docx';
        } else {
            $orig = (string)($_FILES['docx_file']['name'] ?? '');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if ($ext !== 'docx') {
                $error = 'Нужен файл формата .docx';
            } else {
                $result = $uchebni->importSubjectsFromDocx($_FILES['docx_file']['tmp_name']);
                if (empty($result['success'])) {
                    $error = $result['error'] ?? 'Ошибка импорта';
                } else {
                    $parts = [];
                    $parts[] = 'Добавлено дисциплин: ' . (int)$result['added_subjects'];
                    if (!empty($result['skipped_subjects'])) {
                        $parts[] = 'уже есть (пропущено): ' . (int)$result['skipped_subjects'];
                    }
                    $message = implode('. ', $parts);
                    $import_linked_teachers = $result['linked_teachers'] ?? [];
                    $import_missing_teachers = $result['missing_teachers'] ?? [];
                    if (!empty($result['messages'])) {
                        $skipMsgs = array_values(array_filter($result['messages'], function ($m) {
                            return mb_stripos($m, 'Уже есть:') === 0;
                        }));
                        if ($skipMsgs) {
                            $message .= '. ' . implode('; ', array_slice($skipMsgs, 0, 15));
                            if (count($skipMsgs) > 15) {
                                $message .= '…';
                            }
                        }
                    }
                }
            }
        }
    }

    if ($action === 'add') {
        $id = $uchebni->addSubject(subjectPayloadFromPost());
        if ($id) {
            $uchebni->setSubjectTeachers($id, $teacher_ids);
            $message = 'Дисциплина добавлена!';
        } else {
            $error = 'Ошибка при добавлении';
        }
    }

    if ($action === 'edit') {
        $subject_id = (int)$_POST['subject_id'];
        $payload = subjectPayloadFromPost();
        $payload['is_active'] = isset($_POST['is_active']) ? 1 : 0;
        $ok = $uchebni->updateSubject($subject_id, $payload);
        if ($ok) {
            $uchebni->setSubjectTeachers($subject_id, $teacher_ids);
            $message = 'Обновлено!';
        } else {
            $error = 'Ошибка при обновлении';
        }
    }

    if ($action === 'delete') {
        $result = $uchebni->deleteSubject((int)($_POST['subject_id'] ?? 0));
        if (!empty($result['success'])) {
            $message = 'Дисциплина удалена';
        } else {
            $error = $result['error'] ?? 'Ошибка при удалении';
        }
    }

    if ($action === 'delete_selected') {
        $ids = array_map('intval', $_POST['subject_ids'] ?? []);
        if (empty($ids)) {
            $error = 'Выберите дисциплины для удаления';
        } else {
            $result = $uchebni->deleteSubjects($ids);
            if (!empty($result['deleted'])) {
                $message = 'Удалено: ' . (int)$result['deleted'];
            }
            if (!empty($result['errors'])) {
                $error = implode('; ', array_slice($result['errors'], 0, 8));
                if (count($result['errors']) > 8) {
                    $error .= '…';
                }
            } elseif (empty($result['deleted'])) {
                $error = 'Не удалось удалить выбранные дисциплины';
            }
        }
    }

    if ($action === 'delete_all') {
        $result = $uchebni->deleteAllSubjects();
        if (!empty($result['deleted'])) {
            $message = 'Удалено всех: ' . (int)$result['deleted'];
        }
        if (!empty($result['errors'])) {
            $error = implode('; ', array_slice($result['errors'], 0, 8));
            if (count($result['errors']) > 8) {
                $error .= '…';
            }
        } elseif (empty($result['deleted'])) {
            $error = empty($uchebni->getSubjects(false))
                ? 'Нет дисциплин для удаления'
                : 'Не удалось удалить дисциплины';
        }
    }
}

$subjects = $uchebni->getSubjects(false);
$subject_teachers_map = [];
foreach ($subjects as $s) {
    $subject_teachers_map[(int)$s['id']] = $uchebni->getSubjectTeachers((int)$s['id']);
}

$weeks1 = Uchebni::WEEKS_SEM1;
$weeks2 = Uchebni::WEEKS_SEM2;

$current_user = getCurrentUser();
$page_title = 'Дисциплины';
$page_subtitle = count($subjects) . ' записей';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
    <div class="d-flex gap-2">
        <button type="submit" form="bulkSubjectsForm" name="action" value="delete_selected" class="btn btn-outline-danger"
            onclick="return confirmBulkDeleteSelected();" <?php echo empty($subjects) ? 'disabled' : ''; ?>>
            <i class="bi bi-trash me-1"></i>Удалить выбранные
        </button>
        <form method="post" class="d-inline" onsubmit="return confirm('Удалить ВСЕ дисциплины? Это действие нельзя отменить.');">
            <input type="hidden" name="action" value="delete_all">
            <button type="submit" class="btn btn-outline-danger" <?php echo empty($subjects) ? 'disabled' : ''; ?>>
                <i class="bi bi-x-circle me-1"></i>Удалить все
            </button>
        </form>
    </div>
    <div class="d-flex gap-2">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importDocxModal">
            <i class="bi bi-file-earmark-word me-1"></i>Импорт из Word
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addSubjectModal">
            <i class="bi bi-plus-lg me-1"></i>Добавить дисциплину
        </button>
    </div>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show">
    <?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($import_linked_teachers)): ?>
<div class="alert alert-info alert-dismissible fade show">
    <div class="fw-semibold mb-1">Преподаватели из Word совпали со списком:</div>
    <ul class="mb-0 ps-3">
        <?php foreach ($import_linked_teachers as $fio): ?>
        <li><?php echo htmlspecialchars($fio); ?></li>
        <?php endforeach; ?>
    </ul>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($import_missing_teachers)): ?>
<div class="alert alert-warning alert-dismissible fade show">
    <div class="fw-semibold mb-1">Этих преподавателей нет в базе — добавьте их вручную:</div>
    <ul class="mb-2 ps-3">
        <?php foreach ($import_missing_teachers as $fio): ?>
        <li>Добавьте вот этого преподавателя в базу: <strong><?php echo htmlspecialchars($fio); ?></strong></li>
        <?php endforeach; ?>
    </ul>
    <a href="teachers.php" class="btn btn-sm btn-outline-warning">Открыть преподавателей</a>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <form method="post" id="bulkSubjectsForm">
    <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle text-center">
            <thead class="table-light">
                <tr>
                    <th rowspan="2" class="align-middle" style="width:2.5rem">
                        <input type="checkbox" class="form-check-input" id="selectAllSubjects" title="Выбрать все" <?php echo empty($subjects) ? 'disabled' : ''; ?>>
                    </th>
                    <th rowspan="2" class="align-middle" style="width:5rem">№</th>
                    <th rowspan="2" class="align-middle text-start">Наименование предмета</th>
                    <th rowspan="2" class="align-middle text-start">Ф.И.О. преподавателя</th>
                    <th colspan="2">Кол-во часов</th>
                    <th rowspan="2" class="align-middle" style="width:4rem"></th>
                </tr>
                <tr>
                    <th style="width:7rem">1 сем <?php echo (int)$weeks1; ?> нед</th>
                    <th style="width:7rem">2 сем <?php echo (int)$weeks2; ?> нед</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $prev_category_key = null;
                foreach ($subjects as $s):
                    $teachers = $subject_teachers_map[(int)$s['id']] ?? [];
                    $teacher_names = array_map(function ($t) {
                        return Uchebni::formatFio($t);
                    }, $teachers);
                    $category_header = Uchebni::formatCategoryHeader($s);
                    $category_key = $category_header !== '' ? $category_header : null;
                    if ($category_key !== null && $category_key !== $prev_category_key):
                        $prev_category_key = $category_key;
                ?>
                <tr class="table-secondary">
                    <td colspan="7" class="fw-semibold text-center">
                        <?php echo htmlspecialchars($category_header); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr class="<?php echo $s['is_active'] ? '' : 'table-secondary'; ?>">
                    <td>
                        <input type="checkbox" class="form-check-input subject-check" name="subject_ids[]" value="<?php echo (int)$s['id']; ?>">
                    </td>
                    <td><?php echo htmlspecialchars(Uchebni::formatSubjectNumber($s)); ?></td>
                    <td class="text-start">
                        <?php echo htmlspecialchars(Uchebni::formatSubjectTitle($s)); ?>
                        <?php if (!$s['is_active']): ?>
                            <span class="badge bg-secondary ms-1">неактивна</span>
                        <?php endif; ?>
                        <?php if (!empty($s['description'])): ?>
                            <br><small class="text-muted"><?php echo htmlspecialchars($s['description']); ?></small>
                        <?php endif; ?>
                    </td>
                    <td class="text-start small">
                        <?php if ($teacher_names): ?>
                            <?php echo htmlspecialchars(implode(', ', $teacher_names)); ?>
                            <?php if (!empty($s['pending_teacher'])): ?>
                                <div class="uchebni-pending-teacher mt-1">
                                    <span class="uchebni-pending-teacher__fio"><?php echo htmlspecialchars($s['pending_teacher']); ?></span>
                                    <span class="uchebni-pending-teacher__hint">
                                        <i class="bi bi-exclamation-triangle-fill"></i>
                                        нет в базе — добавьте
                                    </span>
                                </div>
                            <?php endif; ?>
                        <?php elseif (!empty($s['pending_teacher'])): ?>
                            <div class="uchebni-pending-teacher">
                                <span class="uchebni-pending-teacher__fio"><?php echo htmlspecialchars($s['pending_teacher']); ?></span>
                                <span class="uchebni-pending-teacher__hint">
                                    <i class="bi bi-exclamation-triangle-fill"></i>
                                    нет в базе — добавьте
                                </span>
                            </div>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td><?php echo (int)($s['hours_sem1'] ?? 0); ?></td>
                    <td><?php echo (int)($s['hours_sem2'] ?? 0); ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <button type="button" class="btn btn-outline-primary btn-edit-subject"
                                data-subject='<?php echo htmlspecialchars(json_encode($s, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                data-teachers='<?php echo htmlspecialchars(json_encode($teachers, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                title="Редактировать">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <button type="button" class="btn btn-outline-danger btn-delete-one"
                                data-id="<?php echo (int)$s['id']; ?>"
                                data-title="<?php echo htmlspecialchars(Uchebni::formatSubjectTitle($s), ENT_QUOTES); ?>"
                                title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($subjects)): ?>
                <tr><td colspan="7" class="text-center text-muted py-4">Дисциплины не добавлены</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    </form>
</div>

<form method="post" id="singleDeleteForm" class="d-none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="subject_id" id="singleDeleteId" value="">
</form>

<?php
$assessment_options = Uchebni::ASSESSMENT_TYPES;
?>

<div class="modal fade" id="importDocxModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content">
            <input type="hidden" name="action" value="import_docx">
            <div class="modal-header">
                <h5 class="modal-title">Импорт дисциплин из Word</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="small text-muted mb-3">
                    Загрузите файл по шаблону: таблица с колонками
                    «№», «Наименование предмета», «Ф.И.О. преподавателя», «Кол-во часов».
                    Блоки (ООД, ЖММ, КМ…) — отдельными строками.
                </p>
                <div class="mb-3">
                    <a href="subject.docx" class="link-primary" download>
                        <i class="bi bi-download me-1"></i>Скачать шаблон subject.docx
                    </a>
                </div>
                <div class="mb-2">
                    <label class="form-label">Файл .docx</label>
                    <input type="file" name="docx_file" class="form-control" accept=".docx,application/vnd.openxmlformats-officedocument.wordprocessingml.document" required>
                </div>
                <ul class="small text-muted mb-0 ps-3">
                    <li>ФИО есть в «Преподаватели» — привяжем к дисциплине</li>
                    <li>Нет в базе — не создаём, покажем: «Добавьте вот этого преподавателя в базу»</li>
                    <li>Дисциплина уже есть в том же блоке — пропустим («уже есть»)</li>
                </ul>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Импортировать</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="addSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="addSubjectForm">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Новая дисциплина</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Код блока</label>
                        <input type="text" name="category_code" class="form-control" placeholder="ООД" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Название блока</label>
                        <input type="text" name="category_name" class="form-control" placeholder="Общеобразовательные дисциплины">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">№ в блоке</label>
                        <input type="number" name="item_number" class="form-control" value="1" min="1">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Наименование предмета *</label>
                        <input type="text" name="name" class="form-control" required placeholder="Русский язык">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Форма контроля</label>
                        <select name="assessment" class="form-select">
                            <?php foreach ($assessment_options as $val => $label): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Часов · 1 сем (<?php echo (int)$weeks1; ?> нед)</label>
                        <input type="number" name="hours_sem1" class="form-control" value="0" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Часов · 2 сем (<?php echo (int)$weeks2; ?> нед)</label>
                        <input type="number" name="hours_sem2" class="form-control" value="0" min="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea name="description" class="form-control" rows="2"></textarea>
                </div>
                <?php
                $teacher_picker_prefix = '';
                include __DIR__ . '/includes/subject_teacher_picker.php';
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editSubjectModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="editSubjectForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="subject_id" id="edit_subject_id">
            <div class="modal-header">
                <h5 class="modal-title">Редактирование</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Код блока</label>
                        <input type="text" name="category_code" id="edit_sub_category_code" class="form-control" placeholder="ООД" maxlength="20">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Название блока</label>
                        <input type="text" name="category_name" id="edit_sub_category_name" class="form-control">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">№ в блоке</label>
                        <input type="number" name="item_number" id="edit_sub_item_number" class="form-control" min="1">
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-8">
                        <label class="form-label">Наименование предмета *</label>
                        <input type="text" name="name" id="edit_sub_name" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Форма контроля</label>
                        <select name="assessment" id="edit_sub_assessment" class="form-select">
                            <?php foreach ($assessment_options as $val => $label): ?>
                            <option value="<?php echo htmlspecialchars($val); ?>"><?php echo htmlspecialchars($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Часов · 1 сем (<?php echo (int)$weeks1; ?> нед)</label>
                        <input type="number" name="hours_sem1" id="edit_sub_hours_sem1" class="form-control" min="0">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Часов · 2 сем (<?php echo (int)$weeks2; ?> нед)</label>
                        <input type="number" name="hours_sem2" id="edit_sub_hours_sem2" class="form-control" min="0">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label">Описание</label>
                    <textarea name="description" id="edit_sub_desc" class="form-control" rows="2"></textarea>
                </div>
                <div class="form-check mb-3">
                    <input type="checkbox" name="is_active" id="edit_sub_active" class="form-check-input" value="1">
                    <label class="form-check-label" for="edit_sub_active">Активна</label>
                </div>
                <?php
                $teacher_picker_prefix = 'edit_';
                include __DIR__ . '/includes/subject_teacher_picker.php';
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
}

function formatTeacherFio(t) {
    return [t.last_name, t.first_name, t.middle_name].filter(Boolean).join(' ');
}

function initTeacherPicker(prefix) {
    const search = document.getElementById(prefix + 'teacher_search');
    const results = document.getElementById(prefix + 'teacher_results');
    const selectedWrap = document.getElementById(prefix + 'teacher_selected');
    const emptyHint = document.getElementById(prefix + 'teacher_empty');
    if (!search || !results || !selectedWrap) return null;

    const selected = new Map();
    let timer = null;

    function renderSelected() {
        selectedWrap.innerHTML = '';
        if (emptyHint) emptyHint.classList.toggle('d-none', selected.size > 0);

        selected.forEach(function(t, id) {
            const chip = document.createElement('span');
            chip.className = 'badge text-bg-primary d-inline-flex align-items-center gap-2 me-1 mb-1';
            chip.innerHTML = escapeHtml(formatTeacherFio(t))
                + '<button type="button" class="btn-close btn-close-white" style="font-size:.55rem" aria-label="Удалить"></button>'
                + '<input type="hidden" name="teacher_ids[]" value="' + escapeHtml(id) + '">';
            chip.querySelector('button').addEventListener('click', function() {
                selected.delete(String(id));
                renderSelected();
            });
            selectedWrap.appendChild(chip);
        });
    }

    function addTeacher(t) {
        selected.set(String(t.id), t);
        search.value = '';
        results.classList.add('d-none');
        results.innerHTML = '';
        renderSelected();
    }

    function setTeachers(list) {
        selected.clear();
        (list || []).forEach(function(t) {
            selected.set(String(t.id), t);
        });
        renderSelected();
    }

    function clearAll() {
        selected.clear();
        search.value = '';
        results.classList.add('d-none');
        results.innerHTML = '';
        renderSelected();
    }

    search.addEventListener('input', function() {
        clearTimeout(timer);
        const q = search.value.trim();
        if (q.length < 2) {
            results.innerHTML = '';
            results.classList.add('d-none');
            return;
        }
        timer = setTimeout(function() {
            fetch('api/search_teachers.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    const list = (data.teachers || []).filter(t => !selected.has(String(t.id)));
                    if (!data.success || !list.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено. Сначала добавьте преподавателя в разделе «Преподаватели».</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = list.map(t => {
                        const fio = formatTeacherFio(t);
                        const meta = t.user_login || '';
                        return '<button type="button" class="list-group-item list-group-item-action text-start"'
                            + ' data-id="' + escapeHtml(t.id) + '"'
                            + ' data-fio="' + escapeHtml(fio) + '"'
                            + ' data-last="' + escapeHtml(t.last_name || '') + '"'
                            + ' data-first="' + escapeHtml(t.first_name || '') + '"'
                            + ' data-middle="' + escapeHtml(t.middle_name || '') + '">'
                            + '<strong>' + escapeHtml(fio) + '</strong>'
                            + (meta ? '<br><small class="text-muted">' + escapeHtml(meta) + '</small>' : '')
                            + '</button>';
                    }).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('.list-group-item-action').forEach(btn => {
                        btn.addEventListener('click', function() {
                            addTeacher({
                                id: this.dataset.id,
                                last_name: this.dataset.last || '',
                                first_name: this.dataset.first || '',
                                middle_name: this.dataset.middle || ''
                            });
                        });
                    });
                })
                .catch(function() {
                    results.innerHTML = '<div class="list-group-item text-danger small">Ошибка поиска</div>';
                    results.classList.remove('d-none');
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!search.contains(e.target) && !results.contains(e.target)) {
            results.classList.add('d-none');
        }
    });

    renderSelected();
    return { setTeachers: setTeachers, clearAll: clearAll };
}

const addTeacherPicker = initTeacherPicker('');
const editTeacherPicker = initTeacherPicker('edit_');

function confirmBulkDeleteSelected() {
    const n = document.querySelectorAll('.subject-check:checked').length;
    if (!n) {
        alert('Выберите дисциплины для удаления');
        return false;
    }
    return confirm('Удалить выбранные дисциплины (' + n + ')?');
}

(function() {
    const selectAll = document.getElementById('selectAllSubjects');
    const checks = function() { return document.querySelectorAll('.subject-check'); };
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checks().forEach(function(cb) { cb.checked = selectAll.checked; });
        });
        document.addEventListener('change', function(e) {
            if (!e.target.classList.contains('subject-check')) return;
            const list = Array.from(checks());
            selectAll.checked = list.length > 0 && list.every(function(cb) { return cb.checked; });
            selectAll.indeterminate = list.some(function(cb) { return cb.checked; }) && !selectAll.checked;
        });
    }
})();

document.querySelectorAll('.btn-delete-one').forEach(function(btn) {
    btn.addEventListener('click', function() {
        const title = this.dataset.title || '';
        if (!confirm('Удалить дисциплину «' + title + '»?')) return;
        document.getElementById('singleDeleteId').value = this.dataset.id;
        document.getElementById('singleDeleteForm').submit();
    });
});

document.getElementById('addSubjectModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('addSubjectForm').reset();
    if (addTeacherPicker) addTeacherPicker.clearAll();
});

document.querySelectorAll('.btn-edit-subject').forEach(btn => {
    btn.addEventListener('click', function() {
        const s = JSON.parse(this.dataset.subject);
        const teachers = JSON.parse(this.dataset.teachers || '[]');
        document.getElementById('edit_subject_id').value = s.id;
        document.getElementById('edit_sub_category_code').value = s.category_code || s.code || '';
        document.getElementById('edit_sub_category_name').value = s.category_name || '';
        document.getElementById('edit_sub_item_number').value = s.item_number || 1;
        document.getElementById('edit_sub_name').value = s.name;
        document.getElementById('edit_sub_assessment').value = s.assessment || '';
        document.getElementById('edit_sub_hours_sem1').value = s.hours_sem1 || 0;
        document.getElementById('edit_sub_hours_sem2').value = s.hours_sem2 || 0;
        document.getElementById('edit_sub_desc').value = s.description || '';
        document.getElementById('edit_sub_active').checked = s.is_active == 1;
        if (editTeacherPicker) editTeacherPicker.setTeachers(teachers);
        new bootstrap.Modal(document.getElementById('editSubjectModal')).show();
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
