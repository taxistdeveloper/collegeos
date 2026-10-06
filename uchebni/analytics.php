<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_uchebni_analytics');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;

$performance = $uchebni->getAnalyticsPerformanceByGroup($period_id);
$attendance = $uchebni->getAnalyticsAttendance($period_id);
$teachers_rating = $uchebni->getAnalyticsTeacherRating($period_id);
$classroom_load = $uchebni->getAnalyticsClassroomLoad($period_id);
$debts = $uchebni->getAnalyticsDebts($period_id);

$current_user = getCurrentUser();
$page_title = 'Аналитика';
$page_subtitle = $period ? $period['name'] : '';
require_once 'includes/header.php';
?>

<ul class="nav nav-tabs mb-4" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-performance">Успеваемость</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-attendance">Посещаемость</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-teachers">Преподаватели</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-classrooms">Аудитории</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-debts">Задолженности</button></li>
</ul>

<div class="tab-content">
    <!-- Успеваемость по группам -->
    <div class="tab-pane fade show active" id="tab-performance">
        <div class="card">
            <div class="card-header"><i class="bi bi-graph-up me-2"></i>Успеваемость по группам</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Группа</th><th>Средний балл</th><th>Оценок</th><th>Неуспевающих</th><th></th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($performance as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['group_name']); ?></strong></td>
                            <td>
                                <?php if ($row['avg_grade']): ?>
                                    <span class="badge <?php echo $row['avg_grade'] >= 70 ? 'bg-success' : ($row['avg_grade'] >= 50 ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                        <?php echo $row['avg_grade']; ?>
                                    </span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <td><?php echo (int)$row['entries']; ?></td>
                            <td><?php echo (int)$row['failing_count']; ?></td>
                            <td style="width:30%">
                                <?php if ($row['avg_grade']): ?>
                                <div class="progress uchebni-progress-thin">
                                    <div class="progress-bar" style="width:<?php echo min(100, $row['avg_grade']); ?>%"></div>
                                </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($performance)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Нет данных — заполните сводную ведомость в отделении</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Посещаемость -->
    <div class="tab-pane fade" id="tab-attendance">
        <div class="card">
            <div class="card-header"><i class="bi bi-person-check me-2"></i>Посещаемость по группам</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Группа</th><th>% присутствия</th><th>Присутствовал</th><th>Отсутствовал</th><th>Опоздал</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($attendance as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['group_name']); ?></strong></td>
                            <td>
                                <span class="badge <?php echo $row['attendance_pct'] >= 80 ? 'bg-success' : ($row['attendance_pct'] >= 60 ? 'bg-warning text-dark' : 'bg-danger'); ?>">
                                    <?php echo $row['attendance_pct']; ?>%
                                </span>
                            </td>
                            <td><?php echo (int)$row['present']; ?></td>
                            <td><?php echo (int)$row['absent']; ?></td>
                            <td><?php echo (int)$row['late']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($attendance)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Нет данных</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Рейтинг преподавателей -->
    <div class="tab-pane fade" id="tab-teachers">
        <div class="card">
            <div class="card-header"><i class="bi bi-award me-2"></i>Рейтинг преподавателей</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead class="table-light">
                        <tr><th>Преподаватель</th><th>Ср. балл</th><th>Посещаемость</th><th>Нагрузка</th><th>Пар</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($teachers_rating as $row): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($row['teacher_name']); ?></strong></td>
                            <td><?php echo $row['avg_grade'] ? $row['avg_grade'] : '—'; ?></td>
                            <td><?php echo $row['attendance_pct'] !== null ? $row['attendance_pct'] . '%' : '—'; ?></td>
                            <td>
                                <span class="badge <?php echo $row['workload'] >= $row['max_hours'] ? 'bg-danger' : 'bg-primary'; ?>">
                                    <?php echo $row['workload']; ?>/<?php echo $row['max_hours']; ?> ч
                                </span>
                            </td>
                            <td><?php echo (int)$row['lessons']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($teachers_rating)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Нет данных</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Загрузка кабинетов -->
    <div class="tab-pane fade" id="tab-classrooms">
        <div class="row g-3">
            <?php foreach ($classroom_load as $row):
                $pct = min(100, round(100 * $row['hours_per_week'] / 36)); // 6 пар × 6 дней
            ?>
            <div class="col-md-4">
                <div class="card uchebni-analytics-card h-100">
                    <div class="card-body">
                        <h6 class="card-title"><i class="bi bi-door-open me-1"></i>Каб. <?php echo htmlspecialchars($row['number']); ?></h6>
                        <p class="text-muted small mb-2"><?php echo htmlspecialchars($row['building'] ?: ''); ?> · <?php echo (int)$row['capacity']; ?> мест</p>
                        <div class="metric-value text-primary"><?php echo (int)$row['hours_per_week']; ?> <span class="fs-6 text-muted">ч/нед</span></div>
                        <div class="progress uchebni-progress-thin mt-2">
                            <div class="progress-bar bg-info" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <small class="text-muted"><?php echo (int)$row['groups_count']; ?> групп</small>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($classroom_load)): ?>
            <div class="col-12"><div class="alert alert-secondary">Аудитории не добавлены</div></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Задолженности -->
    <div class="tab-pane fade" id="tab-debts">
        <div class="card">
            <div class="card-header"><i class="bi bi-exclamation-triangle me-2"></i>Академические задолженности</div>
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-sm">
                    <thead class="table-light">
                        <tr><th>Студент</th><th>Группа</th><th>Дисциплина</th><th>Ср. балл</th><th>Неудовл.</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($debts as $row):
                            $fio = trim($row['last_name'] . ' ' . $row['first_name'] . ' ' . ($row['middle_name'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fio); ?></td>
                            <td><?php echo htmlspecialchars($row['group_name']); ?></td>
                            <td><?php echo htmlspecialchars($row['subject_name']); ?></td>
                            <td>
                                <?php if ($row['graded_lessons'] == 0): ?>
                                    <span class="badge bg-secondary">Нет оценок</span>
                                <?php else: ?>
                                    <span class="badge <?php echo $row['avg_grade'] < 50 ? 'bg-danger' : 'bg-warning text-dark'; ?>">
                                        <?php echo $row['avg_grade']; ?>
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo (int)$row['fails']; ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($debts)): ?>
                        <tr><td colspan="5" class="text-center text-muted py-3">Задолженностей не обнаружено</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
