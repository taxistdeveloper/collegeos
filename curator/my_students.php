<?php
require_once '../config/config.php';
startSessionSafely();
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
// Отключена строгая проверка прав для страницы "Мои студенты", чтобы избежать ложных отказов в доступе

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);

// Получение студентов из групп куратора
$db = getDB();
$students = [];

if (!empty($curator_groups)) {
    $group_ids = array_column($curator_groups, 'id');
    $placeholders = str_repeat('?,', count($group_ids) - 1) . '?';

    $not_graduated = sqlNotGraduatedCondition('s');
    $sql = "SELECT s.*, g.name as group_name, g.is_active as group_is_active
            FROM students s 
            LEFT JOIN `groups` g ON s.group_id = g.id 
            WHERE s.group_id IN ($placeholders) 
            AND $not_graduated
            ORDER BY s.first_name, s.middle_name";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param(str_repeat('i', count($group_ids)), ...$group_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result->fetch_all(MYSQLI_ASSOC);
    } else {
        // Обработка ошибки подготовки запроса
        error_log("Ошибка подготовки SQL запроса: " . $db->getConnection()->error);
        $students = [];
    }
}

// Статистика текущих студентов (без выпускников)
$student_stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
];

// Количество выпускников для ссылки в архив
$graduated_count = 0;
if (!empty($curator_groups)) {
    $group_ids = array_column($curator_groups, 'id');
    $placeholders = str_repeat('?,', count($group_ids) - 1) . '?';
    $graduated_sql = sqlGraduatedCondition('s');
    $count_sql = "SELECT COUNT(*) as cnt FROM students s WHERE s.group_id IN ($placeholders) AND $graduated_sql";
    $count_stmt = $db->prepare($count_sql);
    if ($count_stmt) {
        $count_stmt->bind_param(str_repeat('i', count($group_ids)), ...$group_ids);
        $count_stmt->execute();
        $graduated_count = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
    }
}

