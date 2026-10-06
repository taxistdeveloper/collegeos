<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);

$can_manage = hasPermission('manage_schedule');
$can_auto = hasPermission('auto_schedule');
$can_view = hasPermission('view_schedule') || $can_manage;

if (!$can_view) {
    requirePermission('view_uchebni');
}

$uchebni = new Uchebni();
$current_user = getCurrentUser();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;
$message = '';
$error = '';

$filter_group = (int)($_GET['group_id'] ?? 0);
$filter_shift = (int)($_GET['shift'] ?? 1);
if ($filter_shift !== 2) {
    $filter_shift = 1;
}
$filter_teacher = 0;
$view_mode = ($_GET['view'] ?? 'week') === 'day' ? 'day' : 'week';
$selected_date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date) || !strtotime($selected_date)) {
    $selected_date = date('Y-m-d');
}
$cal_month = $_GET['month'] ?? date('Y-m', strtotime($selected_date));
if (!preg_match('/^\d{4}-\d{2}$/', $cal_month)) {
    $cal_month = date('Y-m', strtotime($selected_date));
}

if ($current_user['role'] === 'teacher') {
    $teacher = $uchebni->getTeacherByUserId($current_user['id']);
    if ($teacher) {
        $filter_teacher = (int)$teacher['id'];
    }
} else {
    $filter_teacher = (int)($_GET['teacher_id'] ?? 0);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Сохраняем режим просмотра после POST
    if (isset($_POST['return_view']) && $_POST['return_view'] === 'day') {
        $view_mode = 'day';
    }
    if (!empty($_POST['return_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['return_date'])) {
        $selected_date = $_POST['return_date'];
        $cal_month = date('Y-m', strtotime($selected_date));
    }

    if ($action === 'add_curator' && $can_manage && $period_id) {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $teacherId = (int)($_POST['teacher_id'] ?? 0);
        $classroomId = (int)($_POST['classroom_id'] ?? 0);
        $shift = (int)($_POST['shift'] ?? $filter_shift) === 2 ? 2 : 1;
        $subjectId = $uchebni->getOrCreateCuratorSubject();
        if ($classroomId <= 0) {
            $classroomId = $uchebni->getOrCreateCuratorClassroom();
        }
        if ($groupId <= 0 || $teacherId <= 0 || $subjectId <= 0) {
            $error = 'Укажите группу и куратора';
        } else {
            $result = $uchebni->addScheduleSlot([
                'period_id' => $period_id,
                'group_id' => $groupId,
                'subject_id' => $subjectId,
                'teacher_id' => $teacherId,
                'classroom_id' => $classroomId,
                'day_of_week' => 2, // вторник
                'pair_number' => 0,
                'shift' => $shift,
                'week_kind' => Uchebni::WEEK_KIND_ALL,
                'lesson_type' => 'curator',
            ], $current_user['id']);
            if (!empty($result['success'])) {
                $message = 'Кураторский час добавлен (вторник, первый слот)';
                $filter_shift = $shift;
            } else {
                $error = $result['error'] ?? 'Не удалось сохранить';
                if (!empty($result['conflicts'])) {
                    $msgs = [];
                    foreach ($result['conflicts'] as $c) {
                        $msgs[] = $c['message'] ?? 'конфликт';
                    }
                    $error .= ': ' . implode('; ', $msgs);
                }
            }
        }
    }

    if ($action === 'add' && $can_manage && $period_id) {
        $error = 'Ручное добавление обычных пар отключено. Кураторский час — кнопкой «Кураторский час».';
    }

    if ($action === 'delete' && $can_manage) {
        if ($uchebni->deleteScheduleSlot((int)$_POST['slot_id'])) {
            $message = 'Пара удалена';
        }
    }

    if ($action === 'delete_selected' && $can_manage) {
        $ids = isset($_POST['slot_ids']) && is_array($_POST['slot_ids']) ? $_POST['slot_ids'] : [];
        $n = $uchebni->deleteScheduleSlots($ids);
        $message = $n > 0 ? ('Удалено пар: ' . $n) : 'Ничего не выбрано';
    }

    if ($action === 'clear_schedule' && $can_manage && $period_id) {
        $clearGroup = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $clearShift = (int)($_POST['shift'] ?? $filter_shift);
        if ($clearShift !== 2) {
            $clearShift = 1;
        }
        $keepCurator = empty($_POST['include_curator']);
        $n = $uchebni->clearScheduleSlots($period_id, $clearGroup, $clearShift, $keepCurator);
        $scope = $clearGroup ? 'по выбранной группе' : 'по всей смене';
        $message = $n > 0
            ? ('Расписание очищено ' . $scope . ': удалено ' . $n . ' пар'
                . ($keepCurator ? ' (кураторский час сохранён)' : ' (включая кураторский час)'))
            : 'Нечего удалять — активных пар нет';
        $filter_shift = $clearShift;
        if ($clearGroup) {
            $filter_group = $clearGroup;
        }
    }

    if ($action === 'delete_substitution' && $can_manage) {
        $subId = (int)($_POST['substitution_id'] ?? 0);
        $ok = false;
        if ($subId > 0) {
            $ok = $uchebni->deleteSubstitution($subId);
        } else {
            $sid = (int)($_POST['schedule_id'] ?? 0);
            $ld = $_POST['lesson_date'] ?? '';
            if ($sid > 0 && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ld)) {
                $rows = $uchebni->getSubstitutions([
                    'schedule_id' => $sid,
                    'lesson_date' => $ld,
                ]);
                if (!empty($rows[0]['id'])) {
                    $ok = $uchebni->deleteSubstitution((int)$rows[0]['id']);
                }
            }
        }
        if ($ok) {
            $message = 'Замена удалена — часы вернулись исходному преподавателю';
        } else {
            $error = 'Не удалось удалить замену';
        }
    }

    if ($action === 'set_classroom' && $can_manage) {
        $result = $uchebni->updateScheduleClassroom(
            (int)($_POST['slot_id'] ?? 0),
            (int)($_POST['classroom_id'] ?? 0)
        );
        if (!empty($result['success'])) {
            $message = 'Кабинет сохранён';
        } else {
            $error = $result['error'] ?? 'Не удалось сохранить кабинет';
        }
    }

    if ($action === 'auto' && $can_auto && $period_id) {
        @set_time_limit(120);
        $group_id = !empty($_POST['group_id']) ? (int)$_POST['group_id'] : null;
        $auto_shift = (int)($_POST['shift'] ?? 1) === 2 ? 2 : 1;
        try {
            $auto_result = $uchebni->autoGenerateSchedule($period_id, $group_id, $current_user['id'], $auto_shift);
        } catch (Throwable $e) {
            $auto_result = ['success' => false, 'error' => 'Сбой автоформирования: ' . $e->getMessage()];
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $redir = 'schedule.php?' . http_build_query(array_filter([
            'view' => $view_mode,
            'shift' => $auto_shift,
            'group_id' => $group_id ?: null,
            'date' => $selected_date,
            'auto' => 1,
        ]));

        if (!empty($auto_result['success'])) {
            $msg = 'Готово: сформировано ' . (int)$auto_result['placed'] . ' пар'
                . ' (числитель/знаменатель учтены). Старое расписание по выбранной области заменено.';
            if (!empty($auto_result['hint'])) {
                $msg .= ' ' . $auto_result['hint'];
            }
            $_SESSION['uchebni_flash_ok'] = $msg;
            if (!empty($auto_result['failed'])) {
                $_SESSION['uchebni_flash_err'] = 'Не размещено полностью: ' . implode('; ', array_slice($auto_result['failed'], 0, 8));
            }
        } else {
            $_SESSION['uchebni_flash_err'] = $auto_result['error'] ?? 'Ошибка автоформирования';
        }
        header('Location: ' . $redir);
        exit;
    }
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!empty($_SESSION['uchebni_flash_ok'])) {
    $message = (string)$_SESSION['uchebni_flash_ok'];
    unset($_SESSION['uchebni_flash_ok']);
}
if (!empty($_SESSION['uchebni_flash_err'])) {
    $error = (string)$_SESSION['uchebni_flash_err'];
    unset($_SESSION['uchebni_flash_err']);
}

$filters = ['period_id' => $period_id, 'shift' => $filter_shift];
if ($filter_group) $filters['group_id'] = $filter_group;
if ($filter_teacher) $filters['teacher_id'] = $filter_teacher;

// Даты недели относительно выбранной даты (для показа замен)
$anchor_ts = strtotime($selected_date);
$anchor_dow = (int)date('N', $anchor_ts);
$week_monday_ts = strtotime('-' . ($anchor_dow - 1) . ' days', $anchor_ts);
$week_dates = [];
for ($d = 1; $d <= 5; $d++) {
    $week_dates[$d] = date('Y-m-d', strtotime('+' . ($d - 1) . ' days', $week_monday_ts));
}

$week_mode = ($_GET['week_mode'] ?? 'template') === 'current' ? 'current' : 'template';
$view_week_kind = $uchebni->getWeekKindForDate($week_dates[1], $period);
$day_week_kind = $uchebni->getWeekKindForDate($selected_date, $period);

/**
 * Разбить слоты ячейки на верх (числитель) и низ (знаменатель) — как в Excel колледжа.
 * Верх = числитель; низ = знаменатель; «каждую неделю» — на обе половины (объединение).
 */
function uchebni_split_pair_halves(array $slots)
{
    $num = [];
    $den = [];
    $all = [];
    foreach ($slots as $slot) {
        $kind = Uchebni::normalizeWeekKind($slot['week_kind'] ?? 'all');
        if ($kind === Uchebni::WEEK_KIND_NUM) {
            $num[] = $slot;
        } elseif ($kind === Uchebni::WEEK_KIND_DEN) {
            $den[] = $slot;
        } else {
            $all[] = $slot;
        }
    }
    return [
        'top' => array_merge($all, $num),
        'bottom' => array_merge($all, $den),
        'all' => $all,
        'num' => $num,
        'den' => $den,
        'is_merged' => !empty($all) && empty($num) && empty($den),
    ];
}

function uchebni_render_classroom_select(array $slot, array $classrooms, $can_manage)
{
    $currentId = (int)($slot['classroom_id'] ?? 0);
    $label = trim((string)($slot['classroom_number'] ?? ''));
    if (!$can_manage) {
        echo $label !== '' ? ('каб. ' . htmlspecialchars($label)) : '<span class="text-warning">каб. —</span>';
        return;
    }
    echo '<form method="post" class="uchebni-room-form mt-1" onchange="this.submit()">';
    echo '<input type="hidden" name="action" value="set_classroom">';
    echo '<input type="hidden" name="slot_id" value="' . (int)$slot['id'] . '">';
    echo '<select name="classroom_id" class="form-select form-select-sm uchebni-room-select' . ($currentId <= 0 ? ' is-unassigned' : '') . '" title="Кабинет">';
    echo '<option value="0"' . ($currentId <= 0 ? ' selected' : '') . '>каб. —</option>';
    foreach ($classrooms as $c) {
        $cid = (int)$c['id'];
        $sel = $cid === $currentId ? ' selected' : '';
        $txt = 'каб. ' . ($c['number'] ?? '');
        if (!empty($c['building'])) {
            $txt .= ' (' . $c['building'] . ')';
        }
        echo '<option value="' . $cid . '"' . $sel . '>' . htmlspecialchars($txt) . '</option>';
    }
    echo '</select></form>';
}

function uchebni_render_half_lessons(array $slots, $can_manage, $skipAllDelete = false, array $classrooms = [], $dayDate = null)
{
    if (empty($slots)) {
        echo '<span class="uchebni-half-empty">—</span>';
        return;
    }
    foreach ($slots as $slot) {
        $sub = $slot['_sub'] ?? null;
        $displayTeacher = $sub ? ($sub['substitute_teacher_name'] ?? '') : ($slot['teacher_name'] ?? '');
        $subSubject = $sub['substitute_subject_name'] ?? ($slot['substitute_subject_name'] ?? '');
        $displaySubject = ($sub && $subSubject !== '' && $subSubject !== null)
            ? $subSubject
            : ($slot['subject_name'] ?? '');
        $kind = Uchebni::normalizeWeekKind($slot['week_kind'] ?? 'all');
        $showDelete = $can_manage && !($skipAllDelete && $kind === Uchebni::WEEK_KIND_ALL);
        $slotDate = $dayDate ?: ($slot['substitution_date'] ?? null);
        echo '<div class="uchebni-half-lesson' . ($sub ? ' lesson-with-sub' : '') . '">';
        // Чекбокс один раз: для «каждую нед.» не дублируем в нижней половине Ч/З
        $showCheck = $can_manage && !($skipAllDelete && $kind === Uchebni::WEEK_KIND_ALL);
        if ($showCheck) {
            echo '<label class="uchebni-slot-check form-check mb-1">';
            echo '<input type="checkbox" class="form-check-input slot-check" form="scheduleBulkForm" name="slot_ids[]" value="'
                . (int)$slot['id'] . '">';
            echo '<span class="form-check-label small text-muted">выбрать</span>';
            echo '</label>';
        }
        echo '<div class="lesson-subject">' . htmlspecialchars($displaySubject);
        if ($sub) {
            echo ' <span class="lesson-sub-badge">замена</span>';
        }
        echo '</div>';
        echo '<div class="lesson-meta">' . htmlspecialchars($slot['group_name'] ?? '') . '<br>';
        if ($sub) {
            $origName = $slot['teacher_name'] ?? '';
            echo '<span class="text-success">' . htmlspecialchars($displayTeacher) . '</span>';
            if ($origName !== '') {
                echo '<br><span class="lesson-sub-orig">вместо ' . htmlspecialchars($origName) . '</span>';
            }
        } else {
            echo htmlspecialchars($displayTeacher);
        }
        echo '</div>';
        uchebni_render_classroom_select($slot, $classrooms, $can_manage);
        if ($can_manage && $slotDate) {
            if ($sub) {
                $subId = (int)($sub['id'] ?? ($slot['substitution_id'] ?? 0));
                if ($subId > 0) {
                    echo '<a class="btn btn-link btn-sm p-0" style="font-size:0.65rem" href="substitutions.php?edit='
                        . $subId . '">правка</a> ';
                } else {
                    echo '<a class="btn btn-link btn-sm p-0" style="font-size:0.65rem" href="substitutions.php?schedule_id='
                        . (int)$slot['id'] . '&amp;date=' . urlencode($slotDate) . '">правка</a> ';
                }
                echo '<form method="post" class="d-inline" onsubmit="return confirm(\'Удалить замену? Часы вернутся исходному преподавателю.\');">';
                echo '<input type="hidden" name="action" value="delete_substitution">';
                if ($subId > 0) {
                    echo '<input type="hidden" name="substitution_id" value="' . $subId . '">';
                }
                echo '<input type="hidden" name="schedule_id" value="' . (int)$slot['id'] . '">';
                echo '<input type="hidden" name="lesson_date" value="' . htmlspecialchars($slotDate) . '">';
                echo '<input type="hidden" name="return_view" value="week">';
                if ($slotDate) {
                    echo '<input type="hidden" name="return_date" value="' . htmlspecialchars($slotDate) . '">';
                }
                echo '<button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:0.65rem">удалить замену</button>';
                echo '</form> ';
            } else {
                echo '<a class="btn btn-link btn-sm p-0" style="font-size:0.65rem" href="substitutions.php?schedule_id='
                    . (int)$slot['id'] . '&amp;date=' . urlencode($slotDate) . '">замена</a> ';
            }
        }
        if ($showDelete) {
            echo '<form method="post" class="mt-1" onsubmit="return confirm(\'Удалить пару?\');">';
            echo '<input type="hidden" name="action" value="delete">';
            echo '<input type="hidden" name="slot_id" value="' . (int)$slot['id'] . '">';
            echo '<button type="submit" class="btn btn-link btn-sm p-0 text-danger" style="font-size:0.65rem">удалить</button>';
            echo '</form>';
        }
        echo '</div>';
    }
}

$schedule = $period_id ? $uchebni->getSchedule($filters) : [];
$subs_by_slot_date = []; // "scheduleId|Y-m-d" => sub
$subs_for_banner = [];

if ($period_id) {
    $subFilters = [
        'date_from' => $week_dates[1],
        'date_to' => $week_dates[5],
    ];
    if ($filter_group) {
        $subFilters['group_id'] = $filter_group;
    }
    // На день — берём все замены выбранной даты (и неделю для сетки)
    if ($view_mode === 'day') {
        $subFilters = ['lesson_date' => $selected_date];
        if ($filter_group) {
            $subFilters['group_id'] = $filter_group;
        }
    }

    $rawSubs = $uchebni->getSubstitutions($subFilters);
    // Если смотрим день — дополнительно подтянуть неделю для контекста; для недели уже есть
    if ($view_mode === 'day') {
        $weekSubsExtra = $uchebni->getSubstitutions([
            'date_from' => $week_dates[1],
            'date_to' => $week_dates[5],
            'group_id' => $filter_group ?: null,
        ]);
        $rawSubs = array_merge($rawSubs, $weekSubsExtra);
    }

    $haveIds = array_map(function ($r) {
        return (int)$r['id'];
    }, $schedule);
    $seenSubKeys = [];

    foreach ($rawSubs as $sub) {
        $subDate = substr((string)($sub['lesson_date'] ?? ''), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subDate)) {
            continue;
        }
        $subShift = (int)($sub['shift'] ?? 1);
        if ($subShift !== 2) {
            $subShift = 1;
        }
        if ($subShift !== $filter_shift) {
            continue;
        }
        if ($filter_teacher) {
            $ot = (int)$sub['original_teacher_id'];
            $st = (int)$sub['substitute_teacher_id'];
            if ($ot !== $filter_teacher && $st !== $filter_teacher) {
                continue;
            }
        }

        $sid = (int)$sub['schedule_id'];
        $key = $sid . '|' . $subDate;
        if (isset($seenSubKeys[$key])) {
            continue;
        }
        $seenSubKeys[$key] = true;
        $subs_by_slot_date[$key] = $sub;

        // В баннер — замены на выбранный день или на всю неделю
        if ($view_mode === 'day') {
            if ($subDate === $selected_date) {
                $subs_for_banner[] = $sub;
            }
        } else {
            $subs_for_banner[] = $sub;
        }

        // Подмешать поля замены в уже загруженные слоты
        foreach ($schedule as &$schRow) {
            if ((int)$schRow['id'] === $sid && $subDate === ($view_mode === 'day' ? $selected_date : ($week_dates[(int)$schRow['day_of_week']] ?? ''))) {
                $schRow['substitute_teacher_id'] = $sub['substitute_teacher_id'];
                $schRow['substitute_teacher_name'] = $sub['substitute_teacher_name'];
                $schRow['substitute_subject_id'] = $sub['substitute_subject_id'] ?? null;
                $schRow['substitute_subject_name'] = $sub['substitute_subject_name'] ?? null;
                $schRow['substitution_reason'] = $sub['reason'] ?? '';
                $schRow['substitution_date'] = $subDate;
                $schRow['substitution_id'] = (int)($sub['id'] ?? 0);
            }
        }
        unset($schRow);

        // Пара, где фильтр — заменяющий, а слота ещё нет в выборке
        if ($filter_teacher && (int)$sub['substitute_teacher_id'] === $filter_teacher && !in_array($sid, $haveIds, true)) {
            $slot = $uchebni->getScheduleById($sid);
            if ($slot && (int)$slot['is_active'] && (int)($slot['shift'] ?? 1) === $filter_shift) {
                $slot['substitute_teacher_id'] = $sub['substitute_teacher_id'];
                $slot['substitute_teacher_name'] = $sub['substitute_teacher_name'];
                $slot['substitute_subject_id'] = $sub['substitute_subject_id'] ?? null;
                $slot['substitute_subject_name'] = $sub['substitute_subject_name'] ?? null;
                $slot['substitution_reason'] = $sub['reason'] ?? '';
                $slot['substitution_date'] = $subDate;
                $slot['substitution_id'] = (int)($sub['id'] ?? 0);
                $schedule[] = $slot;
                $haveIds[] = $sid;
            }
        }
    }

    // Для дневного вида — явно повесить замену выбранной даты на слоты
    if ($view_mode === 'day') {
        foreach ($schedule as &$schRow) {
            $key = (int)$schRow['id'] . '|' . $selected_date;
            if (isset($subs_by_slot_date[$key])) {
                $sub = $subs_by_slot_date[$key];
                $schRow['substitute_teacher_id'] = $sub['substitute_teacher_id'];
                $schRow['substitute_teacher_name'] = $sub['substitute_teacher_name'];
                $schRow['substitute_subject_id'] = $sub['substitute_subject_id'] ?? null;
                $schRow['substitute_subject_name'] = $sub['substitute_subject_name'] ?? null;
                $schRow['substitution_reason'] = $sub['reason'] ?? '';
                $schRow['substitution_date'] = $selected_date;
                $schRow['substitution_id'] = (int)($sub['id'] ?? 0);
            }
        }
        unset($schRow);

        // У исходного преподавателя пара с заменой на этот день убирается
        if ($filter_teacher) {
            $schedule = array_values(array_filter($schedule, function ($row) use ($filter_teacher, $selected_date, $subs_by_slot_date) {
                $key = (int)$row['id'] . '|' . $selected_date;
                $sub = $subs_by_slot_date[$key] ?? null;
                if ($sub
                    && (int)$sub['original_teacher_id'] === $filter_teacher
                    && (int)$sub['substitute_teacher_id'] !== $filter_teacher
                ) {
                    return false;
                }
                return true;
            }));
        }
    }
}
$groups = $uchebni->getActiveGroups();
$subjects = $uchebni->getSubjects();
$teachers = $uchebni->getTeachers();
$classrooms = $uchebni->getClassrooms();

$selected_group = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === $filter_group) {
        $selected_group = $g;
        break;
    }
}

