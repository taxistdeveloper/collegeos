<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
requirePermission('manage_schedule');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$message = '';
$error = '';
$current_user = getCurrentUser();

$schedule_list = $period_id ? $uchebni->getSchedule(['period_id' => $period_id]) : [];
$teachers = $uchebni->getTeachers();

$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-14 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d', strtotime('+60 days'));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_from)) {
    $date_from = date('Y-m-d', strtotime('-14 days'));
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date_to)) {
    $date_to = date('Y-m-d', strtotime('+60 days'));
}

$edit_id = !empty($_GET['edit']) ? (int)$_GET['edit'] : 0;
$edit_row = $edit_id ? $uchebni->getSubstitutionById($edit_id) : null;
if ($edit_id && !$edit_row) {
    $error = 'Замена не найдена';
    $edit_id = 0;
}

$pref_schedule_id = !empty($_GET['schedule_id']) ? (int)$_GET['schedule_id'] : 0;
$pref_date = !empty($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])
    ? $_GET['date']
    : '';

function uchebni_normalize_sub_date($lesson_date, $slotDow)
{
    $dateTs = strtotime($lesson_date);
    if (!$dateTs || $slotDow < 1 || $slotDow > 5) {
        return [null, 'Некорректная дата занятия'];
    }
    $dateDow = (int)date('N', $dateTs);
    if ($dateDow !== $slotDow) {
        $diff = $slotDow - $dateDow;
        $lesson_date = date('Y-m-d', strtotime(($diff >= 0 ? '+' : '') . $diff . ' days', $dateTs));
    }
    return [$lesson_date, null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if (!empty($_POST['delete_id'])) {
        if ($uchebni->deleteSubstitution((int)$_POST['delete_id'])) {
            $message = 'Замена удалена — часы снова у исходного преподавателя';
            if ($edit_id === (int)$_POST['delete_id']) {
                $edit_id = 0;
                $edit_row = null;
            }
        } else {
            $error = 'Не удалось удалить замену';
        }
    } elseif ($action === 'delete' && !empty($_POST['id'])) {
        if ($uchebni->deleteSubstitution((int)$_POST['id'])) {
            $message = 'Замена удалена — часы снова у исходного преподавателя';
            if ($edit_id === (int)$_POST['id']) {
                $edit_id = 0;
                $edit_row = null;
            }
        } else {
            $error = 'Не удалось удалить замену';
        }
    } elseif ($action === 'delete_selected') {
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? $_POST['ids'] : [];
        $n = $uchebni->deleteSubstitutions($ids);
        $message = $n > 0
            ? ('Удалено замен: ' . $n . '. Часы возвращены исходным преподавателям.')
            : 'Ничего не выбрано';
        if ($n > 0 && $edit_id && in_array($edit_id, array_map('intval', $ids), true)) {
            $edit_id = 0;
            $edit_row = null;
        }
    } elseif ($action === 'delete_filtered') {
        $rows = $uchebni->getSubstitutions([
            'date_from' => sanitize($_POST['date_from'] ?? $date_from),
            'date_to' => sanitize($_POST['date_to'] ?? $date_to),
        ]);
        $ids = array_column($rows, 'id');
        $n = $uchebni->deleteSubstitutions($ids);
        $message = $n > 0
            ? ('Удалено по фильтру: ' . $n)
            : 'В выбранном диапазоне замен нет';
        $edit_id = 0;
        $edit_row = null;
    } elseif ($action === 'save') {
        $sub_id = (int)($_POST['id'] ?? 0);
        $schedule_id = (int)($_POST['schedule_id'] ?? 0);
        $lesson_date = $_POST['lesson_date'] ?? '';
        $substitute_teacher_id = (int)($_POST['substitute_teacher_id'] ?? 0);
        $substitute_subject_id = (int)($_POST['substitute_subject_id'] ?? 0);
        $reason = sanitize($_POST['reason'] ?? '');

        $slot = $uchebni->getScheduleById($schedule_id);
        if (!$slot) {
            $error = 'Занятие не найдено';
        } elseif (!$lesson_date || !$substitute_teacher_id) {
            $error = 'Заполните все поля';
        } else {
            list($lesson_date, $dateErr) = uchebni_normalize_sub_date($lesson_date, (int)$slot['day_of_week']);
            if ($dateErr) {
                $error = $dateErr;
            }
            if (!$error && (int)$slot['teacher_id'] === $substitute_teacher_id) {
                $error = 'Замещающий не может совпадать с исходным преподавателем';
            }

            $teacherSubjects = $uchebni->getTeacherSubjectsList($substitute_teacher_id);
            if (!$error && $teacherSubjects && !$substitute_subject_id) {
                $error = 'Выберите дисциплину замещающего преподавателя';
            } elseif (!$error && $substitute_subject_id) {
                $allowed = array_column($teacherSubjects, 'id');
                if (!in_array($substitute_subject_id, $allowed, true)) {
                    $error = 'Выбранная дисциплина не привязана к преподавателю';
                }
            }

            if (!$error) {
                $payload = [
                    'schedule_id' => $schedule_id,
                    'lesson_date' => $lesson_date,
                    'original_teacher_id' => (int)$slot['teacher_id'],
                    'substitute_teacher_id' => $substitute_teacher_id,
                    'substitute_subject_id' => $substitute_subject_id ?: null,
                    'reason' => $reason,
                ];
                $ok = $sub_id > 0
                    ? $uchebni->updateSubstitution($sub_id, $payload, $current_user['id'])
                    : $uchebni->addSubstitution($payload, $current_user['id']);

                if ($ok) {
                    $shift = (int)($slot['shift'] ?? 1) === 2 ? 2 : 1;
                    $month = date('Y-m', strtotime($lesson_date));
                    header('Location: schedule.php?view=day&date=' . urlencode($lesson_date)
                        . '&month=' . urlencode($month)
                        . '&shift=' . $shift
                        . '&group_id=' . (int)$slot['group_id']
                        . '&sub=1');
                    exit;
                }
                $error = $sub_id > 0
                    ? 'Не удалось обновить (возможно, на эту дату уже есть другая замена)'
                    : 'Ошибка сохранения';
            }
        }

        if ($error && $sub_id > 0) {
            $edit_id = $sub_id;
            $edit_row = $uchebni->getSubstitutionById($sub_id);
        }
    }
}

$substitutions = $uchebni->getSubstitutions([
    'date_from' => $date_from,
    'date_to' => $date_to,
]);

$schedule_options = [];
foreach ($schedule_list as $s) {
    $day = Uchebni::DAY_NAMES[$s['day_of_week']] ?? '';
    $shift = (int)($s['shift'] ?? 1) === 2 ? 2 : 1;
    $shiftName = Uchebni::SHIFT_NAMES[$shift] ?? '';
    $pairLabel = ((int)$s['pair_number'] === 0) ? 'Кураторский час' : ($s['pair_number'] . ' пара');
    $time = '';
    if (!empty($s['start_time']) && !empty($s['end_time'])) {
        $time = substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5);
    }
    $label = $s['group_name'] . ' · ' . $s['subject_name'];
    $meta = $day . ' · ' . $shiftName . ' · ' . $pairLabel;
    if ($time) {
        $meta .= ' · ' . $time;
    }
    $meta .= ' · ' . $s['teacher_name'];
    if (!empty($s['classroom_number'])) {
        $meta .= ' · каб. ' . $s['classroom_number'];
    }
    $schedule_options[] = [
        'id' => (int)$s['id'],
        'label' => $label,
        'meta' => $meta,
        'day_of_week' => (int)$s['day_of_week'],
        'teacher_id' => (int)$s['teacher_id'],
        'search' => mb_strtolower($label . ' ' . $meta),
    ];
}

