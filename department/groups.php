<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../classes/Group.php';
require_once '../classes/User.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkRole(['department_head']);

$departmentService = new Department();
$groupService = new Group();
$userService = new User();
$current_user = getCurrentUser();
$dept = $departmentService->getDepartmentByHead((int)$current_user['id']);
$message = '';
$error = '';

$members = $dept ? $departmentService->getMembers((int)$dept['id']) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dept) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_existing') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId <= 0) {
            $error = 'Выберите группу';
        } else {
            $result = $departmentService->attachGroup($groupId, (int)$dept['id']);
            if ($result === true) {
                $message = 'Группа добавлена в отделение';
            } else {
                $error = $result;
            }
        }
    }

    if ($action === 'add') {
        $data = [
            'name' => sanitize($_POST['name'] ?? ''),
            'code' => sanitize($_POST['code'] ?? ''),
            'specialty' => sanitize($_POST['specialty'] ?? ''),
            'qualification' => sanitize($_POST['qualification'] ?? ''),
            'curator_id' => !empty($_POST['curator_id']) ? (int)$_POST['curator_id'] : null,
            'course' => sanitize($_POST['course'] ?? '1'),
            'language' => sanitize($_POST['language'] ?? 'kaz'),
            'study_form' => sanitize($_POST['study_form'] ?? 'full_time'),
            'study_duration' => sanitize($_POST['study_duration'] ?? ''),
            'start_date' => $_POST['start_date'] ?? date('Y-m-d'),
            'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
            'arrival_date' => !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : null,
            'enrollment_order_number' => sanitize($_POST['enrollment_order_number'] ?? ''),
            'max_students' => (int)($_POST['max_students'] ?? 25),
            'description' => sanitize($_POST['description'] ?? ''),
            'arrival_from' => '',
            'education_type' => '',
            'residence_type' => '',
        ];

        if ($data['name'] === '' || $data['code'] === '') {
            $error = 'Укажите название и код группы';
        } elseif (!$groupService->isCodeUnique($data['code'])) {
            $existing = $groupService->getGroupByCode($data['code']);
            if ($existing && !empty($existing['department_name'])) {
                $error = 'Группа с таким кодом уже добавлена в отделение «' . $existing['department_name'] . '»';
            } elseif ($existing && !empty($existing['department_id'])) {
                $error = 'Группа с таким кодом уже добавлена в другое отделение';
            } else {
                $error = 'Группа с таким кодом уже существует';
            }
        } else {
            try {
                if ($groupService->createGroup($data)) {
                    $newId = (int)getDB()->getLastInsertId();
                    if ($newId > 0) {
                        $departmentService->setGroupDepartment($newId, (int)$dept['id']);
                    }
                    $message = 'Группа создана';
                } else {
                    $error = 'Ошибка создания группы';
                }
            } catch (Throwable $e) {
                $error = 'Ошибка создания группы';
            }
        }
    }

    if ($action === 'edit') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if (!$departmentService->ownsGroup((int)$dept['id'], $groupId)) {
            $error = 'Группа не принадлежит вашему отделению';
        } else {
            $existing = $groupService->getGroupById($groupId);
            $data = [
                'name' => sanitize($_POST['name'] ?? ''),
                'code' => sanitize($_POST['code'] ?? ''),
                'specialty' => sanitize($_POST['specialty'] ?? ''),
                'qualification' => sanitize($_POST['qualification'] ?? ($existing['qualification'] ?? '')),
                'curator_id' => !empty($_POST['curator_id']) ? (int)$_POST['curator_id'] : null,
                'course' => sanitize($_POST['course'] ?? ($existing['course'] ?? '1')),
                'language' => sanitize($_POST['language'] ?? ($existing['language'] ?? 'kaz')),
                'study_form' => sanitize($_POST['study_form'] ?? ($existing['study_form'] ?? 'full_time')),
                'study_duration' => sanitize($_POST['study_duration'] ?? ($existing['study_duration'] ?? '')),
                'start_date' => $_POST['start_date'] ?? ($existing['start_date'] ?? date('Y-m-d')),
                'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : ($existing['end_date'] ?? null),
                'arrival_date' => !empty($_POST['arrival_date']) ? $_POST['arrival_date'] : ($existing['arrival_date'] ?? null),
                'enrollment_order_number' => sanitize($_POST['enrollment_order_number'] ?? ($existing['enrollment_order_number'] ?? '')),
                'max_students' => (int)($_POST['max_students'] ?? ($existing['max_students'] ?? 25)),
                'description' => sanitize($_POST['description'] ?? ($existing['description'] ?? '')),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'arrival_from' => $existing['arrival_from'] ?? '',
                'education_type' => $existing['education_type'] ?? '',
                'residence_type' => $existing['residence_type'] ?? '',
            ];
            if (!$groupService->isCodeUnique($data['code'], $groupId)) {
                $error = 'Группа с таким кодом уже существует';
            } elseif ($groupService->updateGroup($groupId, $data)) {
                $message = 'Группа обновлена';
            } else {
                $error = 'Ошибка обновления группы';
            }
        }
    }

    if ($action === 'remove') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if (!$departmentService->ownsGroup((int)$dept['id'], $groupId)) {
            $error = 'Группа не принадлежит вашему отделению';
        } elseif ($departmentService->setGroupDepartment($groupId, null)) {
            $message = 'Группа убрана из отделения';
        } else {
            $error = 'Не удалось убрать группу';
        }
    }
}

