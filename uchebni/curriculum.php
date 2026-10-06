<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_schedule');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$semester = $uchebni->getPeriodSemesterNumber($period);
$message = '';
$error = '';

$groups = $uchebni->getActiveGroups();
$selected_group_id = (int)($_GET['group_id'] ?? $_POST['group_id'] ?? 0);
if ($selected_group_id <= 0 && !empty($groups)) {
    $selected_group_id = (int)$groups[0]['id'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $period_id) {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $group_id = (int)($_POST['group_id'] ?? 0);
        $category_code = sanitize($_POST['category_code'] ?? '');
        $selected_group_id = $group_id ?: $selected_group_id;
        $result = $uchebni->addGroupSubjectsByCategory([
            'period_id' => $period_id,
            'group_id' => $group_id,
            'category_code' => $category_code,
            'semester' => $semester,
            'lesson_type' => sanitize($_POST['lesson_type'] ?? 'lecture'),
        ]);
        if ($result['added'] > 0) {
            $message = 'Добавлен блок «' . $result['label'] . '»: ' . $result['added'] . ' дисц.';
            if ($result['skipped'] > 0) {
                $message .= ' (пропущено уже в плане: ' . $result['skipped'] . ')';
            }
        } elseif ($result['skipped'] > 0) {
            $error = 'Все дисциплины блока «' . $result['label'] . '» уже в плане группы';
        } else {
            $error = 'Не удалось добавить: блок не найден или пуст';
        }
    }
    if ($action === 'delete') {
        $ok = $uchebni->deleteGroupSubject((int)$_POST['id']);
        if (!empty($_POST['group_id'])) {
            $selected_group_id = (int)$_POST['group_id'];
        }
        $message = $ok ? 'Удалено из учебного плана' : 'Ошибка удаления';
    }
}

$subjects = $uchebni->getSubjects();
$curriculum = ($period_id && $selected_group_id)
    ? $uchebni->getGroupSubjects($period_id, $selected_group_id)
    : [];

$assigned_subject_ids = array_map('intval', array_column($curriculum, 'subject_id'));
$available_subjects = array_filter($subjects, function ($s) use ($assigned_subject_ids) {
    return !in_array((int)$s['id'], $assigned_subject_ids, true);
});

$available_categories = [];
foreach ($available_subjects as $s) {
    $code = trim((string)($s['category_code'] ?? ''));
    if ($code === '') {
        continue;
    }
    if (!isset($available_categories[$code])) {
        $available_categories[$code] = [
            'code' => $code,
            'name' => trim((string)($s['category_name'] ?? '')),
            'count' => 0,
        ];
    }
    $available_categories[$code]['count']++;
    if ($available_categories[$code]['name'] === '' && !empty($s['category_name'])) {
        $available_categories[$code]['name'] = trim((string)$s['category_name']);
    }
}

$subject_teachers_map = [];
foreach ($curriculum as $row) {
    $sid = (int)$row['subject_id'];
    if (!isset($subject_teachers_map[$sid])) {
        $subject_teachers_map[$sid] = $uchebni->getSubjectTeachers($sid);
    }
}

$selected_group = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === $selected_group_id) {
        $selected_group = $g;
        break;
    }
}

$weeks1 = Uchebni::WEEKS_SEM1;
$weeks2 = Uchebni::WEEKS_SEM2;

$current_user = getCurrentUser();
$page_title = 'Учебный план';
$page_subtitle = $period
    ? ($period['name'] . ($selected_group ? ' · ' . $selected_group['name'] : ''))
    : 'Семестр не задан';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php if (!$period_id): ?>
<div class="alert alert-warning">Не задан текущий семестр. Запустите миграцию или добавьте период.</div>
<?php elseif (empty($groups)): ?>
<div class="alert alert-warning">Нет активных групп. Сначала добавьте группы.</div>
<?php else: ?>

<?php
$group_options = [];
foreach ($groups as $g) {
    $label = $g['name'] . (!empty($g['code']) ? ' (' . $g['code'] . ')' : '');
    $meta_parts = [];
    if (!empty($g['course'])) {
        $meta_parts[] = $g['course'] . ' курс';
    }
    if (!empty($g['specialty'])) {
        $meta_parts[] = $g['specialty'];
    }
    $group_options[] = [
        'id' => (int)$g['id'],
        'label' => $label,
        'meta' => implode(' · ', $meta_parts),
        'search' => mb_strtolower($label . ' ' . ($g['specialty'] ?? '') . ' ' . ($g['course'] ?? '')),
    ];
}
$selected_group_label = $selected_group
    ? ($selected_group['name'] . (!empty($selected_group['code']) ? ' (' . $selected_group['code'] . ')' : ''))
    : 'Выберите группу';