foreach ($students as $student) {
    $status = getStudentStatus($student);
    if ($status === 'academic_leave') {
        $student_stats['academic_leave']++;
    } else {
        $student_stats['active']++;
    }
}
$page_title = 'Мои студенты';
$page_subtitle = '';
$hide_header_search = true;
$initial_search = isset($_GET['search']) ? trim($_GET['search']) : '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">
                    <?php if (isset($_SESSION['success_message'])): ?>
                        <div class="alert alert-success alert-dismissible fade show curator-animate-fadeInUp" role="alert">
                            <i class="bi bi-check-circle-fill me-2"></i>
                            <strong><?php echo $_SESSION['success_message']; ?></strong>
                            <?php if (isset($_SESSION['success_details'])): ?>
                                <br><small class="text-success-emphasis"><?php echo $_SESSION['success_details']; ?></small>
                                <?php unset($_SESSION['success_details']); ?>
                            <?php endif; ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['success_message']); ?>
                    <?php endif; ?>

                    <?php if (isset($_SESSION['error_message'])): ?>
                        <div class="alert alert-warning alert-dismissible fade show curator-animate-fadeInUp" role="alert">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                        </div>
                        <?php unset($_SESSION['error_message']); ?>
                    <?php endif; ?>

                    <?php if (empty($students)): ?>
                        <div class="curator-empty-state curator-animate-fadeInUp">
                            <div class="empty-icon">
                                <i class="bi bi-mortarboard"></i>
                            </div>
                            <div class="empty-title">У вас пока нет студентов</div>
                            <div class="empty-description">Студенты появятся после назначения вас куратором групп</div>
                        </div>
                    <?php else: ?>
                        <div class="curator-stats-grid curator-animate-fadeInUp">
                            <div class="curator-stat-card stat-primary">
                                <div class="stat-label"><span class="stat-dot"></span>Текущих студентов</div>
                                <div class="stat-number"><?php echo $student_stats['total']; ?></div>
                                <div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
                            </div>
                            <div class="curator-stat-card stat-success">
                                <div class="stat-label"><span class="stat-dot"></span>Активных</div>
                                <div class="stat-number"><?php echo $student_stats['active']; ?></div>
                                <div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
                            </div>
                            <div class="curator-stat-card stat-warning">
                                <div class="stat-label"><span class="stat-dot"></span>В академ. отпуске</div>
                                <div class="stat-number"><?php echo $student_stats['academic_leave']; ?></div>
                                <div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
                            </div>
                            <a href="graduates.php" class="curator-stat-card stat-info text-decoration-none" style="color: inherit;">
                                <div class="stat-label"><span class="stat-dot"></span>В архиве</div>
                                <div class="stat-number"><?php echo $graduated_count; ?></div>
                                <div class="stat-icon"><i class="bi bi-archive-fill"></i></div>
                            </a>
                        </div>

                        <!-- Фильтры -->
                        <div class="curator-filters curator-animate-fadeInUp">
                            <div class="curator-filter-row">
                                <div class="curator-form-group">
                                    <label class="curator-form-label">Статус</label>
                                    <select class="form-control curator-form-control curator-form-select" id="statusFilter">
                                        <option value="">Все текущие</option>
                                        <option value="active">Активные</option>
                                        <option value="academic_leave">В академ. отпуске</option>
                                    </select>
                                </div>
                                <div class="curator-form-group">
                                    <label class="curator-form-label">Группа</label>
                                    <select class="form-control curator-form-control curator-form-select" id="groupFilter">
                                        <option value="">Все группы</option>
                                        <?php foreach ($curator_groups as $group_item): ?>
                                            <option value="<?php echo $group_item['id']; ?>">
                                                <?php echo htmlspecialchars($group_item['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="curator-form-group">
                                    <label class="curator-form-label">Курс</label>
                                    <select class="form-control curator-form-control curator-form-select" id="courseFilter">
                                        <option value="">Все курсы</option>
                                        <option value="1 курс">1 курс</option>
                                        <option value="2 курс">2 курс</option>
                                        <option value="3 курс">3 курс</option>
                                        <option value="4 курс">4 курс</option>
                                        <option value="5 курс">5 курс</option>
                                    </select>
                                </div>
                                <div class="curator-form-group">
                                    <label class="curator-form-label">Поиск</label>
                                    <div class="curator-search-box">
                                        <i class="bi bi-search search-icon"></i>
                                        <input type="text" class="form-control curator-form-control" id="searchInput" placeholder="Поиск по ФИО или ИИН" value="<?php echo htmlspecialchars($initial_search); ?>">
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Таблица студентов -->
                        <div class="curator-table-container curator-animate-fadeInUp">
                            <div class="table-responsive">
                                <table class="table curator-table mb-0" id="studentsTable">
                                    <thead>
                                        <tr>
                                            <th>ФИО</th>
                                            <th>ИИН</th>
                                            <th>Группа</th>
                                            <th>Курс</th>
                                            <th>Статус</th>
                                            <th>Телефон</th>
                                            <th>Email</th>
                                            <th>Действия</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($students as $student):
                                            $status = getStudentStatus($student);
                                        ?>
                                            <tr data-status="<?php echo $status; ?>"
                                                data-group="<?php echo $student['group_id']; ?>"
                                                data-course="<?php echo $student['course']; ?>"
                                                data-search="<?php echo strtolower($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['iin']); ?>">
                                                <td>
                                                    <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name']); ?></strong>
                                                </td>
                                                <td>
                                                    <code><?php echo htmlspecialchars($student['iin']); ?></code>
                                                </td>
                                                <td>
                                                    <span class="curator-badge badge-primary"><?php echo htmlspecialchars($student['group_name']); ?></span>
                                                </td>
                                                <td>
                                                    <span class="curator-badge badge-info"><?php echo htmlspecialchars($student['course']); ?></span>
                                                </td>
                                                <td>
                                                        <?php
                                                        echo '<span class="' . getStudentStatusBadgeClass($status, 'curator') . '">'
                                                            . htmlspecialchars(getStudentStatusLabel($status))
                                                            . '</span>';
                                                        ?>
                                                </td>
                                                <td>
                                                    <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($student['phone']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="text-decoration-none">
                                                        <?php echo htmlspecialchars($student['email']); ?>
                                                    </a>
                                                </td>
                                                <td>
                                                    <div class="curator-table-actions">
                                                        <a href="view_student.php?id=<?php echo $student['id']; ?>"
                                                            class="btn btn-outline-primary" title="Просмотр">
                                                            <i class="bi bi-eye"></i>
                                                        </a>
                                                        <a href="edit_student_new.php?id=<?php echo $student['id']; ?>"
                                                            class="btn btn-outline-warning" title="Редактировать">
                                                            <i class="bi bi-pencil"></i>
                                                        </a>
                                                        <button type="button" data-id="<?php echo $student['id']; ?>" class="btn btn-outline-danger btn-delete" title="Удалить">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
            <?php endif; ?>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
    <script>
        // Фильтрация таблицы
        function filterTable() {
            const statusFilter = document.getElementById('statusFilter').value;
            const groupFilter = document.getElementById('groupFilter').value;
            const courseFilter = document.getElementById('courseFilter').value;
            const searchInput = document.getElementById('searchInput').value.toLowerCase();

            const rows = document.querySelectorAll('#studentsTable tbody tr');

            rows.forEach(row => {
                const status = row.dataset.status;
                const group = row.dataset.group;
                const course = row.dataset.course;
                const search = row.dataset.search;

                let show = true;

                if (statusFilter && status !== statusFilter) show = false;
                if (groupFilter && group !== groupFilter) show = false;
                if (courseFilter && course !== courseFilter) show = false;
                if (searchInput && !search.includes(searchInput)) show = false;

                row.style.display = show ? '' : 'none';
            });
        }

        // Привязка событий
        document.getElementById('statusFilter').addEventListener('change', filterTable);
        document.getElementById('groupFilter').addEventListener('change', filterTable);
        document.getElementById('courseFilter').addEventListener('change', filterTable);
        document.getElementById('searchInput').addEventListener('input', filterTable);

        <?php if ($initial_search !== ''): ?>
        filterTable();
        <?php endif; ?>
    </script>
</body>

</html>