$teacher_options = [];
foreach ($teachers as $t) {
    $fio = Uchebni::formatFio($t);
    $subject_list = $uchebni->getTeacherSubjectsList($t['id']);
    $subject_names = array_column($subject_list, 'name');
    $subjects_label = $subject_names ? implode(', ', $subject_names) : '';
    $n = count($subject_names);
    $meta_parts = [];
    if ($n === 1) {
        $meta_parts[] = $subject_names[0];
    } elseif ($n > 1) {
        $meta_parts[] = $n . ' ' . ($n >= 2 && $n <= 4 ? 'дисциплины' : 'дисциплин');
    }
    if (!empty($t['department'])) {
        $meta_parts[] = $t['department'];
    }
    $teacher_options[] = [
        'id' => (int)$t['id'],
        'label' => $fio,
        'meta' => implode(' · ', $meta_parts),
        'subjects' => $subject_list,
        'search' => mb_strtolower($fio . ' ' . $subjects_label . ' ' . ($t['department'] ?? '')),
    ];
}

$form_schedule_id = $edit_row ? (int)$edit_row['schedule_id'] : $pref_schedule_id;
$form_date = $edit_row ? $edit_row['lesson_date'] : ($pref_date ?: date('Y-m-d'));
$form_teacher_id = $edit_row ? (int)$edit_row['substitute_teacher_id'] : 0;
$form_subject_id = $edit_row ? (int)($edit_row['substitute_subject_id'] ?? 0) : 0;
$form_reason = $edit_row ? (string)($edit_row['reason'] ?? '') : '';