$selected_teacher = null;
foreach ($teachers as $t) {
    if ((int)$t['id'] === $filter_teacher) {
        $selected_teacher = $t;
        break;
    }
}

// Сетка: day → pair → slots (только текущая смена)
$grid = [];
foreach ($schedule as $slot) {
    $slotKind = Uchebni::normalizeWeekKind($slot['week_kind'] ?? 'all');
    if ($week_mode === 'current') {
        // Для шаблона недели фильтруем по виду недели понедельника; для дня — ниже отдельно
        if ($view_mode === 'day') {
            if (!Uchebni::slotActiveOnWeekKind($slotKind, $day_week_kind)) {
                continue;
            }
        } elseif (!Uchebni::slotActiveOnWeekKind($slotKind, $view_week_kind)) {
            continue;
        }
    }
    $grid[(int)$slot['day_of_week']][(int)$slot['pair_number']][] = $slot;
}

// Строки сетки = объединение слотов weekday + tuesday для смены
$pair_rows = [];
foreach (['weekday', 'tuesday'] as $dt) {
    foreach ($uchebni->getBellSlots($dt, $filter_shift) as $bell) {
        $pn = (int)$bell['pair_number'];
        if (!isset($pair_rows[$pn])) {
            $pair_rows[$pn] = [
                'pair_number' => $pn,
                'label' => $bell['label'],
                'sort' => (int)$bell['sort_order'],
            ];
        }
    }
}
uksort($pair_rows, function ($a, $b) use ($pair_rows) {
    return $pair_rows[$a]['sort'] <=> $pair_rows[$b]['sort'];
});

// Карта доступности слота по дню
$bell_by_day_pair = [];
foreach (Uchebni::DAY_NAMES as $d => $_) {
    foreach ($uchebni->getBellSlotsForDay($d, $filter_shift) as $bell) {
        $bell_by_day_pair[$d][(int)$bell['pair_number']] = $bell;
    }
}

