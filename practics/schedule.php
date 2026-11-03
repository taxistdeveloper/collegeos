<?php
require_once '../config/config.php';
require_once '../classes/Practice.php';
require_once '../includes/auth.php';

checkAuth();

$practice = new Practice();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$isTeacher = ($role === 'teacher');
$isAdminOrMethodist = in_array($role, ['admin', 'methodist', 'director', 'manager', 'department_head', 'practicant'], true);

$id = (int)($_GET['id'] ?? 0);
$workload = $practice->getWorkloadById($id);

if (!$workload) {
    header('Location: index.php');
    exit;
}

// Если преподаватель, может смотреть только свои нагрузки
if ($isTeacher) {
    $teacherId = $practice->getOrCreateTeacherByUserId((int)$current_user['id']);
    if ((int)$workload['teacher_id'] !== $teacherId) {
        header('Location: index.php');
        exit;
    }
}

$message = '';
$error = '';
$isSubmitted = ($workload['status'] === 'submitted');
$canEdit = (!$isSubmitted || $isAdminOrMethodist);

// Обработка сохранения или отправки
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {
    $postedAction = $_POST['form_action'] ?? 'save';
    $rawEntries = $_POST['entries'] ?? [];
    $comment = sanitize($_POST['comment'] ?? '');

    $entries = [];
    if (is_array($rawEntries)) {
        foreach ($rawEntries as $row) {
            if (!is_array($row)) continue;
            $moduleText = trim($row['curriculum'] ?? $row['module_name'] ?? '');
            $date = trim($row['date'] ?? '');
            $timeStart = trim($row['time_start'] ?? '');
            $timeEnd = trim($row['time_end'] ?? '');
            $place = trim($row['place'] ?? '');
            $isDot = !empty($row['is_dot']) ? 1 : 0;

            // Вычисляем часы
            $hours = 0.0;
            if ($timeStart !== '' && $timeEnd !== '') {
                $sParts = explode(':', $timeStart);
                $eParts = explode(':', $timeEnd);
                if (count($sParts) >= 2 && count($eParts) >= 2) {
                    $sMin = (int)$sParts[0] * 60 + (int)$sParts[1];
                    $eMin = (int)$eParts[0] * 60 + (int)$eParts[1];
                    if ($eMin > $sMin) {
                        $hours = round(($eMin - $sMin) / 60, 1);
                    }
                }
            }

            if ($moduleText !== '' || $date !== '' || $hours > 0) {
                $entries[] = [
                    'module_name' => $moduleText,
                    'section'     => '',
                    'topic'       => '',
                    'date'        => $date,
                    'time_start'  => $timeStart,
                    'time_end'    => $timeEnd,
                    'hours'       => $hours,
                    'place'       => $place,
                    'is_dot'      => (bool)$isDot,
                ];
            }
        }
    }

    $res = $practice->saveSchedule($id, $entries, $comment);
    if ($res['ok']) {
        if ($postedAction === 'submit') {
            $subRes = $practice->submitSchedule($id, $comment);
            if ($subRes['ok']) {
                $message = 'График практики успешно отправлен на проверку!';
                $workload = $practice->getWorkloadById($id);
                $isSubmitted = true;
                $canEdit = $isAdminOrMethodist;
            } else {
                $error = 'Не удалось отправить график: ' . ($subRes['error'] ?? '');
            }
        } else {
            $message = 'Черновик графика сохранен!';
            $workload = $practice->getWorkloadById($id);
        }
    } else {
        $error = 'Ошибка сохранения: ' . ($res['error'] ?? '');
    }
}

// Загрузка записей графика
$formData = json_decode($workload['form_data'] ?? '{}', true);
$entries = $formData['entries'] ?? [];
if (empty($entries)) {
    // Начальные пустые строки
    $entries = [
        ['module_name' => $workload['module_name'], 'date' => '', 'time_start' => '09:00', 'time_end' => '13:00', 'hours' => 4.0, 'place' => 'Кабинет 306', 'is_dot' => false],
        ['module_name' => $workload['module_name'], 'date' => '', 'time_start' => '09:00', 'time_end' => '13:00', 'hours' => 4.0, 'place' => 'Кабинет 306', 'is_dot' => false],
        ['module_name' => $workload['module_name'], 'date' => '', 'time_start' => '09:00', 'time_end' => '13:00', 'hours' => 4.0, 'place' => 'Кабинет 306', 'is_dot' => false],
    ];
}

$summary = Practice::calculateHoursSummary($entries, (int)$workload['practice_hours']);

$page_title = 'График практики: ' . $workload['module_name'];
$page_subtitle = 'Преподаватель: ' . ($workload['teacher_name'] ?: '—') . ' · Группа: ' . ($workload['study_group'] ?: '—');