$selected_group_meta_parts = [];
if ($selected_group && !empty($selected_group['course'])) {
    $selected_group_meta_parts[] = $selected_group['course'] . ' курс';
}
if ($selected_group && !empty($selected_group['specialty'])) {
    $selected_group_meta_parts[] = $selected_group['specialty'];
}
$selected_group_meta = implode(' · ', $selected_group_meta_parts);

$subject_options = [];
foreach ($available_categories as $cat) {
    $label = trim($cat['code'] . ' ' . $cat['name']);
    $meta = $cat['count'] . ' ' . (
        $cat['count'] === 1 ? 'дисциплина' : (
            ($cat['count'] >= 2 && $cat['count'] <= 4) ? 'дисциплины' : 'дисциплин'
        )
    );
    $subject_options[] = [
        'id' => $cat['code'],
        'label' => $label !== '' ? $label : $cat['code'],
        'meta' => $meta,
        'search' => mb_strtolower($cat['code'] . ' ' . $cat['name']),
    ];
}
?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <div style="flex:1; min-width:280px; max-width:420px">
        <label class="form-label mb-1">Группа</label>
        <form method="get" id="groupFilterForm">
            <input type="hidden" name="group_id" id="group_id_input" value="<?php echo (int)$selected_group_id; ?>">
            <div class="uchebni-combobox" id="groupCombobox" data-submit-form="groupFilterForm">
                <button type="button" class="uchebni-combobox-toggle" id="groupComboboxToggle" aria-expanded="false">
                    <i class="bi bi-people-fill uchebni-combobox-icon"></i>
                    <span class="uchebni-combobox-label">
                        <span class="uchebni-combobox-title" id="groupComboboxTitle"><?php echo htmlspecialchars($selected_group_label); ?></span>
                        <span class="uchebni-combobox-meta <?php echo $selected_group_meta ? '' : 'd-none'; ?>" id="groupComboboxMeta"><?php echo htmlspecialchars($selected_group_meta); ?></span>
                    </span>
                    <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                </button>
                <div class="uchebni-combobox-panel d-none" id="groupComboboxPanel">
                    <div class="uchebni-combobox-search">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="groupComboboxSearch" placeholder="Поиск группы…" autocomplete="off">
                        </div>
                    </div>
                    <div class="uchebni-combobox-list" id="groupComboboxList"></div>
                </div>
            </div>
        </form>
        <div class="form-text">Часы и преподаватели — из «Дисциплин». Сюда добавляется целый блок (ООД и т.п.).</div>
    </div>
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addCurriculumModal"
        <?php echo empty($available_categories) ? 'disabled' : ''; ?>>
        <i class="bi bi-plus-lg me-1"></i>Добавить блок
    </button>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-bordered table-hover mb-0 align-middle text-center">
            <thead class="table-light">
                <tr>
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
                foreach ($curriculum as $row):
                    $subjectRow = [
                        'name' => $row['subject_name'],
                        'assessment' => $row['assessment'] ?? '',
                        'category_code' => $row['category_code'] ?? '',
                        'category_name' => $row['category_name'] ?? '',
                        'item_number' => $row['item_number'] ?? 0,
                    ];
                    $teachers = $subject_teachers_map[(int)$row['subject_id']] ?? [];
                    $teacher_names = array_map(function ($t) {
                        return Uchebni::formatFio($t);
                    }, $teachers);
                    if (!$teacher_names && !empty($row['teacher_name'])) {
                        $teacher_names = [trim($row['teacher_name'])];
                    }
                    $category_header = Uchebni::formatCategoryHeader($subjectRow);
                    $category_key = $category_header !== '' ? $category_header : null;
                    if ($category_key !== null && $category_key !== $prev_category_key):
                        $prev_category_key = $category_key;
                ?>
                <tr class="table-secondary">
                    <td colspan="6" class="fw-semibold text-center">
                        <?php echo htmlspecialchars($category_header); ?>
                    </td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td><?php echo htmlspecialchars(Uchebni::formatSubjectNumber($subjectRow)); ?></td>
                    <td class="text-start"><?php echo htmlspecialchars(Uchebni::formatSubjectTitle($subjectRow)); ?></td>
                    <td class="text-start small">
                        <?php echo $teacher_names ? htmlspecialchars(implode(', ', $teacher_names)) : '—'; ?>
                    </td>
                    <td><?php echo (int)($row['hours_sem1'] ?? 0); ?></td>
                    <td><?php echo (int)($row['hours_sem2'] ?? 0); ?></td>
                    <td>
                        <form method="post" class="d-inline" onsubmit="return confirm('Убрать из плана группы?');">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">
                            <input type="hidden" name="group_id" value="<?php echo (int)$selected_group_id; ?>">
                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Убрать из плана">
                                <i class="bi bi-trash"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($curriculum)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        Для этой группы план пуст. Добавьте блок дисциплин (например ООД).
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal fade" id="addCurriculumModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="group_id" value="<?php echo (int)$selected_group_id; ?>">
            <div class="modal-header">
                <h5 class="modal-title">Блок в план группы</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Группа: <strong><?php echo htmlspecialchars($selected_group['name'] ?? ''); ?></strong>.
                    Выберите код блока (например ООД) — в план добавятся все дисциплины этого блока.
                </p>
                <div class="mb-3">
                    <label class="form-label">Блок *</label>
                    <input type="hidden" name="category_code" id="category_code_input" value="">
                    <div class="uchebni-combobox uchebni-combobox--modal" id="categoryCombobox">
                        <button type="button" class="uchebni-combobox-toggle" id="categoryComboboxToggle" aria-expanded="false">
                            <i class="bi bi-collection uchebni-combobox-icon"></i>
                            <span class="uchebni-combobox-label">
                                <span class="uchebni-combobox-title" id="categoryComboboxTitle">Выберите блок</span>
                                <span class="uchebni-combobox-meta d-none" id="categoryComboboxMeta"></span>
                            </span>
                            <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                        </button>
                        <div class="uchebni-combobox-panel d-none" id="categoryComboboxPanel">
                            <div class="uchebni-combobox-search">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="search" class="form-control" id="categoryComboboxSearch" placeholder="Поиск блока…" autocomplete="off">
                                </div>
                            </div>
                            <div class="uchebni-combobox-list" id="categoryComboboxList"></div>
                        </div>
                    </div>
                </div>
                <div class="mb-0">
                    <label class="form-label">Тип занятия (для расписания)</label>
                    <select name="lesson_type" class="form-select">
                        <option value="lecture">Лекция</option>
                        <option value="practice">Практика</option>
                        <option value="lab">Лабораторная</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Добавить все</button>
            </div>
        </form>
    </div>
