<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);
$permissionChecker = new PermissionChecker();
if (!hasPermission('manage_workload') && !hasPermission('view_workload') && !hasPermission('view_schedule') && !hasPermission('view_uchebni')) {
    $permissionChecker->requirePermission('view_uchebni');
}

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
// Режим по роли входа: права из других ролей не открывают чужие ведомости
$is_methodist = ($role === 'methodist');
$is_teacher_session = ($role === 'teacher');

$teachers = $uchebni->getTeachers(true);
$teacher_id = !empty($_GET['teacher_id']) ? (int)$_GET['teacher_id'] : 0;

if ($is_teacher_session) {
    $linked = $uchebni->getTeacherByUserId((int)$current_user['id']);
    $teacher_id = $linked ? (int)$linked['id'] : 0;
} elseif ($teacher_id <= 0 && !empty($teachers)) {
    $teacher_id = (int)$teachers[0]['id'];
}

// Только текущий учебный год (год сентября)
$year_start = (int)date('Y');
if ($period && !empty($period['start_date'])) {
    $y = (int)date('Y', strtotime($period['start_date']));
    $m = (int)date('n', strtotime($period['start_date']));
    $year_start = ($m >= 1 && $m <= 7) ? ($y - 1) : $y;
}

$statement = $teacher_id
    ? $uchebni->getTeacherAnnualStatement($teacher_id, $year_start)
    : null;

$selected_name = '';
$selected_name_short = '';
if ($statement && !empty($statement['teacher'])) {
    $selected_name = Uchebni::formatFio($statement['teacher']);
    $t = $statement['teacher'];
    $ln = trim((string)($t['last_name'] ?? ''));
    $fn = trim((string)($t['first_name'] ?? ''));
    $mn = trim((string)($t['middle_name'] ?? ''));
    $ini = '';
    if ($fn !== '') {
        $ini .= mb_substr($fn, 0, 1) . '.';
    }
    if ($mn !== '') {
        $ini .= mb_substr($mn, 0, 1) . '.';
    }
    $selected_name_short = trim($ln . ' ' . $ini);
}

$org_name = defined('APP_NAME') ? APP_NAME : 'Организация образования';
$year_label = $statement['year_label'] ?? ($year_start . '/' . ($year_start + 1));
$year_parts = explode('/', $year_label);
$year_from_short = isset($year_parts[0]) ? substr($year_parts[0], -2) : '';
$year_to_short = isset($year_parts[1]) ? substr($year_parts[1], -2) : '';

