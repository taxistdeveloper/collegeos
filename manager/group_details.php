<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_reports');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение ID группы из параметра
$group_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$group_id) {
    header('Location: groups.php');
    exit;
}

// Получение информации о группе
$group_info = $group->getGroupById($group_id);
if (!$group_info) {
    header('Location: groups.php');
    exit;
}

$db = getDB();

// Получение студентов группы
$graduated = sqlGraduatedCondition('s');
$graduating = sqlGraduatingStudentCondition('s');
$sql = "SELECT s.*, 
               CASE WHEN s.academic_leave = 1 THEN 'academic_leave'
                    WHEN $graduated THEN 'graduated'
                    WHEN $graduating THEN 'graduating'
                    ELSE 'active' END as status
        FROM students s 
        WHERE s.group_id = ? 
        ORDER BY s.first_name, s.middle_name";

$stmt = $db->prepare($sql);
$stmt->bind_param("i", $group_id);
$stmt->execute();
$result = $stmt->get_result();
$students = $result->fetch_all(MYSQLI_ASSOC);

// Статистика студентов
$stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0,
    'disabled' => 0,
    'orphan' => 0,
    'without_parental_care' => 0,
    'minors' => 0
];

foreach ($students as $student) {
    switch ($student['status']) {
        case 'active':
        case 'graduating':
            $stats['active']++;
            break;
        case 'academic_leave':
            $stats['academic_leave']++;
            break;
        case 'graduated':
            $stats['graduated']++;
            break;
    }

    if ($student['gender'] === 'мужской') {
        $stats['male']++;
    } elseif ($student['gender'] === 'женский') {
        $stats['female']++;
    }

    if ($student['disability']) {
        $stats['disabled']++;
    }

    if ($student['orphan']) {
        $stats['orphan']++;
    }

    if ($student['without_parental_care']) {
        $stats['without_parental_care']++;
    }

    // Подсчет несовершеннолетних
    if ($student['birth_date']) {
        $birth_year = date('Y', strtotime($student['birth_date']));
        $current_year = date('Y');
        $age = $current_year - $birth_year;
        if ($age < 18) {
            $stats['minors']++;
        }
    }
}

// Заполненность группы
$occupancy_percentage = $group_info['max_students'] > 0 ? round(($stats['total'] / $group_info['max_students']) * 100, 1) : 0;

// Получение списка несовершеннолетних студентов
$minors = [];
foreach ($students as $student) {
    if ($student['birth_date']) {
        $birth_year = date('Y', strtotime($student['birth_date']));
        $current_year = date('Y');
        $age = $current_year - $birth_year;
        if ($age < 18) {
            $student['age'] = $age;
            $minors[] = $student;
        }
    }
}

// Получение информации о кураторе
$curator_info = null;
if ($group_info['curator_id']) {
    $curator_info = $user->getUserById($group_info['curator_id']);
}

// Получение недавней активности (если есть логи)
$recent_activity = []; // Можно расширить в будущем для логирования действий

$page_title = 'Группа: ' . ($group_info['name'] ?? '');
$page_subtitle = 'Информация о группе и студентах';
$document_title = $page_title;
include 'includes/layout_start.php';
?>

