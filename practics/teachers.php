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

$teachers = $practice->getTeachers();

$page_title = 'Преподаватели практики';
$page_subtitle = 'Список преподавателей и их нагрузка по практике';

require_once 'includes/header.php';
?>

<div class="card shadow-sm border-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>№</th>
                    <th>ФИО Преподавателя</th>
                    <th>Отделение / Специализация</th>
                    <th>Логин</th>
                    <th>Email</th>
                    <th class="text-end">Нагрузки</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($teachers)): ?>
                    <tr>
                        <td colspan="6" class="text-center py-4 text-muted">Преподаватели не найдены</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($teachers as $idx => $t): ?>
                        <tr>
                            <td class="text-muted"><?php echo $idx + 1; ?></td>
                            <td>
                                <strong><?php echo htmlspecialchars($t['fullname']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($t['department'] ?: '—'); ?></td>
                            <td><code><?php echo htmlspecialchars($t['login']); ?></code></td>
                            <td><?php echo htmlspecialchars($t['email'] ?: '—'); ?></td>
                            <td class="text-end">
                                <a href="workloads.php?teacher_id=<?php echo (int)$t['id']; ?>" class="btn btn-outline-primary btn-sm">
                                    <i class="bi bi-briefcase me-1"></i>Нагрузки
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>