$bell_map_js = [];
foreach ([1, 2] as $sh) {
    foreach (Uchebni::DAY_NAMES as $d => $_) {
        $bell_map_js[$sh][$d] = [];
        foreach ($uchebni->getBellSlotsForDay($d, $sh) as $bell) {
            $bell_map_js[$sh][$d][] = [
                'pair_number' => (int)$bell['pair_number'],
                'label' => $bell['label'],
                'start' => substr($bell['start_time'], 0, 5),
                'end' => substr($bell['end_time'], 0, 5),
            ];
        }
    }
}

$group_options = [
    ['id' => 0, 'label' => 'Все группы', 'meta' => 'Показать всё расписание', 'search' => 'все группы'],
];
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

$teacher_options = [
    ['id' => 0, 'label' => 'Все преподаватели', 'meta' => 'Без фильтра', 'search' => 'все преподаватели'],
];
foreach ($teachers as $t) {
    $fio = Uchebni::formatFio($t);
    $meta_parts = [];
    if (!empty($t['department'])) {
        $meta_parts[] = $t['department'];
    }
    if (!empty($t['position'])) {
        $meta_parts[] = $t['position'];
    }
    $teacher_options[] = [
        'id' => (int)$t['id'],
        'label' => $fio,
        'meta' => implode(' · ', $meta_parts),
        'search' => mb_strtolower($fio . ' ' . ($t['department'] ?? '') . ' ' . ($t['position'] ?? '')),
    ];
}

$modal_group_options = array_values(array_filter($group_options, function ($o) {
    return (int)$o['id'] > 0;
}));
$modal_teacher_options = array_values(array_filter($teacher_options, function ($o) {
    return (int)$o['id'] > 0;
}));

$subject_options = [];
$subjects_by_category = [];
$category_counts = [];
$curator_subject_id = $uchebni->getOrCreateCuratorSubject();
$curator_classroom_id = $uchebni->getOrCreateCuratorClassroom();
$group_curator_map = $uchebni->getGroupCuratorTeacherMap();
foreach ($subjects as $s) {
    if ((int)$s['id'] === $curator_subject_id || ($s['code'] ?? '') === 'CURATOR') {
        continue;
    }
    $num = Uchebni::formatSubjectNumber($s);
    $title = Uchebni::formatSubjectTitle($s);
    $label = $num !== '' ? ($num . ' · ' . $title) : $title;
    $cat = trim((string)($s['category_code'] ?? ''));
    if ($cat === '') {
        $cat = '_other';
    }
    $meta_parts = [];
    if (!empty($s['category_name'])) {
        $meta_parts[] = $s['category_name'];
    }
    $opt = [
        'id' => (int)$s['id'],
        'label' => $label,
        'meta' => implode(' · ', $meta_parts),
        'search' => mb_strtolower($label . ' ' . ($s['category_code'] ?? '') . ' ' . ($s['category_name'] ?? '')),
        'category' => $cat,
    ];
    $subject_options[] = $opt;
    $subjects_by_category[$cat][] = $opt;
    if (!isset($category_counts[$cat])) {
        $category_counts[$cat] = [
            'code' => $cat === '_other' ? '' : $cat,
            'name' => $cat === '_other' ? 'Без блока' : trim((string)($s['category_name'] ?? '')),
            'count' => 0,
        ];
    }
    $category_counts[$cat]['count']++;
    if ($category_counts[$cat]['name'] === '' && !empty($s['category_name'])) {
        $category_counts[$cat]['name'] = trim((string)$s['category_name']);
    }
}

$category_options = [];
foreach ($category_counts as $codeKey => $cat) {
    $code = $cat['code'] !== '' ? $cat['code'] : 'Прочее';
    $label = trim($code . ($cat['name'] !== '' && $cat['name'] !== $code ? ' · ' . $cat['name'] : ''));
    $n = (int)$cat['count'];
    $meta = $n . ' ' . ($n === 1 ? 'дисциплина' : (($n >= 2 && $n <= 4) ? 'дисциплины' : 'дисциплин'));
    $category_options[] = [
        'id' => $codeKey,
        'label' => $label,
        'meta' => $meta,
        'search' => mb_strtolower($code . ' ' . $cat['name']),
    ];
}
usort($category_options, function ($a, $b) {
    if ($a['id'] === '_other') return 1;
    if ($b['id'] === '_other') return -1;
    return strcmp($a['label'], $b['label']);
});

$classroom_options = [];
foreach ($classrooms as $c) {
    $label = $c['number'];
    $meta_parts = [];
    if (!empty($c['building'])) {
        $meta_parts[] = $c['building'];
    }
    if (!empty($c['capacity'])) {
        $meta_parts[] = $c['capacity'] . ' мест';
    }
    $classroom_options[] = [
        'id' => (int)$c['id'],
        'label' => 'каб. ' . $label,
        'meta' => implode(' · ', $meta_parts),
        'search' => mb_strtolower($label . ' ' . ($c['building'] ?? '') . ' ' . ($c['equipment'] ?? '')),
    ];
}

// subject_id => [teacher_id, ...]
$subject_teacher_ids = [];
foreach ($subjects as $s) {
    $sid = (int)$s['id'];
    $subject_teacher_ids[$sid] = $uchebni->getSubjectTeacherIds($sid);
}

$selected_group_label = $selected_group
    ? ($selected_group['name'] . (!empty($selected_group['code']) ? ' (' . $selected_group['code'] . ')' : ''))
    : 'Все группы';
$selected_group_meta_parts = [];
if ($selected_group && !empty($selected_group['course'])) {
    $selected_group_meta_parts[] = $selected_group['course'] . ' курс';
}
if ($selected_group && !empty($selected_group['specialty'])) {
    $selected_group_meta_parts[] = $selected_group['specialty'];
}
$selected_group_meta = $selected_group
    ? implode(' · ', $selected_group_meta_parts)
    : 'Показать всё расписание';

$group_weekly_hours = ($period_id && $filter_group)
    ? $uchebni->getGroupWeeklyHours($period_id, $filter_group, $filter_shift)
    : null;
$group_hours_over = ($group_weekly_hours !== null && $group_weekly_hours > Uchebni::MAX_WEEKLY_HOURS + 0.01);

$selected_teacher_label = $selected_teacher
    ? Uchebni::formatFio($selected_teacher)
    : 'Все преподаватели';
$selected_teacher_meta_parts = [];
if ($selected_teacher && !empty($selected_teacher['department'])) {
    $selected_teacher_meta_parts[] = $selected_teacher['department'];
}
if ($selected_teacher && !empty($selected_teacher['position'])) {
    $selected_teacher_meta_parts[] = $selected_teacher['position'];
}
$selected_teacher_meta = $selected_teacher
    ? implode(' · ', $selected_teacher_meta_parts)
    : 'Без фильтра';

// День календаря
$selected_dow = (int)date('N', strtotime($selected_date)); // 1=Пн … 7=Вс
$selected_day_name = Uchebni::DAY_NAMES[$selected_dow] ?? 'Выходной';
$is_study_day = isset(Uchebni::DAY_NAMES[$selected_dow]);
$day_bells = $is_study_day ? $uchebni->getBellSlotsForDay($selected_dow, $filter_shift) : [];
$day_slots_by_pair = $grid[$selected_dow] ?? [];
$day_lesson_count = 0;
foreach ($day_slots_by_pair as $plist) {
    $day_lesson_count += count($plist);
}

// Календарь месяца
$cal_ts = strtotime($cal_month . '-01');
$cal_year = (int)date('Y', $cal_ts);
$cal_mon = (int)date('n', $cal_ts);
$cal_days_in_month = (int)date('t', $cal_ts);
$cal_start_dow = (int)date('N', $cal_ts); // 1=Пн
$cal_prev = date('Y-m', strtotime('-1 month', $cal_ts));
$cal_next = date('Y-m', strtotime('+1 month', $cal_ts));
$lessons_by_dow = [];
foreach (Uchebni::DAY_NAMES as $d => $_) {
    $lessons_by_dow[$d] = 0;
}
foreach ($schedule as $slot) {
    $d = (int)$slot['day_of_week'];
    if (isset($lessons_by_dow[$d])) {
        $lessons_by_dow[$d]++;
    }
}

// Даты месяца с заменами (для точек на календаре)
$subs_dates_in_month = [];
if ($period_id) {
    $monthStart = $cal_month . '-01';
    $monthEnd = date('Y-m-t', strtotime($monthStart));
    $monthSubs = $uchebni->getSubstitutions([
        'date_from' => $monthStart,
        'date_to' => $monthEnd,
        'group_id' => $filter_group ?: null,
    ]);
    foreach ($monthSubs as $sub) {
        $subShift = (int)($sub['shift'] ?? 1) === 2 ? 2 : 1;
        if ($subShift !== $filter_shift) {
            continue;
        }
        $d = substr((string)$sub['lesson_date'], 0, 10);
        $subs_dates_in_month[$d] = ($subs_dates_in_month[$d] ?? 0) + 1;
    }
}

$filter_query = http_build_query(array_filter([
    'shift' => $filter_shift,
    'group_id' => $filter_group ?: null,
    'teacher_id' => $filter_teacher ?: null,
    'week_mode' => $week_mode !== 'current' ? $week_mode : null,
    'date' => $view_mode === 'day' ? $selected_date : null,
]));

$page_title = 'Расписание';
$page_subtitle = ($period ? $period['name'] . ' · ' : '')
    . (Uchebni::SHIFT_NAMES[$filter_shift] ?? '')
    . ' · '
    . (Uchebni::WEEK_KIND_NAMES[$view_week_kind] ?? '');
