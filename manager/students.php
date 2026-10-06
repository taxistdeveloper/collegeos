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
$group_filter = isset($_GET['group']) ? (int)$_GET['group'] : 0;
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$gender_filter = isset($_GET['gender']) ? $_GET['gender'] : '';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$sql = "SELECT s.*, g.name as group_name, g.course, g.specialty, g.language, g.study_form,
               g.is_active as group_is_active,
               u.first_name as curator_first_name, u.last_name as curator_last_name
        FROM students s 
        LEFT JOIN `groups` g ON s.group_id = g.id 
        LEFT JOIN users u ON g.curator_id = u.id
        WHERE 1=1";

$params = [];
$types = '';

if ($status_filter) {
    switch ($status_filter) {
        case 'active':
            $sql .= " AND s.academic_leave = 0 AND " . sqlNotGraduatedCondition('s');
            break;
        case 'academic_leave':
            $sql .= " AND s.academic_leave = 1";
            break;
        case 'graduated':
            $sql .= " AND " . sqlGraduatedCondition('s');
            break;
    }
}

if ($group_filter > 0) {
    $sql .= " AND s.group_id = ?";
    $params[] = $group_filter;
    $types .= 'i';
}

if ($course_filter) {
    $sql .= " AND g.course = ?";
    $params[] = $course_filter;
    $types .= 's';
}

if ($gender_filter) {
    $sql .= " AND s.gender = ?";
    $params[] = $gender_filter;
    $types .= 's';
}

if ($search) {
    $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'ssss';
}

$sql .= " ORDER BY s.last_name, s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
if ($stmt && !empty($params)) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
} elseif ($stmt) {
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $sql = "SELECT s.*, g.name as group_name, g.course, g.specialty, g.language, g.study_form,
                   u.first_name as curator_first_name, u.last_name as curator_last_name
            FROM students s 
            LEFT JOIN `groups` g ON s.group_id = g.id 
            LEFT JOIN users u ON g.curator_id = u.id
            ORDER BY s.last_name, s.first_name, s.middle_name";
    $result = $db->query($sql);
    $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$all_groups = $group->getAllGroups();

$student_stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0,
];

foreach ($students as $student) {
    if ($student['academic_leave']) {
        $student_stats['academic_leave']++;
    } elseif (isStudentGraduated($student)) {
        $student_stats['graduated']++;
    } else {
        $student_stats['active']++;
    }

    if ($student['gender'] === 'мужской') {
        $student_stats['male']++;
    } elseif ($student['gender'] === 'женский') {
        $student_stats['female']++;
    }
}

$page_title = 'Студенты';
$page_subtitle = 'Все студенты колледжа';
$document_title = 'Все студенты';
include 'includes/layout_start.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
    <div class="manager-action-buttons">
        <button type="button" class="btn btn-success" onclick="exportStudents()">
            <i class="bi bi-download me-1"></i>Экспорт
        </button>
        <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
            <i class="bi bi-printer me-1"></i>Печать
        </button>
    </div>
</div>

<div class="manager-stats-grid">
    <div class="manager-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Всего</div>
        <div class="stat-number"><?php echo (int)$student_stats['total']; ?></div>
        <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Активных</div>
        <div class="stat-number"><?php echo (int)$student_stats['active']; ?></div>
        <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-warning">
        <div class="stat-label"><span class="stat-dot"></span>Академ. отпуск</div>
        <div class="stat-number"><?php echo (int)$student_stats['academic_leave']; ?></div>
        <div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
    </div>
    <div class="manager-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Выпускники</div>
        <div class="stat-number"><?php echo (int)$student_stats['graduated']; ?></div>
        <div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
    </div>
</div>

