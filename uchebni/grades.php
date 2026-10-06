<?php
/**
 * Оценки преподавателя — только по своим дисциплинам.
 */
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/Department.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['teacher']);
requirePermission('edit_own_grades');

$uchebni = new Uchebni();
$departmentService = new Department();
$current_user = getCurrentUser();
$message = '';
$error = '';

$teacher = $uchebni->getTeacherByUserId((int)$current_user['id']);
$teacherId = $teacher ? (int)$teacher['id'] : 0;

$period = $uchebni->getCurrentPeriod();
$periodId = $period ? (int)$period['id'] : 0;
$defaultSemester = $period ? $uchebni->getPeriodSemesterNumber($period) : 1;
$defaultYear = Department::resolveAcademicYearFromPeriod($period);

$groupId = !empty($_REQUEST['group_id']) ? (int)$_REQUEST['group_id'] : 0;
$subjectId = !empty($_REQUEST['subject_id']) ? (int)$_REQUEST['subject_id'] : 0;
$semester = !empty($_REQUEST['semester']) ? (int)$_REQUEST['semester'] : $defaultSemester;
$semester = ($semester === 2) ? 2 : 1;
$academicYear = !empty($_REQUEST['year']) ? (int)$_REQUEST['year'] : $defaultYear;
if ($academicYear < 2000 || $academicYear > 2100) {
    $academicYear = $defaultYear;
}

$groups = ($teacherId > 0 && $periodId > 0)
    ? $uchebni->getTeacherTeachingGroups($periodId, $teacherId)
    : [];

$groupIds = array_map(static function ($g) {
    return (int)$g['id'];
}, $groups);
if ($groupId > 0 && !in_array($groupId, $groupIds, true)) {
    $groupId = 0;
    $subjectId = 0;
    $error = 'Группа недоступна: вы не ведёте в ней дисциплины';
}

$groupSubjects = ($groupId > 0 && $periodId > 0 && $teacherId > 0)
    ? $uchebni->getTeacherGroupSubjects($periodId, $groupId, $teacherId)
    : [];

$selectedSubject = null;
foreach ($groupSubjects as $gs) {
    if ((int)$gs['subject_id'] === $subjectId) {
        $selectedSubject = $gs;
        break;
    }
}
if (!$selectedSubject) {
    $subjectId = 0;
}

$monthLabels = Department::gradeMonthLabels($semester);
$editable = Department::gradeEditableFlags($academicYear, $semester);
$students = [];
$gradesMap = [];
$sheet = null;

if ($teacherId > 0 && $groupId > 0 && $subjectId > 0 && $selectedSubject) {
    $departmentId = (int)($selectedSubject['department_id'] ?? 0);
    if ($departmentId <= 0) {
        foreach ($groups as $g) {
            if ((int)$g['id'] === $groupId) {
                $departmentId = (int)($g['department_id'] ?? 0);
                break;
            }
        }
    }

    if ($departmentId <= 0) {
        $error = 'У группы не указано отделение — обратитесь в учебную часть';
    } else {
        $sheet = $departmentService->getOrCreateGradeSheet([
            'department_id' => $departmentId,
            'group_id' => $groupId,
            'subject_id' => $subjectId,
            'teacher_id' => $teacherId,
            'period_id' => $periodId ?: null,
            'semester' => $semester,
            'academic_year' => $academicYear,
            'created_by' => (int)$current_user['id'],
        ]);

        $students = $departmentService->getGroupStudents($groupId);
        $gradesMap = $sheet ? $departmentService->getStudentGradesMap((int)$sheet['id']) : [];

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades' && $sheet) {
            $posted = $_POST['grades'] ?? [];
            $rows = [];
            $studentIds = [];
            foreach ($students as $st) {
                $studentIds[(int)$st['id']] = true;
            }
            foreach ($studentIds as $studentId => $_) {
                $existing = $gradesMap[$studentId] ?? [];
                $row = $posted[$studentId] ?? [];
                $rows[$studentId] = [
                    'm1' => $editable['m1'] ? ($row['m1'] ?? '') : ($existing['m1'] ?? ''),
                    'm2' => $editable['m2'] ? ($row['m2'] ?? '') : ($existing['m2'] ?? ''),
                    'm3' => $editable['m3'] ? ($row['m3'] ?? '') : ($existing['m3'] ?? ''),
                    'm4' => $editable['m4'] ? ($row['m4'] ?? '') : ($existing['m4'] ?? ''),
                    'attendance' => $editable['attendance'] ? ($row['attendance'] ?? '') : ($existing['attendance'] ?? ''),
                    'exam' => $editable['exam'] ? ($row['exam'] ?? '') : ($existing['exam'] ?? ''),
                ];
            }
            if ($departmentService->saveStudentGrades((int)$sheet['id'], $rows)) {
                $message = 'Оценки сохранены';
                $gradesMap = $departmentService->getStudentGradesMap((int)$sheet['id']);
            } else {
                $error = 'Не удалось сохранить оценки';
            }
        }
    }
}

