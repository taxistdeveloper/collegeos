<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../includes/auth.php';

checkRole(['department_head']);

$departmentService = new Department();
$current_user = getCurrentUser();
$dept = $departmentService->getDepartmentByHead((int)$current_user['id']);
$stats = $dept ? $departmentService->getStats((int)$dept['id']) : ['members' => 0, 'groups' => 0, 'students' => 0];

$page_title = 'Кабинет заведующего';
$page_subtitle = $dept
    ? ($dept['name'] . ' · ' . date('d.m.Y'))
    : 'Создайте отделение, чтобы начать работу';
include 'includes/header.php';
?>

<?php if (!$dept): ?>
    <div class="card mb-4">
        <div class="card-body dept-empty">
            <i class="bi bi-building fs-1 d-block mb-3"></i>
            <h2 class="h5">Отделение ещё не создано</h2>
            <p class="mb-3">Создайте своё отделение — после этого можно добавить сотрудников и группы.</p>
            <a href="department.php" class="btn btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Создать отделение
            </a>
        </div>
    </div>
<?php else: ?>
    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="dept-stat">
                <div class="dept-stat-value"><?php echo (int)$stats['members']; ?></div>
                <div class="dept-stat-label">Сотрудники</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dept-stat">
                <div class="dept-stat-value"><?php echo (int)$stats['groups']; ?></div>
                <div class="dept-stat-label">Группы</div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dept-stat">
                <div class="dept-stat-value"><?php echo (int)$stats['students']; ?></div>
                <div class="dept-stat-label">Студенты в группах</div>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3"><i class="bi bi-building me-2"></i>Моё отделение</h2>
                    <p class="mb-1"><strong><?php echo htmlspecialchars($dept['name']); ?></strong></p>
                    <?php if (!empty($dept['code'])): ?>
                        <p class="text-muted small mb-2">Код: <?php echo htmlspecialchars($dept['code']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($dept['description'])): ?>
                        <p class="mb-3"><?php echo htmlspecialchars($dept['description']); ?></p>
                    <?php endif; ?>
                    <a href="department.php" class="btn btn-outline-primary btn-sm">Редактировать</a>
                </div>
            </div>
        </div>
        <div class="col-md-6">
            <div class="card h-100">
                <div class="card-body">
                    <h2 class="h6 mb-3"><i class="bi bi-lightning-charge me-2"></i>Быстрые действия</h2>
                    <div class="d-grid gap-2">
                        <a href="staff.php" class="btn btn-outline-secondary">
                            <i class="bi bi-person-plus me-1"></i>Добавить сотрудника
                        </a>
                        <a href="groups.php" class="btn btn-outline-secondary">
                            <i class="bi bi-folder-plus me-1"></i>Создать группу
                        </a>
                        <a href="grades.php" class="btn btn-outline-secondary">
                            <i class="bi bi-journal-check me-1"></i>Ведомость оценок
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