require_once 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Панель статуса и часов -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div>
                <span class="badge bg-light text-dark border me-2">
                    <i class="bi bi-people me-1"></i>Группа: <?php echo htmlspecialchars($workload['study_group'] ?: '—'); ?>
                </span>
                <span class="badge bg-light text-dark border me-2">
                    <i class="bi bi-person me-1"></i><?php echo htmlspecialchars($workload['teacher_name'] ?: '—'); ?>
                </span>
                <span class="badge bg-light text-dark border">
                    <i class="bi bi-calendar-event me-1"></i>Срок сдачи: <?php echo date('d.m.Y', strtotime($workload['deadline'])); ?>
                </span>
            </div>
            <div class="d-flex gap-2">
                <a href="export_docx.php?id=<?php echo (int)$workload['id']; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-file-earmark-word me-1"></i>Скачать grafik.docx
                </a>
            </div>
        </div>

        <div class="bg-light p-3 rounded">
            <div class="d-flex justify-content-between align-items-center mb-1">
                <div>
                    <strong>Часы по графику: </strong>
                    <span id="counted-hours-text" class="fs-5 text-primary fw-bold"><?php echo number_format($summary['counted'], 1); ?></span>
                    из <span class="fw-bold"><?php echo (int)$workload['practice_hours']; ?></span> ч.
                    <span id="excluded-wrap" class="text-danger small ms-2 <?php echo $summary['excluded'] > 0 ? '' : 'd-none'; ?>">
                        (не в зачёт: <span id="excluded-hours-text"><?php echo number_format($summary['excluded'], 1); ?></span> ч. — праздники/выходные)
                    </span>
                </div>
                <span id="hours-percent-badge" class="badge bg-primary fs-6"><?php echo (int)$summary['percent']; ?>%</span>
            </div>
            <div class="progress" style="height: 10px;">
                <div id="hours-progress-bar" class="progress-bar bg-success" style="width: <?php echo min(100, (int)$summary['percent']); ?>%;"></div>
            </div>
            <div class="text-muted small mt-2">
                <i class="bi bi-info-circle me-1"></i>Выходные (суббота, воскресенье) и праздничные дни РК не учитываются при подсчёте часов практики.
            </div>
        </div>
    </div>
</div>

