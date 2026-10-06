<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка роли куратора
checkRole(['curator']);

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);

// Получение студентов для каждой группы
$db = getDB();
$groups_with_students = [];

foreach ($curator_groups as $group_item) {
    $not_graduated = sqlNotGraduatedCondition('');
    $sql = "SELECT COUNT(*) as count FROM students WHERE group_id = ? AND $not_graduated";
    $stmt = $db->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("i", $group_item['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $student_count = $result->fetch_assoc()['count'];
    } else {
        error_log("Ошибка подготовки SQL запроса: " . $db->error);
        $student_count = 0;
    }

    $graduated_sql = sqlGraduatedCondition('');
    $grad_sql = "SELECT COUNT(*) as count FROM students WHERE group_id = ? AND $graduated_sql";
    $grad_stmt = $db->prepare($grad_sql);
    $graduated_count = 0;
    if ($grad_stmt) {
        $grad_stmt->bind_param("i", $group_item['id']);
        $grad_stmt->execute();
        $graduated_count = (int)$grad_stmt->get_result()->fetch_assoc()['count'];
    }

    $graduating_sql = sqlGraduatingStudentCondition('');
    $graduating_count = 0;
    $graduating_stmt = $db->prepare("SELECT COUNT(*) as count FROM students WHERE group_id = ? AND $graduating_sql");
    if ($graduating_stmt) {
        $graduating_stmt->bind_param("i", $group_item['id']);
        $graduating_stmt->execute();
        $graduating_count = (int)$graduating_stmt->get_result()->fetch_assoc()['count'];
    }
    
    $group_item['active_students'] = $student_count;
    $group_item['graduated_students'] = $graduated_count;
    $group_item['is_graduating'] = isGraduatingGroup($group_item) || $graduating_count > 0;
    $groups_with_students[] = $group_item;
}

$page_title = 'Мои группы';
$page_subtitle = '';
$course_options = [];
foreach ($groups_with_students as $group_item) {
    if (!empty($group_item['course'])) {
        $course_options[$group_item['course']] = true;
    }
}
$course_options = array_keys($course_options);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
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

                <?php if (empty($groups_with_students)): ?>
                    <div class="curator-empty-state curator-animate-fadeInUp">
                        <div class="empty-icon">
                            <i class="bi bi-collection"></i>
                        </div>
                        <div class="empty-title">У вас пока нет назначенных групп</div>
                        <div class="empty-description">Обратитесь к администратору для назначения групп</div>
                    </div>
                <?php else: ?>
                    <div class="curator-stats-grid curator-animate-fadeInUp">
                        <div class="curator-stat-card stat-primary">
                            <div class="stat-label"><span class="stat-dot"></span>Всего групп</div>
                            <div class="stat-number"><?php echo count($groups_with_students); ?></div>
                            <div class="stat-icon"><i class="bi bi-collection-fill"></i></div>
                        </div>
                        <div class="curator-stat-card stat-success">
                            <div class="stat-label"><span class="stat-dot"></span>Текущих студентов</div>
                            <div class="stat-number"><?php echo array_sum(array_column($groups_with_students, 'active_students')); ?></div>
                            <div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
                        </div>
                        <div class="curator-stat-card stat-info">
                            <div class="stat-label"><span class="stat-dot"></span>Максимум мест</div>
                            <div class="stat-number"><?php echo array_sum(array_column($groups_with_students, 'max_students')); ?></div>
                            <div class="stat-icon"><i class="bi bi-people-fill"></i></div>
                        </div>
                        <div class="curator-stat-card stat-warning">
                            <div class="stat-label"><span class="stat-dot"></span>Заполненность</div>
                            <div class="stat-number"><?php
                            $total_max = array_sum(array_column($groups_with_students, 'max_students'));
                            $total_current = array_sum(array_column($groups_with_students, 'active_students'));
                            echo $total_max > 0 ? round(($total_current / $total_max) * 100, 1) : 0;
                            ?>%</div>
                            <div class="stat-icon"><i class="bi bi-percent"></i></div>
                        </div>
                    </div>

                    <div class="curator-filters curator-animate-fadeInUp">
                        <div class="curator-filter-row">
                            <div class="curator-form-group">
                                <label class="curator-form-label" for="cur-course-filter">Курс</label>
                                <select id="cur-course-filter" class="form-control curator-form-control curator-form-select" aria-label="Фильтр по курсу">
                                    <option value="">Все курсы</option>
                                    <?php foreach ($course_options as $course): ?>
                                        <option value="<?php echo htmlspecialchars($course); ?>"><?php echo htmlspecialchars($course); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="curator-form-group">
                                <label class="curator-form-label" for="cur-groups-search">Поиск</label>
                                <div class="curator-search-box">
                                    <i class="bi bi-search search-icon"></i>
                                    <input id="cur-groups-search" type="text" class="form-control curator-form-control" placeholder="Группа, код или специальность" aria-label="Поиск по группам">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="curator-table-container curator-animate-fadeInUp">
                        <div class="table-responsive">
                            <table class="table curator-table mb-0">
                                <thead>
                                    <tr>
                                        <th>Группа</th>
                                        <th>Специальность</th>
                                        <th>Квалификация</th>
                                        <th>Курс</th>
                                        <th>Язык</th>
                                        <th>Форма</th>
                                        <th>Студенты</th>
                                        <th>Заполненность</th>
                                        <th>Действия</th>
                                    </tr>
                                </thead>
                                <tbody id="cur-groups-tbody">
                                    <?php foreach ($groups_with_students as $group_item): ?>
                                        <?php 
                                        $fill_percentage = $group_item['max_students'] > 0
                                            ? ($group_item['active_students'] / $group_item['max_students']) * 100
                                            : 0;
                                        ?>
                                        <tr
                                            data-name="<?php echo htmlspecialchars($group_item['name']); ?>"
                                            data-code="<?php echo htmlspecialchars($group_item['code']); ?>"
                                            data-specialty="<?php echo htmlspecialchars($group_item['specialty']); ?>"
                                            data-course="<?php echo htmlspecialchars($group_item['course']); ?>">
                                            <td>
                                                <strong><?php echo htmlspecialchars($group_item['name']); ?></strong>
                                                <?php if (!empty($group_item['is_graduating'])): ?>
                                                    <span class="curator-badge badge-warning ms-1">Выпускная группа</span>
                                                <?php endif; ?>
                                                <br>
                                                <code><?php echo htmlspecialchars($group_item['code']); ?></code>
                                            </td>
                                            <td>
                                                <div class="text-truncate-2" style="max-width: 200px;" title="<?php echo htmlspecialchars($group_item['specialty']); ?>">
                                                    <?php echo htmlspecialchars($group_item['specialty']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <div class="text-truncate-2" style="max-width: 150px;" title="<?php echo htmlspecialchars($group_item['qualification']); ?>">
                                                    <?php echo htmlspecialchars($group_item['qualification']); ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="curator-badge badge-info"><?php echo htmlspecialchars($group_item['course']); ?></span>
                                            </td>
                                            <td>
                                                <span class="curator-badge badge-primary"><?php echo htmlspecialchars($group_item['language']); ?></span>
                                            </td>
                                            <td>
                                                <span class="curator-badge badge-success"><?php echo htmlspecialchars($group_item['study_form']); ?></span>
                                            </td>
                                            <td>
                                                <span class="curator-badge badge-primary"><?php echo $group_item['active_students']; ?>/<?php echo $group_item['max_students']; ?></span>
                                                <?php if ($group_item['graduated_students'] > 0): ?>
                                                    <br><small class="text-muted">+<?php echo $group_item['graduated_students']; ?> в архиве</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="curator-progress">
                                                    <div class="curator-progress-bar" 
                                                         style="width: <?php echo $fill_percentage; ?>%">
                                                    </div>
                                                </div>
                                                <small class="text-muted"><?php echo round($fill_percentage, 1); ?>%</small>
                                            </td>
                                            <td>
                                                <div class="curator-table-actions">
                                                    <a href="group_students.php?id=<?php echo $group_item['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Студенты группы">
                                                        <i class="bi bi-people"></i>
                                                    </a>
                                                    <a href="group_details.php?id=<?php echo $group_item['id']; ?>" 
                                                       class="btn btn-outline-info" title="Подробности">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    <a href="group_reports.php?id=<?php echo $group_item['id']; ?>" 
                                                       class="btn btn-outline-success" title="Отчёты группы">
                                                        <i class="bi bi-graph-up"></i>
                                                    </a>
                                                    <button type="button" class="btn btn-outline-info btn-archive-group"
                                                            title="В архив выпускников"
                                                            data-group-id="<?php echo $group_item['id']; ?>"
                                                            data-group-name="<?php echo htmlspecialchars($group_item['name']); ?>"
                                                            data-active="<?php echo $group_item['active_students']; ?>"
                                                            data-graduated="<?php echo $group_item['graduated_students']; ?>">
                                                        <i class="bi bi-archive"></i>
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

            <div class="modal fade" id="graduateGroupModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title"><i class="bi bi-archive me-2"></i>Перевести группу в архив</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p>Группа <strong id="modalGroupName"></strong> будет переведена в архив выпускников:</p>
                            <ul>
                                <li>Всем текущим студентам проставится дата выпуска</li>
                                <li>Группа исчезнет из активных</li>
                                <li>Выпускники останутся в разделе «Выпускники»</li>
                            </ul>
                            <p class="text-muted mb-0" id="modalGroupStats"></p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                            <form method="POST" action="graduate_group.php" id="graduateGroupForm">
                                <input type="hidden" name="group_id" id="modalGroupId" value="">
                                <input type="hidden" name="redirect" value="my_groups.php">
                                <button type="submit" class="btn btn-info">
                                    <i class="bi bi-archive me-1"></i>Перевести в архив
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
    <script>
        document.querySelectorAll('.btn-archive-group').forEach(function(btn) {
            btn.addEventListener('click', function() {
                document.getElementById('modalGroupId').value = this.dataset.groupId;
                document.getElementById('modalGroupName').textContent = this.dataset.groupName;
                document.getElementById('modalGroupStats').textContent =
                    'Текущих студентов: ' + this.dataset.active + ', уже в архиве: ' + this.dataset.graduated;
                new bootstrap.Modal(document.getElementById('graduateGroupModal')).show();
            });
        });
    </script>
</body>
</html>