<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
            <div class="text-muted small">
                <code><?php echo htmlspecialchars($group_info['code'] ?? ''); ?></code>
                · <?php echo htmlspecialchars($group_info['specialty'] ?? ''); ?>
                · <?php echo htmlspecialchars($group_info['course'] ?? ''); ?>
            </div>
            <div class="manager-action-buttons">
                <a href="group_reports.php?id=<?php echo $group_id; ?>" class="btn btn-success btn-sm">
                    <i class="bi bi-graph-up me-1"></i>Отчёты
                </a>
                <a href="students.php?group=<?php echo $group_id; ?>" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-people me-1"></i>Студенты
                </a>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
                    <i class="bi bi-printer me-1"></i>Печать
                </button>
                <a href="groups.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-arrow-left me-1"></i>Назад
                </a>
            </div>
        </div>

        <div class="row">
            <!-- Основная информация о группе -->
            <div class="col-md-8">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Основная информация
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td class="fw-bold">Название группы:</td>
                                        <td><?php echo htmlspecialchars($group_info['name']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Код группы:</td>
                                        <td><code><?php echo htmlspecialchars($group_info['code']); ?></code></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Специальность:</td>
                                        <td><?php echo htmlspecialchars($group_info['specialty']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Квалификация:</td>
                                        <td><?php echo htmlspecialchars($group_info['qualification']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Курс:</td>
                                        <td><span class="badge bg-info"><?php echo htmlspecialchars($group_info['course']); ?></span></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Язык обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['language']); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-borderless">
                                    <tr>
                                        <td class="fw-bold">Форма обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['study_form']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Срок обучения:</td>
                                        <td><?php echo htmlspecialchars($group_info['study_duration']); ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата начала:</td>
                                        <td><?php echo $group_info['start_date'] ? date('d.m.Y', strtotime($group_info['start_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата окончания:</td>
                                        <td><?php echo $group_info['end_date'] ? date('d.m.Y', strtotime($group_info['end_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Дата прибытия:</td>
                                        <td><?php echo $group_info['arrival_date'] ? date('d.m.Y', strtotime($group_info['arrival_date'])) : '—'; ?></td>
                                    </tr>
                                    <tr>
                                        <td class="fw-bold">Приказ о зачислении:</td>
                                        <td><?php echo htmlspecialchars($group_info['enrollment_order_number'] ?: '—'); ?></td>
                                    </tr>
                                </table>
                            </div>
                        </div>

                        <?php if ($group_info['description']): ?>
                            <div class="row mt-3">
                                <div class="col-12">
                                    <h6>Описание:</h6>
                                    <p class="text-muted"><?php echo nl2br(htmlspecialchars($group_info['description'])); ?></p>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Статистика студентов -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-mortarboard me-2"></i>
                            Статистика студентов
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row g-3 mb-3">
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-primary mb-1"><?php echo $stats['total']; ?></h5>
                                    <small>Всего студентов</small>
                                    <div><small class="text-muted">из <?php echo $group_info['max_students']; ?> мест</small></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-success mb-1"><?php echo $stats['active']; ?></h5>
                                    <small>Активных</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-warning mb-1"><?php echo $stats['academic_leave']; ?></h5>
                                    <small>В академ. отпуске</small>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="stat-box border">
                                    <h5 class="text-info mb-1"><?php echo $occupancy_percentage; ?>%</h5>
                                    <small>Заполненность</small>
                                    <div class="progress mt-2" style="height: 6px;">
                                        <div class="progress-bar bg-<?php echo $occupancy_percentage > 80 ? 'success' : ($occupancy_percentage > 60 ? 'warning' : 'danger'); ?>"
                                            style="width: <?php echo $occupancy_percentage; ?>%"></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Дополнительная статистика -->
                        <div class="d-flex flex-wrap gap-2">
                            <span class="badge bg-primary"><?php echo $stats['male']; ?> М</span>
                            <span class="badge bg-secondary"><?php echo $stats['female']; ?> Ж</span>
                            <span class="badge bg-warning text-dark"><?php echo $stats['disabled']; ?> <i class="bi bi-wheelchair"></i></span>
                            <span class="badge bg-info"><?php echo $stats['orphan']; ?> <i class="bi bi-heart"></i></span>
                            <span class="badge bg-danger"><?php echo $stats['without_parental_care']; ?> <i class="bi bi-exclamation-triangle"></i></span>
                            <span class="badge bg-dark"><?php echo $stats['graduated']; ?> <i class="bi bi-award"></i></span>
                            <span class="badge bg-warning text-dark"><?php echo $stats['minors']; ?> <i class="bi bi-calendar-minus"></i></span>
                        </div>
                    </div>
                </div>

                <!-- Несовершеннолетние студенты -->
                <?php if (!empty($minors)): ?>
                    <div class="card mb-3">
                        <div class="card-header" data-bs-toggle="collapse" data-bs-target="#minorsCollapse" style="cursor:pointer;" aria-expanded="false" aria-controls="minorsCollapse">
                            <h6 class="mb-0 d-flex align-items-center justify-content-between">
                                <span>
                                    <i class="bi bi-calendar-minus text-warning me-2"></i>
                                    Несовершеннолетние студенты (<?php echo count($minors); ?>)
                                </span>
                                <span class="ms-2">
                                    <i class="bi bi-caret-down collapse-icon" id="minorsCollapseIcon"></i>
                                </span>
                            </h6>
                        </div>
                        <div class="collapse" id="minorsCollapse">
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead>
                                            <tr>
                                                <th>ФИО</th>
                                                <th>ИИН</th>
                                                <th>Возраст</th>
                                                <th>Дата рождения</th>
                                                <th>Пол</th>
                                                <th>Статус</th>
                                                <th>Особенности</th>
                                                <th>Контакты</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($minors as $minor): ?>
                                                <tr>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($minor['last_name'] . ' ' . $minor['first_name'] . ' ' . $minor['middle_name']); ?></strong>
                                                    </td>
                                                    <td>
                                                        <code><?php echo htmlspecialchars($minor['iin']); ?></code>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-warning"><?php echo $minor['age']; ?> лет</span>
                                                    </td>
                                                    <td>
                                                        <?php echo date('d.m.Y', strtotime($minor['birth_date'])); ?>
                                                    </td>
                                                    <td>
                                                        <span class="badge bg-secondary"><?php echo htmlspecialchars($minor['gender']); ?></span>
                                                    </td>
                                                    <td>
                                                        <?php
                                                        echo '<span class="' . getStudentStatusBadgeClass($minor['status']) . '">'
                                                            . htmlspecialchars(getStudentStatusLabel($minor['status']))
                                                            . '</span>';
                                                        ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($minor['disability']): ?>
                                                            <i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>
                                                        <?php endif; ?>
                                                        <?php if ($minor['orphan']): ?>
                                                            <i class="bi bi-heart text-info" title="Сирота"></i>
                                                        <?php endif; ?>
                                                        <?php if ($minor['without_parental_care']): ?>
                                                            <i class="bi bi-exclamation-triangle text-danger" title="Без попечения родителей"></i>
                                                        <?php endif; ?>
                                                        <?php if (!$minor['disability'] && !$minor['orphan'] && !$minor['without_parental_care']): ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?php if ($minor['phone']): ?>
                                                            <a href="tel:<?php echo htmlspecialchars($minor['phone']); ?>" class="text-decoration-none">
                                                                <i class="bi bi-phone"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if ($minor['email']): ?>
                                                            <a href="mailto:<?php echo htmlspecialchars($minor['email']); ?>" class="text-decoration-none ms-1">
                                                                <i class="bi bi-envelope"></i>
                                                            </a>
                                                        <?php endif; ?>
                                                        <?php if (!$minor['phone'] && !$minor['email']): ?>
                                                            —
                                                        <?php endif; ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function() {
                            var collapseEl = document.getElementById('minorsCollapse');
                            var iconEl = document.getElementById('minorsCollapseIcon');
                            var headerEl = collapseEl.previousElementSibling;

                            // Set default (collapsed)
                            collapseEl.classList.remove('show');
                            if (iconEl) iconEl.classList.remove('bi-caret-up');
                            if (iconEl) iconEl.classList.add('bi-caret-down');

                            // Toggle icon on collapse events
                            collapseEl.addEventListener('show.bs.collapse', function() {
                                if (iconEl) {
                                    iconEl.classList.remove('bi-caret-down');
                                    iconEl.classList.add('bi-caret-up');
                                }
                            });
                            collapseEl.addEventListener('hide.bs.collapse', function() {
                                if (iconEl) {
                                    iconEl.classList.remove('bi-caret-up');
                                    iconEl.classList.add('bi-caret-down');
                                }
                            });
                        });
                    </script>
                <?php endif; ?>

                <!-- Список студентов -->
                <div class="card">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="mb-0">
                            <i class="bi bi-people me-2"></i>
                            Список студентов (<?php echo count($students); ?>)
                        </h6>
                        <a href="students.php?group=<?php echo $group_id; ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-arrow-right me-1"></i>Подробнее
                        </a>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>ФИО</th>
                                        <th>ИИН</th>
                                        <th>Пол</th>
                                        <th>Статус</th>
                                        <th>Особенности</th>
                                        <th>Контакты</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach (array_slice($students, 0, 10) as $student): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($student['last_name'] . ' ' . $student['first_name'] . ' ' . $student['middle_name']); ?></strong>
                                            </td>
                                            <td>
                                                <code><?php echo htmlspecialchars($student['iin']); ?></code>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($student['gender']); ?></span>
                                            </td>
                                            <td>
                                                <?php
                                                echo '<span class="' . getStudentStatusBadgeClass($student['status']) . '">'
                                                    . htmlspecialchars(getStudentStatusLabel($student['status']))
                                                    . '</span>';
                                                ?>
                                            </td>
                                            <td>
                                                <?php if ($student['disability']): ?>
                                                    <i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>
                                                <?php endif; ?>
                                                <?php if ($student['orphan']): ?>
                                                    <i class="bi bi-heart text-info" title="Сирота"></i>
                                                <?php endif; ?>
                                                <?php if ($student['without_parental_care']): ?>
                                                    <i class="bi bi-exclamation-triangle text-danger" title="Без попечения родителей"></i>
                                                <?php endif; ?>
                                                <?php if (!$student['disability'] && !$student['orphan'] && !$student['without_parental_care']): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($student['phone']): ?>
                                                    <a href="tel:<?php echo htmlspecialchars($student['phone']); ?>" class="text-decoration-none">
                                                        <i class="bi bi-phone"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if ($student['email']): ?>
                                                    <a href="mailto:<?php echo htmlspecialchars($student['email']); ?>" class="text-decoration-none ms-1">
                                                        <i class="bi bi-envelope"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <?php if (!$student['phone'] && !$student['email']): ?>
                                                    —
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>

                                    <?php if (count($students) > 10): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">
                                                <em>И еще <?php echo count($students) - 10; ?> студентов...
                                                    <a href="students.php?group=<?php echo $group_id; ?>">Показать всех</a></em>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Боковая панель -->
            <div class="col-md-4">
                <!-- Информация о кураторе -->
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-person-heart me-2"></i>
                            Куратор группы
                        </h6>
                    </div>
                    <div class="card-body">
                        <?php if ($curator_info): ?>
                            <div class="text-center">
                                <div class="mb-3">
                                    <i class="bi bi-person-circle text-primary" style="font-size: 3rem;"></i>
                                </div>
                                <h6><?php echo htmlspecialchars($curator_info['first_name'] . ' ' . $curator_info['last_name']); ?></h6>
                                <?php if ($curator_info['middle_name']): ?>
                                    <p class="text-muted mb-2"><?php echo htmlspecialchars($curator_info['middle_name']); ?></p>
                                <?php endif; ?>

                                <?php if ($curator_info['email']): ?>
                                    <p class="mb-1">
                                        <i class="bi bi-envelope me-1"></i>
                                        <a href="mailto:<?php echo htmlspecialchars($curator_info['email']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($curator_info['email']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>

                                <?php if ($curator_info['phone']): ?>
                                    <p class="mb-0">
                                        <i class="bi bi-phone me-1"></i>
                                        <a href="tel:<?php echo htmlspecialchars($curator_info['phone']); ?>" class="text-decoration-none">
                                            <?php echo htmlspecialchars($curator_info['phone']); ?>
                                        </a>
                                    </p>
                                <?php endif; ?>
                            </div>
                        <?php else: ?>
                            <div class="text-center text-muted">
                                <i class="bi bi-person-x fs-1 mb-3"></i>
                                <p>Куратор не назначен</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>



                <!-- Статус группы -->
                <div class="card">
                    <div class="card-header">
                        <h6 class="mb-0">
                            <i class="bi bi-info-circle me-2"></i>
                            Статус группы
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Статус:</span>
                                <span class="badge bg-<?php echo $group_info['is_active'] ? 'success' : 'danger'; ?>">
                                    <?php echo $group_info['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                </span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Заполненность:</span>
                                <span class="fw-bold"><?php echo $occupancy_percentage; ?>%</span>
                            </div>
                            <div class="progress mt-1" style="height: 8px;">
                                <div class="progress-bar <?php echo $occupancy_percentage > 80 ? 'bg-success' : ($occupancy_percentage > 60 ? 'bg-warning' : 'bg-danger'); ?>"
                                    style="width: <?php echo $occupancy_percentage; ?>%"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Студентов:</span>
                                <span class="fw-bold"><?php echo $stats['total']; ?> / <?php echo $group_info['max_students']; ?></span>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <span>Свободных мест:</span>
                                <span class="fw-bold"><?php echo max(0, $group_info['max_students'] - $stats['total']); ?></span>
                            </div>
                        </div>

                        <?php if ($group_info['start_date']): ?>
                            <div class="mb-0">
                                <div class="d-flex justify-content-between align-items-center">
                                    <span>Дата создания:</span>
                                    <span class="text-muted"><?php echo date('d.m.Y', strtotime($group_info['start_date'])); ?></span>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

<script>
        function scrollToMinors() {
            const minorsSection = document.querySelector('.card:has(.bi-calendar-minus)');
            if (minorsSection) {
                minorsSection.scrollIntoView({
                    behavior: 'smooth'
                });
            }
        }

        function exportGroupData() {
            // Простой экспорт таблицы студентов
            const table = document.querySelector('.table');
            if (!table) {
                alert('Нет данных для экспорта');
                return;
            }

            let csv = '\uFEFF'; // UTF-8 BOM
            csv += 'Группа: <?php echo htmlspecialchars($group_info['name']); ?>\n';
            csv += 'Код: <?php echo htmlspecialchars($group_info['code']); ?>\n';
            csv += 'Всего студентов: <?php echo $stats['total']; ?>\n\n';

            const rows = table.querySelectorAll('tbody tr');
            csv += 'ФИО,ИИН,Пол,Статус\n';

            rows.forEach(row => {
                const cells = row.querySelectorAll('td');
                if (cells.length >= 4) {
                    const rowData = [];
                    for (let i = 0; i < 4; i++) {
                        let text = cells[i].textContent.trim().replace(/\s+/g, ' ').trim();
                        text = text.replace(/"/g, '""');
                        rowData.push('"' + text + '"');
                    }
                    csv += rowData.join(',') + '\n';
                }
            });

            const blob = new Blob([csv], {
                type: 'text/csv;charset=utf-8;'
            });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            link.setAttribute('href', url);
            link.setAttribute('download', 'group_<?php echo $group_info['code']; ?>_' + new Date().toISOString().slice(0, 10) + '.csv');
            link.style.visibility = 'hidden';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
    </script>
<?php include 'includes/layout_end.php'; ?>
