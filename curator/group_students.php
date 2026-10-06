<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkRole(['curator']);

$user = new User();
$group = new Group();
$permissionChecker = new PermissionChecker();
$current_user = getCurrentUser();

$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$group_id) {
    header('Location: my_groups.php');
    exit;
}

$group_info = $group->getGroupById($group_id);

if (!$group->isCuratorGroup($group_id, $current_user['id'])) {
    header('Location: my_groups.php');
    exit;
}

$is_archived_group = !(int)$group_info['is_active'];

$db = getDB();
$students = [];
$graduated_count = 0;

$not_graduated = sqlNotGraduatedCondition('s');
$sql = "SELECT s.*, g.name as group_name, g.is_active as group_is_active
        FROM students s 
        LEFT JOIN `groups` g ON s.group_id = g.id 
        WHERE s.group_id = ? 
        AND $not_graduated
        ORDER BY s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $students = $result->fetch_all(MYSQLI_ASSOC);
}

$graduated_sql = sqlGraduatedCondition('s');
$count_sql = "SELECT COUNT(*) as cnt FROM students s WHERE s.group_id = ? AND $graduated_sql";
$count_stmt = $db->prepare($count_sql);
if ($count_stmt) {
    $count_stmt->bind_param("i", $group_id);
    $count_stmt->execute();
    $graduated_count = (int)$count_stmt->get_result()->fetch_assoc()['cnt'];
}

$student_stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
];

$is_graduating_group = isGraduatingGroup($group_info);
foreach ($students as $student) {
    $status = getStudentStatus($student);
    if ($status === 'academic_leave') {
        $student_stats['academic_leave']++;
    } else {
        $student_stats['active']++;
    }
    if (!$is_graduating_group && isStudentGraduating($student)) {
        $is_graduating_group = true;
    }
}

$page_title = 'Студенты группы «' . $group_info['name'] . '»';
$page_subtitle = 'Код: ' . $group_info['code'] . ' · ' . $group_info['course']
    . ($is_graduating_group ? ' · Выпускная группа' : '');