$page_title = 'Замены';
$page_subtitle = $edit_row ? 'Редактирование замены' : 'Назначение, правка и удаление';
require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="alert alert-info small">
    При замене часы <strong>снимаются</strong> у исходного преподавателя и <strong>добавляются</strong> заменяющему
    (в «Часы», «Нагрузка», ведомость). В расписании на эту дату сразу видно «замена».
</div>

<div class="row g-3">
    <div class="col-lg-5">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <strong><?php echo $edit_row ? 'Редактировать замену' : 'Новая замена'; ?></strong>
                <?php if ($edit_row): ?>
                    <a href="substitutions.php?date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>" class="btn btn-sm btn-outline-secondary">Новая</a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="post" id="substitutionForm">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="id" value="<?php echo (int)$edit_id; ?>">
                    <div class="mb-3">
                        <label class="form-label">Занятие *</label>
                        <input type="hidden" name="schedule_id" id="schedule_id_input" value="<?php echo (int)$form_schedule_id; ?>">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="scheduleCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="scheduleComboboxToggle" aria-expanded="false">
                                <i class="bi bi-calendar3 uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="scheduleComboboxTitle">Выберите занятие</span>
                                    <span class="uchebni-combobox-meta d-none" id="scheduleComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="scheduleComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="scheduleComboboxSearch" placeholder="Поиск занятия…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="scheduleComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Дата занятия *</label>
                        <input type="date" name="lesson_date" id="lesson_date_input" class="form-control" required value="<?php echo htmlspecialchars($form_date); ?>">
                        <div class="form-text" id="lessonDateHint">Дата подставится под день недели выбранной пары</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Замещающий преподаватель *</label>
                        <input type="hidden" name="substitute_teacher_id" id="teacher_id_input" value="<?php echo (int)$form_teacher_id; ?>">
                        <input type="hidden" name="substitute_subject_id" id="subject_id_input" value="<?php echo (int)$form_subject_id; ?>">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="teacherCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="teacherComboboxToggle" aria-expanded="false">
                                <i class="bi bi-person-badge uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="teacherComboboxTitle">Выберите преподавателя</span>
                                    <span class="uchebni-combobox-meta d-none" id="teacherComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="teacherComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="teacherComboboxSearch" placeholder="Поиск по ФИО или дисциплине…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="teacherComboboxList"></div>
                            </div>
                        </div>
                        <div class="mt-2 d-none" id="teacherSubjectsWrap">
                            <label class="form-label small mb-1" for="teacherSubjectsSelect">Предмет на замену *</label>
                            <select class="form-select form-select-sm" id="teacherSubjectsSelect"></select>
                            <div class="form-text">В расписании вместо исходного предмета покажется этот</div>
                        </div>
                        <div class="form-text" id="teacherSubjectsEmpty">После выбора отобразятся его дисциплины</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Причина</label>
                        <input type="text" name="reason" class="form-control" placeholder="Болезнь, командировка..." value="<?php echo htmlspecialchars($form_reason); ?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-save me-1"></i><?php echo $edit_row ? 'Сохранить изменения' : 'Сохранить замену'; ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header">
                <form method="get" class="row g-2 align-items-end">
                    <div class="col-auto">
                        <label class="form-label small mb-0">С</label>
                        <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_from); ?>">
                    </div>
                    <div class="col-auto">
                        <label class="form-label small mb-0">По</label>
                        <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo htmlspecialchars($date_to); ?>">
                    </div>
                    <div class="col-auto">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Показать</button>
                    </div>
                </form>
            </div>
            <form method="post" id="bulkForm">
                <div class="card-body py-2 border-bottom d-flex flex-wrap gap-2 align-items-center">
                    <input type="hidden" name="date_from" value="<?php echo htmlspecialchars($date_from); ?>">
                    <input type="hidden" name="date_to" value="<?php echo htmlspecialchars($date_to); ?>">
                    <button type="submit" name="action" value="delete_selected" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Удалить выбранные замены? Часы вернутся исходным преподавателям.');">
                        Удалить выбранные
                    </button>
                    <button type="submit" name="action" value="delete_filtered" class="btn btn-sm btn-outline-danger"
                            onclick="return confirm('Удалить ВСЕ замены в выбранном диапазоне дат?');">
                        Удалить все в фильтре
                    </button>
                    <span class="text-muted small ms-auto">Найдено: <?php echo count($substitutions); ?></span>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 align-middle">
                        <thead class="table-light">
                            <tr>
                                <th style="width:2rem"><input type="checkbox" id="checkAll" title="Выбрать все"></th>
                                <th>Дата</th>
                                <th>Занятие</th>
                                <th>Был</th>
                                <th>Заменяет</th>
                                <th>Часы</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($substitutions)): ?>
                            <tr><td colspan="7" class="text-muted text-center py-4">Замен пока нет</td></tr>
                            <?php else: ?>
                            <?php foreach ($substitutions as $sub):
                                $displaySubject = !empty($sub['substitute_subject_name'])
                                    ? $sub['substitute_subject_name']
                                    : $sub['subject_name'];
                                $pairNum = (int)$sub['pair_number'];
                                $hours = ($pairNum === 0) ? Uchebni::CURATOR_HOURS : Uchebni::HOURS_PER_PAIR;
                                $viewUrl = 'schedule.php?view=day&date=' . urlencode($sub['lesson_date'])
                                    . '&month=' . urlencode(substr($sub['lesson_date'], 0, 7))
                                    . '&shift=' . ((int)($sub['shift'] ?? 1) === 2 ? 2 : 1)
                                    . '&group_id=' . (int)$sub['group_id'];
                            ?>
                            <tr class="<?php echo $edit_id === (int)$sub['id'] ? 'table-warning' : ''; ?>">
                                <td><input type="checkbox" name="ids[]" value="<?php echo (int)$sub['id']; ?>" class="sub-check"></td>
                                <td>
                                    <a href="<?php echo htmlspecialchars($viewUrl); ?>"><?php echo htmlspecialchars(formatDate($sub['lesson_date'])); ?></a>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($displaySubject); ?></strong>
                                    <?php if (!empty($sub['substitute_subject_name']) && $sub['substitute_subject_name'] !== $sub['subject_name']): ?>
                                        <br><small class="text-muted">вместо <?php echo htmlspecialchars($sub['subject_name']); ?></small>
                                    <?php endif; ?>
                                    <br>
                                    <small class="text-muted"><?php
                                        $subShift = (int)($sub['shift'] ?? 1) === 2 ? 2 : 1;
                                        $pairTxt = ($pairNum === 0) ? 'Кураторский час' : ($pairNum . ' пара');
                                        echo htmlspecialchars($sub['group_name'] . ', ' . (Uchebni::DAY_NAMES[$sub['day_of_week']] ?? '') . ', ' . (Uchebni::SHIFT_NAMES[$subShift] ?? '') . ', ' . $pairTxt);
                                    ?></small>
                                    <?php if (!empty($sub['reason'])): ?>
                                        <br><small class="text-muted">Причина: <?php echo htmlspecialchars($sub['reason']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($sub['original_teacher_name']); ?>
                                    <div class="small text-danger">−<?php echo (int)$hours; ?> ч</div>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($sub['substitute_teacher_name']); ?>
                                    <div class="small text-success">+<?php echo (int)$hours; ?> ч</div>
                                </td>
                                <td class="text-nowrap"><?php echo (int)$hours; ?> ч</td>
                                <td class="text-nowrap">
                                    <a class="btn btn-sm btn-outline-primary" href="?edit=<?php echo (int)$sub['id']; ?>&amp;date_from=<?php echo urlencode($date_from); ?>&amp;date_to=<?php echo urlencode($date_to); ?>">изм.</a>
                                    <button type="submit" name="delete_id" value="<?php echo (int)$sub['id']; ?>"
                                            class="btn btn-sm btn-outline-danger"
                                            onclick="return confirm('Удалить эту замену? Часы вернутся исходному преподавателю.');">
                                        удал.
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const SCHEDULE_OPTIONS = <?php echo json_encode($schedule_options, JSON_UNESCAPED_UNICODE); ?>;
const TEACHER_OPTIONS = <?php echo json_encode($teacher_options, JSON_UNESCAPED_UNICODE); ?>;
const PREF_SCHEDULE_ID = <?php echo (int)$form_schedule_id; ?>;
const PREF_TEACHER_ID = <?php echo (int)$form_teacher_id; ?>;
const PREF_SUBJECT_ID = <?php echo (int)$form_subject_id; ?>;

document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.sub-check').forEach(function(cb) { cb.checked = !!this.checked; }.bind(this));
});

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

    let selectedId = cfg.selectedId !== undefined && cfg.selectedId !== null ? cfg.selectedId : '';
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
        selectedId = item ? item.id : '';
        valueInput.value = item ? item.id : '';
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
    search.addEventListener('input', function() { render(search.value); });
    document.addEventListener('click', function(e) {
        if (!root.contains(e.target)) close();
    });

    const api = {
        setSelected: setSelected,
        open: open,
        reset: function(placeholder) {
            selectedId = '';
            valueInput.value = '';
            titleEl.textContent = placeholder || cfg.placeholder || 'Выберите';
            if (metaEl) { metaEl.textContent = ''; metaEl.classList.add('d-none'); }
        }
    };

    if (selectedId) {
        const item = cfg.items.find(function(x) { return String(x.id) === String(selectedId); });
        if (item) setSelected(item, false);
    }
    return api;
}