require_once 'includes/header.php';
?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if (!empty($_GET['sub'])): ?>
<div class="alert alert-success alert-dismissible fade show">Замена сохранена — в расписании отмечена, часы перенесены заменяющему.<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<?php if (!empty($subs_for_banner)): ?>
<div class="alert alert-warning border-warning">
    <div class="fw-semibold mb-1">
        <i class="bi bi-arrow-left-right me-1"></i>
        <?php echo $view_mode === 'day' ? 'Замены на этот день' : 'Замены на этой неделе'; ?>
        (<?php echo count($subs_for_banner); ?>)
    </div>
    <ul class="mb-0 small">
        <?php foreach ($subs_for_banner as $sub):
            $subDate = substr((string)$sub['lesson_date'], 0, 10);
            $pairLabel = ((int)$sub['pair_number'] === 0) ? 'Кураторский час' : ((int)$sub['pair_number'] . ' пара');
            $dayName = Uchebni::DAY_NAMES[(int)$sub['day_of_week']] ?? '';
        ?>
        <li>
            <a href="?view=day&amp;date=<?php echo urlencode($subDate); ?>&amp;month=<?php echo urlencode(substr($subDate, 0, 7)); ?>&amp;<?php echo htmlspecialchars($filter_query); ?>">
                <?php echo htmlspecialchars(formatDate($subDate)); ?>
            </a>
            · <?php echo htmlspecialchars($dayName); ?>, <?php echo htmlspecialchars($pairLabel); ?>
            · <?php echo htmlspecialchars($sub['group_name']); ?> /
            <?php
                $bannerSubject = !empty($sub['substitute_subject_name'])
                    ? $sub['substitute_subject_name']
                    : $sub['subject_name'];
                echo htmlspecialchars($bannerSubject);
            ?>
            · <strong><?php echo htmlspecialchars($sub['substitute_teacher_name']); ?></strong>
            <span class="text-muted">ведёт пару</span>
        </li>
        <?php endforeach; ?>
    </ul>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-end gap-3 mb-3">
    <form method="get" id="scheduleFilterForm" class="d-flex flex-wrap gap-3" style="flex:1; min-width:280px">
        <input type="hidden" name="view" id="filter_view" value="<?php echo htmlspecialchars($view_mode); ?>">
        <input type="hidden" name="date" id="filter_date" value="<?php echo htmlspecialchars($selected_date); ?>">
        <input type="hidden" name="month" id="filter_month" value="<?php echo htmlspecialchars($cal_month); ?>">
        <div style="min-width:140px">
            <label class="form-label mb-1">Смена</label>
            <select name="shift" class="form-select" onchange="this.form.submit()">
                <option value="1" <?php echo $filter_shift === 1 ? 'selected' : ''; ?>>I смена</option>
                <option value="2" <?php echo $filter_shift === 2 ? 'selected' : ''; ?>>II смена</option>
            </select>
        </div>
        <?php if ($current_user['role'] !== 'teacher'): ?>
        <div style="flex:1; min-width:240px; max-width:360px">
            <label class="form-label mb-1">Группа</label>
            <input type="hidden" name="group_id" id="filter_group_id" value="<?php echo (int)$filter_group; ?>">
            <div class="uchebni-combobox" id="filterGroupCombobox">
                <button type="button" class="uchebni-combobox-toggle" id="filterGroupComboboxToggle" aria-expanded="false">
                    <i class="bi bi-people-fill uchebni-combobox-icon"></i>
                    <span class="uchebni-combobox-label">
                        <span class="uchebni-combobox-title" id="filterGroupComboboxTitle"><?php echo htmlspecialchars($selected_group_label); ?></span>
                        <span class="uchebni-combobox-meta <?php echo $selected_group_meta ? '' : 'd-none'; ?>" id="filterGroupComboboxMeta"><?php echo htmlspecialchars($selected_group_meta); ?></span>
                    </span>
                    <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                </button>
                <div class="uchebni-combobox-panel d-none" id="filterGroupComboboxPanel">
                    <div class="uchebni-combobox-search">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="filterGroupComboboxSearch" placeholder="Поиск группы…" autocomplete="off">
                        </div>
                    </div>
                    <div class="uchebni-combobox-list" id="filterGroupComboboxList"></div>
                </div>
            </div>
        </div>
        <div style="flex:1; min-width:240px; max-width:360px">
            <label class="form-label mb-1">Преподаватель</label>
            <div class="uchebni-combobox" id="filterTeacherCombobox">
                <button type="button" class="uchebni-combobox-toggle" id="filterTeacherComboboxToggle" aria-expanded="false">
                    <i class="bi bi-person-badge uchebni-combobox-icon"></i>
                    <span class="uchebni-combobox-label">
                        <span class="uchebni-combobox-title" id="filterTeacherComboboxTitle"><?php echo htmlspecialchars($selected_teacher_label); ?></span>
                        <span class="uchebni-combobox-meta <?php echo $selected_teacher_meta ? '' : 'd-none'; ?>" id="filterTeacherComboboxMeta"><?php echo htmlspecialchars($selected_teacher_meta); ?></span>
                    </span>
                    <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                </button>
                <div class="uchebni-combobox-panel d-none" id="filterTeacherComboboxPanel">
                    <div class="uchebni-combobox-search">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="search" class="form-control" id="filterTeacherComboboxSearch" placeholder="Поиск преподавателя…" autocomplete="off">
                        </div>
                    </div>
                    <div class="uchebni-combobox-list" id="filterTeacherComboboxList"></div>
                </div>
            </div>
            <input type="hidden" name="teacher_id" id="filter_teacher_id" value="<?php echo (int)$filter_teacher; ?>">
        </div>
        <?php endif; ?>
    </form>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($can_manage): ?>
            <button type="button" class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#curatorHourModal">
                <i class="bi bi-people me-1"></i>Кураторский час
            </button>
            <a href="substitutions.php" class="btn btn-outline-warning"><i class="bi bi-arrow-left-right me-1"></i>Замены</a>
            <button type="button" class="btn btn-outline-danger" data-bs-toggle="modal" data-bs-target="#clearScheduleModal">
                <i class="bi bi-trash me-1"></i>Очистить расписание
            </button>
            <a href="bells.php" class="btn btn-outline-secondary"><i class="bi bi-bell me-1"></i>Звонки</a>
            <a href="curriculum.php" class="btn btn-outline-secondary"><i class="bi bi-journal-bookmark me-1"></i>Учебный план</a>
        <?php endif; ?>
        <?php if ($can_auto): ?>
            <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#autoScheduleModal">
                <i class="bi bi-magic me-1"></i>Сформировать расписание
            </button>
        <?php endif; ?>
    </div>
</div>

<ul class="nav nav-pills gap-2 mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $view_mode === 'week' ? 'active' : ''; ?>"
           href="?view=week&amp;<?php echo htmlspecialchars($filter_query); ?>">
            <i class="bi bi-calendar-week me-1"></i>Неделя
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $view_mode === 'day' ? 'active' : ''; ?>"
           href="?view=day&amp;date=<?php echo urlencode($selected_date); ?>&amp;month=<?php echo urlencode($cal_month); ?>&amp;<?php echo htmlspecialchars($filter_query); ?>">
            <i class="bi bi-calendar3 me-1"></i>День
        </a>
    </li>
</ul>

<div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="badge <?php echo $view_week_kind === 'num' ? 'bg-primary' : 'bg-secondary'; ?>">
        Сейчас: <?php echo $view_week_kind === 'num' ? '1-я неделя — числитель' : '2-я неделя — знаменатель'; ?>
    </span>
    <?php if ($group_weekly_hours !== null): ?>
    <span class="badge <?php echo $group_hours_over ? 'bg-danger' : 'bg-success'; ?>"
          title="Без кураторского часа. Максимум <?php echo (int)Uchebni::MAX_WEEKLY_HOURS; ?> акад.ч/нед">
        Нагрузка группы: <?php echo rtrim(rtrim(number_format((float)$group_weekly_hours, 1, '.', ''), '0'), '.'); ?> /
        <?php echo (int)Uchebni::MAX_WEEKLY_HOURS; ?> ч/нед
    </span>
    <?php endif; ?>
    <span class="text-muted small">неделя с <?php echo date('d.m', $week_monday_ts); ?> · в ячейке: верх = Ч, низ = З (как в Excel)</span>
    <?php if ($can_manage): ?>
    <form method="post" id="scheduleBulkForm" class="d-flex flex-wrap gap-2 align-items-center ms-2">
        <input type="hidden" name="action" value="delete_selected">
        <input type="hidden" name="return_view" value="<?php echo htmlspecialchars($view_mode); ?>">
        <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
        <button type="submit" class="btn btn-sm btn-outline-danger" id="deleteSelectedBtn" disabled
                onclick="return confirm('Удалить выбранные пары из расписания?');">
            <i class="bi bi-check2-square me-1"></i>Удалить выбранные
            <span class="badge bg-danger" id="selectedCount">0</span>
        </button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllSlotsBtn">Выбрать все</button>
        <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSlotSelectionBtn">Снять</button>
    </form>
    <?php endif; ?>
    <div class="ms-auto btn-group btn-group-sm">
        <a class="btn btn-outline-secondary <?php echo $week_mode === 'template' ? 'active' : ''; ?>"
           href="?view=<?php echo urlencode($view_mode); ?>&amp;week_mode=template&amp;date=<?php echo urlencode($selected_date); ?>&amp;<?php echo htmlspecialchars(http_build_query(array_filter(['shift' => $filter_shift, 'group_id' => $filter_group ?: null, 'teacher_id' => $filter_teacher ?: null]))); ?>">
            Шаблон Ч / З
        </a>
        <a class="btn btn-outline-secondary <?php echo $week_mode === 'current' ? 'active' : ''; ?>"
           href="?view=<?php echo urlencode($view_mode); ?>&amp;week_mode=current&amp;date=<?php echo urlencode($selected_date); ?>&amp;<?php echo htmlspecialchars(http_build_query(array_filter(['shift' => $filter_shift, 'group_id' => $filter_group ?: null, 'teacher_id' => $filter_teacher ?: null]))); ?>">
            Только текущая неделя
        </a>
    </div>
</div>