if (!empty($_GET['export']) && $_GET['export'] === 'csv' && $statement && !empty($statement['columns'])) {
    $fioPart = $selected_name_short ?: $selected_name;
    $fioPart = preg_replace('/[^\p{L}\p{N}\s._-]+/u', '', $fioPart);
    $fioPart = trim(preg_replace('/\s+/', '_', $fioPart));
    if ($fioPart === '') {
        $fioPart = 'pedagog';
    }
    $fname = $fioPart . '_vedomost_' . str_replace('/', '-', $year_label) . '.csv';
    $fnameAscii = preg_replace('/[^A-Za-z0-9._-]+/', '_', $fioPart);
    if ($fnameAscii === '' || $fnameAscii === '_') {
        $fnameAscii = 'vedomost';
    }
    $fnameAscii .= '_' . str_replace('/', '-', $year_label) . '.csv';

    header('Content-Type: text/csv; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="' . $fnameAscii . '"; filename*=UTF-8\'\'' . rawurlencode($fname)
    );
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');

    fputcsv($out, ['ФИО педагога', $selected_name], ';');
    fputcsv($out, ['Учебный год', $year_label], ';');
    fputcsv($out, ['Организация', $org_name], ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Ведомость учета учебного времени педагога за год (в часах и (или) кредитах)'], ';');
    fputcsv($out, [], ';');

    $headerGroups = ['Группы'];
    $headerSubjects = ['Месяцы'];
    foreach ($statement['columns'] as $col) {
        $headerGroups[] = $col['group_name'] ?? '';
        $subj = $col['subject_name'] ?? '';
        if (!empty($col['subject_code'])) {
            $subj = trim($col['subject_code'] . ' ' . $subj);
        }
        $headerSubjects[] = $subj;
    }
    $headerGroups[] = 'Итого';
    $headerSubjects[] = '';
    fputcsv($out, $headerGroups, ';');
    fputcsv($out, $headerSubjects, ';');

    foreach ($statement['month_rows'] as $mr) {
        $row = [$mr['label']];
        $sum = 0;
        foreach ($statement['columns'] as $i => $_) {
            $v = (int)($mr['fact'][$i] ?? 0);
            $row[] = $v;
            $sum += $v;
        }
        $row[] = $sum;
        fputcsv($out, $row, ';');
    }

    $exams = ['Экзамены (заносятся на основании экзаменационной ведомости)'];
    $consult = ['Консультации'];
    foreach ($statement['columns'] as $_) {
        $exams[] = 0;
        $consult[] = 0;
    }
    $exams[] = 0;
    $consult[] = 0;
    fputcsv($out, $exams, ';');
    fputcsv($out, $consult, ';');

    $planRow = ['Всего запланировано, часов'];
    $factRow = ['фактически выполнено, часов'];
    $pSum = 0;
    $fSum = 0;
    foreach ($statement['columns'] as $i => $col) {
        $p = (int)($statement['col_plan'][$i] ?? $col['plan_sum'] ?? 0);
        $f = (int)($statement['col_fact'][$i] ?? $col['fact_sum'] ?? 0);
        $planRow[] = $p;
        $factRow[] = $f;
        $pSum += $p;
        $fSum += $f;
    }
    $planRow[] = $pSum;
    $factRow[] = $fSum;
    fputcsv($out, $planRow, ';');
    fputcsv($out, $factRow, ';');
    fputcsv($out, [], ';');
    fputcsv($out, ['Всего часов по плану:', (int)$statement['totals']['plan']], ';');
    fputcsv($out, ['Не выполнено часов:', (int)$statement['totals']['unfulfilled']], ';');
    fputcsv($out, ['Дано часов сверх плана:', (int)$statement['totals']['overtime']], ';');
    fputcsv($out, ['Всего дано за год часов:', (int)$statement['totals']['fact']], ';');
    fclose($out);
    exit;
}

$page_title = 'Ведомость';
$page_subtitle = 'Учебный год ' . $year_label;
$print_doc_title = trim(($selected_name_short ?: $selected_name) . ' — ведомость ' . $year_label);
require_once 'includes/header.php';

$colCount = $statement ? count($statement['columns']) : 0;
?>

<form method="get" class="card mb-4 no-print">
    <div class="card-body row g-3 align-items-end">
        <?php if ($is_methodist): ?>
            <div class="col-md-6">
                <label class="form-label">Преподаватель</label>
                <select name="teacher_id" class="form-select" onchange="this.form.submit()">
                    <?php foreach ($teachers as $t): ?>
                        <option value="<?php echo (int)$t['id']; ?>" <?php echo (int)$t['id'] === $teacher_id ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(Uchebni::formatFio($t)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php else: ?>
            <div class="col-md-6">
                <label class="form-label">Преподаватель</label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($selected_name); ?>" readonly>
                <input type="hidden" name="teacher_id" value="<?php echo (int)$teacher_id; ?>">
            </div>
        <?php endif; ?>
        <div class="col-md-3">
            <label class="form-label">Учебный год</label>
            <input type="text" class="form-control" value="<?php echo htmlspecialchars($year_label); ?>" readonly>
        </div>
        <div class="col-md-3">
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" id="vedomost-print-btn"
                        data-title="<?php echo htmlspecialchars($print_doc_title, ENT_QUOTES); ?>">
                    <i class="bi bi-printer"></i> Печать
                </button>
                <?php if ($statement && !empty($statement['columns'])): ?>
                    <a class="btn btn-outline-success" href="?teacher_id=<?php echo (int)$teacher_id; ?>&export=csv">
                        <i class="bi bi-filetype-csv"></i> CSV
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</form>

<?php if (!$statement || !$teacher_id): ?>
    <div class="alert alert-info no-print">
        <?php if ($is_teacher_session && !$teacher_id): ?>
            Профиль преподавателя не привязан к вашей учётной записи. Обратитесь в учебную часть.
        <?php else: ?>
            Выберите преподавателя.
        <?php endif; ?>
    </div>
<?php elseif (empty($statement['columns'])): ?>
    <div class="alert alert-warning no-print">
        Нет часов за <?php echo htmlspecialchars($year_label); ?>. Заполните нагрузку и расписание.
    </div>
<?php else: ?>

    <!-- Компактный экранный вид -->
    <div class="vedomost-screen no-print">
        <div class="d-flex flex-wrap gap-4 mb-3">
            <div>
                <div class="text-muted small">План</div>
                <div class="fs-4 fw-semibold"><?php echo (int)$statement['totals']['plan']; ?></div>
            </div>
            <div>
                <div class="text-muted small">Факт</div>
                <div class="fs-4 fw-semibold"><?php echo (int)$statement['totals']['fact']; ?></div>
            </div>
            <div>
                <div class="text-muted small">Не выполнено</div>
                <div class="fs-4 fw-semibold"><?php echo (int)$statement['totals']['unfulfilled']; ?></div>
            </div>
            <div>
                <div class="text-muted small">Сверх плана</div>
                <div class="fs-4 fw-semibold"><?php echo (int)$statement['totals']['overtime']; ?></div>
            </div>
        </div>

        <div class="table-responsive card">
            <table class="table table-bordered table-sm align-middle mb-0 vedomost-ui-table">
                <thead class="table-light">
                    <tr>
                        <th>Месяц</th>
                        <?php foreach ($statement['columns'] as $col): ?>
                            <th class="text-center small">
                                <div><?php echo htmlspecialchars($col['group_name']); ?></div>
                                <div class="fw-normal text-muted"><?php echo htmlspecialchars($col['subject_name']); ?></div>
                            </th>
                        <?php endforeach; ?>
                        <th class="text-center">Итого</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($statement['month_rows'] as $mr): ?>
                        <?php
                        $rowFact = 0;
                        foreach ($statement['columns'] as $i => $_) {
                            $rowFact += (int)($mr['fact'][$i] ?? 0);
                        }
                        ?>
                        <tr>
                            <td class="text-nowrap"><?php echo htmlspecialchars($mr['label']); ?></td>
                            <?php foreach ($statement['columns'] as $i => $_): ?>
                                <td class="text-center"><?php echo (int)($mr['fact'][$i] ?? 0); ?></td>
                            <?php endforeach; ?>
                            <td class="text-center fw-semibold"><?php echo $rowFact; ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="table-light">
                        <td class="fw-semibold">План</td>
                        <?php foreach ($statement['columns'] as $i => $col): ?>
                            <td class="text-center fw-semibold"><?php echo (int)($statement['col_plan'][$i] ?? 0); ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold"><?php echo (int)$statement['totals']['plan']; ?></td>
                    </tr>
                    <tr class="table-light">
                        <td class="fw-semibold">Факт</td>
                        <?php foreach ($statement['columns'] as $i => $col): ?>
                            <td class="text-center fw-semibold"><?php echo (int)($statement['col_fact'][$i] ?? 0); ?></td>
                        <?php endforeach; ?>
                        <td class="text-center fw-semibold"><?php echo (int)$statement['totals']['fact']; ?></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Бланк приказа № 130 — только печать -->
    <div class="vedomost-print print-only">
        <section class="vedomost-doc vedomost-blank">
            <div class="vedomost-blank-meta">
                <div>Приложение к приказу МОН РК от 6 апреля 2020 года № 130</div>
                <div>Форма</div>
            </div>

            <div class="vedomost-head">
                <div class="vedomost-ministry">Министерство просвещения Республики Казахстан</div>
                <div class="vedomost-title">Ведомость учета учебного времени педагога за год</div>
                <div class="vedomost-subtitle">(в часах и (или) кредитах)</div>

                <div class="vedomost-fio-main"><?php echo htmlspecialchars($selected_name); ?></div>

                <div class="vedomost-fill-row">
                    <span class="vedomost-fill-label">(наименование организации образования)</span>
                    <span class="vedomost-fill-value"><?php echo htmlspecialchars($org_name); ?></span>
                </div>

                <div class="vedomost-year-line">
                    Годовой учет часов и (или) кредитов, проведенных педагогом
                    в 20<span class="vedomost-u"><?php echo htmlspecialchars($year_from_short); ?></span>/<span class="vedomost-u"><?php echo htmlspecialchars($year_to_short); ?></span>
                    учебном году
                </div>

                <div class="vedomost-fill-row">
                    <span class="vedomost-fill-label">Фамилия, имя, отчество (при его наличии) педагога (полностью)</span>
                    <span class="vedomost-fill-value"><?php echo htmlspecialchars($selected_name); ?></span>
                </div>

                <div class="vedomost-table-caption">
                    Индекс модуля и наименование дисциплин и (или) модуля (наименование практики)
                </div>
            </div>

            <div class="vedomost-table-wrap">
                <table class="vedomost-blank-table">
                    <thead>
                        <tr>
                            <th class="vedomost-corner">Группы</th>
                            <?php foreach ($statement['columns'] as $col): ?>
                                <th><?php echo htmlspecialchars($col['group_name']); ?></th>
                            <?php endforeach; ?>
                            <th>Итого</th>
                        </tr>
                        <tr>
                            <th class="vedomost-corner">Месяцы</th>
                            <?php foreach ($statement['columns'] as $col): ?>
                                <th class="vedomost-subj">
                                    <?php if (!empty($col['subject_code'])): ?>
                                        <div class="vedomost-code"><?php echo htmlspecialchars($col['subject_code']); ?></div>
                                    <?php endif; ?>
                                    <?php echo htmlspecialchars($col['subject_name']); ?>
                                </th>
                            <?php endforeach; ?>
                            <th>часов</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($statement['month_rows'] as $mr): ?>
                            <?php
                            $rowFact = 0;
                            foreach ($statement['columns'] as $i => $_) {
                                $rowFact += (int)($mr['fact'][$i] ?? 0);
                            }
                            ?>
                            <tr>
                                <td class="vedomost-month"><?php echo htmlspecialchars($mr['label']); ?></td>
                                <?php foreach ($statement['columns'] as $i => $_): ?>
                                    <td class="num"><?php echo (int)($mr['fact'][$i] ?? 0); ?></td>
                                <?php endforeach; ?>
                                <td class="num strong"><?php echo $rowFact; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr>
                            <td>Экзамены (заносятся на основании экзаменационной ведомости)</td>
                            <?php for ($i = 0; $i < $colCount; $i++): ?>
                                <td class="num">0</td>
                            <?php endfor; ?>
                            <td class="num">0</td>
                        </tr>
                        <tr>
                            <td>Консультации</td>
                            <?php for ($i = 0; $i < $colCount; $i++): ?>
                                <td class="num">0</td>
                            <?php endfor; ?>
                            <td class="num">0</td>
                        </tr>
                        <tr class="vedomost-totals-row">
                            <td>Всего запланировано, часов</td>
                            <?php foreach ($statement['columns'] as $i => $col): ?>
                                <td class="num strong"><?php echo (int)($statement['col_plan'][$i] ?? 0); ?></td>
                            <?php endforeach; ?>
                            <td class="num strong"><?php echo (int)$statement['totals']['plan']; ?></td>
                        </tr>
                        <tr class="vedomost-totals-row">
                            <td>фактически выполнено, часов</td>
                            <?php foreach ($statement['columns'] as $i => $col): ?>
                                <td class="num strong"><?php echo (int)($statement['col_fact'][$i] ?? 0); ?></td>
                            <?php endforeach; ?>
                            <td class="num strong"><?php echo (int)$statement['totals']['fact']; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="vedomost-summary">
                <div class="vedomost-sum-line">Всего часов по плану: <span class="vedomost-u wide"><?php echo (int)$statement['totals']['plan']; ?></span></div>
                <div class="vedomost-sum-line">Не выполнено часов: <span class="vedomost-u wide"><?php echo (int)$statement['totals']['unfulfilled']; ?></span></div>
                <div class="vedomost-sum-line">Дано часов сверх плана: <span class="vedomost-u wide"><?php echo (int)$statement['totals']['overtime']; ?></span></div>
                <div class="vedomost-sum-line">Всего дано за год часов: <span class="vedomost-u wide"><?php echo (int)$statement['totals']['fact']; ?></span></div>
            </div>

            <div class="vedomost-signs">
                <div class="vedomost-sign-line">
                    <span>Заместитель руководителя по учебной работе</span>
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-hint">(подпись)</span>
                </div>
                <div class="vedomost-sign-line">
                    <span>Преподаватель</span>
                    <span class="vedomost-sign-blank"></span>
                    <span class="vedomost-sign-name"><?php echo htmlspecialchars($selected_name_short ?: $selected_name); ?></span>
                </div>
            </div>
        </section>

        <div class="vedomost-page-break"></div>

        <section class="vedomost-doc vedomost-blank vedomost-extra">
            <div class="vedomost-blank-meta">
                <div>Приложение к приказу МОН РК от 6 апреля 2020 года № 130</div>
                <div>Форма</div>
            </div>

            <div class="vedomost-head">
                <div class="vedomost-title">Дополнительные сведения к годовому учету часов педагога</div>
                <div class="vedomost-fill-row">
                    <span class="vedomost-fill-value"><?php echo htmlspecialchars($selected_name_short ?: $selected_name); ?></span>
                </div>
            </div>

            <div class="vedomost-table-wrap">
                <table class="vedomost-blank-table vedomost-extra-table">
                    <thead>
                        <tr>
                            <th rowspan="3">№ учебной группы</th>
                            <th rowspan="3">Наименование дисциплины и (или) модулей</th>
                            <th colspan="2" rowspan="2">Количество часов</th>
                            <th colspan="6">Из них часы</th>
                            <th rowspan="3">Общее количество часов</th>
                        </tr>
                        <tr>
                            <th colspan="2">факультатива</th>
                            <th colspan="2">консультаций</th>
                            <th colspan="2">экзаменов</th>
                        </tr>
                        <tr>
                            <th>план</th>
                            <th>факт</th>
                            <th>план</th>
                            <th>факт</th>
                            <th>план</th>
                            <th>факт</th>
                            <th>план</th>
                            <th>факт</th>
                        </tr>
                        <tr class="vedomost-col-nums">
                            <th>1</th><th>2</th><th>3</th><th>4</th><th>5</th><th>6</th>
                            <th>7</th><th>8</th><th>9</th><th>10</th><th>11</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $sumP = 0;
                        $sumF = 0;
                        foreach ($statement['columns'] as $i => $col):
                            $p = (int)($statement['col_plan'][$i] ?? 0);
                            $f = (int)($statement['col_fact'][$i] ?? 0);
                            $sumP += $p;
                            $sumF += $f;
                            $totalHours = $f > 0 ? $f : $p;
                            $subjLabel = $col['subject_name'];
                            if (!empty($col['subject_code'])) {
                                $subjLabel = trim($col['subject_code'] . ' ' . $subjLabel);
                            }
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($col['group_name']); ?></td>
                                <td class="left"><?php echo htmlspecialchars($subjLabel); ?></td>
                                <td class="num"><?php echo $p; ?></td>
                                <td class="num"><?php echo $f; ?></td>
                                <td class="num">0</td>
                                <td class="num">0</td>
                                <td class="num">0</td>
                                <td class="num">0</td>
                                <td class="num">0</td>
                                <td class="num">0</td>
                                <td class="num strong"><?php echo $totalHours; ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <tr class="vedomost-totals-row">
                            <td colspan="2" class="right">Итого</td>
                            <td class="num strong"><?php echo $sumP; ?></td>
                            <td class="num strong"><?php echo $sumF; ?></td>
                            <td colspan="6"></td>
                            <td class="num strong"><?php echo $sumF > 0 ? $sumF : $sumP; ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="vedomost-signs">
                <div class="vedomost-fio-block">
                    Фамилия, имя, отчество (при его наличии) педагога (полностью)
                    <div class="vedomost-fill-row">
                        <span class="vedomost-fill-value"><?php echo htmlspecialchars($selected_name); ?></span>
                        <span class="vedomost-sign-hint">(подпись)</span>
                    </div>
                </div>
                <div class="vedomost-sign-line">
                    <span>Проверено</span>
                    <span class="vedomost-sign-blank"></span>
                </div>
                <div class="vedomost-sign-line">
                    <span>Зав. учебной части</span>
                    <span class="vedomost-sign-blank"></span>
                </div>
                <div class="vedomost-sign-line">
                    <span>Заместитель руководителя по учебной работе</span>
                    <span class="vedomost-sign-blank"></span>
                </div>
            </div>

            <div class="vedomost-note">
                Примечание: годовой учет учебного времени педагогов ведет учебная часть в часах
                и (или) кредитах на основании данных форм.
            </div>
        </section>
    </div>
<?php endif; ?>

<style>
.vedomost-ui-table th, .vedomost-ui-table td { font-size: 0.85rem; vertical-align: middle; }
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
.vedomost-fio-main {
    margin: 2mm 0 3mm;
    font-size: 14pt;
    font-weight: 700;
    text-decoration: underline;
}
.vedomost-table-caption { margin-top: 3mm; font-size: 10pt; font-weight: 600; text-align: center; }
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
.vedomost-u.wide { min-width: 3.5rem; margin-left: 0.35rem; }
.vedomost-table-wrap { margin-top: 3mm; overflow-x: auto; }
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
.vedomost-month { text-align: left !important; white-space: nowrap; }
.vedomost-subj { line-height: 1.1; font-weight: 400; font-size: 7pt; word-break: break-word; }
.vedomost-code { font-size: 6.5pt; font-weight: 600; }
.vedomost-blank-table td.left { text-align: left; }
.vedomost-blank-table td.right { text-align: right; }
.vedomost-blank-table .strong { font-weight: 700; }
.vedomost-totals-row td { font-weight: 700; }
.vedomost-col-nums th { font-weight: 400; font-size: 7.5pt; }
.vedomost-summary { margin-top: 4mm; max-width: 28rem; text-align: left; line-height: 1.8; font-size: 11pt; }
.vedomost-signs { margin-top: 6mm; font-size: 11pt; }
.vedomost-sign-line {
    display: flex;
    align-items: flex-end;
    gap: 0.5rem;
    margin-bottom: 4mm;
    flex-wrap: wrap;
}
.vedomost-sign-blank {
    flex: 1 1 10rem;
    min-width: 8rem;
    max-width: 16rem;
    border-bottom: 1px solid #000;
    height: 14pt;
}
.vedomost-sign-hint { font-size: 9pt; }
.vedomost-sign-name { font-weight: 700; }
.vedomost-fio-block { margin-bottom: 4mm; text-align: left; }
.vedomost-fio-block .vedomost-fill-row {
    flex-direction: row;
    align-items: flex-end;
    max-width: none;
    gap: 0.5rem;
}
.vedomost-fio-block .vedomost-fill-value { text-align: left; }
.vedomost-note { margin-top: 5mm; font-size: 9pt; line-height: 1.4; }
.vedomost-page-break { height: 0; }

@media print {
    @page {
        size: A4 portrait;
        margin: 10mm 8mm;
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
    .vedomost-page-break {
        page-break-before: always;
        break-before: page;
    }
    .vedomost-blank-table thead { display: table-header-group; }
    .vedomost-blank-table tr { page-break-inside: avoid; break-inside: avoid; }
    .vedomost-table-wrap { overflow: visible !important; }
}
</style>

<script>
(function () {
    var btn = document.getElementById('vedomost-print-btn');
    if (!btn) return;
    btn.addEventListener('click', function () {
        var prev = document.title;
        var title = btn.getAttribute('data-title') || prev;
        document.title = title;
        window.print();
        setTimeout(function () { document.title = prev; }, 1000);
    });
})();
</script>

<?php require_once 'includes/footer.php'; ?>
