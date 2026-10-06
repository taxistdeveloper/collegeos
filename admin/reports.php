<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/student_status.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$group = new Group();
$db = getDB();

// Параметры фильтрации
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$course_filter = isset($_GET['course']) ? $_GET['course'] : '';
$language_filter = isset($_GET['language']) ? $_GET['language'] : '';
$study_form_filter = isset($_GET['study_form']) ? $_GET['study_form'] : '';
$curator_filter = isset($_GET['curator']) ? (int)$_GET['curator'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$not_graduated = sqlNotGraduatedCondition('s');

// Построение SQL запроса с фильтрами
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

// Фильтр по статусу
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

// Фильтр по курсу
if ($course_filter) {
    $sql .= " AND g.course = ?";
    $params[] = $course_filter;
    $types .= 's';
}

// Фильтр по языку
if ($language_filter) {
    $sql .= " AND g.language = ?";
    $params[] = $language_filter;
    $types .= 's';
}

// Фильтр по форме обучения
if ($study_form_filter) {
    $sql .= " AND g.study_form = ?";
    $params[] = $study_form_filter;
    $types .= 's';
}

// Фильтр по куратору
if ($curator_filter > 0) {
    $sql .= " AND g.curator_id = ?";
    $params[] = $curator_filter;
    $types .= 'i';
}

// Поиск
if ($search) {
    $sql .= " AND (g.name LIKE ? OR g.code LIKE ? OR g.specialty LIKE ?)";
    $search_param = "%$search%";
    $params[] = $search_param;
    $params[] = $search_param;
    $params[] = $search_param;
    $types .= 'sss';
}

$sql .= " GROUP BY g.id ORDER BY g.course, g.name";

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
            GROUP BY g.id 
            ORDER BY g.course, g.name";
    $result = $db->query($sql);
    $groups = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

// Получение всех кураторов для фильтра
$curators = $user->getUsersByRole(4);

// Общая статистика
$total_groups = count($groups);
$total_students = array_sum(array_column($groups, 'current_students'));
$active_groups = count(array_filter($groups, function ($g) {
    return $g['is_active'];
}));
$avg_occupancy = $total_groups > 0 ? round(array_sum(array_column($groups, 'occupancy_percentage')) / $total_groups, 1) : 0;

// Общая статистика по студентам
$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');
$student_stats_sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
    SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
    SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated_students,
    SUM(CASE WHEN disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN orphan = 1 THEN 1 ELSE 0 END) as orphan_students
    FROM students";
$student_stats_result = $db->query($student_stats_sql);
$student_stats = $student_stats_result->fetch_assoc();

// Для header
$page_title = 'Отчеты';
$active_page = 'reports';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Отчеты и статистика</h1>
        <p class="page-subtitle">Аналитика по группам и студентам</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i>
            <span>Печать</span>
        </button>
    </div>
</div>

<!-- Overall Stats -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-people-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['total_students'] ?? 0; ?></div>
            <div class="stat-label">Всего студентов</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-person-check-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['active_students'] ?? 0; ?></div>
            <div class="stat-label">Активных</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-collection-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $total_groups; ?></div>
            <div class="stat-label">Групп</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-check-circle-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $active_groups; ?></div>
            <div class="stat-label">Активных групп</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-percent"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $avg_occupancy; ?>%</div>
            <div class="stat-label">Средняя заполненность</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="bi bi-pause-circle-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['academic_leave_students'] ?? 0; ?></div>
            <div class="stat-label">Академ. отпуск</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-funnel"></i>
            Фильтры
        </h2>
    </div>
    <div class="card-body">
        <form method="GET" action="reports.php">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Статус</label>
                    <select class="form-select" name="status">
                        <option value="">Все</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Неактивные</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Курс</label>
                    <select class="form-select" name="course">
                        <option value="">Все курсы</option>
                        <option value="1 курс" <?php echo $course_filter === '1 курс' ? 'selected' : ''; ?>>1 курс</option>
                        <option value="2 курс" <?php echo $course_filter === '2 курс' ? 'selected' : ''; ?>>2 курс</option>
                        <option value="3 курс" <?php echo $course_filter === '3 курс' ? 'selected' : ''; ?>>3 курс</option>
                        <option value="4 курс" <?php echo $course_filter === '4 курс' ? 'selected' : ''; ?>>4 курс</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Язык</label>
                    <select class="form-select" name="language">
                        <option value="">Все</option>
                        <option value="казахский" <?php echo $language_filter === 'казахский' ? 'selected' : ''; ?>>Казахский</option>
                        <option value="русский" <?php echo $language_filter === 'русский' ? 'selected' : ''; ?>>Русский</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Форма обучения</label>
                    <select class="form-select" name="study_form">
                        <option value="">Все</option>
                        <option value="очная" <?php echo $study_form_filter === 'очная' ? 'selected' : ''; ?>>Очная</option>
                        <option value="заочная" <?php echo $study_form_filter === 'заочная' ? 'selected' : ''; ?>>Заочная</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Куратор</label>
                    <select class="form-select" name="curator">
                        <option value="">Все кураторы</option>
                        <?php foreach ($curators as $curator): ?>
                            <option value="<?php echo $curator['id']; ?>" <?php echo $curator_filter == $curator['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($curator['first_name'] . ' ' . $curator['last_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" name="search" placeholder="Название, код, специальность" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Применить фильтры
                </button>
                <a href="reports.php" class="btn btn-outline">
                    <i class="bi bi-x-circle"></i> Сбросить
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Groups Report Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-table"></i>
            Отчет по группам
        </h2>
        <span class="badge badge-primary"><?php echo count($groups); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Группа</th>
                        <th>Курс</th>
                        <th>Специальность</th>
                        <th>Куратор</th>
                        <th style="text-align: center;">Студентов</th>
                        <th style="text-align: center;">Активных</th>
                        <th style="text-align: center;">Академ.</th>
                        <th style="text-align: center;">Заполненность</th>
                        <th style="text-align: center;">Особые</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $group_item): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="list-avatar" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                        <?php echo mb_substr($group_item['name'], 0, 2); ?>
                                    </div>
                                    <div>
                                        <strong><?php echo htmlspecialchars($group_item['name']); ?></strong>
                                        <?php if (!$group_item['is_active']): ?>
                                            <span class="badge badge-secondary ms-1">Неактивна</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-secondary"><?php echo htmlspecialchars($group_item['course']); ?></span>
                            </td>
                            <td style="font-size: 0.875rem; color: var(--text-secondary);">
                                <?php echo htmlspecialchars($group_item['specialty']); ?>
                            </td>
                            <td>
                                <?php if ($group_item['curator_first_name']): ?>
                                    <?php echo htmlspecialchars($group_item['curator_first_name'] . ' ' . $group_item['curator_last_name']); ?>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">Не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <strong><?php echo $group_item['current_students']; ?></strong>
                                <span style="color: var(--text-muted); font-size: 0.75rem;">/ <?php echo $group_item['max_students']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <span class="badge badge-success"><?php echo $group_item['active_students']; ?></span>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($group_item['academic_leave_students'] > 0): ?>
                                    <span class="badge badge-warning"><?php echo $group_item['academic_leave_students']; ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <div class="d-flex align-items-center justify-content-center gap-2">
                                    <div style="width: 60px; height: 8px; background: var(--content-bg); border-radius: 9999px; overflow: hidden;">
                                        <div style="width: <?php echo min(100, $group_item['occupancy_percentage']); ?>%; height: 100%; background: <?php echo $group_item['occupancy_percentage'] > 90 ? 'var(--danger)' : ($group_item['occupancy_percentage'] > 70 ? 'var(--warning)' : 'var(--success)'); ?>;"></div>
                                    </div>
                                    <span style="font-size: 0.8125rem; font-weight: 500;"><?php echo $group_item['occupancy_percentage']; ?>%</span>
                                </div>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($group_item['disabled_students'] > 0 || $group_item['orphan_students'] > 0): ?>
                                    <div class="d-flex gap-1 justify-content-center">
                                        <?php if ($group_item['disabled_students'] > 0): ?>
                                            <span class="badge badge-warning" title="Инвалидность"><?php echo $group_item['disabled_students']; ?></span>
                                        <?php endif; ?>
                                        <?php if ($group_item['orphan_students'] > 0): ?>
                                            <span class="badge badge-info" title="Сироты"><?php echo $group_item['orphan_students']; ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted);">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Группы не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include 'includes/admin_footer.php'; ?>