<?php if ($view_mode === 'day'): ?>
<div class="row g-3">
    <div class="col-lg-4">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <a class="btn btn-sm btn-outline-secondary"
                   href="?view=day&amp;date=<?php echo urlencode($selected_date); ?>&amp;month=<?php echo urlencode($cal_prev); ?>&amp;<?php echo htmlspecialchars($filter_query); ?>">
                    <i class="bi bi-chevron-left"></i>
                </a>
                <strong><?php
                    $months_ru = [1=>'Январь',2=>'Февраль',3=>'Март',4=>'Апрель',5=>'Май',6=>'Июнь',7=>'Июль',8=>'Август',9=>'Сентябрь',10=>'Октябрь',11=>'Ноябрь',12=>'Декабрь'];
                    echo htmlspecialchars(($months_ru[$cal_mon] ?? '') . ' ' . $cal_year);
                ?></strong>
                <a class="btn btn-sm btn-outline-secondary"
                   href="?view=day&amp;date=<?php echo urlencode($selected_date); ?>&amp;month=<?php echo urlencode($cal_next); ?>&amp;<?php echo htmlspecialchars($filter_query); ?>">
                    <i class="bi bi-chevron-right"></i>
                </a>
            </div>
            <div class="card-body p-2">
                <div class="uchebni-cal-grid">
                    <?php foreach (['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $wd): ?>
                        <div class="uchebni-cal-wd"><?php echo $wd; ?></div>
                    <?php endforeach; ?>
                    <?php for ($i = 1; $i < $cal_start_dow; $i++): ?>
                        <div class="uchebni-cal-day is-empty"></div>
                    <?php endfor; ?>
                    <?php for ($d = 1; $d <= $cal_days_in_month; $d++):
                        $ymd = sprintf('%04d-%02d-%02d', $cal_year, $cal_mon, $d);
                        $dow = (int)date('N', strtotime($ymd));
                        $is_weekend = $dow >= 6;
                        $is_selected = ($ymd === $selected_date);
                        $is_today = ($ymd === date('Y-m-d'));
                        $cnt = $is_weekend ? 0 : ($lessons_by_dow[$dow] ?? 0);
                        $subCnt = $subs_dates_in_month[$ymd] ?? 0;
                        $classes = 'uchebni-cal-day';
                        if ($is_weekend) $classes .= ' is-weekend';
                        if ($is_selected) $classes .= ' is-selected';
                        if ($is_today) $classes .= ' is-today';
                        if ($cnt > 0) $classes .= ' has-lessons';
                        if ($subCnt > 0) $classes .= ' has-substitution-day';
                        $href = '?view=day&date=' . urlencode($ymd) . '&month=' . urlencode($cal_month) . '&' . $filter_query;
                    ?>
                        <?php if ($is_weekend): ?>
                            <div class="<?php echo $classes; ?>">
                                <span class="cal-num"><?php echo $d; ?></span>
                            </div>
                        <?php else: ?>
                            <a href="<?php echo htmlspecialchars($href); ?>" class="<?php echo $classes; ?>">
                                <span class="cal-num"><?php echo $d; ?></span>
                                <?php if ($subCnt > 0): ?>
                                    <span class="cal-dot cal-dot--sub" title="Замен: <?php echo (int)$subCnt; ?>">З<?php echo (int)$subCnt; ?></span>
                                <?php elseif ($cnt > 0): ?>
                                    <span class="cal-dot" title="<?php echo (int)$cnt; ?> пар"><?php echo (int)$cnt; ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                </div>
                <div class="form-text mt-2 px-1">Цифра — число пар шаблона на этот день недели (<?php echo htmlspecialchars(Uchebni::SHIFT_NAMES[$filter_shift] ?? ''); ?>).</div>
            </div>
        </div>
    </div>
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <strong><?php echo htmlspecialchars(formatDate($selected_date)); ?></strong>
                    <span class="text-muted">· <?php echo htmlspecialchars($selected_day_name); ?> · <?php echo htmlspecialchars(Uchebni::SHIFT_NAMES[$filter_shift] ?? ''); ?>
                        · <?php echo htmlspecialchars(Uchebni::WEEK_KIND_NAMES[$day_week_kind] ?? ''); ?></span>
                </div>
            </div>
            <div class="card-body p-0">
                <?php if (!$is_study_day): ?>
                    <div class="text-muted text-center py-5">Выходной день — занятий по шаблону нет.</div>
                <?php elseif (empty($day_bells)): ?>
                    <div class="text-muted text-center py-5">Нет слотов звонков для этой смены.</div>
                <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($day_bells as $bell):
                            $pair = (int)$bell['pair_number'];
                            $slots = $day_slots_by_pair[$pair] ?? [];
                            $time = substr($bell['start_time'], 0, 5) . '–' . substr($bell['end_time'], 0, 5);
                        ?>
                        <div class="list-group-item <?php echo $pair === 0 ? 'list-group-item-warning' : ''; ?>">
                            <div class="d-flex justify-content-between align-items-baseline gap-2 mb-1">
                                <strong><?php echo htmlspecialchars($bell['label']); ?></strong>
                                <span class="text-muted small"><?php echo htmlspecialchars($time); ?></span>
                            </div>
                            <?php if (empty($slots)): ?>
                                <div class="text-muted small d-flex align-items-center gap-2">
                                    <span>Свободно</span>
                                    <?php if ($can_manage && $pair === 0 && $selected_dow === 2): ?>
                                        <button type="button" class="btn btn-sm btn-outline-warning py-0"
                                                data-bs-toggle="modal" data-bs-target="#curatorHourModal"
                                                data-group-id="<?php echo (int)$filter_group; ?>">
                                            + назначить куратора
                                        </button>
                                    <?php endif; ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($slots as $slot):
                                    $subKey = (int)$slot['id'] . '|' . $selected_date;
                                    $sub = $subs_by_slot_date[$subKey] ?? null;
                                    $hasSub = $sub || !empty($slot['substitute_teacher_name']);
                                    $subName = $sub['substitute_teacher_name'] ?? ($slot['substitute_teacher_name'] ?? '');
                                    $subReason = $sub['reason'] ?? ($slot['substitution_reason'] ?? '');
                                    $subSubject = $sub['substitute_subject_name']
                                        ?? ($slot['substitute_subject_name'] ?? '');
                                    // При замене: предмет замены вместо исходного, только заменяющий преподаватель
                                    $displayTeacher = $hasSub ? $subName : $slot['teacher_name'];
                                    $displaySubject = ($hasSub && $subSubject !== '' && $subSubject !== null)
                                        ? $subSubject
                                        : $slot['subject_name'];
                                ?>
                                <div class="uchebni-day-lesson mb-2 <?php echo $hasSub ? 'lesson-with-sub' : ''; ?>">
                                    <?php if ($can_manage): ?>
                                    <label class="uchebni-slot-check form-check mb-1">
                                        <input type="checkbox" class="form-check-input slot-check" form="scheduleBulkForm" name="slot_ids[]" value="<?php echo (int)$slot['id']; ?>">
                                        <span class="form-check-label small text-muted">выбрать</span>
                                    </label>
                                    <?php endif; ?>
                                    <div class="lesson-subject">
                                        <?php echo htmlspecialchars($displaySubject); ?>
                                        <?php
                                        $wkBadge = Uchebni::WEEK_KIND_SHORT[Uchebni::normalizeWeekKind($slot['week_kind'] ?? 'all')] ?? '';
                                        if ($wkBadge !== ''): ?>
                                            <span class="badge bg-secondary"><?php echo $wkBadge; ?></span>
                                        <?php endif; ?>
                                        <?php if ($hasSub): ?>
                                            <span class="lesson-sub-badge">замена</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="lesson-meta">
                                        <?php echo htmlspecialchars($slot['group_name']); ?> ·
                                        <?php if ($hasSub): ?>
                                            <span class="text-success"><?php echo htmlspecialchars($displayTeacher); ?></span>
                                            <span class="lesson-sub-badge">замена</span>
                                            <br><span class="lesson-sub-orig">вместо <?php echo htmlspecialchars($slot['teacher_name']); ?></span>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($displayTeacher); ?>
                                        <?php endif; ?>
                                    </div>
                                    <?php uchebni_render_classroom_select($slot, $classrooms, $can_manage); ?>
                                    <?php if ($can_manage): ?>
                                        <?php
                                        $daySubId = (int)($sub['id'] ?? ($slot['substitution_id'] ?? 0));
                                        if ($hasSub && $daySubId > 0): ?>
                                            <a class="btn btn-link btn-sm p-0" href="substitutions.php?edit=<?php echo $daySubId; ?>">правка</a>
                                        <?php else: ?>
                                            <a class="btn btn-link btn-sm p-0" href="substitutions.php?schedule_id=<?php echo (int)$slot['id']; ?>&amp;date=<?php echo urlencode($selected_date); ?>">
                                                <?php echo $hasSub ? 'правка' : 'замена'; ?>
                                            </a>
                                        <?php endif; ?>
                                        <?php if ($hasSub): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Удалить замену? Часы вернутся исходному преподавателю.');">
                                                <input type="hidden" name="action" value="delete_substitution">
                                                <?php if ($daySubId > 0): ?>
                                                    <input type="hidden" name="substitution_id" value="<?php echo $daySubId; ?>">
                                                <?php endif; ?>
                                                <input type="hidden" name="schedule_id" value="<?php echo (int)$slot['id']; ?>">
                                                <input type="hidden" name="lesson_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                                                <input type="hidden" name="return_view" value="day">
                                                <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                                                <button type="submit" class="btn btn-link btn-sm p-0 text-danger">удалить замену</button>
                                            </form>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                    <?php if ($hasSub && $subReason !== '' && $subReason !== null): ?>
                                        <div class="lesson-sub-reason">Причина: <?php echo htmlspecialchars($subReason); ?></div>
                                    <?php endif; ?>
                                    <?php if ($can_manage): ?>
                                    <form method="post" class="mt-1" onsubmit="return confirm('Удалить пару из шаблона расписания?');">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="slot_id" value="<?php echo $slot['id']; ?>">
                                        <input type="hidden" name="return_view" value="day">
                                        <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
                                        <button type="submit" class="btn btn-link btn-sm p-0 text-danger">удалить пару</button>
                                    </form>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="card-footer text-muted small">Всего пар в шаблоне на этот день: <?php echo (int)$day_lesson_count; ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php else: ?>
<div class="card">
    <div class="card-body overflow-auto">
        <div class="uchebni-schedule-grid uchebni-schedule-grid--5days">
            <div class="grid-header">Пара</div>
            <?php foreach (Uchebni::DAY_NAMES as $d => $name): ?>
                <div class="grid-header">
                    <?php echo $name; ?>
                    <div class="grid-header-date"><?php echo date('d.m', strtotime($week_dates[$d])); ?></div>
                </div>
            <?php endforeach; ?>

            <?php foreach ($pair_rows as $row):
                $pair = (int)$row['pair_number'];
                $is_curator = ($pair === 0);
            ?>
                <div class="grid-pair <?php echo $is_curator ? 'grid-pair--curator' : ''; ?>">
                    <?php echo htmlspecialchars($row['label']); ?>
                </div>
                <?php foreach (Uchebni::DAY_NAMES as $day => $dayName):
                    $bell = $bell_by_day_pair[$day][$pair] ?? null;
                    $dayDate = $week_dates[$day] ?? null;
                    $slotsRaw = $grid[$day][$pair] ?? [];
                    $slots = [];
                    foreach ($slotsRaw as $slot) {
                        $slotKind = Uchebni::normalizeWeekKind($slot['week_kind'] ?? 'all');
                        if ($week_mode === 'current'
                            && !Uchebni::isCuratorSlot($slot)
                            && !Uchebni::slotActiveOnWeekKind($slotKind, $view_week_kind)
                        ) {
                            continue;
                        }
                        $subKey = (int)$slot['id'] . '|' . ($dayDate ?: '');
                        $sub = $dayDate ? ($subs_by_slot_date[$subKey] ?? null) : null;
                        if (!$sub && !empty($slot['substitute_teacher_name']) && ($slot['substitution_date'] ?? '') === $dayDate) {
                            $sub = [
                                'substitute_teacher_name' => $slot['substitute_teacher_name'],
                                'substitute_subject_name' => $slot['substitute_subject_name'] ?? null,
                                'original_teacher_id' => (int)($slot['teacher_id'] ?? 0),
                                'substitute_teacher_id' => (int)($slot['substitute_teacher_id'] ?? 0),
                            ];
                        }
                        if ($filter_teacher && $sub
                            && (int)($sub['original_teacher_id'] ?? 0) === $filter_teacher
                            && (int)($sub['substitute_teacher_id'] ?? 0) !== $filter_teacher
                        ) {
                            continue; // у исходного — пара убрана
                        }
                        $slot['_sub'] = $sub;
                        $slots[] = $slot;
                    }
                    $hasSubInCell = false;
                    foreach ($slots as $_s) {
                        if (!empty($_s['_sub'])) {
                            $hasSubInCell = true;
                            break;
                        }
                    }
                    $cell_class = 'uchebni-schedule-cell';
                    if (!$bell) {
                        $cell_class .= ' is-disabled';
                    } elseif ($slots) {
                        $cell_class .= ' has-lesson';
                    }
                    if ($hasSubInCell) {
                        $cell_class .= ' has-substitution';
                    }
                    if ($is_curator) {
                        $cell_class .= ' is-curator';
                    }
                ?>
                <div class="<?php echo $cell_class; ?>">
                    <?php if ($bell):
                        $halves = uchebni_split_pair_halves($slots);
                        // Кураторский час — каждый вторник, без числителя/знаменателя
                        if ($is_curator) {
                            $halves = [
                                'top' => $slots,
                                'bottom' => $slots,
                                'all' => $slots,
                                'num' => [],
                                'den' => [],
                                'is_merged' => true,
                            ];
                        }
                        $activeTop = ($week_mode !== 'current') || ($view_week_kind === 'num') || $halves['is_merged'];
                        $activeBottom = ($week_mode !== 'current') || ($view_week_kind === 'den') || $halves['is_merged'];
                        ?>
                        <div class="lesson-time"><?php echo substr($bell['start_time'], 0, 5); ?>–<?php echo substr($bell['end_time'], 0, 5); ?></div>
                        <?php if ($halves['is_merged']): ?>
                            <div class="uchebni-pair-stack is-merged">
                                <div class="uchebni-pair-half uchebni-pair-half--merged">
                                    <div class="uchebni-half-label"><?php echo $is_curator ? 'каждый вторник' : 'каждую нед.'; ?></div>
                                    <?php uchebni_render_half_lessons($halves['all'], $can_manage, false, $classrooms, $dayDate); ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="uchebni-pair-stack">
                                <div class="uchebni-pair-half uchebni-pair-half--num <?php echo $activeTop ? 'is-active-week' : 'is-dim'; ?>">
                                    <div class="uchebni-half-label">Ч · числитель</div>
                                    <?php uchebni_render_half_lessons($halves['top'], $can_manage, false, $classrooms, $dayDate); ?>
                                </div>
                                <div class="uchebni-pair-half uchebni-pair-half--den <?php echo $activeBottom ? 'is-active-week' : 'is-dim'; ?>">
                                    <div class="uchebni-half-label">З · знаменатель</div>
                                    <?php uchebni_render_half_lessons($halves['bottom'], $can_manage, true, $classrooms, $dayDate); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <?php if ($can_manage && $is_curator && (int)$day === 2): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning w-100 py-1"
                                    style="font-size:0.7rem"
                                    data-bs-toggle="modal" data-bs-target="#curatorHourModal"
                                    data-group-id="<?php echo (int)$filter_group; ?>">
                                + куратор
                            </button>
                        <?php else: ?>
                            <span class="text-muted" style="font-size:0.7rem">—</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($can_manage): ?>
<div class="modal fade" id="clearScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="clear_schedule">
            <input type="hidden" name="return_view" value="<?php echo htmlspecialchars($view_mode); ?>">
            <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Очистить расписание</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small">Удалит пары из шаблона (деактивирует). Замены на даты останутся в журнале замен, но слотов уже не будет.</p>
                <div class="mb-3">
                    <label class="form-label">Смена *</label>
                    <select name="shift" class="form-select" required>
                        <option value="1" <?php echo $filter_shift === 1 ? 'selected' : ''; ?>>I смена</option>
                        <option value="2" <?php echo $filter_shift === 2 ? 'selected' : ''; ?>>II смена</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Область</label>
                    <select name="group_id" class="form-select">
                        <option value="0">Вся смена (все группы)</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $filter_group ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name'] . (!empty($g['code']) ? ' (' . $g['code'] . ')' : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-check mb-2">
                    <input class="form-check-input" type="checkbox" name="include_curator" value="1" id="includeCuratorClear">
                    <label class="form-check-label" for="includeCuratorClear">Также удалить кураторский час</label>
                </div>
                <div class="alert alert-warning small mb-0">Действие необратимо. Для выборочного удаления отметьте пары галочками и нажмите «Удалить выбранные».</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-danger"
                        onclick="return confirm('Точно очистить расписание по выбранной области?');">
                    Очистить полностью
                </button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="curatorHourModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content" id="curatorHourForm">
            <input type="hidden" name="action" value="add_curator">
            <input type="hidden" name="return_view" value="<?php echo htmlspecialchars($view_mode); ?>">
            <input type="hidden" name="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Кураторский час</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Каждый <strong>вторник</strong>, первый слот
                    (I смена 08:30–09:15 / II смена 13:00–13:45). Без числителя/знаменателя — каждый вторник.
                    В учёте часов = <?php echo (int)Uchebni::CURATOR_HOURS; ?> ч за занятие.
                    Автоформирование этот слот не трогает.
                </p>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Смена *</label>
                        <select name="shift" id="curator_shift" class="form-select" required>
                            <option value="1" <?php echo $filter_shift === 1 ? 'selected' : ''; ?>>I смена</option>
                            <option value="2" <?php echo $filter_shift === 2 ? 'selected' : ''; ?>>II смена</option>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Слот</label>
                        <input type="text" class="form-control" value="Вторник · Кураторский час" readonly>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Группа *</label>
                        <input type="hidden" name="group_id" id="curator_group_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="curatorGroupCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="curatorGroupComboboxToggle" aria-expanded="false">
                                <i class="bi bi-people-fill uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="curatorGroupComboboxTitle">Выберите группу</span>
                                    <span class="uchebni-combobox-meta d-none" id="curatorGroupComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="curatorGroupComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="curatorGroupComboboxSearch" placeholder="Поиск группы…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="curatorGroupComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Куратор *</label>
                        <input type="hidden" name="teacher_id" id="curator_teacher_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="curatorTeacherCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="curatorTeacherComboboxToggle" aria-expanded="false">
                                <i class="bi bi-person-badge uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="curatorTeacherComboboxTitle">Выберите куратора</span>
                                    <span class="uchebni-combobox-meta d-none" id="curatorTeacherComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="curatorTeacherComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="curatorTeacherComboboxSearch" placeholder="Поиск…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="curatorTeacherComboboxList"></div>
                            </div>
                        </div>
                        <div class="form-text">Если куратор группы привязан к карточке преподавателя — подставится сам, можно сменить.</div>
                    </div>
                    <div class="col-12">
                        <label class="form-label">Аудитория</label>
                        <input type="hidden" name="classroom_id" id="curator_classroom_id" value="<?php echo (int)$curator_classroom_id; ?>">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="curatorClassroomCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="curatorClassroomComboboxToggle" aria-expanded="false">
                                <i class="bi bi-door-open uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="curatorClassroomComboboxTitle">каб. кур. (по умолчанию)</span>
                                    <span class="uchebni-combobox-meta d-none" id="curatorClassroomComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="curatorClassroomComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="curatorClassroomComboboxSearch" placeholder="Поиск аудитории…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="curatorClassroomComboboxList"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-warning">Сохранить</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if (false && $can_manage): ?>
<div class="modal fade" id="addSlotModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="addSlotForm">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="return_view" id="return_view" value="<?php echo htmlspecialchars($view_mode); ?>">
            <input type="hidden" name="return_date" id="return_date" value="<?php echo htmlspecialchars($selected_date); ?>">
            <div class="modal-header">
                <h5 class="modal-title">Добавить пару</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">Сначала смену и день (во вторник доступен кураторский час). Для обычной пары: блок (ООД…) → дисциплина → преподаватель подставится сам.</p>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Смена *</label>
                        <select name="shift" id="slot_shift" class="form-select" required>
                            <option value="1" <?php echo $filter_shift === 1 ? 'selected' : ''; ?>>I смена</option>
                            <option value="2" <?php echo $filter_shift === 2 ? 'selected' : ''; ?>>II смена</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">День *</label>
                        <select name="day_of_week" id="slot_day" class="form-select" required>
                            <?php foreach (Uchebni::DAY_NAMES as $d => $name): ?>
                                <option value="<?php echo $d; ?>"><?php echo $name; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Пара *</label>
                        <select name="pair_number" id="slot_pair" class="form-select" required></select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Группа *</label>
                        <input type="hidden" name="group_id" id="slot_group_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="slotGroupCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="slotGroupComboboxToggle" aria-expanded="false">
                                <i class="bi bi-people-fill uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="slotGroupComboboxTitle">Выберите группу</span>
                                    <span class="uchebni-combobox-meta d-none" id="slotGroupComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="slotGroupComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="slotGroupComboboxSearch" placeholder="Поиск группы…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="slotGroupComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="slot_category_wrap">
                        <label class="form-label">Блок *</label>
                        <input type="hidden" id="slot_category_code" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="slotCategoryCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="slotCategoryComboboxToggle" aria-expanded="false">
                                <i class="bi bi-collection uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="slotCategoryComboboxTitle">Выберите блок (ООД…)</span>
                                    <span class="uchebni-combobox-meta d-none" id="slotCategoryComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="slotCategoryComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="slotCategoryComboboxSearch" placeholder="Поиск блока…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="slotCategoryComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="slot_subject_wrap">
                        <label class="form-label">Дисциплина *</label>
                        <input type="hidden" name="subject_id" id="slot_subject_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="slotSubjectCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="slotSubjectComboboxToggle" aria-expanded="false">
                                <i class="bi bi-journal-text uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="slotSubjectComboboxTitle">Сначала выберите блок</span>
                                    <span class="uchebni-combobox-meta d-none" id="slotSubjectComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="slotSubjectComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="slotSubjectComboboxSearch" placeholder="Поиск дисциплины…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="slotSubjectComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="slot_curator_note" style="display:none">
                        <label class="form-label">Дисциплина</label>
                        <div class="form-control bg-light">Кураторский час — дисциплина не нужна</div>
                        <div class="form-text">Нужны только группа, куратор (преподаватель) и аудитория.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" id="slot_teacher_label">Преподаватель *</label>
                        <input type="hidden" name="teacher_id" id="slot_teacher_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="slotTeacherCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="slotTeacherComboboxToggle" aria-expanded="false">
                                <i class="bi bi-person-badge uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="slotTeacherComboboxTitle">Сначала выберите дисциплину</span>
                                    <span class="uchebni-combobox-meta d-none" id="slotTeacherComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="slotTeacherComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="slotTeacherComboboxSearch" placeholder="Поиск преподавателя…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="slotTeacherComboboxList"></div>
                            </div>
                        </div>
                        <div class="form-text" id="slot_teacher_hint">После выбора дисциплины преподаватель подставится автоматически</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Аудитория *</label>
                        <input type="hidden" name="classroom_id" id="slot_classroom_id" value="">
                        <div class="uchebni-combobox uchebni-combobox--modal" id="slotClassroomCombobox">
                            <button type="button" class="uchebni-combobox-toggle" id="slotClassroomComboboxToggle" aria-expanded="false">
                                <i class="bi bi-door-open uchebni-combobox-icon"></i>
                                <span class="uchebni-combobox-label">
                                    <span class="uchebni-combobox-title" id="slotClassroomComboboxTitle">Выберите аудиторию</span>
                                    <span class="uchebni-combobox-meta d-none" id="slotClassroomComboboxMeta"></span>
                                </span>
                                <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                            </button>
                            <div class="uchebni-combobox-panel d-none" id="slotClassroomComboboxPanel">
                                <div class="uchebni-combobox-search">
                                    <div class="input-group">
                                        <span class="input-group-text"><i class="bi bi-search"></i></span>
                                        <input type="search" class="form-control" id="slotClassroomComboboxSearch" placeholder="Поиск аудитории…" autocomplete="off">
                                    </div>
                                </div>
                                <div class="uchebni-combobox-list" id="slotClassroomComboboxList"></div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6" id="slot_lesson_type_wrap">
                        <label class="form-label">Тип</label>
                        <select name="lesson_type" id="slot_lesson_type" class="form-select">
                            <option value="lecture">Лекция</option>
                            <option value="practice">Практика</option>
                            <option value="lab">Лабораторная</option>
                            <option value="curator">Кураторский час</option>
                        </select>
                    </div>
                </div>
                <div id="conflictAlert" class="alert alert-warning mt-3 d-none"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="checkConflictsBtn">Проверить конфликты</button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php if ($can_auto): ?>
<div class="modal fade" id="autoScheduleModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content" id="autoScheduleForm">
            <input type="hidden" name="action" value="auto">
            <div class="modal-header">
                <h5 class="modal-title">Автоформирование расписания</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3">
                    Расписание строится по учебному плану (Пн–Пт, выбранная смена).
                    Учитываются <strong>числитель</strong> и <strong>знаменатель</strong>.
                    <strong>Кабинеты не ставятся</strong> — назначьте их вручную в сетке у каждой пары.
                    Недельная нагрузка группы — не больше <strong><?php echo (int)Uchebni::MAX_WEEKLY_HOURS; ?> акад.ч</strong> (кураторский не считается).
                    Текущее расписание по выбранной группе/смене будет заменено (кураторский час сохранится).
                    Рекомендуется сначала выбрать одну группу — так быстрее и нагляднее.
                </p>
                <div class="mb-3">
                    <label class="form-label">Смена *</label>
                    <select name="shift" class="form-select">
                        <option value="1" <?php echo $filter_shift === 1 ? 'selected' : ''; ?>>I смена</option>
                        <option value="2" <?php echo $filter_shift === 2 ? 'selected' : ''; ?>>II смена</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label">Группа (необязательно)</label>
                    <input type="hidden" name="group_id" id="auto_group_id" value="">
                    <div class="uchebni-combobox uchebni-combobox--modal" id="autoGroupCombobox">
                        <button type="button" class="uchebni-combobox-toggle" id="autoGroupComboboxToggle" aria-expanded="false">
                            <i class="bi bi-people-fill uchebni-combobox-icon"></i>
                            <span class="uchebni-combobox-label">
                                <span class="uchebni-combobox-title" id="autoGroupComboboxTitle">Все группы из учебного плана</span>
                                <span class="uchebni-combobox-meta" id="autoGroupComboboxMeta">Без ограничения</span>
                            </span>
                            <i class="bi bi-chevron-down uchebni-combobox-caret"></i>
                        </button>
                        <div class="uchebni-combobox-panel d-none" id="autoGroupComboboxPanel">
                            <div class="uchebni-combobox-search">
                                <div class="input-group">
                                    <span class="input-group-text"><i class="bi bi-search"></i></span>
                                    <input type="search" class="form-control" id="autoGroupComboboxSearch" placeholder="Поиск группы…" autocomplete="off">
                                </div>
                            </div>
                            <div class="uchebni-combobox-list" id="autoGroupComboboxList"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-success">Сформировать</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<script>
const GROUP_OPTIONS = <?php echo json_encode($group_options, JSON_UNESCAPED_UNICODE); ?>;
const TEACHER_OPTIONS = <?php echo json_encode($teacher_options, JSON_UNESCAPED_UNICODE); ?>;
const MODAL_GROUP_OPTIONS = <?php echo json_encode($modal_group_options, JSON_UNESCAPED_UNICODE); ?>;
const MODAL_TEACHER_OPTIONS = <?php echo json_encode($modal_teacher_options, JSON_UNESCAPED_UNICODE); ?>;
const SUBJECT_OPTIONS = <?php echo json_encode($subject_options, JSON_UNESCAPED_UNICODE); ?>;
const CATEGORY_OPTIONS = <?php echo json_encode($category_options, JSON_UNESCAPED_UNICODE); ?>;
const SUBJECTS_BY_CATEGORY = <?php echo json_encode($subjects_by_category, JSON_UNESCAPED_UNICODE); ?>;
const CLASSROOM_OPTIONS = <?php echo json_encode($classroom_options, JSON_UNESCAPED_UNICODE); ?>;
const SUBJECT_TEACHER_IDS = <?php echo json_encode($subject_teacher_ids, JSON_UNESCAPED_UNICODE); ?>;
const CURATOR_SUBJECT_ID = <?php echo (int)$curator_subject_id; ?>;
const CURATOR_CLASSROOM_ID = <?php echo (int)$curator_classroom_id; ?>;
const GROUP_CURATOR_MAP = <?php echo json_encode($group_curator_map, JSON_UNESCAPED_UNICODE); ?>;
const AUTO_GROUP_OPTIONS = [{id: 0, label: 'Все группы из учебного плана', meta: 'Без ограничения', search: 'все группы'}].concat(MODAL_GROUP_OPTIONS);
const BELL_MAP = <?php echo json_encode($bell_map_js, JSON_UNESCAPED_UNICODE); ?>;
const FILTER_GROUP_ID = <?php echo (int)$filter_group; ?>;
const FILTER_TEACHER_ID = <?php echo (int)$filter_teacher; ?>;
const FILTER_SHIFT = <?php echo (int)$filter_shift; ?>;
const IS_TEACHER = <?php echo $current_user['role'] === 'teacher' ? 'true' : 'false'; ?>;
const VIEW_MODE = <?php echo json_encode($view_mode); ?>;
const SELECTED_DATE = <?php echo json_encode($selected_date); ?>;
const SELECTED_DOW = <?php echo (int)$selected_dow; ?>;
let prefDayOfWeek = VIEW_MODE === 'day' && SELECTED_DOW >= 1 && SELECTED_DOW <= 5 ? SELECTED_DOW : 0;

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
        selectedId = item ? item.id : (cfg.allowEmpty ? 0 : '');
        valueInput.value = item ? (item.id || '') : '';
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
        if (submit && cfg.submitFormId) {
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

    search.addEventListener('input', function() { render(search.value); });

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

    return {
        setSelected: setSelected,
        open: open,
        close: close,
        setItems: function(items, placeholder) {
            cfg.items = Array.isArray(items) ? items.slice() : [];
            if (placeholder) cfg.placeholder = placeholder;
        },
        reset: function(placeholder) {
            selectedId = cfg.allowEmpty ? 0 : '';
            valueInput.value = '';
            titleEl.textContent = placeholder || cfg.placeholder || 'Выберите';
            if (metaEl) {
                metaEl.textContent = '';
                metaEl.classList.add('d-none');
            }
        }
    };
}

if (!IS_TEACHER) {
    initCombobox({
        rootId: 'filterGroupCombobox',
        toggleId: 'filterGroupComboboxToggle',
        panelId: 'filterGroupComboboxPanel',
        searchId: 'filterGroupComboboxSearch',
        listId: 'filterGroupComboboxList',
        titleId: 'filterGroupComboboxTitle',
        metaId: 'filterGroupComboboxMeta',
        valueId: 'filter_group_id',
        submitFormId: 'scheduleFilterForm',
        items: GROUP_OPTIONS,
        selectedId: FILTER_GROUP_ID,
        allowEmpty: true,
        placeholder: 'Все группы'
    });

    initCombobox({
        rootId: 'filterTeacherCombobox',
        toggleId: 'filterTeacherComboboxToggle',
        panelId: 'filterTeacherComboboxPanel',
        searchId: 'filterTeacherComboboxSearch',
        listId: 'filterTeacherComboboxList',
        titleId: 'filterTeacherComboboxTitle',
        metaId: 'filterTeacherComboboxMeta',
        valueId: 'filter_teacher_id',
        submitFormId: 'scheduleFilterForm',
        items: TEACHER_OPTIONS,
        selectedId: FILTER_TEACHER_ID,
        allowEmpty: true,
        placeholder: 'Все преподаватели'
    });
}

function refreshPairOptions() {
    const shiftEl = document.getElementById('slot_shift');
    const dayEl = document.getElementById('slot_day');
    const pairEl = document.getElementById('slot_pair');
    const typeEl = document.getElementById('slot_lesson_type');
    if (!shiftEl || !dayEl || !pairEl) return;

    const shift = String(shiftEl.value);
    const day = String(dayEl.value);
    const slots = (BELL_MAP[shift] && BELL_MAP[shift][day]) ? BELL_MAP[shift][day] : [];
    const prev = pairEl.value;
    pairEl.innerHTML = slots.map(function(s) {
        return '<option value="' + s.pair_number + '">' + escapeHtml(s.label) + ' (' + s.start + '–' + s.end + ')</option>';
    }).join('');
    if (slots.some(function(s) { return String(s.pair_number) === String(prev); })) {
        pairEl.value = prev;
    }
    syncCuratorMode();
}

function isCuratorPair() {
    const pairEl = document.getElementById('slot_pair');
    return pairEl && String(pairEl.value) === '0';
}

function syncCuratorMode() {
    const typeEl = document.getElementById('slot_lesson_type');
    const categoryWrap = document.getElementById('slot_category_wrap');
    const subjectWrap = document.getElementById('slot_subject_wrap');
    const curatorNote = document.getElementById('slot_curator_note');
    const typeWrap = document.getElementById('slot_lesson_type_wrap');
    const teacherLabel = document.getElementById('slot_teacher_label');
    const hint = document.getElementById('slot_teacher_hint');
    const subjectInput = document.getElementById('slot_subject_id');
    const curator = isCuratorPair();

    if (typeEl) {
        if (curator) {
            typeEl.value = 'curator';
            typeEl.disabled = true;
        } else {
            typeEl.disabled = false;
            if (typeEl.value === 'curator') typeEl.value = 'lecture';
        }
    }

    if (categoryWrap) categoryWrap.style.display = curator ? 'none' : '';
    if (subjectWrap) subjectWrap.style.display = curator ? 'none' : '';
    if (curatorNote) curatorNote.style.display = curator ? '' : 'none';
    if (typeWrap) typeWrap.style.display = curator ? 'none' : '';

    if (curator) {
        if (subjectInput) subjectInput.value = String(CURATOR_SUBJECT_ID || '');
        if (slotPickers.category) slotPickers.category.reset('Выберите блок (ООД…)');
        if (slotPickers.subject) {
            slotPickers.subject.setItems([], 'Не требуется');
            slotPickers.subject.reset('Не требуется');
        }
        if (teacherLabel) teacherLabel.textContent = 'Куратор / преподаватель *';
        if (slotPickers.teacher) {
            slotPickers.teacher.setItems(MODAL_TEACHER_OPTIONS, 'Выберите куратора');
            if (!document.getElementById('slot_teacher_id').value) {
                slotPickers.teacher.reset('Выберите куратора');
            }
        }
        if (hint) hint.textContent = 'Для кураторского часа укажите куратора группы и аудиторию';
    } else {
        if (teacherLabel) teacherLabel.textContent = 'Преподаватель *';
        if (subjectInput && String(subjectInput.value) === String(CURATOR_SUBJECT_ID)) {
            subjectInput.value = '';
            if (slotPickers.category) slotPickers.category.reset('Выберите блок (ООД…)');
            if (slotPickers.subject) {
                slotPickers.subject.setItems([], 'Сначала выберите блок');
                slotPickers.subject.reset('Сначала выберите блок');
            }
            applyTeachersForSubject('');
        } else if (subjectInput && subjectInput.value) {
            applyTeachersForSubject(subjectInput.value);
        } else if (!document.getElementById('slot_category_code').value) {
            if (slotPickers.subject) {
                slotPickers.subject.setItems([], 'Сначала выберите блок');
                slotPickers.subject.reset('Сначала выберите блок');
            }
            applyTeachersForSubject('');
        }
        if (hint && !subjectInput.value) {
            hint.textContent = 'Выберите блок → дисциплину — преподаватель подставится автоматически';
        }
    }
}

function applySubjectsForCategory(categoryId) {
    if (!slotPickers.subject) return;
    if (!categoryId) {
        slotPickers.subject.setItems([], 'Сначала выберите блок');
        slotPickers.subject.reset('Сначала выберите блок');
        document.getElementById('slot_subject_id').value = '';
        applyTeachersForSubject('');
        return;
    }
    const list = SUBJECTS_BY_CATEGORY[categoryId] || SUBJECTS_BY_CATEGORY[String(categoryId)] || [];
    slotPickers.subject.setItems(list, 'Выберите дисциплину');
    slotPickers.subject.reset(list.length ? 'Выберите дисциплину' : 'В блоке нет дисциплин');
    document.getElementById('slot_subject_id').value = '';
    applyTeachersForSubject('');
    if (list.length === 1) {
        slotPickers.subject.setSelected(list[0], false);
        applyTeachersForSubject(list[0].id);
    }
}

const slotPickers = {};

<?php if ($can_manage): ?>
(function initCuratorHourModal() {
    const form = document.getElementById('curatorHourForm');
    if (!form || typeof initCombobox !== 'function') return;

    const curatorPickers = {};
    curatorPickers.group = initCombobox({
        rootId: 'curatorGroupCombobox', toggleId: 'curatorGroupComboboxToggle', panelId: 'curatorGroupComboboxPanel',
        searchId: 'curatorGroupComboboxSearch', listId: 'curatorGroupComboboxList',
        titleId: 'curatorGroupComboboxTitle', metaId: 'curatorGroupComboboxMeta', valueId: 'curator_group_id',
        items: MODAL_GROUP_OPTIONS, selectedId: '', placeholder: 'Выберите группу',
        onSelect: function(item) {
            const tid = item ? (GROUP_CURATOR_MAP[String(item.id)] || GROUP_CURATOR_MAP[item.id] || 0) : 0;
            if (tid && curatorPickers.teacher) {
                const tItem = MODAL_TEACHER_OPTIONS.find(function(x) { return String(x.id) === String(tid); });
                if (tItem) curatorPickers.teacher.setSelected(tItem, false);
            }
        }
    });
    curatorPickers.teacher = initCombobox({
        rootId: 'curatorTeacherCombobox', toggleId: 'curatorTeacherComboboxToggle', panelId: 'curatorTeacherComboboxPanel',
        searchId: 'curatorTeacherComboboxSearch', listId: 'curatorTeacherComboboxList',
        titleId: 'curatorTeacherComboboxTitle', metaId: 'curatorTeacherComboboxMeta', valueId: 'curator_teacher_id',
        items: MODAL_TEACHER_OPTIONS, selectedId: '', placeholder: 'Выберите куратора'
    });
    curatorPickers.classroom = initCombobox({
        rootId: 'curatorClassroomCombobox', toggleId: 'curatorClassroomComboboxToggle', panelId: 'curatorClassroomComboboxPanel',
        searchId: 'curatorClassroomComboboxSearch', listId: 'curatorClassroomComboboxList',
        titleId: 'curatorClassroomComboboxTitle', metaId: 'curatorClassroomComboboxMeta', valueId: 'curator_classroom_id',
        items: CLASSROOM_OPTIONS, selectedId: CURATOR_CLASSROOM_ID || '', placeholder: 'Аудитория (необязательно)'
    });
    if (CURATOR_CLASSROOM_ID) {
        const cItem = CLASSROOM_OPTIONS.find(function(x) { return String(x.id) === String(CURATOR_CLASSROOM_ID); });
        if (cItem) curatorPickers.classroom.setSelected(cItem, false);
    }

    const modalEl = document.getElementById('curatorHourModal');
    modalEl.addEventListener('show.bs.modal', function(ev) {
        document.getElementById('curator_shift').value = String(FILTER_SHIFT);
        let gid = FILTER_GROUP_ID;
        const trigger = ev.relatedTarget;
        if (trigger && trigger.dataset && trigger.dataset.groupId) {
            const fromBtn = parseInt(trigger.dataset.groupId, 10);
            if (fromBtn > 0) gid = fromBtn;
        }
        if (gid && curatorPickers.group) {
            const gItem = MODAL_GROUP_OPTIONS.find(function(x) { return String(x.id) === String(gid); });
            if (gItem) {
                curatorPickers.group.setSelected(gItem, false);
                const tid = GROUP_CURATOR_MAP[String(gid)] || GROUP_CURATOR_MAP[gid] || 0;
                if (tid) {
                    const tItem = MODAL_TEACHER_OPTIONS.find(function(x) { return String(x.id) === String(tid); });
                    if (tItem) curatorPickers.teacher.setSelected(tItem, false);
                }
            }
        }
    });

    form.addEventListener('submit', function(e) {
        if (!document.getElementById('curator_group_id').value) {
            e.preventDefault();
            curatorPickers.group.open();
            return;
        }
        if (!document.getElementById('curator_teacher_id').value) {
            e.preventDefault();
            curatorPickers.teacher.open();
        }
    });
})();
<?php endif; ?>

<?php if (false && $can_manage): ?>
function teachersForSubject(subjectId) {
    const raw = SUBJECT_TEACHER_IDS[String(subjectId)] || SUBJECT_TEACHER_IDS[subjectId] || [];
    const idSet = {};
    raw.forEach(function(id) { idSet[String(id)] = true; });
    return MODAL_TEACHER_OPTIONS.filter(function(t) { return idSet[String(t.id)]; });
}

function applyTeachersForSubject(subjectId) {
    const hint = document.getElementById('slot_teacher_hint');
    if (!slotPickers.teacher) return;

    if (!subjectId) {
        slotPickers.teacher.setItems([], 'Сначала выберите дисциплину');
        slotPickers.teacher.reset('Сначала выберите дисциплину');
        if (hint) hint.textContent = 'После выбора дисциплины преподаватель подставится автоматически';
        return;
    }

    const linked = teachersForSubject(subjectId);
    if (linked.length === 1) {
        slotPickers.teacher.setItems(linked, 'Выберите преподавателя');
        slotPickers.teacher.setSelected(linked[0], false);
        if (hint) hint.textContent = 'Преподаватель подставлен по дисциплине';
    } else if (linked.length > 1) {
        slotPickers.teacher.setItems(linked, 'Выберите преподавателя');
        slotPickers.teacher.reset('Выберите преподавателя (' + linked.length + ')');
        if (hint) hint.textContent = 'У дисциплины несколько преподавателей — выберите нужного';
    } else {
        slotPickers.teacher.setItems(MODAL_TEACHER_OPTIONS, 'Выберите преподавателя');
        slotPickers.teacher.reset('Выберите преподавателя');
        if (hint) hint.textContent = 'У дисциплины нет привязанных преподавателей — выберите вручную (привяжите в «Дисциплинах»)';
    }
}

slotPickers.group = initCombobox({
    rootId: 'slotGroupCombobox', toggleId: 'slotGroupComboboxToggle', panelId: 'slotGroupComboboxPanel',
    searchId: 'slotGroupComboboxSearch', listId: 'slotGroupComboboxList',
    titleId: 'slotGroupComboboxTitle', metaId: 'slotGroupComboboxMeta', valueId: 'slot_group_id',
    items: MODAL_GROUP_OPTIONS, selectedId: '', placeholder: 'Выберите группу'
});
slotPickers.category = initCombobox({
    rootId: 'slotCategoryCombobox', toggleId: 'slotCategoryComboboxToggle', panelId: 'slotCategoryComboboxPanel',
    searchId: 'slotCategoryComboboxSearch', listId: 'slotCategoryComboboxList',
    titleId: 'slotCategoryComboboxTitle', metaId: 'slotCategoryComboboxMeta', valueId: 'slot_category_code',
    items: CATEGORY_OPTIONS, selectedId: '', placeholder: 'Выберите блок (ООД…)',
    onSelect: function(item) {
        applySubjectsForCategory(item ? item.id : '');
    }
});
slotPickers.subject = initCombobox({
    rootId: 'slotSubjectCombobox', toggleId: 'slotSubjectComboboxToggle', panelId: 'slotSubjectComboboxPanel',
    searchId: 'slotSubjectComboboxSearch', listId: 'slotSubjectComboboxList',
    titleId: 'slotSubjectComboboxTitle', metaId: 'slotSubjectComboboxMeta', valueId: 'slot_subject_id',
    items: [], selectedId: '', placeholder: 'Сначала выберите блок',
    onSelect: function(item) {
        applyTeachersForSubject(item ? item.id : '');
    }
});
slotPickers.teacher = initCombobox({
    rootId: 'slotTeacherCombobox', toggleId: 'slotTeacherComboboxToggle', panelId: 'slotTeacherComboboxPanel',
    searchId: 'slotTeacherComboboxSearch', listId: 'slotTeacherComboboxList',
    titleId: 'slotTeacherComboboxTitle', metaId: 'slotTeacherComboboxMeta', valueId: 'slot_teacher_id',
    items: [], selectedId: '', placeholder: 'Сначала выберите дисциплину'
});
slotPickers.classroom = initCombobox({
    rootId: 'slotClassroomCombobox', toggleId: 'slotClassroomComboboxToggle', panelId: 'slotClassroomComboboxPanel',
    searchId: 'slotClassroomComboboxSearch', listId: 'slotClassroomComboboxList',
    titleId: 'slotClassroomComboboxTitle', metaId: 'slotClassroomComboboxMeta', valueId: 'slot_classroom_id',
    items: CLASSROOM_OPTIONS, selectedId: '', placeholder: 'Выберите аудиторию'
});

document.getElementById('slot_shift')?.addEventListener('change', refreshPairOptions);
document.getElementById('slot_day')?.addEventListener('change', refreshPairOptions);
document.getElementById('slot_pair')?.addEventListener('change', refreshPairOptions);
refreshPairOptions();

const addSlotForm = document.getElementById('addSlotForm');
if (addSlotForm) {
    addSlotForm.addEventListener('submit', function(e) {
        const typeEl = document.getElementById('slot_lesson_type');
        if (typeEl && typeEl.disabled) typeEl.disabled = false;
        if (isCuratorPair()) {
            document.getElementById('slot_subject_id').value = String(CURATOR_SUBJECT_ID || '');
        }
        const required = [
            { id: 'slot_group_id', picker: slotPickers.group },
            { id: 'slot_teacher_id', picker: slotPickers.teacher },
            { id: 'slot_classroom_id', picker: slotPickers.classroom }
        ];
        if (!isCuratorPair()) {
            if (!document.getElementById('slot_category_code').value) {
                e.preventDefault();
                if (slotPickers.category) slotPickers.category.open();
                return;
            }
            required.splice(1, 0, { id: 'slot_subject_id', picker: slotPickers.subject });
        }
        for (let i = 0; i < required.length; i++) {
            if (!document.getElementById(required[i].id).value) {
                e.preventDefault();
                if (required[i].picker) required[i].picker.open();
                return;
            }
        }
    });

    document.getElementById('addSlotModal').addEventListener('hidden.bs.modal', function() {
        ['group', 'category', 'classroom'].forEach(function(key) {
            if (slotPickers[key]) slotPickers[key].reset();
        });
        applySubjectsForCategory('');
        applyTeachersForSubject('');
        const alertEl = document.getElementById('conflictAlert');
        if (alertEl) {
            alertEl.classList.add('d-none');
            alertEl.textContent = '';
        }
        document.getElementById('slot_shift').value = String(FILTER_SHIFT);
        refreshPairOptions();
    });

    document.getElementById('addSlotModal').addEventListener('show.bs.modal', function(ev) {
        document.getElementById('slot_shift').value = String(FILTER_SHIFT);
        const trigger = ev.relatedTarget;
        let day = prefDayOfWeek;
        if (trigger && trigger.dataset && trigger.dataset.day) {
            day = parseInt(trigger.dataset.day, 10) || day;
        }
        if (day >= 1 && day <= 5) {
            document.getElementById('slot_day').value = String(day);
        }
        document.getElementById('return_view').value = VIEW_MODE;
        document.getElementById('return_date').value = SELECTED_DATE;
        refreshPairOptions();
        if (FILTER_GROUP_ID && slotPickers.group) {
            const item = MODAL_GROUP_OPTIONS.find(function(x) { return String(x.id) === String(FILTER_GROUP_ID); });
            if (item) slotPickers.group.setSelected(item, false);
        }
    });
}

document.getElementById('checkConflictsBtn')?.addEventListener('click', function() {
    const form = document.getElementById('addSlotForm');
    const typeEl = document.getElementById('slot_lesson_type');
    const wasDisabled = typeEl && typeEl.disabled;
    if (wasDisabled) typeEl.disabled = false;
    const fd = new FormData(form);
    if (wasDisabled) typeEl.disabled = true;
    const params = new URLSearchParams(fd);
    fetch('api/check_conflicts.php?' + params.toString())
        .then(r => r.json())
        .then(data => {
            const el = document.getElementById('conflictAlert');
            if (data.conflicts && data.conflicts.length) {
                el.classList.remove('d-none', 'alert-success');
                el.classList.add('alert-warning');
                el.innerHTML = '<strong>Конфликты:</strong><ul class="mb-0">' +
                    data.conflicts.map(c => '<li>' + c.message + '</li>').join('') + '</ul>';
            } else {
                el.classList.remove('d-none', 'alert-warning');
                el.classList.add('alert-success');
                el.textContent = 'Конфликтов не обнаружено — можно сохранять.';
            }
        });
});
<?php endif; ?>

<?php if ($can_manage): ?>
(function() {
    function syncSlotSelection() {
        const boxes = document.querySelectorAll('.slot-check');
        const checked = document.querySelectorAll('.slot-check:checked');
        const btn = document.getElementById('deleteSelectedBtn');
        const cnt = document.getElementById('selectedCount');
        if (cnt) cnt.textContent = String(checked.length);
        if (btn) btn.disabled = checked.length === 0;
    }
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList && e.target.classList.contains('slot-check')) {
            syncSlotSelection();
        }
    });
    document.getElementById('selectAllSlotsBtn')?.addEventListener('click', function() {
        document.querySelectorAll('.slot-check').forEach(function(cb) { cb.checked = true; });
        syncSlotSelection();
    });
    document.getElementById('clearSlotSelectionBtn')?.addEventListener('click', function() {
        document.querySelectorAll('.slot-check').forEach(function(cb) { cb.checked = false; });
        syncSlotSelection();
    });
    syncSlotSelection();
})();
<?php endif; ?>

<?php if ($can_auto): ?>
initCombobox({
    rootId: 'autoGroupCombobox', toggleId: 'autoGroupComboboxToggle', panelId: 'autoGroupComboboxPanel',
    searchId: 'autoGroupComboboxSearch', listId: 'autoGroupComboboxList',
    titleId: 'autoGroupComboboxTitle', metaId: 'autoGroupComboboxMeta', valueId: 'auto_group_id',
    items: AUTO_GROUP_OPTIONS, selectedId: 0, allowEmpty: true,
    placeholder: 'Все группы из учебного плана'
});
<?php endif; ?>
</script>

<?php require_once 'includes/footer.php'; ?>
