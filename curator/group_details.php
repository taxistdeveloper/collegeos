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

// Получение ID группы из параметра
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$group_id) {
    header('Location: my_groups.php');
    exit;
}

// Получение информации о группе
$group_info = $group->getGroupById($group_id);

// Проверка, что группа принадлежит куратору
if (!$group->isCuratorGroup($group_id, $current_user['id'])) {
    header('Location: my_groups.php');
    exit;
}

// Получение статистики студентов группы
$db = getDB();
$student_stats = [
    'total' => 0,
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0
];

$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');
$sql = "SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active,
            SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave,
            SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated,
            SUM(CASE WHEN gender = 'мужской' THEN 1 ELSE 0 END) as male,
            SUM(CASE WHEN gender = 'женский' THEN 1 ELSE 0 END) as female
        FROM students 
        WHERE group_id = ?";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $student_stats = $result->fetch_assoc();
}

// Получение последних добавленных студентов
$sql = "SELECT first_name, last_name, middle_name, iin, created_at 
        FROM students 
        WHERE group_id = ? 
        ORDER BY created_at DESC 
        LIMIT 5";

$stmt = $db->prepare($sql);
if ($stmt) {
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $recent_students = $result->fetch_all(MYSQLI_ASSOC);
} else {
    $recent_students = [];
}