function snapDateToDow(dateStr, dow) {
    const d = new Date(dateStr + 'T12:00:00');
    if (isNaN(d.getTime()) || !dow) return dateStr;
    const cur = d.getDay() === 0 ? 7 : d.getDay();
    const diff = dow - cur;
    d.setDate(d.getDate() + diff);
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return y + '-' + m + '-' + day;
}

function fillTeacherSubjects(teacherItem, preferredSubjectId) {
    const wrap = document.getElementById('teacherSubjectsWrap');
    const empty = document.getElementById('teacherSubjectsEmpty');
    const select = document.getElementById('teacherSubjectsSelect');
    const subjectInput = document.getElementById('subject_id_input');
    const subjects = (teacherItem && teacherItem.subjects) ? teacherItem.subjects : [];
    if (!subjects.length) {
        wrap.classList.add('d-none');
        empty.classList.remove('d-none');
        empty.textContent = 'У преподавателя нет привязанных дисциплин — сохраните без смены предмета';
        subjectInput.value = '';
        select.innerHTML = '';
        return;
    }
    empty.classList.add('d-none');
    wrap.classList.remove('d-none');
    select.innerHTML = subjects.map(function(s) {
        return '<option value="' + s.id + '">' + escapeHtml(s.name) + '</option>';
    }).join('');
    let pick = preferredSubjectId || (subjects.length === 1 ? subjects[0].id : '');
    if (pick && subjects.some(function(s) { return String(s.id) === String(pick); })) {
        select.value = String(pick);
    }
    subjectInput.value = select.value || '';
}

