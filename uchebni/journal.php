<?php
/**
 * Сводная ведомость по группе за текущий учебный год.
 * На экране: группа → предмет → преподаватель; печать — сводная по всем предметам.
 * Данные заполняет заведующий отделением (department/grades.php).
 */
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/Department.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);
requirePermission('view_journal');

$uchebni = new Uchebni();
$departmentService = new Department();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';

$period = $uchebni->getCurrentPeriod();
$periodId = $period ? (int)$period['id'] : 0;
$academicYear = Department::resolveAcademicYearFromPeriod($period);
$defaultSemester = $period ? $uchebni->getPeriodSemesterNumber($period) : 1;

$semester = !empty($_GET['semester']) ? (int)$_GET['semester'] : $defaultSemester;
$semester = ($semester === 2) ? 2 : 1;

$teacherFilterId = null;
if ($role === 'teacher') {
    $linked = $uchebni->getTeacherByUserId((int)$current_user['id']);
    $teacherFilterId = $linked ? (int)$linked['id'] : 0;
    if ($teacherFilterId <= 0) {
        $teacherFilterId = -1;
    }
}

$groups = ($teacherFilterId === -1)
    ? []
    : $departmentService->getActiveDepartmentGroups($teacherFilterId > 0 ? $teacherFilterId : null);

$groupId = !empty($_GET['group_id']) ? (int)$_GET['group_id'] : 0;
$allowedIds = array_map(static function ($g) {
    return (int)$g['id'];
}, $groups);
if ($groupId > 0 && !in_array($groupId, $allowedIds, true)) {
    $groupId = 0;
}
if ($groupId <= 0 && !empty($groups)) {
    $groupId = (int)$groups[0]['id'];
}

$groupSubjects = [];
if ($groupId > 0 && $periodId > 0) {
    $groupSubjects = $uchebni->getGroupSubjects($periodId, $groupId);
    if ($teacherFilterId > 0) {
        $groupSubjects = array_values(array_filter($groupSubjects, static function ($gs) use ($teacherFilterId) {
            return (int)($gs['teacher_id'] ?? 0) === $teacherFilterId;
        }));
    }
}

$subjectId = !empty($_GET['subject_id']) ? (int)$_GET['subject_id'] : 0;
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

$selectedGroup = null;
foreach ($groups as $g) {
    if ((int)$g['id'] === $groupId) {
        $selectedGroup = $g;
        break;
    }
}

$teacherName = '';
if ($selectedSubject) {
    $teacherName = trim((string)($selectedSubject['teacher_name'] ?? ''));
}

$monthLabels = Department::gradeMonthLabels($semester);
$students = [];
$gradesMap = [];
$sheet = null;

if ($groupId > 0 && $subjectId > 0 && $selectedSubject) {
    $db = getDB();
    $stmt = $db->prepare(
        "SELECT * FROM department_grade_sheets
         WHERE group_id = ? AND subject_id = ? AND semester = ? AND academic_year = ?
         LIMIT 1"
    );
    $stmt->bind_param('iiii', $groupId, $subjectId, $semester, $academicYear);
    $stmt->execute();
    $sheet = $stmt->get_result()->fetch_assoc() ?: null;

    $students = $departmentService->getGroupStudents($groupId);
    $gradesMap = $sheet ? $departmentService->getStudentGradesMap((int)$sheet['id']) : [];

    if ($teacherName === '' && $sheet && !empty($sheet['teacher_id'])) {
        $t = $uchebni->getTeacherById((int)$sheet['teacher_id']);
        if ($t) {
            $teacherName = Uchebni::formatFio($t);
        }
    }
}

$yearLabel = $academicYear . '/' . ($academicYear + 1);
$year_from_short = substr((string)$academicYear, -2);
$year_to_short = substr((string)($academicYear + 1), -2);
$org_name = defined('APP_NAME') ? APP_NAME : 'Организация образования';

$groupNameFull = '';
$deptName = '';
if ($selectedGroup) {
    $groupNameFull = ($selectedGroup['name'] ?? '')
        . (!empty($selectedGroup['code']) ? ' (' . $selectedGroup['code'] . ')' : '');
    $deptName = trim((string)($selectedGroup['department_name'] ?? ''));
}

