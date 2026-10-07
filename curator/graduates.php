<?php
require_once '../config/config.php';
startSessionSafely();
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkAuth();

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

$curator_groups = $group->getGroupsByCurator($current_user['id'], false);
$db = getDB();
$students = [];
$archived_groups = [];

foreach ($curator_groups as $g) {
    if (!(int)$g['is_active']) {
        $archived_groups[] = $g;
    }
}

if (!empty($curator_groups)) {
    $group_ids = array_column($curator_groups, 'id');
    $placeholders = str_repeat('?,', count($group_ids) - 1) . '?';
    $graduated_sql = sqlGraduatedCondition('s');

    $sql = "SELECT s.*, g.name as group_name, g.is_active as group_is_active
            FROM students s
            LEFT JOIN `groups` g ON s.group_id = g.id
            WHERE s.group_id IN ($placeholders)
            AND $graduated_sql
            ORDER BY g.name, s.last_name, s.first_name";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param(str_repeat('i', count($group_ids)), ...$group_ids);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result->fetch_all(MYSQLI_ASSOC);
    }
}

$group_filter = isset($_GET['group']) ? (int)$_GET['group'] : 0;
if ($group_filter) {
    $students = array_values(array_filter($students, function ($s) use ($group_filter) {
        return (int)$s['group_id'] === $group_filter;
    }));
}