$selectedGroupName = '';
foreach ($groups as $g) {
    if ((int)$g['id'] === $groupId) {
        $selectedGroupName = $g['name'] . (!empty($g['code']) ? ' (' . $g['code'] . ')' : '');
        break;
    }
}

$page_title = 'Оценки';
$page_subtitle = 'Только ваши дисциплины · итог = рейтинг × 60% + экзамен × 40%';
$extra_css = ['../department/assets/css/department-ui.css'];
require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($teacherId <= 0): ?>
    <div class="card">
        <div class="card-body text-center py-5">
            <i class="bi bi-person-badge fs-1 d-block mb-3 text-muted"></i>
            <h2 class="h5">Профиль преподавателя не привязан</h2>
            <p class="text-muted mb-0">Обратитесь в учебную часть, чтобы связать учётную запись с карточкой преподавателя.</p>
        </div>
    </div>
<?php else: ?>

    <form method="get" class="card mb-4">
        <div class="card-body">
            <div class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label class="form-label">Группа</label>
                    <select name="group_id" class="form-select" onchange="this.form.submit()">
                        <option value="">Выберите группу</option>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $groupId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($g['name'] . (!empty($g['code']) ? ' · ' . $g['code'] : '')); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($periodId && empty($groups)): ?>
                        <div class="form-text text-warning">В текущем периоде нет групп, где вы назначены преподавателем.</div>
                    <?php endif; ?>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Предмет</label>
                    <select name="subject_id" class="form-select" onchange="this.form.submit()" <?php echo $groupId ? '' : 'disabled'; ?>>
                        <option value="">Выберите предмет</option>
                        <?php foreach ($groupSubjects as $gs): ?>
                            <option value="<?php echo (int)$gs['subject_id']; ?>" <?php echo (int)$gs['subject_id'] === $subjectId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($gs['subject_name'] ?? ''); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Семестр</label>
                    <select name="semester" class="form-select" onchange="this.form.submit()">
                        <option value="1" <?php echo $semester === 1 ? 'selected' : ''; ?>>I сем.</option>
                        <option value="2" <?php echo $semester === 2 ? 'selected' : ''; ?>>II сем.</option>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Учебный год</label>
                    <select name="year" class="form-select" onchange="this.form.submit()">
                        <?php for ($y = $defaultYear + 1; $y >= $defaultYear - 5; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y === $academicYear ? 'selected' : ''; ?>>
                                <?php echo $y; ?>/<?php echo $y + 1; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>
            </div>
        </div>
    </form>

    <?php if ($selectedSubject): ?>
        <div class="dept-grade-meta card mb-3">
            <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
                <div>
                    <div class="dept-grade-meta-label">Группа</div>
                    <div class="dept-grade-meta-value"><?php echo htmlspecialchars($selectedGroupName); ?></div>
                </div>
                <div>
                    <div class="dept-grade-meta-label">Предмет</div>
                    <div class="dept-grade-meta-value"><?php echo htmlspecialchars($selectedSubject['subject_name'] ?? ''); ?></div>
                </div>
                <div>
                    <div class="dept-grade-meta-label">Формула итога</div>
                    <div class="dept-grade-meta-value">Рейт × 60% + Экз × 40%</div>
                </div>
            </div>
        </div>

        <?php
        $openMonthNames = [];
        foreach ($monthLabels as $i => $label) {
            if (!empty($editable['m' . ($i + 1)])) {
                $openMonthNames[] = rtrim($label, '.');
            }
        }
        ?>
        <div class="alert alert-light border mb-3">
            <?php if ($openMonthNames): ?>
                Можно выставлять оценки за: <strong><?php echo htmlspecialchars(implode(', ', $openMonthNames)); ?></strong>
                (месяц уже закончился).
            <?php else: ?>
                Пока нет закончившихся месяцев этого семестра — оценки ещё недоступны.
            <?php endif; ?>
            <?php if (!$editable['exam']): ?>
                <span class="text-muted">Экзамен откроется, когда закроются все 4 месяца.</span>
            <?php endif; ?>
        </div>

        <?php if (empty($students)): ?>
            <div class="card">
                <div class="card-body text-center py-5 text-muted">В группе пока нет студентов</div>
            </div>
        <?php else: ?>
            <form method="post" class="card">
                <input type="hidden" name="action" value="save_grades">
                <input type="hidden" name="group_id" value="<?php echo (int)$groupId; ?>">
                <input type="hidden" name="subject_id" value="<?php echo (int)$subjectId; ?>">
                <input type="hidden" name="semester" value="<?php echo (int)$semester; ?>">
                <input type="hidden" name="year" value="<?php echo (int)$academicYear; ?>">

                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table dept-grades-table mb-0 align-middle">
                            <thead>
                                <tr>
                                    <th rowspan="2" class="dept-grades-fio">Ф.И.О. студентов</th>
                                    <?php foreach ($monthLabels as $i => $label):
                                        $isOpen = !empty($editable['m' . ($i + 1)]);
                                        ?>
                                        <th class="text-center<?php echo $isOpen ? '' : ' dept-grades-locked-head'; ?>">
                                            <?php echo htmlspecialchars($label); ?>
                                            <?php if (!$isOpen): ?>
                                                <i class="bi bi-lock-fill ms-1" title="Месяц ещё не закончился"></i>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th rowspan="2" class="text-center<?php echo $editable['attendance'] ? '' : ' dept-grades-locked-head'; ?>" title="Посещаемость, %">пос.</th>
                                    <th rowspan="2" class="text-center" title="Среднее по месяцам">Рейт. Σ</th>
                                    <th rowspan="2" class="text-center<?php echo $editable['exam'] ? '' : ' dept-grades-locked-head'; ?>">экз.</th>
                                    <th rowspan="2" class="text-center">Итого</th>
                                </tr>
                                <tr>
                                    <?php foreach ($monthLabels as $i => $_):
                                        $isOpen = !empty($editable['m' . ($i + 1)]);
                                        ?>
                                        <th class="text-center dept-grades-sub"><?php echo $isOpen ? 'оцен.' : 'закрыт'; ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $st):
                                    $sid = (int)$st['id'];
                                    $g = $gradesMap[$sid] ?? [];
                                    $months = [
                                        'm1' => $g['m1'] ?? '',
                                        'm2' => $g['m2'] ?? '',
                                        'm3' => $g['m3'] ?? '',
                                        'm4' => $g['m4'] ?? '',
                                    ];
                                    $att = $g['attendance'] ?? '';
                                    $exam = $g['exam'] ?? '';
                                    $rating = Department::calcGradeRating(array_values($months));
                                    $total = Department::calcGradeTotal($rating, $exam);
                                    $fio = trim(($st['last_name'] ?? '') . ' ' . ($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? ''));
                                    ?>
                                    <tr data-student="<?php echo $sid; ?>">
                                        <td class="dept-grades-fio">
                                            <?php echo htmlspecialchars($fio); ?>
                                            <?php if ((int)($st['academic_leave'] ?? 0) === 1): ?>
                                                <span class="badge text-bg-warning ms-1">академ</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php foreach (['m1', 'm2', 'm3', 'm4'] as $mk):
                                            $isOpen = !empty($editable[$mk]);
                                            $val = $months[$mk];
                                            ?>
                                            <td class="<?php echo $isOpen ? '' : 'dept-grades-locked-cell'; ?>">
                                                <?php if ($isOpen): ?>
                                                    <input type="number" step="0.01" min="0" max="100"
                                                           class="form-control form-control-sm grade-month"
                                                           name="grades[<?php echo $sid; ?>][<?php echo $mk; ?>]"
                                                           value="<?php echo htmlspecialchars((string)$val); ?>">
                                                <?php else: ?>
                                                    <input type="text" class="form-control form-control-sm grade-month" value="<?php echo $val !== '' && $val !== null ? htmlspecialchars((string)$val) : '—'; ?>" disabled readonly tabindex="-1">
                                                <?php endif; ?>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="<?php echo $editable['attendance'] ? '' : 'dept-grades-locked-cell'; ?>">
                                            <?php if ($editable['attendance']): ?>
                                                <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm"
                                                       name="grades[<?php echo $sid; ?>][attendance]"
                                                       value="<?php echo htmlspecialchars((string)$att); ?>" title="Посещаемость %">
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm" value="<?php echo $att !== '' && $att !== null ? htmlspecialchars((string)$att) : '—'; ?>" disabled readonly tabindex="-1">
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-semibold grade-rating"><?php echo $rating !== null ? htmlspecialchars((string)$rating) : '—'; ?></td>
                                        <td class="<?php echo $editable['exam'] ? '' : 'dept-grades-locked-cell'; ?>">
                                            <?php if ($editable['exam']): ?>
                                                <input type="number" step="0.01" min="0" max="100" class="form-control form-control-sm grade-exam"
                                                       name="grades[<?php echo $sid; ?>][exam]"
                                                       value="<?php echo htmlspecialchars((string)$exam); ?>">
                                            <?php else: ?>
                                                <input type="text" class="form-control form-control-sm grade-exam" value="<?php echo $exam !== '' && $exam !== null ? htmlspecialchars((string)$exam) : '—'; ?>" disabled readonly tabindex="-1">
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center fw-bold grade-total"><?php echo $total !== null ? htmlspecialchars((string)$total) : '—'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <div class="text-muted small">
                        Рейтинг = среднее по месяцам · Итого = рейтинг × 0,6 + экзамен × 0,4.
                    </div>
                    <?php if ($editable['m1'] || $editable['m2'] || $editable['m3'] || $editable['m4'] || $editable['attendance'] || $editable['exam']): ?>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check2-circle me-1"></i>Сохранить
                        </button>
                    <?php else: ?>
                        <button type="button" class="btn btn-secondary" disabled>Пока нечего сохранять</button>
                    <?php endif; ?>
                </div>
            </form>
        <?php endif; ?>
    <?php elseif ($groupId): ?>
        <div class="card">
            <div class="card-body text-center py-5 text-muted">Выберите предмет из ваших дисциплин в этой группе</div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="bi bi-journal-check fs-1 d-block mb-2 text-muted"></i>
                Выберите группу и предмет, чтобы выставить оценки
            </div>
        </div>
    <?php endif; ?>