$subjectTitle = '';
if ($selectedSubject) {
    $subjectTitle = trim((string)($selectedSubject['subject_name'] ?? ''));
    $assessment = trim((string)($selectedSubject['assessment'] ?? ''));
    if ($assessment !== '') {
        $subjectTitle .= ' (' . $assessment . ')';
    }
}

// Сводная на печать — все предметы группы за семестр
$summary = ($groupId > 0)
    ? $departmentService->getSummaryVedomost($groupId, $academicYear, $semester)
    : ['subjects' => [], 'students' => [], 'cells' => []];

$hasPrintData = $groupId > 0
    && !empty($summary['subjects'])
    && !empty($summary['students']);

$print_doc_title = trim($groupNameFull . ' — сводная ведомость ' . $yearLabel);

$page_title = 'Сводная ведомость';
$page_subtitle = 'По группе · учебный год ' . $yearLabel . ' · данные отделения';
require_once 'includes/header.php';
?>

<form method="get" class="card mb-4 no-print">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-md-3">
                <label class="form-label">Группа</label>
                <select name="group_id" class="form-select" onchange="this.form.submit()" <?php echo empty($groups) ? 'disabled' : ''; ?>>
                    <?php if (empty($groups)): ?>
                        <option value="">Нет групп отделения</option>
                    <?php else: ?>
                        <?php foreach ($groups as $g): ?>
                            <option value="<?php echo (int)$g['id']; ?>" <?php echo (int)$g['id'] === $groupId ? 'selected' : ''; ?>>
                                <?php
                                $label = $g['name'] . (!empty($g['code']) ? ' · ' . $g['code'] : '');
                                if (!empty($g['department_name'])) {
                                    $label .= ' — ' . $g['department_name'];
                                }
                                echo htmlspecialchars($label);
                                ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label">Предмет</label>
                <select name="subject_id" class="form-select" onchange="this.form.submit()" <?php echo ($groupId && !empty($groupSubjects)) ? '' : 'disabled'; ?>>
                    <option value="">Выберите предмет</option>
                    <?php foreach ($groupSubjects as $gs): ?>
                        <option value="<?php echo (int)$gs['subject_id']; ?>" <?php echo (int)$gs['subject_id'] === $subjectId ? 'selected' : ''; ?>>
                            <?php
                            $opt = $gs['subject_name'] ?? '';
                            if (!empty($gs['assessment'])) {
                                $opt .= ' (' . $gs['assessment'] . ')';
                            }
                            echo htmlspecialchars($opt);
                            ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($groupId && $periodId && empty($groupSubjects)): ?>
                    <div class="form-text text-warning">В учебном плане группы нет дисциплин.</div>
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
                <label class="form-label">Преподаватель</label>
                <input type="text" class="form-control" readonly
                       value="<?php echo $subjectId ? htmlspecialchars($teacherName !== '' ? $teacherName : 'не назначен') : ''; ?>"
                       placeholder="Сначала выберите предмет">
            </div>
        </div>
        <div class="row g-3 align-items-end mt-1">
            <div class="col-md-9">
                <?php if ($subjectId && $teacherName !== ''): ?>
                    <div class="text-muted small">
                        <i class="bi bi-person-workspace me-1"></i>
                        По предмету «<?php echo htmlspecialchars($selectedSubject['subject_name'] ?? ''); ?>»
                        ведёт: <strong><?php echo htmlspecialchars($teacherName); ?></strong>
                    </div>
                <?php endif; ?>
            </div>
            <div class="col-md-3">
                <button type="button" class="btn btn-primary w-100" id="svodnaya-print-btn"
                        <?php echo $hasPrintData ? '' : 'disabled'; ?>
                        data-title="<?php echo htmlspecialchars($print_doc_title, ENT_QUOTES); ?>">
                    <i class="bi bi-printer me-1"></i>Печать сводной
                </button>
            </div>
        </div>
    </div>
</form>

<?php if (!$groupId || empty($groups)): ?>
    <div class="card no-print">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-table fs-1 d-block mb-2"></i>
            <?php if ($role === 'teacher' && $teacherFilterId === -1): ?>
                Ваш аккаунт не привязан к карточке преподавателя — сводная ведомость недоступна.
            <?php else: ?>
                Нет групп, привязанных к отделениям. Сводную ведомость заполняет заведующий отделением.
            <?php endif; ?>
        </div>
    </div>