<form method="post" id="scheduleForm">
    <input type="hidden" name="form_action" id="formActionInput" value="save">

    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0">Таблица занятий практики</h5>
            <?php if ($canEdit): ?>
                <button type="button" class="btn btn-outline-success btn-sm" id="btnAddRow">
                    <i class="bi bi-plus-lg me-1"></i>Добавить строку
                </button>
            <?php endif; ?>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered align-middle mb-0" id="scheduleTable">
                <thead class="table-light">
                    <tr>
                        <th style="width: 45px;">№</th>
                        <th>Наименование модуля, раздела, темы</th>
                        <th style="width: 150px;">Дата</th>
                        <th style="width: 120px;">Начало</th>
                        <th style="width: 120px;">Окончание</th>
                        <th style="width: 90px;" class="text-center">Часы</th>
                        <th style="width: 180px;">Место проведения</th>
                        <th style="width: 70px;" class="text-center">ДОТ</th>
                        <?php if ($canEdit): ?><th style="width: 50px;"></th><?php endif; ?>
                    </tr>
                </thead>
                <tbody id="scheduleTbody">
                    <?php foreach ($entries as $i => $e): 
                        $curDate = $e['date'] ?? '';
                        $isWknd = Practice::isWeekend($curDate);
                        $isHld = Practice::isHoliday($curDate);
                        $isExcl = ($isWknd || $isHld);
                        $rowClass = $isExcl ? 'schedule-row-excluded' : ($e['hours'] > 0 ? 'schedule-row-counted' : '');
                    ?>
                        <tr class="schedule-row <?php echo $rowClass; ?>" data-index="<?php echo $i; ?>">
                            <td class="text-center text-muted row-number"><?php echo $i + 1; ?></td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <input type="text" name="entries[<?php echo $i; ?>][curriculum]" class="form-control form-control-sm" 
                                           value="<?php echo htmlspecialchars($e['module_name'] ?? ''); ?>" placeholder="Тема занятия практики">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['module_name'] ?? '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <input type="date" name="entries[<?php echo $i; ?>][date]" class="form-control form-control-sm date-input" 
                                           value="<?php echo htmlspecialchars($curDate); ?>">
                                    <div class="row-hint small text-danger mt-1 <?php echo $isExcl ? '' : 'd-none'; ?>">
                                        <?php echo $isWknd ? 'Выходной день' : ($isHld ? 'Праздник РК' : ''); ?>
                                    </div>
                                <?php else: ?>
                                    <?php echo $curDate ? date('d.m.Y', strtotime($curDate)) : '—'; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <input type="time" name="entries[<?php echo $i; ?>][time_start]" class="form-control form-control-sm time-start-input" 
                                           value="<?php echo htmlspecialchars($e['time_start'] ?? ''); ?>">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['time_start'] ?? '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <input type="time" name="entries[<?php echo $i; ?>][time_end]" class="form-control form-control-sm time-end-input" 
                                           value="<?php echo htmlspecialchars($e['time_end'] ?? ''); ?>">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['time_end'] ?? '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center fw-bold row-hours">
                                <?php echo number_format((float)($e['hours'] ?? 0), 1); ?>
                            </td>
                            <td>
                                <?php if ($canEdit): ?>
                                    <input type="text" name="entries[<?php echo $i; ?>][place]" class="form-control form-control-sm" 
                                           value="<?php echo htmlspecialchars($e['place'] ?? ''); ?>" placeholder="Кабинет / база">
                                <?php else: ?>
                                    <?php echo htmlspecialchars($e['place'] ?? '—'); ?>
                                <?php endif; ?>
                            </td>
                            <td class="text-center">
                                <?php if ($canEdit): ?>
                                    <input type="checkbox" name="entries[<?php echo $i; ?>][is_dot]" value="1" class="form-check-input" 
                                           <?php echo !empty($e['is_dot']) ? 'checked' : ''; ?>>
                                <?php else: ?>
                                    <?php echo !empty($e['is_dot']) ? 'Да' : '—'; ?>
                                <?php endif; ?>
                            </td>
                            <?php if ($canEdit): ?>
                                <td class="text-center">
                                    <button type="button" class="btn btn-link text-danger p-0 btn-delete-row" title="Удалить строку">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <?php if ($canEdit): ?>
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <label class="form-label">Комментарий к отправке / примечание</label>
                <textarea name="comment" class="form-control" rows="2" placeholder="Необязательно: укажите примечания для учебной части..."><?php echo htmlspecialchars($workload['comment'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-5">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Назад
            </a>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-secondary" onclick="submitScheduleForm('save');">
                    <i class="bi bi-save me-1"></i>Сохранить черновик
                </button>
                <button type="button" class="btn btn-primary" onclick="submitScheduleForm('submit');">
                    <i class="bi bi-send me-1"></i>Отправить на проверку
                </button>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex justify-content-between align-items-center mb-5">
            <a href="index.php" class="btn btn-outline-secondary">
                <i class="bi bi-arrow-left me-1"></i>Назад
            </a>
            <span class="badge bg-success p-2 fs-6">
                <i class="bi bi-check2-circle me-1"></i>График сдан на проверку <?php echo date('d.m.Y H:i', strtotime($workload['submitted_at'])); ?>
            </span>
        </div>
    <?php endif; ?>
</form>

<script>
const KZ_HOLIDAYS = <?php echo json_encode(Practice::KZ_HOLIDAYS); ?>;
const LIMIT_HOURS = <?php echo (float)$workload['practice_hours']; ?>;

function isWeekend(dateStr) {
    if (!dateStr) return false;
    const d = new Date(dateStr + 'T12:00:00');
    const day = d.getDay();
    return (day === 0 || day === 6);
}

function isHoliday(dateStr) {
    return KZ_HOLIDAYS.includes(dateStr);
}

function calculateRowHours(row) {
    const start = row.querySelector('.time-start-input')?.value || '';
    const end = row.querySelector('.time-end-input')?.value || '';
    const date = row.querySelector('.date-input')?.value || '';
    const hoursCell = row.querySelector('.row-hours');
    const hint = row.querySelector('.row-hint');

    let duration = 0.0;
    if (start && end) {
        const [sh, sm] = start.split(':').map(Number);
        const [eh, em] = end.split(':').map(Number);
        const sMin = sh * 60 + sm;
        const eMin = eh * 60 + em;
        if (eMin > sMin) {
            duration = Math.round(((eMin - sMin) / 60) * 10) / 10;
        }
    }
    if (hoursCell) hoursCell.textContent = duration.toFixed(1);

    const isWknd = isWeekend(date);
    const isHld = isHoliday(date);
    const isExcl = isWknd || isHld;

    row.classList.remove('schedule-row-excluded', 'schedule-row-counted');
    if (isExcl) {
        row.classList.add('schedule-row-excluded');
        if (hint) {
            hint.textContent = isWknd ? 'Выходной день' : 'Праздник РК';
            hint.classList.remove('d-none');
        }
    } else {
        if (duration > 0) row.classList.add('schedule-row-counted');
        if (hint) hint.classList.add('d-none');
    }

    return {
        duration: duration,
        isExcluded: isExcl
    };
}

function recalculateAll() {
    let counted = 0.0;
    let excluded = 0.0;

    document.querySelectorAll('.schedule-row').forEach(row => {
        const res = calculateRowHours(row);
        if (res.isExcluded) {
            excluded += res.duration;
        } else {
            counted += res.duration;
        }
    });

    const countedEl = document.getElementById('counted-hours-text');
    const excludedEl = document.getElementById('excluded-hours-text');
    const excludedWrap = document.getElementById('excluded-wrap');
    const badge = document.getElementById('hours-percent-badge');
    const progress = document.getElementById('hours-progress-bar');

    if (countedEl) countedEl.textContent = counted.toFixed(1);
    if (excludedEl) excludedEl.textContent = excluded.toFixed(1);
    if (excludedWrap) {
        if (excluded > 0) excludedWrap.classList.remove('d-none');
        else excludedWrap.classList.add('d-none');
    }

    const percent = LIMIT_HOURS > 0 ? Math.min(100, Math.round((counted / LIMIT_HOURS) * 100)) : 0;
    if (badge) badge.textContent = percent + '%';
    if (progress) progress.style.width = percent + '%';
}

// Слушатели событий изменения времени и дат
document.addEventListener('DOMContentLoaded', function () {
    const tbody = document.getElementById('scheduleTbody');
    if (tbody) {
        tbody.addEventListener('input', function (e) {
            if (e.target.classList.contains('date-input') || 
                e.target.classList.contains('time-start-input') || 
                e.target.classList.contains('time-end-input')) {
                recalculateAll();
            }
        });

        tbody.addEventListener('click', function (e) {
            const btn = e.target.closest('.btn-delete-row');
            if (btn) {
                const row = btn.closest('tr');
                if (tbody.querySelectorAll('.schedule-row').length > 1) {
                    row.remove();
                    renumberRows();
                    recalculateAll();
                } else {
                    alert('В таблице должна остаться хотя бы одна строка');
                }
            }
        });
    }

    const btnAdd = document.getElementById('btnAddRow');
    if (btnAdd && tbody) {
        btnAdd.addEventListener('click', function () {
            const index = tbody.querySelectorAll('.schedule-row').length;
            const tr = document.createElement('tr');
            tr.className = 'schedule-row';
            tr.dataset.index = index;
            tr.innerHTML = `
                <td class="text-center text-muted row-number">${index + 1}</td>
                <td><input type="text" name="entries[${index}][curriculum]" class="form-control form-control-sm" placeholder="Тема занятия практики"></td>
                <td>
                    <input type="date" name="entries[${index}][date]" class="form-control form-control-sm date-input">
                    <div class="row-hint small text-danger mt-1 d-none"></div>
                </td>
                <td><input type="time" name="entries[${index}][time_start]" class="form-control form-control-sm time-start-input" value="09:00"></td>
                <td><input type="time" name="entries[${index}][time_end]" class="form-control form-control-sm time-end-input" value="13:00"></td>
                <td class="text-center fw-bold row-hours">4.0</td>
                <td><input type="text" name="entries[${index}][place]" class="form-control form-control-sm" placeholder="Кабинет / база" value="Кабинет 306"></td>
                <td class="text-center"><input type="checkbox" name="entries[${index}][is_dot]" value="1" class="form-check-input"></td>
                <td class="text-center">
                    <button type="button" class="btn btn-link text-danger p-0 btn-delete-row" title="Удалить строку">
                        <i class="bi bi-x-circle"></i>
                    </button>
                </td>
            `;
            tbody.appendChild(tr);
            renumberRows();
            recalculateAll();
        });
    }
});

function renumberRows() {
    document.querySelectorAll('#scheduleTbody .schedule-row').forEach((row, i) => {
        row.dataset.index = i;
        const num = row.querySelector('.row-number');
        if (num) num.textContent = i + 1;
        row.querySelectorAll('input').forEach(input => {
            const name = input.getAttribute('name');
            if (name) {
                input.setAttribute('name', name.replace(/entries\[\d+\]/, `entries[${i}]`));
            }
        });
    });
}

function submitScheduleForm(action) {
    const input = document.getElementById('formActionInput');
    if (input) input.value = action;
    if (action === 'submit') {
        if (!confirm('Отправить график практики на проверку? После отправки редактирование будет заблокировано.')) {
            return;
        }
    }
    document.getElementById('scheduleForm').submit();
}
</script>

<?php require_once 'includes/footer.php'; ?>