</div>

<script>
const GROUP_OPTIONS = <?php echo json_encode($group_options, JSON_UNESCAPED_UNICODE); ?>;
const CATEGORY_OPTIONS = <?php echo json_encode(array_values($subject_options), JSON_UNESCAPED_UNICODE); ?>;
const SELECTED_GROUP_ID = <?php echo (int)$selected_group_id; ?>;

function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
}

function initCombobox(cfg) {
    const root = document.getElementById(cfg.rootId);
    const toggle = document.getElementById(cfg.toggleId);
    const panel = document.getElementById(cfg.panelId);
    const search = document.getElementById(cfg.searchId);
    const list = document.getElementById(cfg.listId);
    const titleEl = document.getElementById(cfg.titleId);
    const metaEl = document.getElementById(cfg.metaId);
    const valueInput = document.getElementById(cfg.valueId);
    if (!root || !toggle || !panel || !search || !list || !valueInput) return null;

    let selectedId = cfg.selectedId || 0;
    let activeIndex = -1;

    function close() {
        panel.classList.add('d-none');
        root.classList.remove('is-open');
        toggle.setAttribute('aria-expanded', 'false');
        search.value = '';
        activeIndex = -1;
    }

    function open() {
        panel.classList.remove('d-none');
        root.classList.add('is-open');
        toggle.setAttribute('aria-expanded', 'true');
        render(search.value);
        setTimeout(function() { search.focus(); }, 0);
    }

    function setSelected(item, submit) {
        selectedId = item ? item.id : 0;
        valueInput.value = selectedId || '';
        titleEl.textContent = item ? item.label : (cfg.placeholder || 'Выберите');
        if (metaEl) {
            if (item && item.meta) {
                metaEl.textContent = item.meta;
                metaEl.classList.remove('d-none');
            } else {
                metaEl.textContent = '';
                metaEl.classList.add('d-none');
            }
        }
        close();
        if (submit && cfg.submitFormId && selectedId) {
            const form = document.getElementById(cfg.submitFormId);
            if (form) form.submit();
        }
        if (typeof cfg.onSelect === 'function') cfg.onSelect(item);
    }

    function filtered(q) {
        q = String(q || '').trim().toLowerCase();
        if (!q) return cfg.items.slice();
        return cfg.items.filter(function(item) {
            return (item.search || item.label || '').indexOf(q) !== -1;
        });
    }

    function render(q) {
        const items = filtered(q);
        if (!items.length) {
            list.innerHTML = '<div class="uchebni-combobox-empty">Ничего не найдено</div>';
            activeIndex = -1;
            return;
        }
        list.innerHTML = items.map(function(item, idx) {
            const selected = String(item.id) === String(selectedId) ? ' is-selected' : '';
            return '<button type="button" class="uchebni-combobox-item' + selected + '" data-idx="' + idx + '" data-id="' + escapeHtml(item.id) + '">'
                + '<div class="item-title">' + escapeHtml(item.label) + '</div>'
                + (item.meta ? '<div class="item-meta">' + escapeHtml(item.meta) + '</div>' : '')
                + '</button>';
        }).join('');

        list.querySelectorAll('.uchebni-combobox-item').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const id = this.dataset.id;
                const item = cfg.items.find(function(x) { return String(x.id) === String(id); });
                if (item) setSelected(item, true);
            });
        });
        activeIndex = -1;
    }

    toggle.addEventListener('click', function(e) {
        e.preventDefault();
        if (panel.classList.contains('d-none')) open();
        else close();
    });

    search.addEventListener('input', function() {
        render(search.value);
    });

    search.addEventListener('keydown', function(e) {
        const items = list.querySelectorAll('.uchebni-combobox-item');
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIndex = Math.min(activeIndex + 1, items.length - 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIndex = Math.max(activeIndex - 1, 0);
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && items[activeIndex]) items[activeIndex].click();
            return;
        } else if (e.key === 'Escape') {
            close();
            return;
        } else {
            return;
        }
        items.forEach(function(el, i) {
            el.classList.toggle('is-active', i === activeIndex);
            if (i === activeIndex) el.scrollIntoView({ block: 'nearest' });
        });
    });

    document.addEventListener('click', function(e) {
        if (!root.contains(e.target)) close();
    });

    return { setSelected: setSelected, open: open, close: close };
}