<?php elseif (!$subjectId): ?>
    <div class="card no-print">
        <div class="card-body text-center text-muted py-5">
            <i class="bi bi-journal-text fs-1 d-block mb-2"></i>
            Выберите предмет — справа появится преподаватель из учебного плана.
        </div>
    </div>
<?php else: ?>
    <div class="card mb-3 no-print">
        <div class="card-body d-flex flex-wrap gap-3 justify-content-between align-items-center">
            <div>
                <div class="text-muted small">Группа</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($groupNameFull); ?></div>
            </div>
            <div>
                <div class="text-muted small">Предмет</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($subjectTitle); ?></div>
            </div>
            <div>
                <div class="text-muted small">Преподаватель</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($teacherName !== '' ? $teacherName : 'не назначен'); ?></div>
            </div>
            <div class="text-end">
                <div class="text-muted small">Учебный год · семестр</div>
                <div class="fw-semibold"><?php echo htmlspecialchars($yearLabel); ?> · <?php echo $semester === 2 ? 'II' : 'I'; ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($students)): ?>
        <div class="card no-print">
            <div class="card-body text-center text-muted py-5">В группе пока нет студентов.</div>
        </div>
    <?php elseif (!$sheet): ?>
        <div class="card no-print">
            <div class="card-body text-center text-muted py-5">
                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                Заведующий отделением ещё не заполнил оценки по этому предмету
                за <?php echo htmlspecialchars($yearLabel); ?> (<?php echo $semester === 2 ? 'II' : 'I'; ?> сем.).
            </div>
        </div>
    <?php else: ?>
        <div class="svodnaya-screen no-print">
            <div class="card">
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-bordered table-sm align-middle mb-0 svodnaya-table">
                            <thead class="table-light">
                                <tr>
                                    <th class="svodnaya-fio">Ф.И.О.</th>
                                    <?php foreach ($monthLabels as $label): ?>
                                        <th class="text-center"><?php echo htmlspecialchars($label); ?></th>
                                    <?php endforeach; ?>
                                    <th class="text-center" title="Посещаемость, %">пос.</th>
                                    <th class="text-center">Рейт.</th>
                                    <th class="text-center">экз.</th>
                                    <th class="text-center">Итого</th>
                                    <th class="text-center">Оценка</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $assessment = trim((string)($selectedSubject['assessment'] ?? ''));
                                foreach ($students as $st):
                                    $sid = (int)$st['id'];
                                    $g = $gradesMap[$sid] ?? [];
                                    $m1 = $g['m1'] ?? '';
                                    $m2 = $g['m2'] ?? '';
                                    $m3 = $g['m3'] ?? '';
                                    $m4 = $g['m4'] ?? '';
                                    $att = $g['attendance'] ?? '';
                                    $exam = $g['exam'] ?? '';
                                    $rating = Department::calcGradeRating([$m1, $m2, $m3, $m4]);
                                    $total = Department::calcGradeTotal($rating, $exam);
                                    $cell = Department::formatSummaryGradeCell($total, $assessment);
                                    $fio = trim(($st['last_name'] ?? '') . ' ' . ($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? ''));
                                    $isFail = ($cell === 'н/з');
                                    ?>
                                    <tr>
                                        <td class="svodnaya-fio">
                                            <?php echo htmlspecialchars($fio); ?>
                                            <?php if ((int)($st['academic_leave'] ?? 0) === 1): ?>
                                                <span class="badge text-bg-warning ms-1">академ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?php echo $m1 !== '' && $m1 !== null ? htmlspecialchars((string)$m1) : '—'; ?></td>
                                        <td class="text-center"><?php echo $m2 !== '' && $m2 !== null ? htmlspecialchars((string)$m2) : '—'; ?></td>
                                        <td class="text-center"><?php echo $m3 !== '' && $m3 !== null ? htmlspecialchars((string)$m3) : '—'; ?></td>
                                        <td class="text-center"><?php echo $m4 !== '' && $m4 !== null ? htmlspecialchars((string)$m4) : '—'; ?></td>
                                        <td class="text-center"><?php echo $att !== '' && $att !== null ? htmlspecialchars((string)$att) : '—'; ?></td>
                                        <td class="text-center fw-semibold"><?php echo $rating !== null ? htmlspecialchars((string)$rating) : '—'; ?></td>
                                        <td class="text-center"><?php echo $exam !== '' && $exam !== null ? htmlspecialchars((string)$exam) : '—'; ?></td>
                                        <td class="text-center fw-bold"><?php echo $total !== null ? htmlspecialchars((string)$total) : '—'; ?></td>
                                        <td class="text-center <?php echo $isFail ? 'text-danger fw-semibold' : 'fw-semibold'; ?>">
                                            <?php echo $cell !== '' ? htmlspecialchars($cell) : '—'; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer text-muted small">
                    Только просмотр. Оценки вносит заведующий отделением.
                    Итого = рейтинг × 60% + экзамен × 40%.
                </div>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<?php if ($hasPrintData): ?>
    <!-- Бланк печати: сводная по группе (все предметы семестра) -->
    <div class="vedomost-print print-only">
        <section class="vedomost-doc vedomost-blank">
            <div class="vedomost-blank-meta">
                <div>Приложение к приказу МОН РК от 6 апреля 2020 года № 130</div>
                <div>Форма</div>
            </div>

            <div class="vedomost-head">
                <div class="vedomost-ministry">Министерство просвещения Республики Казахстан</div>
                <div class="vedomost-title">Сводная ведомость по группе</div>
                <div class="vedomost-subtitle">успеваемости обучающихся за учебный год</div>

                <div class="vedomost-fill-row">
                    <span class="vedomost-fill-label">(наименование организации образования)</span>
                    <span class="vedomost-fill-value"><?php echo htmlspecialchars($org_name); ?></span>
                </div>

                <div class="vedomost-year-line">
                    за 20<span class="vedomost-u"><?php echo htmlspecialchars($year_from_short); ?></span>/<span class="vedomost-u"><?php echo htmlspecialchars($year_to_short); ?></span>
                    учебный год · <?php echo $semester === 2 ? 'II' : 'I'; ?> семестр
                </div>

                <div class="vedomost-fill-row">
                    <span class="vedomost-fill-label">Группа</span>
                    <span class="vedomost-fill-value"><?php echo htmlspecialchars($groupNameFull); ?></span>
                </div>

                <?php if ($deptName !== ''): ?>
                    <div class="vedomost-fill-row">
                        <span class="vedomost-fill-label">Отделение</span>
                        <span class="vedomost-fill-value"><?php echo htmlspecialchars($deptName); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="vedomost-table-wrap">
                <table class="vedomost-blank-table">
                    <thead>
                        <tr>
                            <th class="vedomost-corner" style="width:2rem">№</th>
                            <th class="vedomost-corner">Ф.И.О.</th>
                            <?php foreach ($summary['subjects'] as $col): ?>
                                <th class="vedomost-subj">
                                    <?php echo htmlspecialchars($col['title']); ?>
                                    <?php if (!empty($col['teacher_name'])): ?>
                                        <div class="vedomost-code"><?php echo htmlspecialchars($col['teacher_name']); ?></div>
                                    <?php endif; ?>
                                </th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $n = 0;
                        foreach ($summary['students'] as $st):
                            $n++;
                            $sid = (int)$st['id'];
                            $fio = trim(($st['last_name'] ?? '') . ' ' . ($st['first_name'] ?? '') . ' ' . ($st['middle_name'] ?? ''));
                            ?>
                            <tr>
                                <td><?php echo $n; ?></td>
                                <td class="left"><?php echo htmlspecialchars($fio); ?></td>
                                <?php foreach ($summary['subjects'] as $col):
                                    $cell = $summary['cells'][$sid][$col['key']] ?? '';
                                    ?>
                                    <td><?php echo $cell !== '' ? htmlspecialchars($cell) : ''; ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="vedomost-signs">
                <div class="vedomost-sign-line">
                    Заведующий отделением
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-hint">(подпись)</span>
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-hint">(Ф.И.О.)</span>
                </div>
                <div class="vedomost-sign-line">
                    Методист учебной части
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-hint">(подпись)</span>
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-hint">(Ф.И.О.)</span>
                </div>
            </div>

            <div class="vedomost-note">
                Примечание: итоговая оценка по дисциплине = рейтинг × 60% + экзамен × 40%.
                При результате менее 50% проставляется «н/з».
            </div>
        </section>
    </div>
