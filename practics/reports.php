<?php
require_once '../config/config.php';
require_once '../classes/Practice.php';
require_once '../includes/auth.php';

checkAuth();

$practice = new Practice();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$isAdminOrMethodist = in_array($role, ['admin', 'methodist', 'director', 'manager', 'department_head', 'practicant'], true);

if (!$isAdminOrMethodist) {
    header('Location: index.php');
    exit;
}

$db = getDB();

// Сводка по группам
$groupStats = [];
$res = $db->query("
    SELECT study_group, 
           COUNT(*) as total_workloads,
           SUM(practice_hours) as total_hours,
           SUM(CASE WHEN status = 'submitted' THEN 1 ELSE 0 END) as submitted_count,
           ROUND(AVG(progress_percent)) as avg_progress
    FROM workloads
    WHERE study_group IS NOT NULL AND study_group != ''
    GROUP BY study_group
    ORDER BY study_group ASC
");
if ($res) {
    while ($r = $res->fetch_assoc()) {
        $groupStats[] = $r;
    }
}

// Сводка по статусам
$stats = $practice->getStats();

$page_title = 'Отчёты по практике';
$page_subtitle = 'Сводные данные о выполнении графиков практики';

require_once 'includes/header.php';
?>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-muted small">Всего назначено часов</div>
            <div class="fs-3 fw-bold text-primary">
                <?php
                $hRes = $db->query("SELECT SUM(practice_hours) as th FROM workloads");
                $hRow = $hRes ? $hRes->fetch_assoc() : null;
                echo (int)($hRow['th'] ?? 0);
                ?> ч.
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-muted small">Всего практик</div>
            <div class="fs-3 fw-bold"><?php echo (int)$stats['total']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-muted small">Сдано графиков</div>
            <div class="fs-3 fw-bold text-success"><?php echo (int)$stats['submitted']; ?></div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card p-3 border-0 shadow-sm text-center">
            <div class="text-muted small">Просрочено</div>
            <div class="fs-3 fw-bold text-danger"><?php echo (int)$stats['overdue']; ?></div>
        </div>
    </div>
</div>

<div class="card shadow-sm border-0">
    <div class="card-header bg-white py-3">
        <h5 class="card-title mb-0">Выполнение практики по группам</h5>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>№</th>
                    <th>Учебная группа</th>
                    <th>Количество практик</th>
                    <th>Всего часов</th>
                    <th>Сдано графиков</th>
                    <th>Средний прогресс</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($groupStats)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Данных пока нет</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($groupStats as $idx => $gs): ?>
                        <tr>
                            <td class="text-muted"><?php echo $idx + 1; ?></td>
                            <td><strong><?php echo htmlspecialchars($gs['study_group']); ?></strong></td>
                            <td><?php echo (int)$gs['total_workloads']; ?></td>
                            <td><strong><?php echo (int)$gs['total_hours']; ?> ч.</strong></td>
                            <td>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">
                                    <?php echo (int)$gs['submitted_count']; ?> из <?php echo (int)$gs['total_workloads']; ?>
                                </span>
                            </td>
                            <td style="width: 180px;">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="progress flex-grow-1" style="height: 6px;">
                                        <div class="progress-bar bg-success" style="width: <?php echo (int)$gs['avg_progress']; ?>%"></div>
                                    </div>
                                    <span class="small text-muted"><?php echo (int)$gs['avg_progress']; ?>%</span>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

