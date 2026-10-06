<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/student_status.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$group = new Group();
$db = getDB();

if (!function_exists('adminCurrentAcademicYear')) {
    function adminCurrentAcademicYear()
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        return ($month >= 1 && $month <= 7) ? ($year - 1) : $year;
    }

    function adminAcademicYearLabel($yearStart)
    {
        $yearStart = (int)$yearStart;
        return $yearStart . '–' . ($yearStart + 1);
    }

    function adminGroupAdmissionYear($item)
    {
        return Group::admissionYearFromText($item['code'] ?? '', $item['name'] ?? '');
    }

    function adminGroupInAcademicYear($item, $yearStart)
    {
        $admissionYear = adminGroupAdmissionYear($item);
        return $admissionYear !== null && (int)$admissionYear === (int)$yearStart;
    }
}

$all_groups = $group->getAllGroups();
$total_all_students = 0;
$count_all = $db->query('SELECT COUNT(*) AS cnt FROM students');
if ($count_all) {
    $total_all_students = (int)($count_all->fetch_assoc()['cnt'] ?? 0);
}

$current_academic_year = adminCurrentAcademicYear();
$year_filter = isset($_GET['year']) ? trim((string)$_GET['year']) : (string)$current_academic_year;
if ($year_filter !== 'all' && !preg_match('/^\d{4}$/', $year_filter)) {
    $year_filter = (string)$current_academic_year;
}
$status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$group_filter = isset($_GET['group']) ? (int)$_GET['group'] : 0;
$course_filter = isset($_GET['course']) ? trim((string)$_GET['course']) : '';
$gender_filter = isset($_GET['gender']) ? trim((string)$_GET['gender']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$year_options = [];
for ($y = $current_academic_year + 1; $y >= $current_academic_year - 5; $y--) {
    $year_options[$y] = adminAcademicYearLabel($y);
}
$year_label = $year_filter !== 'all' ? adminAcademicYearLabel((int)$year_filter) : 'Все годы';

$year_filter_is_default = $year_filter === (string)$current_academic_year;
$filters_active = !$year_filter_is_default || $group_filter > 0 || $course_filter !== '' || $status_filter !== '' || $gender_filter !== '' || $search !== '';

$groups_for_filter = $all_groups;
if ($year_filter !== 'all') {
    $year_start = (int)$year_filter;
    $groups_for_filter = array_values(array_filter($groups_for_filter, function ($item) use ($year_start) {
        return adminGroupInAcademicYear($item, $year_start);
    }));
}
usort($groups_for_filter, function ($a, $b) {
    return strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? ''));
});

$year_group_ids = null;
if ($year_filter !== 'all') {
    $year_start = (int)$year_filter;
    $year_group_ids = [];
    foreach ($all_groups as $item) {
        if (adminGroupInAcademicYear($item, $year_start)) {
            $year_group_ids[] = (int)$item['id'];
        }
    }
}