initCombobox({
    rootId: 'groupCombobox',
    toggleId: 'groupComboboxToggle',
    panelId: 'groupComboboxPanel',
    searchId: 'groupComboboxSearch',
    listId: 'groupComboboxList',
    titleId: 'groupComboboxTitle',
    metaId: 'groupComboboxMeta',
    valueId: 'group_id_input',
    submitFormId: 'groupFilterForm',
    items: GROUP_OPTIONS,
    selectedId: SELECTED_GROUP_ID,
    placeholder: 'Выберите группу'
});

const categoryPicker = initCombobox({
    rootId: 'categoryCombobox',
    toggleId: 'categoryComboboxToggle',
    panelId: 'categoryComboboxPanel',
    searchId: 'categoryComboboxSearch',
    listId: 'categoryComboboxList',
    titleId: 'categoryComboboxTitle',
    metaId: 'categoryComboboxMeta',
    valueId: 'category_code_input',
    items: CATEGORY_OPTIONS,
    selectedId: '',
    placeholder: 'Выберите блок'
});

const addForm = document.querySelector('#addCurriculumModal form');
if (addForm) {
    addForm.addEventListener('submit', function(e) {
        if (!document.getElementById('category_code_input').value) {
            e.preventDefault();
            if (categoryPicker) categoryPicker.open();
        }
    });
    document.getElementById('addCurriculumModal').addEventListener('hidden.bs.modal', function() {
        document.getElementById('category_code_input').value = '';
        document.getElementById('categoryComboboxTitle').textContent = 'Выберите блок';
        document.getElementById('categoryComboboxMeta').classList.add('d-none');
        document.getElementById('categoryComboboxMeta').textContent = '';
    });
}
</script>

<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