$page_title = 'Выпускники';
$page_subtitle = '';
$hide_header_search = true;
$initial_search = isset($_GET['search']) ? trim($_GET['search']) : '';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
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

            <?php if (!empty($archived_groups)): ?>
                <div class="curator-section curator-animate-fadeInUp mb-4">
                    <h2 class="curator-section-title"><i class="bi bi-archive me-2"></i>Архивные группы</h2>
                    <div class="row g-3">
                        <?php foreach ($archived_groups as $archived): ?>
                            <div class="col-md-6 col-lg-4">
                                <div class="curator-stat-card">
                                    <div class="d-flex justify-content-between align-items-start mb-2">
                                        <strong><?php echo htmlspecialchars($archived['name']); ?></strong>
                                        <span class="curator-badge badge-secondary">Архив</span>
                                    </div>
                                    <div class="text-muted small mb-3">
                                        <code><?php echo htmlspecialchars($archived['code']); ?></code>
                                        · <?php echo htmlspecialchars($archived['course']); ?>
                                    </div>
                                    <a href="graduates.php?group=<?php echo $archived['id']; ?>" class="btn btn-outline-primary btn-sm">
                                        <i class="bi bi-people me-1"></i>Выпускники группы
                                    </a>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (empty($students)): ?>
                <div class="curator-empty-state curator-animate-fadeInUp">
                    <div class="empty-icon"><i class="bi bi-archive"></i></div>
                    <div class="empty-title">Архив выпускников пуст</div>
                    <div class="empty-description">Завершившие обучение студенты появятся здесь автоматически или после перевода группы в архив</div>
                    <a href="my_groups.php" class="curator-btn-primary mt-3">
                        <i class="bi bi-collection me-1"></i>Мои группы
                    </a>
                </div>
            <?php else: ?>
                <div class="curator-stats-grid curator-animate-fadeInUp">
                    <div class="curator-stat-card stat-info">
                        <div class="stat-label"><span class="stat-dot"></span>Всего выпускников</div>
                        <div class="stat-number"><?php echo count($students); ?></div>
                        <div class="stat-icon"><i class="bi bi-award-fill"></i></div>
                    </div>
                    <div class="curator-stat-card stat-primary">
                        <div class="stat-label"><span class="stat-dot"></span>Групп в архиве</div>
                        <div class="stat-number"><?php echo count(array_unique(array_column($students, 'group_id'))); ?></div>
                        <div class="stat-icon"><i class="bi bi-archive-fill"></i></div>
                    </div>
                </div>

                <div class="curator-filters curator-animate-fadeInUp">
                    <div class="curator-filter-row">
                        <div class="curator-form-group">
                            <label class="curator-form-label">Группа</label>
                            <select class="form-control curator-form-control curator-form-select" id="groupFilter">
                                <option value="">Все группы</option>
                                <?php foreach ($curator_groups as $group_item): ?>
                                    <option value="<?php echo $group_item['id']; ?>" <?php echo $group_filter === (int)$group_item['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($group_item['name']); ?>
                                        <?php echo !(int)$group_item['is_active'] ? ' (архив)' : ''; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="curator-form-group">
                            <label class="curator-form-label">Поиск</label>
                            <div class="curator-search-box">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="form-control curator-form-control" id="searchInput"
                                       placeholder="Поиск по ФИО или ИИН" value="<?php echo htmlspecialchars($initial_search); ?>">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="curator-table-container curator-animate-fadeInUp">
                    <div class="table-responsive">
                        <table class="table curator-table mb-0" id="graduatesTable">
                            <thead>
                                <tr>
                                    <th>ФИО</th>
                                    <th>ИИН</th>
                                    <th>Группа</th>
                                    <th>Курс</th>
                                    <th>Дата выпуска</th>
                                    <th>Телефон</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($students as $student): ?>
                                    <tr data-group="<?php echo $student['group_id']; ?>"
                                        data-search="<?php echo strtolower(($student['last_name'] ?? '') . ' ' . $student['first_name'] . ' ' . $student['middle_name'] . ' ' . $student['iin']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars(trim(($student['last_name'] ?? '') . ' ' . $student['first_name'] . ' ' . $student['middle_name'])); ?></strong>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($student['iin']); ?></code></td>
                                        <td>
                                            <span class="curator-badge badge-primary"><?php echo htmlspecialchars($student['group_name']); ?></span>
                                            <?php if (!(int)$student['group_is_active']): ?>
                                                <span class="curator-badge badge-secondary">Архив</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><span class="curator-badge badge-info"><?php echo htmlspecialchars($student['course']); ?></span></td>
                                        <td>
                                            <?php
                                            if (!empty($student['graduation_date'])) {
                                                echo htmlspecialchars($student['graduation_date']);
                                            } elseif (!empty($student['course_end_date'])) {
                                                echo htmlspecialchars($student['course_end_date']) . ' <small class="text-muted">(окончание курса)</small>';
                                            } else {
                                                echo '—';
                                            }
                                            ?>
                                        </td>
                                        <td>
                                            <?php if (!empty($student['phone'])): ?>
                                                <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($student['phone']); ?>
                                                </a>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="view_student.php?id=<?php echo $student['id']; ?>" class="btn btn-outline-primary btn-sm" title="Просмотр">
                                                <i class="bi bi-eye"></i>
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
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
<script src="../assets/js/main.js"></script>
<script src="assets/js/curator-ui.js"></script>
<script>
    function filterTable() {
        const groupFilter = document.getElementById('groupFilter')?.value || '';
        const searchInput = (document.getElementById('searchInput')?.value || '').toLowerCase();

        document.querySelectorAll('#graduatesTable tbody tr').forEach(function(row) {
            let show = true;
            if (groupFilter && row.dataset.group !== groupFilter) show = false;
            if (searchInput && !row.dataset.search.includes(searchInput)) show = false;
            row.style.display = show ? '' : 'none';
        });
    }

    const groupFilterEl = document.getElementById('groupFilter');
    const searchInputEl = document.getElementById('searchInput');
    if (groupFilterEl) {
        groupFilterEl.addEventListener('change', function() {
            const val = this.value;
            if (val) {
                window.location.href = 'graduates.php?group=' + val;
            } else {
                window.location.href = 'graduates.php';
            }
        });
    }
    if (searchInputEl) {
        searchInputEl.addEventListener('input', filterTable);
    }
    <?php if ($initial_search !== ''): ?>
    filterTable();
    <?php endif; ?>
</script>
</body>
</html>