<?php endif; ?>

<style>
.svodnaya-table { font-size: 0.9rem; }
.svodnaya-fio { white-space: nowrap; min-width: 12rem; position: sticky; left: 0; background: #fff; z-index: 1; }
.svodnaya-table thead .svodnaya-fio { background: var(--bs-table-bg, #f8f9fa); z-index: 2; }

.print-only { display: none; }

.vedomost-blank-meta {
    display: flex;
    justify-content: space-between;
    gap: 1rem;
    font-size: 8pt;
    margin-bottom: 4mm;
}
.vedomost-head { text-align: center; }
.vedomost-ministry { font-size: 11pt; margin-bottom: 2pt; }
.vedomost-title { font-weight: 700; font-size: 13pt; line-height: 1.3; }
.vedomost-subtitle { font-size: 11pt; margin-bottom: 2mm; }
.vedomost-fill-row {
    display: flex;
    flex-direction: column;
    align-items: center;
    margin: 2mm auto 0;
    max-width: 42rem;
}
.vedomost-fill-label { font-size: 9pt; }
.vedomost-fill-value {
    display: block;
    width: 100%;
    border-bottom: 1px solid #000;
    text-align: center;
    font-weight: 700;
    padding: 1pt 4pt 0;
    min-height: 14pt;
}
.vedomost-year-line { margin-top: 3mm; font-size: 11pt; line-height: 1.4; }
.vedomost-u {
    display: inline-block;
    min-width: 1.6rem;
    border-bottom: 1px solid #000;
    text-align: center;
    font-weight: 700;
    padding: 0 2pt;
}
.vedomost-table-wrap { margin-top: 4mm; overflow-x: auto; }
.vedomost-blank-table { width: 100%; border-collapse: collapse; }
.vedomost-blank-table th,
.vedomost-blank-table td {
    border: 1px solid #000;
    padding: 1.5pt 2pt;
    font-size: 8pt;
    vertical-align: middle;
    text-align: center;
}
.vedomost-blank-table th { font-weight: 700; }
.vedomost-corner { white-space: nowrap; min-width: 6.5rem; text-align: left !important; }
.vedomost-subj { line-height: 1.15; font-weight: 600; font-size: 7pt; word-break: break-word; max-width: 4.5rem; }
.vedomost-code { font-size: 6pt; font-weight: 400; margin-top: 1pt; }
.vedomost-blank-table td.left { text-align: left; }
.vedomost-signs { margin-top: 8mm; font-size: 11pt; }
.vedomost-sign-line {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
    margin-bottom: 5mm;
    flex-wrap: wrap;
}
.vedomost-sign-blank {
    flex: 1 1 8rem;
    min-width: 6rem;
    max-width: 14rem;
    border-bottom: 1px solid #000;
    height: 14pt;
}
.vedomost-sign-hint { font-size: 9pt; }
.vedomost-note { margin-top: 5mm; font-size: 9pt; line-height: 1.4; text-align: left; }

@media print {
    @page {
        size: A4 landscape;
        margin: 8mm 8mm;
    }

    html, body {
        background: #fff !important;
        color: #000 !important;
        margin: 0 !important;
        padding: 0 !important;
    }

    body * { visibility: hidden !important; }
    .vedomost-print,
    .vedomost-print * { visibility: visible !important; }

    .print-only { display: block !important; }
    .no-print { display: none !important; visibility: hidden !important; }

    .vedomost-print {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        font-family: "Times New Roman", Times, "Liberation Serif", serif !important;
        color: #000 !important;
        font-size: 11pt;
        line-height: 1.25;
        background: #fff;
    }

    .vedomost-blank { border: none; padding: 0; margin: 0; background: #fff; }
    .vedomost-blank-table thead { display: table-header-group; }
    .vedomost-blank-table tr { page-break-inside: avoid; break-inside: avoid; }
    .vedomost-table-wrap { overflow: visible !important; }
}
</style>

<script>
(function () {
    var btn = document.getElementById('svodnaya-print-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        if (btn.disabled) return;
        var prev = document.title;
        var title = btn.getAttribute('data-title') || prev;
        document.title = title;
        window.print();
        setTimeout(function () { document.title = prev; }, 1000);
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
