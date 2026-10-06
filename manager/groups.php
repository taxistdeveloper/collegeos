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

$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$language_filter = isset($_GET['language']) ? $_GET['language'] : '';
$study_form_filter = isset($_GET['study_form']) ? $_GET['study_form'] : '';
$curator_filter = isset($_GET['curator']) ? (int)$_GET['curator'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$not_graduated = sqlNotGraduatedCondition('s');

$sql = "SELECT g.*, 
               COUNT(s.id) as current_students,
               SUM(CASE WHEN s.academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
               SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
               SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
               SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
               u.first_name as curator_first_name, u.last_name as curator_last_name,
               ROUND((COUNT(s.id) / NULLIF(g.max_students, 0)) * 100, 1) as occupancy_percentage
        FROM `groups` g 
        LEFT JOIN students s ON g.id = s.group_id 
        LEFT JOIN users u ON g.curator_id = u.id
        WHERE 1=1";

$params = [];
$types = '';

if ($status_filter) {
    switch ($status_filter) {
        case 'active':
            $sql .= " AND g.is_active = 1";
            break;
        case 'inactive':
            $sql .= " AND g.is_active = 0";
            break;
    }
}

if ($course_filter) {
    $sql .= " AND g.course = ?";
    $params[] = $course_filter;
    $types .= 's';
}

if ($language_filter) {
    $sql .= " AND g.language = ?";
    $params[] = $language_filter;
    $types .= 's';
}

if ($study_form_filter) {
    $sql .= " AND g.study_form = ?";
    $params[] = $study_form_filter;
    $types .= 's';
}

if ($curator_filter > 0) {
    $sql .= " AND g.curator_id = ?";
    $params[] = $curator_filter;
    $types .= 'i';
}

if ($search) {
    $sql .= " AND (g.name LIKE ? OR g.code LIKE ? OR g.specialty LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$sql .= " GROUP BY g.id";

if ($status_filter === 'full') {
    $sql .= " HAVING COUNT(s.id) >= g.max_students";
} elseif ($status_filter === 'available') {
    $sql .= " HAVING COUNT(s.id) < g.max_students";
}

$sql .= " ORDER BY g.course, g.name";

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result->fetch_all(MYSQLI_ASSOC);
} elseif ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $groups = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $groups = [];
}

$curators = $user->getUsersByRole(4);

$total_groups = count($groups);
$total_students = array_sum(array_column($groups, 'current_students'));
$active_groups = count(array_filter($groups, function ($g) {
    return !empty($g['is_active']);
}));
$avg_occupancy = $total_groups > 0
    ? round(array_sum(array_map(function ($g) {
        return (float)($g['occupancy_percentage'] ?? 0);
    }, $groups)) / $total_groups, 1)
    : 0;

$page_title = 'Группы';
$page_subtitle = 'Учебные группы и заполненность';
$document_title = 'Все группы';
include 'includes/layout_start.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
    <div class="manager-action-buttons">
        <button type="button" class="btn btn-success" onclick="exportGroups()">
            <i class="bi bi-download me-1"></i>Экспорт
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Печать
        </button>
    </div>
</div>

<div class="manager-stats-grid">
    <div class="manager-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Всего групп</div>
        <div class="stat-number"><?php echo (int)$total_groups; ?></div>
        <div class="stat-icon"><i class="bi bi-collection-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Активных</div>
        <div class="stat-number"><?php echo (int)$active_groups; ?></div>
        <div class="stat-icon"><i class="bi bi-check-circle-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Студентов</div>
        <div class="stat-number"><?php echo (int)$total_students; ?></div>
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-warning">
        <div class="stat-label"><span class="stat-dot"></span>Заполненность</div>
        <div class="stat-number"><?php echo $avg_occupancy; ?>%</div>
        <div class="stat-icon"><i class="bi bi-speedometer2"></i></div>
    </div>
</div>

<div class="manager-filters no-print">
    <form method="GET" action="groups.php">
        <div class="manager-filter-row">
            <div class="manager-form-group">
                <label class="manager-form-label">Статус</label>
                <select class="form-control manager-form-control manager-form-select" name="status">
                    <option value="">Все статусы</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                    <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Неактивные</option>
                    <option value="full" <?php echo $status_filter === 'full' ? 'selected' : ''; ?>>Заполненные</option>
                    <option value="available" <?php echo $status_filter === 'available' ? 'selected' : ''; ?>>Есть места</option>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Курс</label>
                <select class="form-control manager-form-control manager-form-select" name="course">
                    <option value="">Все курсы</option>
                    <?php foreach (['1 курс', '2 курс', '3 курс', '4 курс', '5 курс'] as $course_opt): ?>
                        <option value="<?php echo $course_opt; ?>" <?php echo $course_filter === $course_opt ? 'selected' : ''; ?>>
                            <?php echo $course_opt; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Язык</label>
                <select class="form-control manager-form-control manager-form-select" name="language">
                    <option value="">Все языки</option>
                    <option value="казахский" <?php echo $language_filter === 'казахский' ? 'selected' : ''; ?>>Казахский</option>
                    <option value="русский" <?php echo $language_filter === 'русский' ? 'selected' : ''; ?>>Русский</option>
                    <option value="английский" <?php echo $language_filter === 'английский' ? 'selected' : ''; ?>>Английский</option>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Форма</label>
                <select class="form-control manager-form-control manager-form-select" name="study_form">
                    <option value="">Все формы</option>
                    <option value="очная" <?php echo $study_form_filter === 'очная' ? 'selected' : ''; ?>>Очная</option>
                    <option value="заочная" <?php echo $study_form_filter === 'заочная' ? 'selected' : ''; ?>>Заочная</option>
                    <option value="вечерняя" <?php echo $study_form_filter === 'вечерняя' ? 'selected' : ''; ?>>Вечерняя</option>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Куратор</label>
                <select class="form-control manager-form-control manager-form-select" name="curator">
                    <option value="">Все кураторы</option>
                    <?php foreach ($curators as $curator): ?>
                        <option value="<?php echo (int)$curator['id']; ?>" <?php echo $curator_filter == $curator['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(trim(($curator['last_name'] ?? '') . ' ' . ($curator['first_name'] ?? ''))); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Поиск</label>
                <div class="manager-search-box">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="form-control manager-form-control" name="search" id="searchInput"
                           placeholder="Название или код" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Найти
                    </button>
                    <a href="groups.php" class="btn btn-outline-secondary">Сброс</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (empty($groups)): ?>
    <div class="manager-empty-state">
        <div class="empty-icon"><i class="bi bi-inbox"></i></div>
        <div class="empty-title">Группы не найдены</div>
        <div class="empty-description">Попробуйте изменить параметры фильтрации</div>
        <a href="groups.php" class="btn btn-primary btn-sm">Сбросить фильтры</a>
    </div>
<?php else: ?>
    <div class="manager-table-container">
        <div class="table-responsive">
            <table class="table manager-table mb-0" id="groupsTable">
                <thead>
                    <tr>
                        <th>Группа</th>
                        <th>Специальность</th>
                        <th>Курс</th>
                        <th>Студенты</th>
                        <th>Заполненность</th>
                        <th>Куратор</th>
                        <th>Статус</th>
                        <th class="no-print">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group_item):
                        $occupancy = (float)($group_item['occupancy_percentage'] ?? 0);
                        $bar_class = $occupancy > 80 ? 'success' : ($occupancy > 50 ? 'warning' : 'danger');
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($group_item['name']); ?></strong>
                                <div class="text-muted small"><?php echo htmlspecialchars($group_item['code'] ?? ''); ?></div>
                            </td>
                            <td>
                                <div><?php echo htmlspecialchars($group_item['specialty'] ?? ''); ?></div>
                                <?php if (!empty($group_item['qualification'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars($group_item['qualification']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="manager-badge badge-info"><?php echo htmlspecialchars($group_item['course'] ?? ''); ?></span>
                            </td>
                            <td>
                                <span class="manager-badge badge-primary">
                                    <?php echo (int)$group_item['current_students']; ?> / <?php echo (int)$group_item['max_students']; ?>
                                </span>
                            </td>
                            <td style="min-width: 120px;">
                                <div class="manager-progress mb-1">
                                    <div class="manager-progress-bar bg-<?php echo $bar_class; ?>"
                                         style="width: <?php echo min(100, max(0, $occupancy)); ?>%"></div>
                                </div>
                                <small class="text-muted"><?php echo $occupancy; ?>%</small>
                            </td>
                            <td>
                                <?php if (!empty($group_item['curator_first_name'])): ?>
                                    <?php echo htmlspecialchars(trim($group_item['curator_last_name'] . ' ' . $group_item['curator_first_name'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">Не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="manager-badge badge-<?php echo !empty($group_item['is_active']) ? 'success' : 'danger'; ?>">
                                    <?php echo !empty($group_item['is_active']) ? 'Активна' : 'Неактивна'; ?>
                                </span>
                                <?php if (function_exists('isGraduatingGroup') && isGraduatingGroup($group_item)): ?>
                                    <span class="manager-badge badge-warning">Выпускная</span>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <div class="manager-table-actions">
                                    <a href="group_details.php?id=<?php echo (int)$group_item['id']; ?>"
                                       class="btn btn-outline-primary" title="Детали">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                    <a href="students.php?group=<?php echo (int)$group_item['id']; ?>"
                                       class="btn btn-outline-info" title="Студенты">
                                        <i class="bi bi-people"></i>
                                    </a>
                                    <a href="group_reports.php?id=<?php echo (int)$group_item['id']; ?>"
                                       class="btn btn-outline-success" title="Отчёты">
                                        <i class="bi bi-graph-up"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$extra_scripts = <<<'JS'
<script>
function exportGroups() {
    const table = document.querySelector('#groupsTable');
    if (!table) {
        alert('Нет данных для экспорта');
        return;
    }
    const rows = table.querySelectorAll('tbody tr');
    let csv = '\uFEFFГруппа,Специальность,Курс,Студенты,Заполненность,Куратор,Статус\n';
    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        const rowData = [];
        cells.forEach((cell, index) => {
            if (index < 7) {
                let text = cell.textContent.trim().replace(/\s+/g, ' ');
                text = text.replace(/"/g, '""');
                rowData.push('"' + text + '"');
            }
        });
        csv += rowData.join(',') + '\n';
    });
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'groups_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
JS;
include 'includes/layout_end.php';
