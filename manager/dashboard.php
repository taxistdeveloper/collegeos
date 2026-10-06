<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkAuth();

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_reports');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();
$db = getDB();

$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');

$sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
    SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
    SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated_students,
    SUM(CASE WHEN disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN orphan = 1 THEN 1 ELSE 0 END) as orphan_students
    FROM students";
$result = $db->query($sql);
$stats = $result ? $result->fetch_assoc() : [
    'total_students' => 0,
    'active_students' => 0,
    'academic_leave_students' => 0,
    'graduated_students' => 0,
    'disabled_students' => 0,
    'orphan_students' => 0,
];

$not_graduated_s = sqlNotGraduatedCondition('s');
$sql = "SELECT g.id, g.name, g.code, g.course, g.max_students,
               COUNT(s.id) as current_students,
               SUM(CASE WHEN s.academic_leave = 0 AND $not_graduated_s THEN 1 ELSE 0 END) as active_students,
               CONCAT(IFNULL(u.last_name, ''), ' ', IFNULL(u.first_name, '')) as curator_name
        FROM `groups` g
        LEFT JOIN students s ON g.id = s.group_id
        LEFT JOIN users u ON g.curator_id = u.id
        WHERE g.is_active = 1
        GROUP BY g.id
        ORDER BY g.course, g.name
        LIMIT 8";
$result = $db->query($sql);
$recent_groups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];

$sql = "SELECT COUNT(*) as total_groups,
               SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_groups
        FROM `groups`";
$result = $db->query($sql);
$group_counts = $result ? $result->fetch_assoc() : ['total_groups' => 0, 'active_groups' => 0];

$page_title = 'Дашборд';
$page_subtitle = 'Обзор по студентам и группам';
$document_title = 'Дашборд менеджера';
include 'includes/layout_start.php';
?>

<div class="manager-stats-grid">
    <a href="students.php" class="manager-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Всего студентов</div>
        <div class="stat-number"><?php echo (int)$stats['total_students']; ?></div>
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
    </a>
    <a href="students.php?status=active" class="manager-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Активных</div>
        <div class="stat-number"><?php echo (int)$stats['active_students']; ?></div>
        <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
    </a>
    <a href="students.php?status=academic_leave" class="manager-stat-card stat-warning">
        <div class="stat-label"><span class="stat-dot"></span>Академ. отпуск</div>
        <div class="stat-number"><?php echo (int)$stats['academic_leave_students']; ?></div>
        <div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
    </a>
    <a href="groups.php" class="manager-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Активных групп</div>
        <div class="stat-number"><?php echo (int)$group_counts['active_groups']; ?></div>
        <div class="stat-icon"><i class="bi bi-collection-fill"></i></div>
    </a>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-6">
        <div class="manager-section h-100">
            <div class="manager-section-header">
                <h2 class="manager-section-title">Социальные категории</h2>
            </div>
            <div class="manager-info-grid">
                <div class="manager-info-item">
                    <strong>С инвалидностью</strong>
                    <?php echo (int)$stats['disabled_students']; ?>
                </div>
                <div class="manager-info-item">
                    <strong>Сироты</strong>
                    <?php echo (int)$stats['orphan_students']; ?>
                </div>
                <div class="manager-info-item">
                    <strong>Выпускники</strong>
                    <?php echo (int)$stats['graduated_students']; ?>
                </div>
                <div class="manager-info-item">
                    <strong>Всего групп</strong>
                    <?php echo (int)$group_counts['total_groups']; ?>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="manager-section h-100">
            <div class="manager-section-header">
                <h2 class="manager-section-title">Быстрые действия</h2>
            </div>
            <div class="manager-action-buttons">
                <a href="students.php" class="btn btn-primary">
                    <i class="bi bi-people-fill me-1"></i>Все студенты
                </a>
                <a href="groups.php" class="btn btn-outline-primary">
                    <i class="bi bi-collection-fill me-1"></i>Все группы
                </a>
                <a href="students.php?status=academic_leave" class="btn btn-outline-warning">
                    <i class="bi bi-pause-circle me-1"></i>Академ. отпуск
                </a>
            </div>
        </div>
    </div>
</div>

<div class="manager-section">
    <div class="manager-section-header">
        <h2 class="manager-section-title">Активные группы</h2>
        <a href="groups.php" class="btn btn-sm btn-outline-primary">Все группы</a>
    </div>

    <?php if (empty($recent_groups)): ?>
        <div class="text-muted">Активных групп пока нет</div>
    <?php else: ?>
        <div class="manager-table-container" style="box-shadow: none; border: none;">
            <div class="table-responsive">
                <table class="table manager-table mb-0">
                    <thead>
                        <tr>
                            <th>Группа</th>
                            <th>Курс</th>
                            <th>Студенты</th>
                            <th>Куратор</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_groups as $g): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($g['name']); ?></strong>
                                    <div class="text-muted small"><?php echo htmlspecialchars($g['code'] ?? ''); ?></div>
                                </td>
                                <td><span class="manager-badge badge-info"><?php echo htmlspecialchars($g['course'] ?? ''); ?></span></td>
                                <td>
                                    <?php echo (int)$g['active_students']; ?> активн. /
                                    <?php echo (int)$g['current_students']; ?> всего
                                </td>
                                <td>
                                    <?php
                                    $curator = trim($g['curator_name'] ?? '');
                                    echo $curator !== '' ? htmlspecialchars($curator) : '<span class="text-muted">Не назначен</span>';
                                    ?>
                                </td>
                                <td>
                                    <a href="group_details.php?id=<?php echo (int)$g['id']; ?>" class="btn btn-sm btn-outline-primary">
                                        Открыть
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/layout_end.php'; ?>