<div class="manager-filters no-print">
    <form method="GET" action="students.php">
        <div class="manager-filter-row">
            <div class="manager-form-group">
                <label class="manager-form-label">Статус</label>
                <select class="form-control manager-form-control manager-form-select" name="status">
                    <option value="">Все статусы</option>
                    <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                    <option value="academic_leave" <?php echo $status_filter === 'academic_leave' ? 'selected' : ''; ?>>Академ. отпуск</option>
                    <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Выпускники</option>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Группа</label>
                <select class="form-control manager-form-control manager-form-select" name="group">
                    <option value="">Все группы</option>
                    <?php foreach ($all_groups as $group_item): ?>
                        <option value="<?php echo (int)$group_item['id']; ?>" <?php echo $group_filter == $group_item['id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($group_item['name']); ?>
                        </option>
                    <?php endforeach; ?>
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
                <label class="manager-form-label">Пол</label>
                <select class="form-control manager-form-control manager-form-select" name="gender">
                    <option value="">Все</option>
                    <option value="мужской" <?php echo $gender_filter === 'мужской' ? 'selected' : ''; ?>>Мужской</option>
                    <option value="женский" <?php echo $gender_filter === 'женский' ? 'selected' : ''; ?>>Женский</option>
                </select>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">Поиск</label>
                <div class="manager-search-box">
                    <i class="bi bi-search search-icon"></i>
                    <input type="text" class="form-control manager-form-control" name="search" id="searchInput"
                           placeholder="ФИО или ИИН" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="manager-form-group">
                <label class="manager-form-label">&nbsp;</label>
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-search me-1"></i>Найти
                    </button>
                    <a href="students.php" class="btn btn-outline-secondary">Сброс</a>
                </div>
            </div>
        </div>
    </form>
</div>

<?php if (empty($students)): ?>
    <div class="manager-empty-state">
        <div class="empty-icon"><i class="bi bi-people"></i></div>
        <div class="empty-title">Студенты не найдены</div>
        <div class="empty-description">Попробуйте изменить параметры фильтрации</div>
        <a href="students.php" class="btn btn-primary btn-sm">Сбросить фильтры</a>
    </div>
<?php else: ?>
    <div class="manager-table-container">
        <div class="table-responsive">
            <table class="table manager-table mb-0" id="studentsTable">
                <thead>
                    <tr>
                        <th>ФИО</th>
                        <th>ИИН</th>
                        <th>Группа</th>
                        <th>Курс</th>
                        <th>Статус</th>
                        <th>Телефон</th>
                        <th>Куратор</th>
                        <th class="no-print">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($students as $student):
                        $status = getStudentStatus($student);
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars(trim($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name'])); ?></strong>
                                <?php if (!empty($student['disability'])): ?>
                                    <i class="bi bi-wheelchair text-warning ms-1" title="Инвалидность"></i>
                                <?php endif; ?>
                                <?php if (!empty($student['orphan'])): ?>
                                    <i class="bi bi-heart text-info ms-1" title="Сирота"></i>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($student['iin'] ?? ''); ?></code></td>
                            <td>
                                <?php if (!empty($student['group_name'])): ?>
                                    <span class="manager-badge badge-primary"><?php echo htmlspecialchars($student['group_name']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($student['course'])): ?>
                                    <span class="manager-badge badge-info"><?php echo htmlspecialchars($student['course']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="<?php echo getStudentStatusBadgeClass($status); ?>">
                                    <?php echo htmlspecialchars(getStudentStatusLabel($status)); ?>
                                </span>
                            </td>
                            <td>
                                <?php if (!empty($student['phone'])): ?>
                                    <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                        <?php echo htmlspecialchars($student['phone']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($student['curator_first_name'])): ?>
                                    <?php echo htmlspecialchars(trim($student['curator_last_name'] . ' ' . $student['curator_first_name'])); ?>
                                <?php else: ?>
                                    <span class="text-muted">Не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td class="no-print">
                                <div class="manager-table-actions">
                                    <a href="view_student.php?id=<?php echo (int)$student['id']; ?>"
                                       class="btn btn-outline-primary" title="Просмотр">
                                        <i class="bi bi-eye"></i>
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
function exportStudents() {
    const table = document.querySelector('#studentsTable');
    if (!table) {
        alert('Нет данных для экспорта');
        return;
    }
    const rows = table.querySelectorAll('tbody tr');
    let csv = '\uFEFFФИО,ИИН,Группа,Курс,Статус,Телефон,Куратор\n';
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
    link.download = 'students_' + new Date().toISOString().slice(0, 10) + '.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>
JS;
include 'includes/layout_end.php';