<?php endif; ?>

<script>
(function () {
    function parseNum(v) {
        if (v === null || v === undefined || String(v).trim() === '') return null;
        var n = parseFloat(String(v).replace(',', '.'));
        return isNaN(n) ? null : n;
    }
    function calcRating(months) {
        var vals = months.filter(function (v) { return v !== null; });
        if (!vals.length) return null;
        var sum = vals.reduce(function (a, b) { return a + b; }, 0);
        return Math.round((sum / vals.length) * 100) / 100;
    }
    function calcTotal(rating, exam) {
        if (rating === null || exam === null) return null;
        return Math.round((rating * 0.6 + exam * 0.4) * 100) / 100;
    }
    function refreshRow(tr) {
        var months = Array.prototype.map.call(tr.querySelectorAll('.grade-month'), function (inp) {
            return parseNum(inp.value);
        });
        var examEl = tr.querySelector('.grade-exam');
        var exam = examEl ? parseNum(examEl.value) : null;
        var rating = calcRating(months);
        var total = calcTotal(rating, exam);
        tr.querySelector('.grade-rating').textContent = rating === null ? '—' : String(rating);
        tr.querySelector('.grade-total').textContent = total === null ? '—' : String(total);
    }
    document.querySelectorAll('.dept-grades-table tbody tr').forEach(function (tr) {
        tr.querySelectorAll('input').forEach(function (inp) {
            inp.addEventListener('input', function () { refreshRow(tr); });
        });
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