$hide_header_search = true;
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
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['success_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['success_message']); ?>
            <?php endif; ?>
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($_SESSION['error_message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
                <?php unset($_SESSION['error_message']); ?>
            <?php endif; ?>

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <div class="curator-action-buttons">
                    <a href="my_groups.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-arrow-left me-1"></i>К группам
                    </a>
                    <a href="group_details.php?id=<?php echo $group_id; ?>" class="btn btn-outline-primary btn-sm">
                        <i class="bi bi-info-circle me-1"></i>О группе
                    </a>
                    <?php if (!$is_archived_group): ?>
                        <a href="add_student.php" class="btn btn-warning btn-sm">
                            <i class="bi bi-person-plus-fill me-1"></i>Добавить студента
                        </a>
                        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#graduateGroupModal">
                            <i class="bi bi-archive me-1"></i>В архив
                        </button>
                    <?php else: ?>
                        <span class="curator-badge badge-secondary align-self-center">Группа в архиве</span>
                    <?php endif; ?>
                </div>
            </div>

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
                <a href="graduates.php?group=<?php echo $group_id; ?>" class="curator-stat-card stat-info text-decoration-none" style="color: inherit;">
                    <div class="stat-label"><span class="stat-dot"></span>В архиве</div>
                    <div class="stat-number"><?php echo $graduated_count; ?></div>
                    <div class="stat-icon"><i class="bi bi-archive-fill"></i></div>
                </a>
            </div>

            <?php if (empty($students) && $graduated_count === 0): ?>
                <div class="curator-empty-state curator-animate-fadeInUp">
                    <div class="empty-icon"><i class="bi bi-people"></i></div>
                    <div class="empty-title">В группе пока нет студентов</div>
                    <div class="empty-description">Добавьте первого студента в группу</div>
                    <a href="add_student.php" class="curator-btn-primary">
                        <i class="bi bi-person-plus-fill me-1"></i>Добавить студента
                    </a>
                </div>
            <?php elseif (empty($students) && $graduated_count > 0): ?>
                <div class="curator-empty-state curator-animate-fadeInUp">
                    <div class="empty-icon"><i class="bi bi-archive"></i></div>
                    <div class="empty-title">Все студенты группы в архиве</div>
                    <div class="empty-description">Текущих студентов нет — все <?php echo $graduated_count; ?> переведены в выпускники</div>
                    <a href="graduates.php?group=<?php echo $group_id; ?>" class="curator-btn-primary">
                        <i class="bi bi-award me-1"></i>Открыть выпускников
                    </a>
                </div>
            <?php else: ?>
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
                            <label class="curator-form-label">Пол</label>
                            <select class="form-control curator-form-control curator-form-select" id="genderFilter">
                                <option value="">Все</option>
                                <option value="мужской">Мужской</option>
                                <option value="женский">Женский</option>
                            </select>
                        </div>
                        <div class="curator-form-group">
                            <label class="curator-form-label">Поиск</label>
                            <div class="curator-search-box">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="form-control curator-form-control" id="searchInput" placeholder="Поиск по ФИО или ИИН">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="curator-table-container curator-animate-fadeInUp">
                    <div class="table-responsive">
                        <table class="table curator-table mb-0" id="studentsTable">
                            <thead>
                                <tr>
                                    <th>ФИО</th>
                                    <th>ИИН</th>
                                    <th>Пол</th>
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
                                        data-course="<?php echo htmlspecialchars($student['course']); ?>"
                                        data-gender="<?php echo htmlspecialchars($student['gender']); ?>"
                                        data-search="<?php echo strtolower($student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['iin']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars(trim(($student['last_name'] ?? '') . ' ' . $student['first_name'] . ' ' . $student['middle_name'])); ?></strong>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($student['iin']); ?></code></td>
                                        <td><span class="curator-badge badge-primary"><?php echo htmlspecialchars($student['gender']); ?></span></td>
                                        <td><span class="curator-badge badge-info"><?php echo htmlspecialchars($student['course']); ?></span></td>
                                        <td>
                                            <span class="<?php echo getStudentStatusBadgeClass($status, 'curator'); ?>">
                                                <?php echo htmlspecialchars(getStudentStatusLabel($status)); ?>
                                            </span>
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
                                                <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn btn-outline-primary" title="Просмотр">
                                                    <i class="bi bi-eye"></i>
                                                </a>
                                                <?php if ($permissionChecker->hasPermission($current_user['id'], 'delete_students') || $permissionChecker->hasPermission($current_user['id'], 'delete_own_students') || $permissionChecker->hasPermission($current_user['id'], 'delete_all_students')): ?>
                                                    <button type="button" data-id="<?php echo $student['id']; ?>" class="btn btn-outline-danger btn-delete" title="Удалить">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (!$is_archived_group): ?>
            <div class="modal fade" id="graduateGroupModal" tabindex="-1" aria-labelledby="graduateGroupModalLabel" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="graduateGroupModalLabel">
                                <i class="bi bi-archive me-2"></i>Перевести группу в архив
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Группа <strong><?php echo htmlspecialchars($group_info['name']); ?></strong> будет переведена в архив выпускников:</p>
                            <ul>
                                <li>Всем текущим студентам проставится дата выпуска (сегодня)</li>
                                <li>Группа исчезнет из списка активных групп</li>
                                <li>Все выпускники останутся доступны в разделе «Выпускники»</li>
                            </ul>
                            <p class="text-muted mb-0">Текущих студентов: <?php echo $student_stats['total']; ?>, уже в архиве: <?php echo $graduated_count; ?></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <form method="POST" action="graduate_group.php" class="d-inline">
                                <input type="hidden" name="group_id" value="<?php echo $group_id; ?>">
                                <input type="hidden" name="redirect" value="group_students.php?id=<?php echo $group_id; ?>">
                                <button type="submit" class="btn btn-info">
                                    <i class="bi bi-archive me-1"></i>Перевести в архив
                                </button>
                            </form>
                        </div>
                    </div>
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
    function filterTable() {
        const statusFilter = document.getElementById('statusFilter')?.value || '';
        const genderFilter = document.getElementById('genderFilter')?.value || '';
        const searchInput = (document.getElementById('searchInput')?.value || '').toLowerCase();

        document.querySelectorAll('#studentsTable tbody tr').forEach(function(row) {
            let show = true;
            if (statusFilter && row.dataset.status !== statusFilter) show = false;
            if (genderFilter && row.dataset.gender !== genderFilter) show = false;
            if (searchInput && !row.dataset.search.includes(searchInput)) show = false;
            row.style.display = show ? '' : 'none';
        });
    }

    ['statusFilter', 'genderFilter', 'searchInput'].forEach(function(id) {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener(id === 'searchInput' ? 'input' : 'change', filterTable);
        }
    });
</script>
</body>
</html>
