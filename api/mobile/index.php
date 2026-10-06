<?php

/**
 * Мобильный REST API — единая точка входа
 * POST /api/mobile/index.php?action=auth/login
 *   student: { type: "student", code, device_id }
 *   teacher: { type: "teacher", login, password }
 * GET  /api/mobile/index.php?action=schedule
 * GET  /api/mobile/index.php?action=dashboard
 * GET  /api/mobile/index.php?action=hours
 * POST /api/mobile/index.php?action=hours/save
 * POST /api/mobile/index.php?action=hours/sync
 * GET  /api/mobile/index.php?action=practice
 * GET|POST /api/mobile/index.php?action=covers
 * GET  /api/mobile/index.php?action=bells
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/mobile_api.php';
require_once __DIR__ . '/../../classes/MobileAuth.php';
require_once __DIR__ . '/../../classes/Uchebni.php';

mobileApiCors();

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($action) {
        case 'auth/login':
            handleAuthLogin();
            break;
        case 'auth/me':
            handleAuthMe();
            break;
        case 'schedule':
            handleSchedule($method);
            break;
        case 'journal':
            handleJournal($method);
            break;
        case 'lesson-status':
            handleLessonStatus($method);
            break;
        case 'substitutions':
            handleSubstitutions($method);
            break;
        case 'period':
            handlePeriod();
            break;
        case 'teacher/profile':
            handleTeacherProfile();
            break;
        case 'dashboard':
            handleDashboard();
            break;
        case 'hours':
            handleHours($method);
            break;
        case 'hours/save':
            handleHoursSave($method);
            break;
        case 'hours/sync':
            handleHoursSync($method);
            break;
        case 'practice':
            handlePractice($method);
            break;
        case 'covers':
            handleCovers($method);
            break;
        case 'bells':
            handleBells($method);
            break;
        default:
            mobileJsonError('Неизвестное действие', 404);
    }
} catch (Exception $e) {
    mobileJsonError('Ошибка сервера: ' . $e->getMessage(), 500);
}

function handleAuthLogin()
{
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        mobileJsonError('Метод не поддерживается', 405);
    }

    $body = mobileGetJsonBody();
    $type = $body['type'] ?? 'teacher';
    $auth = new MobileAuth();

    if ($type === 'student') {
        $result = $auth->loginStudentByInvite($body['code'] ?? '', $body['device_id'] ?? '');
    } else {
        $result = $auth->loginTeacher($body['login'] ?? '', $body['password'] ?? '');
    }

    if (!empty($result['error'])) {
        mobileJsonError($result['error'], 401);
    }

    mobileJsonSuccess($result);
}

function handleAuthMe()
{
    $auth = mobileRequireAuth();
    mobileJsonSuccess(['user' => $auth]);
}

function handleSchedule($method)
{
    if ($method !== 'GET') {
        mobileJsonError('Метод не поддерживается', 405);
    }

    $auth = mobileRequireAuth(['student', 'teacher']);
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;

    $dayOfWeek = isset($_GET['day']) ? (int)$_GET['day'] : null;
    $lessonDate = $_GET['date'] ?? null;
    $today = $lessonDate ?: date('Y-m-d');
    $shift = isset($_GET['shift']) ? ((int)$_GET['shift'] === 2 ? 2 : 1) : 1;

    if ($dayOfWeek === null && $lessonDate) {
        $dayOfWeek = (int)date('N', strtotime($lessonDate));
        if ($dayOfWeek === 7) {
            $dayOfWeek = null;
        }
    }

    $filters = ['period_id' => $periodId, 'shift' => $shift];

    if ($auth['type'] === 'student') {
        $filters['group_id'] = (int)$auth['group_id'];
    } elseif ($auth['type'] === 'teacher') {
        if (!empty($auth['teacher_id'])) {
            $filters['teacher_id'] = (int)$auth['teacher_id'];
        }
    }

    if ($dayOfWeek !== null && $dayOfWeek >= 1 && $dayOfWeek <= 6) {
        $filters['day_of_week'] = $dayOfWeek;
    }

    $rows = $uchebni->getScheduleWithMeta($filters, $today);

    $weekKind = $uchebni->getWeekKindForDate($today, $period);
    $rows = array_values(array_filter($rows, function ($row) use ($weekKind) {
        $slotKind = Uchebni::normalizeWeekKind($row['week_kind'] ?? 'all');
        return Uchebni::slotActiveOnWeekKind($slotKind, $weekKind);
    }));

    // Для преподавателя добавить замены (где он замещает другого)
    if ($auth['type'] === 'teacher' && !empty($auth['teacher_id'])) {
        $subRows = $uchebni->getSubstitutions([
            'teacher_id' => (int)$auth['teacher_id'],
            'lesson_date' => $today,
        ]);

        $existingIds = array_column($rows, 'id');
        foreach ($subRows as $sub) {
            if ((int)$sub['substitute_teacher_id'] === (int)$auth['teacher_id']) {
                $schedId = (int)$sub['schedule_id'];
                if (!in_array($schedId, $existingIds, true)) {
                    $slot = $uchebni->getScheduleById($schedId);
                    $slotShift = (int)($slot['shift'] ?? 1) === 2 ? 2 : 1;
                    if ($slot && $slotShift === $shift) {
                        $slot['substitute_teacher_id'] = $sub['substitute_teacher_id'];
                        $slot['substitute_teacher_name'] = $sub['substitute_teacher_name'];
                        $slot['substitute_subject_id'] = $sub['substitute_subject_id'] ?? null;
                        $slot['substitute_subject_name'] = $sub['substitute_subject_name'] ?? null;
                        $slot['substitution_reason'] = $sub['reason'];
                        $slot['teacher_id'] = $sub['original_teacher_id'];
                        $slot['teacher_name'] = $sub['original_teacher_name'];
                        $status = $uchebni->getLessonStatus($schedId, $today);
                        $slot['lesson_status'] = $status['status'] ?? 'scheduled';
                        $rows[] = $slot;
                    }
                }
            }
        }

        usort($rows, function ($a, $b) {
            $d = ($a['day_of_week'] ?? 0) <=> ($b['day_of_week'] ?? 0);
            if ($d !== 0) {
                return $d;
            }
            $s = ((int)($a['shift'] ?? 1)) <=> ((int)($b['shift'] ?? 1));
            return $s !== 0 ? $s : (($a['pair_number'] ?? 0) <=> ($b['pair_number'] ?? 0));
        });
    }

    $items = array_map(function ($row) use ($today) {
        return mobileFormatScheduleItem($row, $today);
    }, $rows);

    mobileJsonSuccess([
        'period' => $period,
        'date' => $today,
        'day_of_week' => $dayOfWeek ?? (int)date('N', strtotime($today)),
        'shift' => $shift,
        'shift_name' => Uchebni::SHIFT_NAMES[$shift] ?? '',
        'week_kind' => $weekKind,
        'week_kind_label' => Uchebni::WEEK_KIND_NAMES[$weekKind] ?? '',
        'week_kind_short' => Uchebni::WEEK_KIND_SHORT[$weekKind] ?? '',
        'schedule' => $items,
    ]);
}

function handleJournal($method)
{
    $auth = mobileRequireAuth(['teacher']);

    $uchebni = new Uchebni();
    $scheduleId = (int)($_GET['schedule_id'] ?? 0);
    $lessonDate = $_GET['date'] ?? date('Y-m-d');

    if ($method === 'GET') {
        if (!$scheduleId) {
            mobileJsonError('Укажите schedule_id');
        }

        $slot = $uchebni->getScheduleById($scheduleId);
        if (!$slot) {
            mobileJsonError('Занятие не найдено', 404);
        }

        if (!empty($auth['teacher_id']) && (int)$slot['teacher_id'] !== (int)$auth['teacher_id']) {
            $subs = $uchebni->getSubstitutions(['teacher_id' => (int)$auth['teacher_id'], 'lesson_date' => $lessonDate]);
            $allowed = false;
            foreach ($subs as $sub) {
                if ((int)$sub['schedule_id'] === $scheduleId && (int)$sub['substitute_teacher_id'] === (int)$auth['teacher_id']) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                mobileJsonError('Нет доступа к этому занятию', 403);
            }
        }

        $journal = $uchebni->getJournalForSchedule($scheduleId, $lessonDate);
        $status = $uchebni->getLessonStatus($scheduleId, $lessonDate);

        mobileJsonSuccess([
            'schedule' => mobileFormatScheduleItem($slot, $lessonDate),
            'lesson_date' => $lessonDate,
            'lesson_status' => $status['status'] ?? 'scheduled',
            'lesson_status_note' => $status['note'] ?? '',
            'students' => array_map(function ($j) {
                return [
                    'id' => (int)$j['id'],
                    'name' => trim($j['last_name'] . ' ' . $j['first_name'] . ' ' . ($j['middle_name'] ?? '')),
                    'iin' => $j['iin'] ?? '',
                    'grade' => $j['grade'] !== null ? (float)$j['grade'] : null,
                    'attendance' => $j['attendance'] ?? 'present',
                    'note' => $j['note'] ?? '',
                ];
            }, $journal),
        ]);
    }

    if ($method === 'POST') {
        $body = mobileGetJsonBody();
        $scheduleId = (int)($body['schedule_id'] ?? 0);
        $lessonDate = $body['lesson_date'] ?? date('Y-m-d');
        $entries = $body['entries'] ?? [];

        if (!$scheduleId) {
            mobileJsonError('Укажите schedule_id');
        }

        $slot = $uchebni->getScheduleById($scheduleId);
        if (!$slot) {
            mobileJsonError('Занятие не найдено', 404);
        }

        if (!empty($auth['teacher_id']) && (int)$slot['teacher_id'] !== (int)$auth['teacher_id']) {
            mobileJsonError('Нет доступа к этому занятию', 403);
        }

        foreach ($entries as $entry) {
            $attendance = Uchebni::normalizeAttendance($entry['attendance'] ?? 'present');
            $gradeRaw = $entry['grade'] ?? '';
            if ($gradeRaw === null || $gradeRaw === '') {
                $grade = '';
            } else {
                $grade = max(0, min(100, (float)$gradeRaw));
            }
            $uchebni->saveJournalEntry([
                'schedule_id' => $scheduleId,
                'student_id' => (int)$entry['student_id'],
                'lesson_date' => $lessonDate,
                'grade' => $grade,
                'attendance' => $attendance,
                'note' => sanitize($entry['note'] ?? ''),
            ], $auth['user_id'] ?? null);
        }

        mobileJsonSuccess(null, 'Журнал сохранён');
    }

    mobileJsonError('Метод не поддерживается', 405);
}

function handleLessonStatus($method)
{
    $auth = mobileRequireAuth(['teacher']);

    if ($method === 'POST') {
        $body = mobileGetJsonBody();
        $scheduleId = (int)($body['schedule_id'] ?? 0);
        $lessonDate = $body['lesson_date'] ?? date('Y-m-d');
        $status = $body['status'] ?? 'held';
        $note = sanitize($body['note'] ?? '');

        if (!$scheduleId) {
            mobileJsonError('Укажите schedule_id');
        }

        $uchebni = new Uchebni();
        $slot = $uchebni->getScheduleById($scheduleId);
        if (!$slot) {
            mobileJsonError('Занятие не найдено', 404);
        }

        if (!empty($auth['teacher_id']) && (int)$slot['teacher_id'] !== (int)$auth['teacher_id']) {
            $subs = $uchebni->getSubstitutions(['teacher_id' => (int)$auth['teacher_id'], 'lesson_date' => $lessonDate]);
            $allowed = false;
            foreach ($subs as $sub) {
                if ((int)$sub['schedule_id'] === $scheduleId && (int)$sub['substitute_teacher_id'] === (int)$auth['teacher_id']) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                mobileJsonError('Нет доступа', 403);
            }
        }

        $ok = $uchebni->setLessonStatus($scheduleId, $lessonDate, $status, $note, $auth['user_id'] ?? null);
        if (!$ok) {
            mobileJsonError('Не удалось сохранить статус');
        }

        mobileJsonSuccess([
            'schedule_id' => $scheduleId,
            'lesson_date' => $lessonDate,
            'status' => $status,
        ], 'Статус урока сохранён');
    }

    mobileJsonError('Метод не поддерживается', 405);
}

function handleSubstitutions($method)
{
    $auth = mobileRequireAuth(['student', 'teacher']);
    $uchebni = new Uchebni();

    if ($method === 'GET') {
        $filters = [];
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));
        $filters['date_from'] = $dateFrom;
        $filters['date_to'] = $dateTo;

        if ($auth['type'] === 'teacher' && !empty($auth['teacher_id'])) {
            $filters['teacher_id'] = (int)$auth['teacher_id'];
        } elseif ($auth['type'] === 'student') {
            $filters['group_id'] = (int)$auth['group_id'];
        }

        $rows = $uchebni->getSubstitutions($filters);

        mobileJsonSuccess([
            'substitutions' => array_map(function ($row) {
                return [
                    'id' => (int)$row['id'],
                    'schedule_id' => (int)$row['schedule_id'],
                    'lesson_date' => $row['lesson_date'],
                    'day_of_week' => (int)$row['day_of_week'],
                    'day_name' => Uchebni::DAY_NAMES[(int)$row['day_of_week']] ?? '',
                    'pair_number' => (int)$row['pair_number'],
                    'group_name' => $row['group_name'] ?? '',
                    'subject_name' => $row['subject_name'] ?? '',
                    'original_teacher' => $row['original_teacher_name'] ?? '',
                    'substitute_teacher' => $row['substitute_teacher_name'] ?? '',
                    'reason' => $row['reason'] ?? '',
                ];
            }, $rows),
        ]);
    }

    mobileJsonError('Метод не поддерживается', 405);
}

function handlePeriod()
{
    mobileRequireAuth(['student', 'teacher']);
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $today = date('Y-m-d');
    $weekKind = $uchebni->getWeekKindForDate($today, $period);
    mobileJsonSuccess([
        'period' => $period,
        'date' => $today,
        'week_kind' => $weekKind,
        'week_kind_label' => Uchebni::WEEK_KIND_NAMES[$weekKind] ?? '',
        'week_kind_short' => Uchebni::WEEK_KIND_SHORT[$weekKind] ?? '',
    ]);
}

function handleTeacherProfile()
{
    $auth = mobileRequireAuth(['teacher']);
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;
    $teacherId = !empty($auth['teacher_id']) ? (int)$auth['teacher_id'] : 0;

    if ($teacherId <= 0) {
        mobileJsonError('Профиль преподавателя не найден', 404);
    }

    $teacher = $uchebni->getTeacherById($teacherId);
    if (!$teacher) {
        mobileJsonError('Профиль преподавателя не найден', 404);
    }

    $today = date('Y-m-d');
    $weekKind = $uchebni->getWeekKindForDate($today, $period);
    $hoursWeek = $periodId ? $uchebni->getTeacherWorkload($teacherId, $periodId) : 0;
    $maxHours = Uchebni::effectiveTeacherMaxHours($teacher['max_hours_per_week'] ?? Uchebni::MAX_WEEKLY_HOURS);
    $disciplines = $uchebni->getTeacherSubjectsList($teacherId);

    mobileJsonSuccess([
        'teacher' => [
            'id' => $teacherId,
            'name' => trim(($teacher['last_name'] ?? '') . ' ' . ($teacher['first_name'] ?? '') . ' ' . ($teacher['middle_name'] ?? '')),
            'specialization' => $teacher['specialization'] ?? '',
            'max_hours_per_week' => $maxHours,
        ],
        'period' => $period,
        'date' => $today,
        'week_kind' => $weekKind,
        'week_kind_label' => Uchebni::WEEK_KIND_NAMES[$weekKind] ?? '',
        'week_kind_short' => Uchebni::WEEK_KIND_SHORT[$weekKind] ?? '',
        'hours_week' => (int)$hoursWeek,
        'max_hours_week' => (int)$maxHours,
        'disciplines' => array_map(function ($d) {
            return [
                'id' => (int)$d['id'],
                'name' => $d['name'],
            ];
        }, $disciplines),
        'attendance_options' => array_map(function ($value) {
            return [
                'value' => $value,
                'label' => Uchebni::ATTENDANCE_NAMES[$value] ?? $value,
            ];
        }, Uchebni::ATTENDANCE_VALUES),
    ]);
}

function mobileRequireTeacherId()
{
    $auth = mobileRequireAuth(['teacher']);
    $teacherId = !empty($auth['teacher_id']) ? (int)$auth['teacher_id'] : 0;
    if ($teacherId <= 0) {
        mobileJsonError('Профиль преподавателя не найден', 404);
    }
    return [$auth, $teacherId];
}

function handleHours($method)
{
    if ($method !== 'GET') {
        mobileJsonError('Метод не поддерживается', 405);
    }
    list(, $teacherId) = mobileRequireTeacherId();
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;
    $grid = $uchebni->getTeacherEditableHourGrid($teacherId, $periodId ?: null);

    if (!empty($grid['error'])) {
        mobileJsonError($grid['error'], 400);
    }

    // Совместимость с Flutter (nagruzka): date + subjects/groups/semester
    $days = [];
    foreach ($grid['days'] as $d) {
        $ymd = $d['ymd'];
        $weekPart = ($d['week_kind'] ?? '') === 'den' ? 'denominator' : 'numerator';
        $days[] = [
            'date' => $ymd,
            'ymd' => $ymd,
            'day' => (int)$d['day'],
            'dow' => (int)$d['dow'],
            'dow_short' => $d['dow_short'] ?? '',
            'week_part' => $weekPart,
            'week_label' => $d['week_label'] ?? '',
            'is_today' => !empty($d['is_today']),
            'is_practice' => !empty($d['is_practice']),
            'label' => $d['label'] ?? '',
            'lessons' => $d['lessons'] ?? [],
        ];
    }

    $subjects = [];
    $groupsById = [];
    foreach ($days as $d) {
        foreach ($d['lessons'] as $lesson) {
            $sid = (int)($lesson['subject_id'] ?? 0);
            $gid = (int)($lesson['group_id'] ?? 0);
            if ($sid > 0 && !isset($subjects[$sid])) {
                $tot = $grid['totals'][(string)$sid] ?? ['planned' => 0, 'total' => 0];
                $subjects[$sid] = [
                    'id' => $sid,
                    'title' => $lesson['title'] ?? '',
                    'name' => $lesson['title'] ?? '',
                    'color' => $lesson['color'] ?? Uchebni::subjectColor($sid),
                    'planned_hours' => (int)($tot['planned'] ?? 0),
                    'total' => (int)($tot['total'] ?? 0),
                ];
            }
            if ($gid > 0 && !isset($groupsById[$gid])) {
                $groupsById[$gid] = [
                    'id' => $gid,
                    'code' => $lesson['group_code'] ?? '',
                    'title' => $lesson['group_code'] ?? '',
                ];
            }
        }
    }
    foreach ($grid['totals'] as $sidStr => $tot) {
        $sid = (int)$sidStr;
        if (!isset($subjects[$sid])) {
            $subj = $uchebni->getSubjectById($sid);
            $subjects[$sid] = [
                'id' => $sid,
                'title' => $subj['name'] ?? ('#' . $sid),
                'name' => $subj['name'] ?? ('#' . $sid),
                'color' => Uchebni::subjectColor($sid),
                'planned_hours' => (int)($tot['planned'] ?? 0),
                'total' => (int)($tot['total'] ?? 0),
            ];
        }
    }

    $semester = $grid['period'] ? [
        'id' => (int)$grid['period']['id'],
        'title' => $grid['period']['name'] ?? '',
        'name' => $grid['period']['name'] ?? '',
        'start_date' => $grid['period']['start_date'] ?? null,
        'end_date' => $grid['period']['end_date'] ?? null,
    ] : null;

    mobileJsonSuccess([
        'period' => $grid['period'],
        'period_id' => (int)$grid['period_id'],
        'semester' => $semester,
        'teacher_id' => (int)$grid['teacher_id'],
        'fact_end' => $grid['fact_end'],
        'today' => date('Y-m-d'),
        'default_day' => $grid['default_day'],
        'hours_per_pair' => (int)$grid['hours_per_pair'],
        'days' => $days,
        'cells' => $grid['cells'],
        'totals' => $grid['totals'],
        'subjects' => array_values($subjects),
        'groups' => array_values($groupsById),
        'practice' => $grid['practice'],
    ]);
}

function handleHoursSave($method)
{
    if ($method !== 'POST') {
        mobileJsonError('Метод не поддерживается', 405);
    }
    list(, $teacherId) = mobileRequireTeacherId();
    $body = mobileGetJsonBody();
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = (int)($body['period_id'] ?? $body['semester_id'] ?? 0);
    if ($periodId <= 0) {
        $periodId = $period ? (int)$period['id'] : 0;
    }

    $weekPart = (string)($body['week_part'] ?? 'both');
    if ($weekPart === 'numerator') {
        $weekPart = 'num';
    } elseif ($weekPart === 'denominator') {
        $weekPart = 'den';
    }

    $result = $uchebni->upsertHourEntry([
        'period_id' => $periodId,
        'teacher_id' => $teacherId,
        'subject_id' => (int)($body['subject_id'] ?? 0),
        'group_id' => (int)($body['group_id'] ?? 0),
        'entry_date' => $body['entry_date'] ?? '',
        'hours' => (int)($body['hours'] ?? 0),
        'week_part' => $weekPart,
        'note' => Uchebni::HOUR_NOTE_MANUAL,
    ]);

    if (empty($result['ok'])) {
        mobileJsonError($result['error'] ?? 'Ошибка сохранения', 422);
    }
    mobileJsonSuccess([
        'total' => (int)$result['total'],
        'hours' => (int)($body['hours'] ?? 0),
        'week_part' => $body['week_part'] ?? 'both',
    ], 'Часы сохранены');
}

function handleHoursSync($method)
{
    if ($method !== 'POST') {
        mobileJsonError('Метод не поддерживается', 405);
    }
    list(, $teacherId) = mobileRequireTeacherId();
    $body = mobileGetJsonBody();
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = (int)($body['period_id'] ?? $body['semester_id'] ?? 0);
    if ($periodId <= 0) {
        $periodId = $period ? (int)$period['id'] : 0;
    }
    if ($periodId <= 0) {
        mobileJsonError('Нет активного периода', 400);
    }
    $n = $uchebni->syncTeacherHoursFromSchedule($teacherId, $periodId);
    mobileJsonSuccess(['synced' => $n], 'Синхронизировано из расписания');
}

function handleDashboard()
{
    list(, $teacherId) = mobileRequireTeacherId();
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;
    $teacher = $uchebni->getTeacherById($teacherId);
    if (!$teacher) {
        mobileJsonError('Преподаватель не найден', 404);
    }

    $today = date('Y-m-d');
    $weekKind = $uchebni->getWeekKindForDate($today, $period);
    $weekPart = $weekKind === Uchebni::WEEK_KIND_DEN ? 'denominator' : 'numerator';

    [, $totalsBySubject] = $uchebni->getHourEntriesMapAndTotals($teacherId, $periodId);
    $disciplines = $uchebni->getTeacherSubjectsList($teacherId);
    $planned = 0;
    $fact = 0;
    $progress = [];
    foreach ($disciplines as $s) {
        $sid = (int)$s['id'];
        $p = $uchebni->getSubjectPlannedHours($s, $period);
        $t = (int)($totalsBySubject[$sid] ?? 0);
        $planned += $p;
        $fact += $t;
        $progress[] = [
            'id' => $sid,
            'title' => $s['name'] ?? '',
            'color' => Uchebni::subjectColor($sid),
            'planned' => $p,
            'fact' => $t,
            'percent' => $p > 0 ? min(100, (int)round($t / $p * 100)) : 0,
        ];
    }
    // Часы по предметам без привязки в teacher_subjects
    foreach ($totalsBySubject as $sid => $t) {
        $sid = (int)$sid;
        if ($t <= 0) {
            continue;
        }
        $found = false;
        foreach ($progress as $row) {
            if ((int)$row['id'] === $sid) {
                $found = true;
                break;
            }
        }
        if ($found) {
            continue;
        }
        $subj = $uchebni->getSubjectById($sid);
        $p = $subj ? $uchebni->getSubjectPlannedHours($subj, $period) : 0;
        $planned += $p;
        $fact += (int)$t;
        $progress[] = [
            'id' => $sid,
            'title' => $subj['name'] ?? ('#' . $sid),
            'color' => Uchebni::subjectColor($sid),
            'planned' => $p,
            'fact' => (int)$t,
            'percent' => $p > 0 ? min(100, (int)round($t / $p * 100)) : 0,
        ];
    }

    mobileJsonSuccess([
        'teacher' => [
            'id' => $teacherId,
            'full_name' => Uchebni::formatFio($teacher),
            'last_name' => $teacher['last_name'] ?? '',
            'first_name' => $teacher['first_name'] ?? '',
        ],
        'semester' => $period ? [
            'id' => (int)$period['id'],
            'title' => $period['name'] ?? '',
            'name' => $period['name'] ?? '',
            'start_date' => $period['start_date'] ?? null,
            'end_date' => $period['end_date'] ?? null,
        ] : null,
        'period' => $period,
        'week_part' => $weekPart,
        'week_part_label' => Uchebni::weekPartLabel($weekKind),
        'week_kind' => $weekKind,
        'planned' => $planned,
        'fact' => $fact,
        'remaining' => $planned - $fact,
        'hours_per_pair' => Uchebni::HOURS_PER_PAIR,
        'subjects_progress' => $progress,
        'today' => $today,
    ]);
}

function handlePractice($method)
{
    if ($method !== 'GET') {
        mobileJsonError('Метод не поддерживается', 405);
    }
    mobileRequireAuth(['teacher', 'student']);
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;
    $items = $periodId ? $uchebni->getPracticePeriods($periodId) : [];
    $dates = $periodId ? $uchebni->getPracticeDateSet($periodId) : [];

    mobileJsonSuccess([
        'period' => $period,
        'items' => array_map(static function ($row) {
            return [
                'id' => (int)$row['id'],
                'title' => $row['title'],
                'start_date' => $row['start_date'],
                'end_date' => $row['end_date'],
                'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
                'group_name' => $row['group_name'] ?? null,
                'group_code' => $row['group_code'] ?? null,
            ];
        }, $items),
        'dates' => $dates,
    ]);
}

function handleCovers($method)
{
    list($auth, $teacherId) = mobileRequireTeacherId();
    $uchebni = new Uchebni();
    $period = $uchebni->getCurrentPeriod();
    $periodId = $period ? (int)$period['id'] : 0;

    if ($method === 'GET') {
        $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-14 days'));
        $dateTo = $_GET['date_to'] ?? date('Y-m-d', strtotime('+30 days'));
        $rows = $periodId ? $uchebni->getScheduleCovers([
            'period_id' => $periodId,
            'teacher_id' => $teacherId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]) : [];
        mobileJsonSuccess([
            'covers' => array_map(static function ($c) {
                return [
                    'id' => (int)$c['id'],
                    'lesson_date' => $c['lesson_date'],
                    'shift' => (int)$c['shift'],
                    'pair_number' => (int)$c['pair_number'],
                    'group_id' => (int)$c['group_id'],
                    'group_name' => $c['group_name'] ?? '',
                    'group_code' => $c['group_code'] ?? '',
                    'subject_id' => (int)$c['subject_id'],
                    'subject_name' => $c['subject_name'] ?? '',
                    'replaced_teacher_name' => $c['replaced_teacher_name'] ?? null,
                    'replaced_subject_name' => $c['replaced_subject_name'] ?? null,
                    'is_cover' => true,
                ];
            }, $rows),
        ]);
    }

    if ($method === 'POST') {
        $body = mobileGetJsonBody();
        $subAction = $body['action'] ?? 'save';
        if ($subAction === 'delete') {
            $ok = $uchebni->deleteScheduleCover((int)($body['id'] ?? 0), $teacherId);
            if (!$ok) {
                mobileJsonError('Не удалось удалить', 404);
            }
            mobileJsonSuccess(['deleted' => true], 'Удалено');
        }

        $result = $uchebni->createScheduleCover([
            'period_id' => $periodId,
            'cover_teacher_id' => $teacherId,
            'lesson_date' => $body['lesson_date'] ?? '',
            'shift' => (int)($body['shift'] ?? $body['shift_num'] ?? 1),
            'pair_number' => (int)($body['pair_number'] ?? $body['pair_num'] ?? 0),
            'group_id' => (int)($body['group_id'] ?? 0),
            'subject_id' => (int)($body['subject_id'] ?? 0),
            'replaced_teacher_name' => $body['replaced_teacher_name'] ?? ($body['replaced_teacher'] ?? ''),
            'replaced_subject_name' => $body['replaced_subject_name'] ?? ($body['replaced_subject'] ?? ''),
            'note' => $body['note'] ?? '',
            'created_by' => (int)($auth['user_id'] ?? 0),
        ]);
        if (empty($result['ok'])) {
            mobileJsonError($result['error'] ?? 'Ошибка', 422);
        }
        mobileJsonSuccess(['cover' => $result['cover'], 'id' => $result['id']], 'Сохранено');
    }

    mobileJsonError('Метод не поддерживается', 405);
}

function handleBells($method)
{
    if ($method !== 'GET') {
        mobileJsonError('Метод не поддерживается', 405);
    }
    mobileRequireAuth(['teacher', 'student']);
    $uchebni = new Uchebni();
    $grouped = $uchebni->getAllBellScheduleGrouped();
    $out = [];
    foreach ($grouped as $dayType => $byShift) {
        foreach ($byShift as $shift => $slots) {
            foreach ($slots as $slot) {
                $out[] = [
                    'day_type' => $dayType,
                    'day_type_label' => Uchebni::BELL_DAY_TYPES[$dayType] ?? $dayType,
                    'shift' => (int)$shift,
                    'pair_number' => (int)($slot['pair_number'] ?? 0),
                    'label' => $slot['label'] ?? '',
                    'start_time' => $slot['start_time'] ?? null,
                    'end_time' => $slot['end_time'] ?? null,
                    'break_after' => (int)($slot['break_after'] ?? 0),
                ];
            }
        }
    }
    mobileJsonSuccess(['bells' => $out, 'day_types' => Uchebni::BELL_DAY_TYPES]);
}
