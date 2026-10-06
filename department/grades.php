<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../classes/Uchebni.php';
require_once '../includes/auth.php';

checkRole(['department_head']);

$departmentService = new Department();
$uchebni = new Uchebni();
$current_user = getCurrentUser();
$dept = $departmentService->getDepartmentByHead((int)$current_user['id']);
$message = '';
$error = '';

$period = $uchebni->getCurrentPeriod();
$periodId = $period ? (int)$period['id'] : 0;
$defaultSemester = $period ? $uchebni->getPeriodSemesterNumber($period) : 1;

$defaultYear = (int)date('Y');
if ($period && !empty($period['start_date'])) {
    $y = (int)date('Y', strtotime($period['start_date']));
    $m = (int)date('n', strtotime($period['start_date']));
    $defaultYear = ($m >= 1 && $m <= 7) ? ($y - 1) : $y;
}

$groups = $dept ? $departmentService->getGroups((int)$dept['id']) : [];
$groupId = !empty($_REQUEST['group_id']) ? (int)$_REQUEST['group_id'] : 0;
$subjectId = !empty($_REQUEST['subject_id']) ? (int)$_REQUEST['subject_id'] : 0;
$semester = !empty($_REQUEST['semester']) ? (int)$_REQUEST['semester'] : $defaultSemester;
$semester = ($semester === 2) ? 2 : 1;
$academicYear = !empty($_REQUEST['year']) ? (int)$_REQUEST['year'] : $defaultYear;
if ($academicYear < 2000 || $academicYear > 2100) {
    $academicYear = $defaultYear;
}

if ($groupId > 0 && $dept && !$departmentService->ownsGroup((int)$dept['id'], $groupId)) {
    $groupId = 0;
    $subjectId = 0;
    $error = 'Группа не принадлежит вашему отделению';
}

$groupSubjects = ($groupId > 0 && $periodId > 0)
    ? $uchebni->getGroupSubjects($periodId, $groupId)
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

if ($dept && $groupId > 0 && $subjectId > 0 && $selectedSubject) {
    $sheet = $departmentService->getOrCreateGradeSheet([
        'department_id' => (int)$dept['id'],
        'group_id' => $groupId,
        'subject_id' => $subjectId,
        'teacher_id' => !empty($selectedSubject['teacher_id']) ? (int)$selectedSubject['teacher_id'] : null,
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
            $merged = [
                'm1' => $editable['m1'] ? ($row['m1'] ?? '') : ($existing['m1'] ?? ''),
                'm2' => $editable['m2'] ? ($row['m2'] ?? '') : ($existing['m2'] ?? ''),
                'm3' => $editable['m3'] ? ($row['m3'] ?? '') : ($existing['m3'] ?? ''),
                'm4' => $editable['m4'] ? ($row['m4'] ?? '') : ($existing['m4'] ?? ''),
                'attendance' => $editable['attendance'] ? ($row['attendance'] ?? '') : ($existing['attendance'] ?? ''),
                'exam' => $editable['exam'] ? ($row['exam'] ?? '') : ($existing['exam'] ?? ''),
            ];
            $rows[$studentId] = $merged;
        }
        if ($departmentService->saveStudentGrades((int)$sheet['id'], $rows)) {
            $message = 'Оценки сохранены';
            $gradesMap = $departmentService->getStudentGradesMap((int)$sheet['id']);
        } else {
            $error = 'Не удалось сохранить оценки';
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
$page_subtitle = 'Ведомость успеваемости · итог = рейтинг × 60% + экзамен × 40%';
include 'includes/header.php';
?>

<?php if (!$dept): ?>
    <div class="card">
        <div class="card-body dept-empty">
            <i class="bi bi-building fs-1 d-block mb-3"></i>
            <h2 class="h5">Сначала создайте отделение</h2>
            <a href="department.php" class="btn btn-primary">Создать отделение</a>
        </div>
    </div>
<?php else: ?>

    <?php if ($message): ?>
        <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

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
                </div>
                <div class="col-md-4">
                    <label class="form-label">Предмет (из учебной части)</label>
                    <select name="subject_id" class="form-select" onchange="this.form.submit()" <?php echo $groupId ? '' : 'disabled'; ?>>
                        <option value="">Выберите предмет</option>
                        <?php foreach ($groupSubjects as $gs): ?>
                            <option value="<?php echo (int)$gs['subject_id']; ?>" <?php echo (int)$gs['subject_id'] === $subjectId ? 'selected' : ''; ?>>
                                <?php
                                $label = $gs['subject_name'] ?? '';
                                if (!empty($gs['teacher_name'])) {
                                    $label .= ' — ' . $gs['teacher_name'];
                                }
                                echo htmlspecialchars($label);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if ($groupId && $periodId && empty($groupSubjects)): ?>
                        <div class="form-text text-warning">В учебном плане этой группы пока нет дисциплин.</div>
                    <?php elseif (!$periodId): ?>
                        <div class="form-text text-warning">Нет текущего учебного периода в учебной части.</div>
                    <?php endif; ?>
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
                    <div class="dept-grade-meta-label">Преподаватель</div>
                    <div class="dept-grade-meta-value">
                        <?php echo htmlspecialchars($selectedSubject['teacher_name'] ?: 'не назначен в учебной части'); ?>
                    </div>
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
                (месяц уже закончился). Остальные месяцы откроются после своего окончания.
            <?php else: ?>
                Пока нет закончившихся месяцев этого семестра — оценки ещё недоступны.
            <?php endif; ?>
            <?php if (!$editable['exam']): ?>
                <span class="text-muted">Экзамен откроется, когда закроются все 4 месяца.</span>
            <?php endif; ?>
        </div>

        <?php if (empty($students)): ?>
            <div class="card">
                <div class="card-body dept-empty">
                    <i class="bi bi-people fs-1 d-block mb-2"></i>
                    В группе пока нет студентов
                </div>
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
                        Оценка за месяц — только после его окончания.
                        Рейтинг = среднее по заполненным месяцам · Итого = рейтинг × 0,6 + экзамен × 0,4.
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
            <div class="card-body dept-empty">
                Выберите предмет из учебного плана группы
            </div>
        </div>
    <?php else: ?>
        <div class="card">
            <div class="card-body dept-empty">
                <i class="bi bi-journal-check fs-1 d-block mb-2"></i>
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
        var exam = parseNum(tr.querySelector('.grade-exam').value);
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

<?php include 'includes/footer.php'; ?>