$students = [];
if ($year_group_ids !== null && empty($year_group_ids)) {
    $students = [];
} else {
    $sql = "SELECT s.*, g.name as group_name, g.code as group_code, g.course, g.specialty, g.language, g.study_form,
                   g.is_active as group_is_active,
                   u.first_name as curator_first_name, u.last_name as curator_last_name
            FROM students s
            LEFT JOIN `groups` g ON s.group_id = g.id
            LEFT JOIN users u ON g.curator_id = u.id
            WHERE 1=1";

    $params = [];
    $types = '';

    if ($year_group_ids !== null) {
        $placeholders = implode(',', array_fill(0, count($year_group_ids), '?'));
        $sql .= " AND s.group_id IN ($placeholders)";
        foreach ($year_group_ids as $gid) {
            $params[] = $gid;
            $types .= 'i';
        }
    }

    if ($status_filter === 'active') {
        $sql .= " AND s.academic_leave = 0 AND " . sqlNotGraduatedCondition('s');
    } elseif ($status_filter === 'academic_leave') {
        $sql .= " AND s.academic_leave = 1";
    } elseif ($status_filter === 'graduated') {
        $sql .= " AND " . sqlGraduatedCondition('s');
    }

    if ($group_filter > 0) {
        $sql .= " AND s.group_id = ?";
        $params[] = $group_filter;
        $types .= 'i';
    }

    if ($course_filter !== '') {
        $sql .= " AND g.course = ?";
        $params[] = $course_filter;
        $types .= 's';
    }

    if ($gender_filter !== '') {
        $sql .= " AND s.gender = ?";
        $params[] = $gender_filter;
        $types .= 's';
    }

    if ($search !== '') {
        $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ?)";
        $search_param = '%' . $search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $params[] = $search_param;
        $types .= 'ssss';
    }

    $sql .= " ORDER BY s.last_name, s.first_name, s.middle_name";

    $stmt = $db->prepare($sql);
    if ($stmt) {
        if ($types !== '') {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $result = $stmt->get_result();
        $students = $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

$student_stats = [
    'total' => count($students),
    'active' => 0,
    'academic_leave' => 0,
    'graduated' => 0,
    'male' => 0,
    'female' => 0
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

$per_page = 20;
$total_filtered = count($students);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;
$students_page = array_slice($students, $offset, $per_page);
$shown_from = $total_filtered === 0 ? 0 : $offset + 1;
$shown_to = min($offset + count($students_page), $total_filtered);

$filter_query = [];
if (!$year_filter_is_default) {
    $filter_query['year'] = $year_filter;
}
if ($group_filter > 0) {
    $filter_query['group'] = $group_filter;
}
if ($course_filter !== '') {
    $filter_query['course'] = $course_filter;
}
if ($status_filter !== '') {
    $filter_query['status'] = $status_filter;
}
if ($gender_filter !== '') {
    $filter_query['gender'] = $gender_filter;
}
if ($search !== '') {
    $filter_query['search'] = $search;
}

$studentsPageUrl = function ($page) use ($filter_query) {
    $query = $filter_query;
    if ((int)$page > 1) {
        $query['page'] = (int)$page;
    }
    $qs = http_build_query($query);
    return 'students.php' . ($qs !== '' ? '?' . $qs : '');
};

$export_rows = [];
foreach ($students as $student) {
    $status = getStudentStatus($student);
    $status_label = getStudentStatusLabel($status);
    if ($status === 'academic_leave') {
        $status_label = 'Академ.';
    }
    $export_rows[] = [
        'fio' => trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? '')),
        'iin' => $student['iin'] ?? '',
        'group' => $student['group_name'] ?? '',
        'course' => $student['course'] ?? '',
        'gender' => $student['gender'] ?? '',
        'status' => $status_label,
        'phone' => $student['phone'] ?? '',
        'email' => $student['email'] ?? '',
    ];
}

$page_title = 'Управление студентами';
$active_page = 'students';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Студенты</h1>
        <p class="page-subtitle">Учебный год <?php echo htmlspecialchars($year_label); ?></p>
    </div>
    <div class="page-actions">
        <button class="btn btn-success" onclick="exportStudents()">
            <i class="bi bi-download"></i>
            <span>Экспорт</span>
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#importStudentsModal">
            <i class="bi bi-upload"></i>
            <span>Импорт</span>
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-people-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['total']; ?></div>
            <div class="stat-label">Всего студентов</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon green">
            <i class="bi bi-person-check-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['active']; ?></div>
            <div class="stat-label">Активных</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-pause-circle-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['academic_leave']; ?></div>
            <div class="stat-label">Академ. отпуск</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-mortarboard-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['graduated']; ?></div>
            <div class="stat-label">Выпускников</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon purple">
            <i class="bi bi-gender-male"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['male']; ?></div>
            <div class="stat-label">Мужчин</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon" style="background: rgba(236, 72, 153, 0.1); color: #ec4899;">
            <i class="bi bi-gender-female"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo $student_stats['female']; ?></div>
            <div class="stat-label">Женщин</div>
        </div>
    </div>
</div>

<!-- Filters -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-funnel"></i>
            Фильтры и поиск
        </h2>
    </div>
    <div class="card-body">
        <form method="GET" action="students.php">
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">Учебный год</label>
                    <select class="form-select" name="year">
                        <option value="all" <?php echo $year_filter === 'all' ? 'selected' : ''; ?>>Все годы</option>
                        <?php foreach ($year_options as $year_value => $year_option_label): ?>
                            <option value="<?php echo (int)$year_value; ?>" <?php echo $year_filter === (string)$year_value ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($year_option_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Группа</label>
                    <select class="form-select" name="group">
                        <option value="">Все группы</option>
                        <?php foreach ($groups_for_filter as $filter_group): ?>
                            <option value="<?php echo (int)$filter_group['id']; ?>" <?php echo $group_filter === (int)$filter_group['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($filter_group['name']); ?>
                                <?php if (!empty($filter_group['code'])): ?>
                                    (<?php echo htmlspecialchars($filter_group['code']); ?>)
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
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
                    <label class="form-label">Статус</label>
                    <select class="form-select" name="status">
                        <option value="">Все статусы</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                        <option value="academic_leave" <?php echo $status_filter === 'academic_leave' ? 'selected' : ''; ?>>Академ. отпуск</option>
                        <option value="graduated" <?php echo $status_filter === 'graduated' ? 'selected' : ''; ?>>Выпускники</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Пол</label>
                    <select class="form-select" name="gender">
                        <option value="">Все</option>
                        <option value="мужской" <?php echo $gender_filter === 'мужской' ? 'selected' : ''; ?>>Мужской</option>
                        <option value="женский" <?php echo $gender_filter === 'женский' ? 'selected' : ''; ?>>Женский</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" name="search" placeholder="ФИО или ИИН" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Применить
                </button>
                <?php if ($filters_active): ?>
                    <a href="students.php" class="btn btn-outline">
                        <i class="bi bi-x-circle"></i> Сбросить
                    </a>
                    <span class="text-secondary ms-2" style="font-size: 0.875rem;">
                        Найдено: <?php echo $total_filtered; ?> из <?php echo $total_all_students; ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

    <!-- Students Table -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="bi bi-list"></i>
                Список студентов
                <?php if ($year_filter !== 'all'): ?>
                    <span class="badge badge-primary" style="margin-left: 0.35rem; text-transform: none; letter-spacing: 0;"><?php echo htmlspecialchars($year_label); ?></span>
                <?php endif; ?>
            </h2>
            <span class="badge badge-primary"><?php echo $total_filtered; ?></span>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-wrapper">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>ФИО</th>
                            <th>ИИН</th>
                            <th>Группа</th>
                            <th>Курс</th>
                            <th>Пол</th>
                            <th>Статус</th>
                            <th>Контакты</th>
                            <th style="width: 120px;">Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($students_page as $student):
                            $status = getStudentStatus($student);
                            $is_graduated = ($status === 'graduated');
                            $student_fio = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                            if ($student_fio === '') {
                                $student_fio = trim(($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
                            }
                            $initials = mb_substr($student['last_name'] ?? $student['first_name'] ?? '', 0, 1) . mb_substr($student['first_name'] ?? $student['middle_name'] ?? '', 0, 1);
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="list-avatar" style="width: 32px; height: 32px; font-size: 0.75rem;">
                                            <?php echo htmlspecialchars($initials); ?>
                                        </div>
                                        <div>
                                            <div style="font-weight: 500;">
                                                <?php echo htmlspecialchars($student_fio); ?>
                                            </div>
                                            <?php if ($student['disability'] || $student['orphan']): ?>
                                                <div>
                                                    <?php if ($student['disability']): ?>
                                                        <i class="bi bi-wheelchair text-warning" title="Инвалидность"></i>
                                                    <?php endif; ?>
                                                    <?php if ($student['orphan']): ?>
                                                        <i class="bi bi-heart text-info" title="Сирота"></i>
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($student['iin']); ?>
                                    </code>
                                </td>
                                <td>
                                    <?php if (!empty($student['group_name'])): ?>
                                        <span class="badge badge-primary"><?php echo htmlspecialchars($student['group_name']); ?></span>
                                    <?php else: ?>
                                        <span style="color: var(--text-secondary); font-size: 0.875rem;">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($student['course']); ?></span>
                                </td>
                                <td style="font-size: 0.8125rem;">
                                    <?php echo htmlspecialchars($student['gender']); ?>
                                </td>
                                <td>
                                    <span class="<?php echo getStudentStatusBadgeClass($status, 'admin'); ?>">
                                        <?php echo htmlspecialchars($status === 'academic_leave' ? 'Академ.' : getStudentStatusLabel($status)); ?>
                                    </span>
                                </td>
                                <td style="font-size: 0.8125rem;">
                                    <?php if ($student['phone']): ?>
                                        <div><i class="bi bi-telephone me-1"></i><?php echo htmlspecialchars($student['phone']); ?></div>
                                    <?php endif; ?>
                                    <?php if ($student['email']): ?>
                                        <div><i class="bi bi-envelope me-1"></i><?php echo htmlspecialchars($student['email']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <a href="../curator/view_student.php?id=<?php echo (int)$student['id']; ?>&from=admin" class="btn btn-icon btn-sm btn-outline" title="Просмотр">
                                            <i class="bi bi-eye"></i>
                                        </a>
                                        <a href="../curator/edit_student_new.php?id=<?php echo (int)$student['id']; ?>&from=admin" class="btn btn-icon btn-sm btn-outline" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                                onclick="deleteStudent(<?php echo (int)$student['id']; ?>, <?php echo htmlspecialchars(json_encode($student_fio), ENT_QUOTES); ?>)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($students)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                    <i class="bi bi-people fs-1 d-block mb-2"></i>
                                    <?php
                                    $year_only_empty = $year_filter !== 'all'
                                        && $group_filter === 0
                                        && $course_filter === ''
                                        && $status_filter === ''
                                        && $gender_filter === ''
                                        && $search === '';
                                    ?>
                                    <?php if ($year_only_empty): ?>
                                        Нет студентов за учебный год <?php echo htmlspecialchars($year_label); ?>
                                    <?php else: ?>
                                        Студенты не найдены
                                    <?php endif; ?>
                                    <?php if ($filters_active): ?>
                                        <div class="mt-2">
                                            <a href="students.php" class="btn btn-outline btn-sm">Сбросить фильтры</a>
                                        </div>
                                    <?php else: ?>
                                        <div class="mt-2">
                                            <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#importStudentsModal">
                                                <i class="bi bi-upload me-1"></i>Импортировать студентов
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if ($total_filtered > 0): ?>
            <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span style="font-size: 0.875rem; color: var(--text-secondary);">
                    Показано <?php echo $shown_from; ?>–<?php echo $shown_to; ?> из <?php echo $total_filtered; ?>
                </span>
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="Страницы студентов">
                        <div class="d-flex gap-1 flex-wrap align-items-center">
                            <?php if ($current_page > 1): ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($studentsPageUrl($current_page - 1)); ?>" title="Назад">
                                    <i class="bi bi-chevron-left"></i>
                                </a>
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline" style="opacity: 0.5; pointer-events: none;">
                                    <i class="bi bi-chevron-left"></i>
                                </span>
                            <?php endif; ?>

                            <?php
                            $page_window = 2;
                            $start_page = max(1, $current_page - $page_window);
                            $end_page = min($total_pages, $current_page + $page_window);
                            ?>

                            <?php if ($start_page > 1): ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($studentsPageUrl(1)); ?>">1</a>
                                <?php if ($start_page > 2): ?>
                                    <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                                <?php if ($p === $current_page): ?>
                                    <span class="btn btn-sm btn-primary"><?php echo $p; ?></span>
                                <?php else: ?>
                                    <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($studentsPageUrl($p)); ?>"><?php echo $p; ?></a>
                                <?php endif; ?>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                                <?php endif; ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($studentsPageUrl($total_pages)); ?>"><?php echo $total_pages; ?></a>
                            <?php endif; ?>

                            <?php if ($current_page < $total_pages): ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($studentsPageUrl($current_page + 1)); ?>" title="Вперёд">
                                    <i class="bi bi-chevron-right"></i>
                                </a>
                            <?php else: ?>
                                <span class="btn btn-sm btn-outline" style="opacity: 0.5; pointer-events: none;">
                                    <i class="bi bi-chevron-right"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                    </nav>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

<!-- Import Modal -->
<div class="modal fade" id="importStudentsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Импорт студентов из CSV</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div style="background: rgba(6, 182, 212, 0.1); color: var(--info); padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem;">
                    <strong><i class="bi bi-info-circle me-2"></i>Инструкции:</strong>
                    <ul style="margin: 0.5rem 0 0 1rem; padding: 0;">
                        <li>Поддерживаются файлы CSV (.csv)</li>
                        <li>Первая строка должна содержать заголовки</li>
                        <li>Обязательное поле: ИИН (12 цифр)</li>
                    </ul>
                </div>
                
                <div class="mb-3">
                    <label class="form-label">Выберите файл для импорта</label>
                    <input type="file" class="form-control" id="csvFile" accept=".csv">
                    <small class="text-muted">Формат: CSV с разделителем ; или ,</small>
                </div>
                
                <button type="button" class="btn btn-outline" onclick="downloadCSVTemplate()">
                    <i class="bi bi-download"></i> Скачать шаблон
                </button>
                
                <div id="importProgress" class="mt-3"></div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                <button type="button" class="btn btn-primary" onclick="handleCSVImport()">
                    <i class="bi bi-upload"></i> Импортировать
                </button>
            </div>
        </div>
    </div>
</div>

<script>
    const EXPORT_STUDENTS = <?php echo json_encode($export_rows, JSON_UNESCAPED_UNICODE); ?>;

    function exportStudents() {
        if (!EXPORT_STUDENTS.length) {
            alert('Нет данных для экспорта');
            return;
        }

        let csv = 'ФИО,ИИН,Группа,Курс,Пол,Статус,Телефон,Email\n';
        EXPORT_STUDENTS.forEach(function (row) {
            csv += [
                row.fio,
                row.iin,
                row.group,
                row.course,
                row.gender,
                row.status,
                row.phone,
                row.email
            ].map(function (value) {
                return '"' + String(value || '').replace(/"/g, '""') + '"';
            }).join(',') + '\n';
        });

        const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'students_' + new Date().toISOString().slice(0, 10) + '.csv';
        link.click();
    }
    
    function deleteStudent(studentId, studentName) {
        if (confirm(`Удалить студента "${studentName}"?`)) {
            const formData = new FormData();
            formData.append('id', studentId);
            
            fetch('../api/delete_student.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Студент удален');
                    location.reload();
                } else {
                    alert('Ошибка: ' + (data.error || 'Неизвестная ошибка'));
                }
            })
            .catch(error => {
                alert('Ошибка: ' + error.message);
            });
        }
    }
    
    function downloadCSVTemplate() {
        const csvContent = "iin;first_name;last_name;middle_name;nationality;phone;email\n" +
            "960325350262;Айдар;Нурланов;Айдарұлы;казах;87001234567;aidar@example.com";
        
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        link.href = URL.createObjectURL(blob);
        link.download = 'template_students.csv';
        link.click();
    }
    
    function handleCSVImport() {
        const fileInput = document.getElementById('csvFile');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Выберите файл');
            return;
        }
        
        const progressContainer = document.getElementById('importProgress');
        progressContainer.innerHTML = '<div style="background: var(--content-bg); border-radius: var(--radius); padding: 0.5rem;"><div style="background: var(--primary); height: 8px; border-radius: 9999px; width: 0%; transition: width 0.3s;"></div></div>';
        
        readStudentCsv(file).then(function (csv) {
            processCSVImport(csv, progressContainer);
        }).catch(function (error) {
            progressContainer.innerHTML = '<div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius);"><strong>' + error.message + '</strong></div>';
        });
    }

    function readStudentCsv(file) {
        return file.arrayBuffer().then(function (buffer) {
            const bytes = new Uint8Array(buffer);
            let start = 0;
            if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
                start = 3;
            }
            const slice = bytes.subarray(start);
            try {
                return new TextDecoder('utf-8', { fatal: true }).decode(slice);
            } catch (e) {
                return new TextDecoder('windows-1251').decode(slice);
            }
        });
    }
    
    function processCSVImport(csv, progressContainer) {
        try {
            const lines = csv.split('\n').filter(line => line.trim() !== '');
            if (lines.length < 2) throw new Error('Файл должен содержать данные');
            
            const delimiter = lines[0].includes(';') ? ';' : ',';
            const headers = lines[0].split(delimiter).map(h => h.replace(/"/g, '').trim());
            
            const validStudents = [];
            
            for (let i = 1; i < lines.length; i++) {
                const values = lines[i].split(delimiter).map(v => v.replace(/"/g, '').trim());
                const studentData = {};
                
                headers.forEach((header, index) => {
                    if (values[index]) studentData[header] = values[index];
                });
                
                let iin = studentData.iin || Object.values(studentData)[0];
                if (iin) studentData.iin = iin.replace(/\D/g, '');
                
                if (studentData.iin && studentData.iin.length === 12) {
                    validStudents.push(studentData);
                }
            }
            
            if (validStudents.length === 0) throw new Error('Нет валидных записей');
            
            progressContainer.querySelector('div > div').style.width = '50%';
            
            const formData = new FormData();
            formData.append('action', 'save_imported_data');
            formData.append('data', JSON.stringify(validStudents));
            
            fetch('../api/ultra_simple.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(result => {
                    if (!result.success) {
                        throw new Error(result.error || 'Не удалось сохранить импорт');
                    }
                    const saved = result.saved ?? validStudents.length;
                    const failed = result.errors || 0;
                    progressContainer.innerHTML = `
                        <div style="background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 1rem; border-radius: var(--radius);">
                            <strong><i class="bi bi-check-circle me-2"></i>Сохранено: ${saved}</strong>
                            <div>Новых: ${result.inserted || 0}, обновлено: ${result.updated || 0}${failed ? ', ошибок: ' + failed : ''}</div>
                        </div>
                    `;
                    
                    setTimeout(() => {
                        bootstrap.Modal.getInstance(document.getElementById('importStudentsModal')).hide();
                        location.reload();
                    }, 2000);
                })
                .catch(error => {
                    progressContainer.innerHTML = `
                        <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius);">
                            <strong><i class="bi bi-exclamation-triangle me-2"></i>${error.message}</strong>
                        </div>
                    `;
                });
        } catch (error) {
            progressContainer.innerHTML = `
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius);">
                    <strong><i class="bi bi-exclamation-triangle me-2"></i>${error.message}</strong>
                </div>
            `;
        }
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