$groups = $dept ? $departmentService->getGroups((int)$dept['id']) : [];
$groupIds = array_map(static function ($g) {
    return (int)$g['id'];
}, $groups);

$candidates = [];
$occupied = [];
if ($dept) {
    foreach ($groupService->getAllGroups() as $g) {
        $gid = (int)$g['id'];
        if (in_array($gid, $groupIds, true)) {
            continue;
        }
        if (!empty($g['department_id']) && (int)$g['department_id'] !== (int)$dept['id']) {
            $occupied[] = $g;
            continue;
        }
        $candidates[] = $g;
    }
}

$page_title = 'Группы отделения';
$page_subtitle = $dept ? $dept['name'] : 'Сначала создайте отделение';
include 'includes/header.php';
?>

<?php if (!$dept): ?>
    <div class="card">
        <div class="card-body dept-empty">
            <p class="mb-3">Сначала создайте отделение.</p>
            <a href="department.php" class="btn btn-primary">Создать отделение</a>
        </div>
    </div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="dept-toolbar">
    <div class="dept-search">
        <i class="bi bi-search"></i>
        <input type="search" id="groupsTableSearch" class="form-control" placeholder="Поиск по названию, коду, курсу…" autocomplete="off">
    </div>
    <div class="dept-toolbar-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExistingModal">
            <i class="bi bi-folder-check me-1"></i>Добавить из портала
        </button>
        <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#addGroupModal">
            <i class="bi bi-folder-plus me-1"></i>Создать группу
        </button>
    </div>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="groupsTable">
                <thead>
                    <tr>
                        <th>Группа</th>
                        <th>Код</th>
                        <th>Отделение</th>
                        <th>Курс</th>
                        <th>Куратор</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g):
                        $curator = trim(($g['curator_last_name'] ?? '') . ' ' . ($g['curator_first_name'] ?? '') . ' ' . ($g['curator_middle_name'] ?? ''));
                        $rowSearch = mb_strtolower(trim(($g['name'] ?? '') . ' ' . ($g['code'] ?? '') . ' ' . ($g['specialty'] ?? '') . ' ' . ($g['course'] ?? '') . ' ' . $curator . ' ' . ($dept['name'] ?? '')));
                    ?>
                        <tr data-search="<?php echo htmlspecialchars($rowSearch, ENT_QUOTES); ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($g['name']); ?></strong>
                                <?php if (!empty($g['specialty'])): ?>
                                    <div class="small text-muted"><?php echo htmlspecialchars($g['specialty']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($g['code']); ?></code></td>
                            <td>
                                <span class="badge text-bg-primary"><?php echo htmlspecialchars($dept['name']); ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($g['course'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($curator !== '' ? $curator : '—'); ?></td>
                            <td>
                                <span class="badge <?php echo !empty($g['is_active']) ? 'text-bg-success' : 'text-bg-secondary'; ?>">
                                    <?php echo !empty($g['is_active']) ? 'Активна' : 'Архив'; ?>
                                </span>
                                <?php if (isGraduatingGroup($g)): ?>
                                    <span class="badge text-bg-warning">Выпускная группа</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <button class="btn btn-sm btn-outline-secondary"
                                        onclick='editGroup(<?php echo htmlspecialchars(json_encode($g, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)'>
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Убрать группу из отделения?');">
                                    <input type="hidden" name="action" value="remove">
                                    <input type="hidden" name="group_id" value="<?php echo (int)$g['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($groups)): ?>
                        <tr id="groupsEmptyRow">
                            <td colspan="7" class="dept-empty">Групп пока нет — добавьте из портала</td>
                        </tr>
                    <?php else: ?>
                        <tr id="groupsNoMatchRow" class="d-none">
                            <td colspan="7" class="dept-empty">Ничего не найдено</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addExistingModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-scrollable">
        <div class="modal-content">
            <form method="POST" id="addExistingForm">
                <input type="hidden" name="action" value="add_existing">
                <input type="hidden" name="group_id" id="picker_group_id" value="">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить группу из портала</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div id="addExistingError" class="alert alert-danger d-none mb-3" role="alert"></div>
                    <?php if (empty($candidates) && empty($occupied)): ?>
                        <div class="dept-picker-empty">Нет групп в портале</div>
                    <?php else: ?>
                        <div class="dept-picker" id="groupPicker">
                            <div class="dept-picker-search">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="search" class="form-control" id="groupPickerSearch"
                                           placeholder="Название, код, специальность…" autocomplete="off">
                                </div>
                            </div>
                            <div class="dept-picker-list" id="groupPickerList">
                                <?php foreach ($candidates as $c):
                                    $curator = trim(($c['curator_last_name'] ?? '') . ' ' . ($c['curator_first_name'] ?? '') . ' ' . ($c['curator_middle_name'] ?? ''));
                                    $search = mb_strtolower(trim(($c['name'] ?? '') . ' ' . ($c['code'] ?? '') . ' ' . ($c['specialty'] ?? '') . ' ' . ($c['course'] ?? '') . ' ' . $curator));
                                ?>
                                    <button type="button"
                                            class="dept-picker-item"
                                            data-id="<?php echo (int)$c['id']; ?>"
                                            data-search="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                                        <span class="dept-picker-item-check"><i class="bi bi-check-lg"></i></span>
                                        <span class="flex-grow-1">
                                            <span class="dept-picker-item-title d-block"><?php echo htmlspecialchars($c['name']); ?></span>
                                            <span class="dept-picker-item-meta">
                                                <span><code><?php echo htmlspecialchars($c['code']); ?></code></span>
                                                <?php if (!empty($c['course'])): ?>
                                                    <span><?php echo htmlspecialchars($c['course']); ?> курс</span>
                                                <?php endif; ?>
                                                <?php if (!empty($c['specialty'])): ?>
                                                    <span><?php echo htmlspecialchars($c['specialty']); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </span>
                                    </button>
                                <?php endforeach; ?>

                                <?php foreach ($occupied as $c):
                                    $deptName = trim((string)($c['department_name'] ?? 'другое отделение'));
                                    $curator = trim(($c['curator_last_name'] ?? '') . ' ' . ($c['curator_first_name'] ?? '') . ' ' . ($c['curator_middle_name'] ?? ''));
                                    $search = mb_strtolower(trim(($c['name'] ?? '') . ' ' . ($c['code'] ?? '') . ' ' . ($c['specialty'] ?? '') . ' ' . ($c['course'] ?? '') . ' ' . $curator . ' ' . $deptName));
                                    $busyMsg = 'Эта группа уже добавлена в отделение «' . $deptName . '»';
                                ?>
                                    <button type="button"
                                            class="dept-picker-item is-busy"
                                            data-busy="1"
                                            data-busy-msg="<?php echo htmlspecialchars($busyMsg, ENT_QUOTES); ?>"
                                            data-search="<?php echo htmlspecialchars($search, ENT_QUOTES); ?>">
                                        <span class="dept-picker-item-check"><i class="bi bi-lock-fill"></i></span>
                                        <span class="flex-grow-1">
                                            <span class="dept-picker-item-title d-block"><?php echo htmlspecialchars($c['name']); ?></span>
                                            <span class="dept-picker-item-meta">
                                                <span><code><?php echo htmlspecialchars($c['code']); ?></code></span>
                                                <span class="text-danger">уже в «<?php echo htmlspecialchars($deptName); ?>»</span>
                                            </span>
                                        </span>
                                    </button>
                                <?php endforeach; ?>
                                <div class="dept-picker-empty d-none" id="groupPickerNoMatch">Ничего не найдено</div>
                            </div>
                            <div class="dept-picker-count">
                                Свободных: <span id="groupPickerCount"><?php echo count($candidates); ?></span>
                                <?php if (!empty($occupied)): ?>
                                    · занято другим отделением: <?php echo count($occupied); ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="addExistingSubmit" disabled>Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header">
                    <h5 class="modal-title">Новая группа</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Код <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Специальность</label>
                            <input type="text" class="form-control" name="specialty">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Курс</label>
                            <select class="form-select" name="course">
                                <?php for ($c = 1; $c <= 4; $c++): ?>
                                    <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Язык</label>
                            <select class="form-select" name="language">
                                <option value="kaz">Казахский</option>
                                <option value="rus">Русский</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Форма обучения</label>
                            <select class="form-select" name="study_form">
                                <option value="full_time">Очная</option>
                                <option value="part_time">Заочная</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Куратор</label>
                            <select class="form-select" name="curator_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>">
                                        <?php echo htmlspecialchars(trim($m['last_name'] . ' ' . $m['first_name'] . ' ' . $m['middle_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Макс. студентов</label>
                            <input type="number" class="form-control" name="max_students" value="25" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дата начала</label>
                            <input type="date" class="form-control" name="start_date" value="<?php echo date('Y-m-d'); ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Создать</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="group_id" id="edit_group_id">
                <div class="modal-header">
                    <h5 class="modal-title">Редактировать группу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Код <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" id="edit_code" required>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label">Специальность</label>
                            <input type="text" class="form-control" name="specialty" id="edit_specialty">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Курс</label>
                            <select class="form-select" name="course" id="edit_course">
                                <?php for ($c = 1; $c <= 4; $c++): ?>
                                    <option value="<?php echo $c; ?>"><?php echo $c; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Куратор</label>
                            <select class="form-select" name="curator_id" id="edit_curator_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($members as $m): ?>
                                    <option value="<?php echo (int)$m['id']; ?>">
                                        <?php echo htmlspecialchars(trim($m['last_name'] . ' ' . $m['first_name'] . ' ' . $m['middle_name'])); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Макс. студентов</label>
                            <input type="number" class="form-control" name="max_students" id="edit_max_students" min="1">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Дата начала</label>
                            <input type="date" class="form-control" name="start_date" id="edit_start_date">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                                <label class="form-check-label" for="edit_is_active">Активна</label>
                            </div>
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

<script>
function editGroup(g) {
    document.getElementById('edit_group_id').value = g.id;
    document.getElementById('edit_name').value = g.name || '';
    document.getElementById('edit_code').value = g.code || '';
    document.getElementById('edit_specialty').value = g.specialty || '';
    document.getElementById('edit_course').value = String(g.course || '1');
    document.getElementById('edit_curator_id').value = g.curator_id ? String(g.curator_id) : '';
    document.getElementById('edit_max_students').value = g.max_students || 25;
    document.getElementById('edit_start_date').value = g.start_date || '';
    document.getElementById('edit_is_active').checked = parseInt(g.is_active, 10) === 1;
    new bootstrap.Modal(document.getElementById('editGroupModal')).show();
}

(function () {
    const tableSearch = document.getElementById('groupsTableSearch');
    const table = document.getElementById('groupsTable');
    const noMatchRow = document.getElementById('groupsNoMatchRow');
    if (tableSearch && table) {
        tableSearch.addEventListener('input', function () {
            const q = (this.value || '').trim().toLowerCase();
            let visible = 0;
            table.querySelectorAll('tbody tr[data-search]').forEach(function (row) {
                const match = !q || (row.getAttribute('data-search') || '').includes(q);
                row.classList.toggle('d-none', !match);
                if (match) visible += 1;
            });
            if (noMatchRow) {
                noMatchRow.classList.toggle('d-none', visible > 0 || q === '');
            }
        });
    }

    const picker = document.getElementById('groupPicker');
    if (!picker) return;

    const search = document.getElementById('groupPickerSearch');
    const list = document.getElementById('groupPickerList');
    const noMatch = document.getElementById('groupPickerNoMatch');
    const countEl = document.getElementById('groupPickerCount');
    const hiddenId = document.getElementById('picker_group_id');
    const submitBtn = document.getElementById('addExistingSubmit');
    const errorBox = document.getElementById('addExistingError');
    const items = Array.from(list.querySelectorAll('.dept-picker-item'));
    const freeTotal = items.filter(function (el) { return !el.getAttribute('data-busy'); }).length;

    function hideError() {
        if (!errorBox) return;
        errorBox.classList.add('d-none');
        errorBox.textContent = '';
    }

    function showError(msg) {
        if (!errorBox) {
            alert(msg);
            return;
        }
        errorBox.textContent = msg;
        errorBox.classList.remove('d-none');
    }

    function filterPicker() {
        const q = (search.value || '').trim().toLowerCase();
        let freeVisible = 0;
        let anyVisible = 0;
        items.forEach(function (item) {
            const match = !q || (item.getAttribute('data-search') || '').includes(q);
            item.classList.toggle('d-none', !match);
            if (match) {
                anyVisible += 1;
                if (!item.getAttribute('data-busy')) freeVisible += 1;
            }
        });
        if (noMatch) noMatch.classList.toggle('d-none', anyVisible > 0);
        if (countEl) countEl.textContent = String(freeVisible);
    }

    function selectItem(item) {
        hideError();
        if (item.getAttribute('data-busy')) {
            items.forEach(function (el) { el.classList.remove('is-selected'); });
            hiddenId.value = '';
            if (submitBtn) submitBtn.disabled = true;
            showError(item.getAttribute('data-busy-msg') || 'Эта группа уже добавлена в другое отделение');
            return;
        }
        items.forEach(function (el) { el.classList.remove('is-selected'); });
        item.classList.add('is-selected');
        hiddenId.value = item.getAttribute('data-id') || '';
        if (submitBtn) submitBtn.disabled = !hiddenId.value;
    }

    search.addEventListener('input', filterPicker);
    items.forEach(function (item) {
        item.addEventListener('click', function () { selectItem(item); });
    });

    document.getElementById('addExistingModal').addEventListener('shown.bs.modal', function () {
        hideError();
        search.focus();
    });

    document.getElementById('addExistingModal').addEventListener('hidden.bs.modal', function () {
        search.value = '';
        hiddenId.value = '';
        hideError();
        items.forEach(function (el) {
            el.classList.remove('is-selected', 'd-none');
        });
        if (noMatch) noMatch.classList.add('d-none');
        if (countEl) countEl.textContent = String(freeTotal);
        if (submitBtn) submitBtn.disabled = true;
    });

    document.getElementById('addExistingForm').addEventListener('submit', function (e) {
        if (!hiddenId.value) {
            e.preventDefault();
            showError('Выберите свободную группу');
        }
    });
})();
</script>

<?php include 'includes/footer.php'; ?>
