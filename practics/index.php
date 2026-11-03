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

$page_title = 'Практика';
$page_subtitle = $isTeacher ? 'Мои графики и нагрузки' : 'Обзор графиков и нагрузок преподавателей';

if ($isTeacher) {
    $teacherId = $practice->getOrCreateTeacherByUserId((int)$current_user['id']);
    $myWorkloads = $practice->getWorkloads(['teacher_id' => $teacherId]);
} else {
    $stats = $practice->getStats();
    $recentWorkloads = $practice->getWorkloads(['limit' => 10]);
}

require_once 'includes/header.php';
?>

<?php if ($isTeacher): ?>
    <!-- ВИД ДЛЯ ПРЕПОДАВАТЕЛЯ -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0">Моя учебная нагрузка практики</h4>
            <p class="text-muted small mb-0">Заполните график занятий и отправьте на проверку администратору</p>
        </div>
    </div>

    <?php if (empty($myWorkloads)): ?>
        <div class="card p-5 text-center text-muted">
            <i class="bi bi-briefcase fs-1 mb-2"></i>
            <h5>Нагрузка практики пока не назначена</h5>
            <p class="mb-0">Когда учебная часть назначит вам практику, она появится здесь.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($myWorkloads as $w): 
                $statusMap = [
                    'assigned'    => ['Назначена', 'practice-badge-assigned', 'bi-clock'],
                    'in_progress' => ['В процессе', 'practice-badge-in_progress', 'bi-pencil'],
                    'submitted'   => ['Сдана', 'practice-badge-submitted', 'bi-check2-circle'],
                    'overdue'     => ['Просрочена', 'practice-badge-overdue', 'bi-exclamation-triangle'],
                ];
                [$stText, $stBadge, $stIcon] = $statusMap[$w['status']] ?? [$w['status'], 'badge-secondary', 'bi-circle'];
            ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 shadow-sm border-0">
                        <div class="card-body d-flex flex-column">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <span class="badge <?php echo $stBadge; ?> d-inline-flex align-items-center gap-1">
                                    <i class="bi <?php echo $stIcon; ?>"></i><?php echo $stText; ?>
                                </span>
                                <span class="badge bg-light text-dark border">
                                    <?php echo htmlspecialchars($w['study_group'] ?: 'Без группы'); ?>
                                </span>
                            </div>
                            <h5 class="card-title mb-2 text-primary">
                                <?php echo htmlspecialchars($w['module_name']); ?>
                            </h5>
                            <div class="text-muted small mb-3">
                                <div><i class="bi bi-clock me-1"></i>Нагрузка: <strong><?php echo (int)$w['practice_hours']; ?> ч.</strong></div>
                                <div><i class="bi bi-calendar-event me-1"></i>Срок сдачи: <strong><?php echo date('d.m.Y', strtotime($w['deadline'])); ?></strong></div>
                            </div>
                            
                            <div class="mt-auto">
                                <div class="d-flex justify-content-between small text-muted mb-1">
                                    <span>Заполнено:</span>
                                    <span><?php echo (int)$w['progress_percent']; ?>%</span>
                                </div>
                                <div class="progress mb-3" style="height: 6px;">
                                    <div class="progress-bar bg-success" style="width: <?php echo (int)$w['progress_percent']; ?>%"></div>
                                </div>
                                <div class="d-flex gap-2">
                                    <a href="schedule.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-primary btn-sm flex-grow-1">
                                        <i class="bi bi-pencil-square me-1"></i>
                                        <?php echo $w['status'] === 'submitted' ? 'Просмотр графика' : 'Заполнить график'; ?>
                                    </a>
                                    <a href="export_docx.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-outline-secondary btn-sm" title="Скачать DOCX">
                                        <i class="bi bi-file-earmark-word"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

<?php else: ?>
    <!-- ВИД ДЛЯ АДМИНИСТРАТОРА И УЧЕБНОЙ ЧАСТИ -->
    <div class="curator-stats-grid mb-4">
        <div class="curator-stat-card stat-primary">
            <div class="stat-label"><span class="stat-dot"></span>Всего нагрузок</div>
            <div class="stat-number"><?php echo (int)$stats['total']; ?></div>
            <div class="stat-icon"><i class="bi bi-journal-bookmark"></i></div>
        </div>
        <div class="curator-stat-card stat-warning">
            <div class="stat-label"><span class="stat-dot"></span>В процессе</div>
            <div class="stat-number"><?php echo (int)($stats['assigned'] + $stats['in_progress']); ?></div>
            <div class="stat-icon"><i class="bi bi-pencil"></i></div>
        </div>
        <div class="curator-stat-card stat-success">
            <div class="stat-label"><span class="stat-dot"></span>Сдано графиков</div>
            <div class="stat-number"><?php echo (int)$stats['submitted']; ?></div>
            <div class="stat-icon"><i class="bi bi-check2-circle"></i></div>
        </div>
        <div class="curator-stat-card stat-info">
            <div class="stat-label"><span class="stat-dot"></span>Преподавателей</div>
            <div class="stat-number"><?php echo (int)$stats['teachers']; ?></div>
            <div class="stat-icon"><i class="bi bi-people"></i></div>
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h5 class="mb-0">Последние нагрузки практики</h5>
        <div class="d-flex gap-2">
            <a href="workloads.php" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-list-ul me-1"></i>Все нагрузки
            </a>
            <a href="workloads.php?action=create" class="btn btn-primary btn-sm">
                <i class="bi bi-plus-lg me-1"></i>Назначить практику
            </a>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Преподаватель</th>
                        <th>Модуль / Дисциплина</th>
                        <th>Группа</th>
                        <th>Часы</th>
                        <th>Срок</th>
                        <th>Статус</th>
                        <th>Прогресс</th>
                        <th class="text-end">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentWorkloads)): ?>
                        <tr>
                            <td colspan="8" class="text-center py-4 text-muted">Нагрузки практики пока не созданы</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentWorkloads as $w): 
                            $statusMap = [
                                'assigned'    => ['Назначена', 'practice-badge-assigned'],
                                'in_progress' => ['В процессе', 'practice-badge-in_progress'],
                                'submitted'   => ['Сдана', 'practice-badge-submitted'],
                                'overdue'     => ['Просрочена', 'practice-badge-overdue'],
                            ];
                            [$stText, $stBadge] = $statusMap[$w['status']] ?? [$w['status'], 'badge-secondary'];
                        ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($w['teacher_name'] ?: '—'); ?></strong>
                                    <?php if (!empty($w['department'])): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars($w['department']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($w['module_name']); ?></td>
                                <td><span class="badge bg-light text-dark border"><?php echo htmlspecialchars($w['study_group'] ?: '—'); ?></span></td>
                                <td><strong><?php echo (int)$w['practice_hours']; ?> ч.</strong></td>
                                <td><?php echo date('d.m.Y', strtotime($w['deadline'])); ?></td>
                                <td><span class="badge <?php echo $stBadge; ?>"><?php echo $stText; ?></span></td>
                                <td style="width: 120px;">
                                    <div class="small text-muted mb-1"><?php echo (int)$w['progress_percent']; ?>%</div>
                                    <div class="progress" style="height: 4px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo (int)$w['progress_percent']; ?>%"></div>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <a href="schedule.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-light btn-sm" title="График">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="export_docx.php?id=<?php echo (int)$w['id']; ?>" class="btn btn-light btn-sm" title="Скачать DOCX">
                                        <i class="bi bi-download"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>