$page_title = 'Группа «' . $group_info['name'] . '»';
$page_subtitle = $group_info['code'] . ' · ' . $group_info['course'];
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

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <div class="curator-action-buttons">
                    <a href="my_groups.php" class="btn btn-outline-secondary btn-sm">
                        <i class="bi bi-collection me-1"></i>Мои группы
                    </a>
                    <a href="group_students.php?id=<?php echo $group_id; ?>" class="btn btn-primary btn-sm">
                        <i class="bi bi-people me-1"></i>Студенты группы
                    </a>
                    <a href="add_student.php" class="btn btn-warning btn-sm">
                        <i class="bi bi-person-plus-fill me-1"></i>Добавить студента
                    </a>
                </div>
            </div>

                    <div class="row">
                        <!-- Основная информация о группе -->
                        <div class="col-md-8 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-collection me-2"></i>
                                        Информация о группе
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <h6 class="text-primary">Основные данные</h6>
                                            <p><strong>Название:</strong> <?php echo htmlspecialchars($group_info['name']); ?></p>
                                            <p><strong>Код группы:</strong> <code><?php echo htmlspecialchars($group_info['code']); ?></code></p>
                                            <p><strong>Специальность:</strong> <?php echo htmlspecialchars($group_info['specialty']); ?></p>
                                            <p><strong>Квалификация:</strong> <?php echo htmlspecialchars($group_info['qualification']); ?></p>
                                            <p><strong>Курс:</strong> <span class="badge bg-info"><?php echo htmlspecialchars($group_info['course']); ?></span></p>
                                        </div>
                                        <div class="col-md-6">
                                            <h6 class="text-primary">Образовательные параметры</h6>
                                            <p><strong>Язык обучения:</strong> <?php echo htmlspecialchars($group_info['language']); ?></p>
                                            <p><strong>Форма обучения:</strong> <?php echo htmlspecialchars($group_info['study_form']); ?></p>
                                            <p><strong>Срок обучения:</strong> <?php echo htmlspecialchars($group_info['study_duration']); ?></p>
                                            <p><strong>Дата начала:</strong> <?php echo formatDate($group_info['start_date']); ?></p>
                                            <?php if ($group_info['end_date']): ?>
                                                <p><strong>Дата окончания:</strong> <?php echo formatDate($group_info['end_date']); ?></p>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <?php if ($group_info['description']): ?>
                                        <div class="row mt-3">
                                            <div class="col-12">
                                                <h6 class="text-primary">Описание</h6>
                                                <p><?php echo htmlspecialchars($group_info['description']); ?></p>
                                            </div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Статистика -->
                        <div class="col-md-4 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-bar-chart-fill me-2"></i>
                                        Статистика
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-primary mb-1"><?php echo $student_stats['total']; ?></h4>
                                                <small class="text-muted">Всего студентов</small>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-success mb-1"><?php echo $student_stats['active']; ?></h4>
                                                <small class="text-muted">Активных</small>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-warning mb-1"><?php echo $student_stats['academic_leave']; ?></h4>
                                                <small class="text-muted">В академ. отпуске</small>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-info mb-1"><?php echo $student_stats['graduated']; ?></h4>
                                                <small class="text-muted">Выпускников</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="row text-center">
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-primary mb-1"><?php echo $student_stats['male']; ?></h4>
                                                <small class="text-muted">Мужчин</small>
                                            </div>
                                        </div>
                                        <div class="col-6 mb-3">
                                            <div class="border rounded p-2">
                                                <h4 class="text-pink mb-1"><?php echo $student_stats['female']; ?></h4>
                                                <small class="text-muted">Женщин</small>
                                            </div>
                                        </div>
                                    </div>

                                    <hr>

                                    <div class="text-center">
                                        <div class="border rounded p-2">
                                            <h4 class="text-success mb-1"><?php echo round(($student_stats['total'] / $group_info['max_students']) * 100, 1); ?>%</h4>
                                            <small class="text-muted">Заполненность группы</small>
                                            <div class="progress mt-2" style="height: 8px;">
                                                <div class="progress-bar bg-success"
                                                    style="width: <?php echo ($student_stats['total'] / $group_info['max_students']) * 100; ?>%">
                                                </div>
                                            </div>
                                            <small class="text-muted"><?php echo $student_stats['total']; ?>/<?php echo $group_info['max_students']; ?> мест</small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Последние добавленные студенты -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-clock-fill me-2"></i>
                                        Последние добавленные студенты
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <?php if (empty($recent_students)): ?>
                                        <p class="text-muted">Нет студентов в группе</p>
                                    <?php else: ?>
                                        <div class="list-group list-group-flush">
                                            <?php foreach ($recent_students as $student): ?>
                                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                                    <div>
                                                        <h6 class="mb-1"><?php echo htmlspecialchars($student['first_name'] . ' ' . $student['last_name'] . ' ' . $student['middle_name']); ?></h6>
                                                        <small class="text-muted">ИИН: <?php echo htmlspecialchars($student['iin']); ?></small>
                                                    </div>
                                                    <small class="text-muted">
                                                        <?php echo formatDate($student['created_at']); ?>
                                                    </small>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Быстрые действия -->
                        <div class="col-md-6 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-lightning-fill me-2"></i>
                                        Быстрые действия
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="d-grid gap-2">
                                        <a href="group_students.php?id=<?php echo $group_id; ?>" class="btn btn-outline-primary">
                                            <i class="bi bi-people me-2"></i>Просмотр студентов
                                        </a>
                                        <a href="add_student.php" class="btn btn-outline-success">
                                            <i class="bi bi-person-plus me-2"></i>Добавить студента
                                        </a>
                                        <a href="my_students.php" class="btn btn-outline-info">
                                            <i class="bi bi-mortarboard me-2"></i>Все мои студенты
                                        </a>
                                        <a href="my_groups.php" class="btn btn-outline-secondary">
                                            <i class="bi bi-collection me-2"></i>Все мои группы
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Информация о кураторе -->
                        <div class="col-12 mb-4">
                            <div class="card">
                                <div class="card-header">
                                    <h5 class="card-title mb-0">
                                        <i class="bi bi-person-heart me-2"></i>
                                        Информация о кураторе
                                    </h5>
                                </div>
                                <div class="card-body">
                                    <div class="row">
                                        <div class="col-md-6">
                                            <p><strong>Куратор группы:</strong>
                                                <?php if ($group_info['curator_first_name']): ?>
                                                    <?php echo htmlspecialchars($group_info['curator_first_name'] . ' ' . $group_info['curator_last_name'] . ' ' . $group_info['curator_middle_name']); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Не назначен</span>
                                                <?php endif; ?>
                                            </p>
                                            <p><strong>Статус группы:</strong>
                                                <span class="badge bg-<?php echo $group_info['is_active'] ? 'success' : 'danger'; ?>">
                                                    <?php echo $group_info['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                                </span>
                                            </p>
                                        </div>
                                        <div class="col-md-6">
                                            <p><strong>Дата создания:</strong> <?php echo formatDate($group_info['created_at']); ?></p>
                                            <p><strong>Последнее обновление:</strong> <?php echo formatDate($group_info['updated_at']); ?></p>
                                        </div>
                                    </div>
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
</body>

</html>