document.getElementById('teacherSubjectsSelect')?.addEventListener('change', function() {
    document.getElementById('subject_id_input').value = this.value || '';
});

const schedulePicker = initCombobox({
    rootId: 'scheduleCombobox', toggleId: 'scheduleComboboxToggle', panelId: 'scheduleComboboxPanel',
    searchId: 'scheduleComboboxSearch', listId: 'scheduleComboboxList',
    titleId: 'scheduleComboboxTitle', metaId: 'scheduleComboboxMeta', valueId: 'schedule_id_input',
    items: SCHEDULE_OPTIONS, selectedId: PREF_SCHEDULE_ID || '', placeholder: 'Выберите занятие',
    onSelect: function(item) {
        if (!item) return;
        const dateEl = document.getElementById('lesson_date_input');
        const hint = document.getElementById('lessonDateHint');
        if (dateEl && item.day_of_week) {
            dateEl.value = snapDateToDow(dateEl.value || '<?php echo date('Y-m-d'); ?>', item.day_of_week);
            if (hint) hint.textContent = 'День пары: ' + (['', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт'][item.day_of_week] || '') + ' — дата выровнена';
        }
    }
});

const teacherPicker = initCombobox({
    rootId: 'teacherCombobox', toggleId: 'teacherComboboxToggle', panelId: 'teacherComboboxPanel',
    searchId: 'teacherComboboxSearch', listId: 'teacherComboboxList',
    titleId: 'teacherComboboxTitle', metaId: 'teacherComboboxMeta', valueId: 'teacher_id_input',
    items: TEACHER_OPTIONS, selectedId: PREF_TEACHER_ID || '', placeholder: 'Выберите преподавателя',
    onSelect: function(item) {
        fillTeacherSubjects(item, PREF_SUBJECT_ID);
    }
});

if (PREF_TEACHER_ID) {
    const tItem = TEACHER_OPTIONS.find(function(x) { return String(x.id) === String(PREF_TEACHER_ID); });
    if (tItem) fillTeacherSubjects(tItem, PREF_SUBJECT_ID);
}

document.getElementById('substitutionForm')?.addEventListener('submit', function(e) {
    if (!document.getElementById('schedule_id_input').value) {
        e.preventDefault();
        if (schedulePicker) schedulePicker.open();
        return;
    }
    if (!document.getElementById('teacher_id_input').value) {
        e.preventDefault();
        if (teacherPicker) teacherPicker.open();
        return;
    }
    const wrap = document.getElementById('teacherSubjectsWrap');
    if (wrap && !wrap.classList.contains('d-none') && !document.getElementById('subject_id_input').value) {
        e.preventDefault();
        document.getElementById('teacherSubjectsSelect')?.focus();
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
