<?php
require_once '../config/config.php';
require_once '../classes/Group.php';
require_once '../classes/User.php';
require_once '../classes/Department.php';
require_once '../includes/student_status.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$group = new Group();
$user = new User();
$message = '';
$error = '';

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

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add':
                $data = [
                    'name' => sanitize($_POST['name'] ?? ''),
                    'code' => sanitize($_POST['code'] ?? ''),
                    'specialty' => sanitize($_POST['specialty'] ?? ''),
                    'qualification' => sanitize($_POST['qualification'] ?? ''),
                    'curator_id' => !empty($_POST['curator_id']) ? (int)$_POST['curator_id'] : null,
                    'course' => $_POST['course'] ?? '',
                    'language' => $_POST['language'] ?? '',
                    'study_form' => $_POST['study_form'] ?? '',
                    'study_duration' => $_POST['study_duration'] ?? '',
                    'start_date' => $_POST['start_date'] ?? '',
                    'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    'arrival_date' => $_POST['arrival_date'] ?? '',
                    'enrollment_order_number' => sanitize($_POST['enrollment_order_number'] ?? ''),
                    'max_students' => (int)($_POST['max_students'] ?? 0),
                    'description' => ''
                ];

                $admissionYear = Group::admissionYearFromText($data['code'], $data['name']);
                if ($admissionYear) {
                    $data['course'] = Group::courseLabelForAdmissionYear($admissionYear);
                }

                if ($group->isCodeUnique($data['code'])) {
                    if ($group->createGroup($data)) {
                        $message = 'Группа успешно создана!';
                        AdminLog::info('group_create', 'Создана группа «' . $data['name'] . '»', [
                            'code' => $data['code'],
                            'course' => $data['course'],
                            'study_duration' => $data['study_duration'],
                        ]);
                    } else {
                        $error = 'Ошибка при создании группы: ' . ($group->getLastError() ?: 'неизвестная ошибка');
                    }
                } else {
                    $error = 'Группа с таким кодом уже существует';
                    AdminLog::warning('group_create', $error, ['code' => $data['code']]);
                }
                break;

            case 'edit':
                $id = (int)$_POST['group_id'];
                $data = [
                    'name' => sanitize($_POST['name'] ?? ''),
                    'code' => sanitize($_POST['code'] ?? ''),
                    'specialty' => sanitize($_POST['specialty'] ?? ''),
                    'qualification' => sanitize($_POST['qualification'] ?? ''),
                    'curator_id' => !empty($_POST['curator_id']) ? (int)$_POST['curator_id'] : null,
                    'course' => $_POST['course'] ?? '',
                    'language' => $_POST['language'] ?? '',
                    'study_form' => $_POST['study_form'] ?? '',
                    'study_duration' => $_POST['study_duration'] ?? '',
                    'start_date' => $_POST['start_date'] ?? '',
                    'end_date' => !empty($_POST['end_date']) ? $_POST['end_date'] : null,
                    'arrival_date' => $_POST['arrival_date'] ?? '',
                    'enrollment_order_number' => sanitize($_POST['enrollment_order_number'] ?? ''),
                    'max_students' => (int)($_POST['max_students'] ?? 0),
                    'description' => ($group->getGroupById($id)['description'] ?? ''),
                    'is_active' => isset($_POST['is_active']) ? 1 : 0
                ];

                $admissionYear = Group::admissionYearFromText($data['code'], $data['name']);
                if ($admissionYear) {
                    $data['course'] = Group::courseLabelForAdmissionYear($admissionYear);
                }

                if ($group->isCodeUnique($data['code'], $id)) {
                    if ($group->updateGroup($id, $data)) {
                        $message = 'Группа успешно обновлена!';
                        AdminLog::info('group_update', 'Обновлена группа «' . $data['name'] . '»', [
                            'id' => $id,
                            'code' => $data['code'],
                        ]);
                    } else {
                        $error = 'Ошибка при обновлении группы: ' . ($group->getLastError() ?: 'неизвестная ошибка');
                    }
                } else {
                    $error = 'Группа с таким кодом уже существует';
                    AdminLog::warning('group_update', $error, ['id' => $id, 'code' => $data['code']]);
                }
                break;

            case 'delete':
                $id = (int)$_POST['group_id'];
                if ($group->deleteGroup($id)) {
                    $message = 'Группа успешно удалена!';
                    AdminLog::info('group_delete', 'Удалена группа ID ' . $id, ['id' => $id]);
                } else {
                    $error = 'Ошибка при удалении группы: ' . ($group->getLastError() ?: 'неизвестная ошибка');
                }
                break;
        }
    } catch (Throwable $e) {
        $error = 'Ошибка: ' . $e->getMessage();
        AdminLog::error('groups_page', $e->getMessage(), [
            'action' => $action,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);
    }
}

$courseSyncCount = $group->syncCoursesByAdmissionYear();
if ($courseSyncCount > 0 && $message === '') {
    $message = 'Курсы групп обновлены по году набора: ' . $courseSyncCount;
    AdminLog::info('group_course_sync', $message, ['updated' => $courseSyncCount]);
}

// Получение данных
$all_groups = $group->getAllGroups();
$curators = $user->getUsersByRole(4); // ID роли куратора
$departmentService = new Department();
$departments = $departmentService->getAllDepartments();

$current_academic_year = adminCurrentAcademicYear();
$year_filter = isset($_GET['year']) ? trim((string)$_GET['year']) : (string)$current_academic_year;
if ($year_filter !== 'all' && !preg_match('/^\d{4}$/', $year_filter)) {
    $year_filter = (string)$current_academic_year;
}
$group_filter = isset($_GET['group']) ? (int)$_GET['group'] : 0;
$course_filter = isset($_GET['course']) ? trim((string)$_GET['course']) : '';
$department_filter = isset($_GET['department']) ? (int)$_GET['department'] : 0;
$status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$year_options = [];
for ($y = $current_academic_year + 1; $y >= $current_academic_year - 5; $y--) {
    $year_options[$y] = adminAcademicYearLabel($y);
}
$year_label = $year_filter !== 'all' ? adminAcademicYearLabel((int)$year_filter) : 'Все годы';

$study_duration_options = [
    '10 мес.',
    '1 г. 6 мес.',
    '1 г. 10 мес.',
    '2 г. 6 мес.',
    '2 г. 10 мес.',
    '3 г. 6 мес.',
    '3 г. 10 мес.',
    '4 года',
];

$groups = $all_groups;

if ($year_filter !== 'all') {
    $year_start = (int)$year_filter;
    $groups = array_values(array_filter($groups, function ($item) use ($year_start) {
        return adminGroupInAcademicYear($item, $year_start);
    }));
}

if ($group_filter > 0) {
    $groups = array_values(array_filter($groups, function ($item) use ($group_filter) {
        return (int)$item['id'] === $group_filter;
    }));
}

if ($course_filter !== '') {
    $groups = array_values(array_filter($groups, function ($item) use ($course_filter) {
        return ($item['course'] ?? '') === $course_filter;
    }));
}

if ($department_filter > 0) {
    $groups = array_values(array_filter($groups, function ($item) use ($department_filter) {
        return (int)($item['department_id'] ?? 0) === $department_filter;
    }));
}

if ($status_filter === 'active') {
    $groups = array_values(array_filter($groups, function ($item) {
        return (int)$item['is_active'] === 1;
    }));
} elseif ($status_filter === 'inactive') {
    $groups = array_values(array_filter($groups, function ($item) {
        return (int)$item['is_active'] === 0;
    }));
}

if ($search !== '') {
    $search_lower = mb_strtolower($search);
    $groups = array_values(array_filter($groups, function ($item) use ($search_lower) {
        $haystack = mb_strtolower(
            ($item['name'] ?? '') . ' ' .
            ($item['code'] ?? '') . ' ' .
            ($item['specialty'] ?? '') . ' ' .
            ($item['qualification'] ?? '')
        );
        return mb_strpos($haystack, $search_lower) !== false;
    }));
}

usort($groups, function ($a, $b) {
    $courseA = (int)preg_replace('/\D+/', '', $a['course'] ?? '0');
    $courseB = (int)preg_replace('/\D+/', '', $b['course'] ?? '0');
    if ($courseA !== $courseB) {
        return $courseA <=> $courseB;
    }
    return strcmp(mb_strtolower($a['name'] ?? ''), mb_strtolower($b['name'] ?? ''));
});

$year_filter_is_default = $year_filter === (string)$current_academic_year;
$filters_active = !$year_filter_is_default || $group_filter > 0 || $course_filter !== '' || $department_filter > 0 || $status_filter !== '' || $search !== '';

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

$per_page = 20;
$total_filtered = count($groups);
$total_pages = max(1, (int)ceil($total_filtered / $per_page));
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;
$groups_page = array_slice($groups, $offset, $per_page);
$shown_from = $total_filtered === 0 ? 0 : $offset + 1;
$shown_to = min($offset + count($groups_page), $total_filtered);

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
if ($department_filter > 0) {
    $filter_query['department'] = $department_filter;
}
if ($status_filter !== '') {
    $filter_query['status'] = $status_filter;
}
if ($search !== '') {
    $filter_query['search'] = $search;
}

$groupsPageUrl = function ($page) use ($filter_query) {
    $query = $filter_query;
    if ((int)$page > 1) {
        $query['page'] = (int)$page;
    }
    $qs = http_build_query($query);
    return 'groups.php' . ($qs !== '' ? '?' . $qs : '');
};

// Для header
$page_title = 'Управление группами';
$active_page = 'groups';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Группы</h1>
        <p class="page-subtitle">Учебный год <?php echo htmlspecialchars($year_label); ?></p>
    </div>
    <div class="page-actions">
        <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addGroupModal">
            <i class="bi bi-folder-plus"></i>
            <span>Создать группу</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filters -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-funnel"></i>
            Фильтры и поиск
        </h2>
    </div>
    <div class="card-body">
        <form method="GET" action="groups.php">
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
                    <label class="form-label">Отделение</label>
                    <select class="form-select" name="department">
                        <option value="">Все отделения</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?php echo (int)$dept['id']; ?>" <?php echo $department_filter === (int)$dept['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($dept['name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Статус</label>
                    <select class="form-select" name="status">
                        <option value="">Все статусы</option>
                        <option value="active" <?php echo $status_filter === 'active' ? 'selected' : ''; ?>>Активные</option>
                        <option value="inactive" <?php echo $status_filter === 'inactive' ? 'selected' : ''; ?>>Неактивные</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" name="search" placeholder="Название или код" value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-search"></i> Применить
                </button>
                <?php if ($filters_active): ?>
                    <a href="groups.php" class="btn btn-outline">
                        <i class="bi bi-x-circle"></i> Сбросить
                    </a>
                    <span class="text-secondary ms-2" style="font-size: 0.875rem;">
                        Найдено: <?php echo $total_filtered; ?> из <?php echo count($all_groups); ?>
                    </span>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Groups Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-collection"></i>
            Список групп
            <?php if ($year_filter !== 'all'): ?>
                <span class="badge badge-primary" style="margin-left: 0.35rem; text-transform: none; letter-spacing: 0;"><?php echo htmlspecialchars($year_label); ?></span>
            <?php endif; ?>
        </h2>
        <span class="badge badge-success"><?php echo $total_filtered; ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Группа</th>
                        <th>Отделение</th>
                        <th>Специальность</th>
                        <th>Курс</th>
                        <th>Куратор</th>
                        <th>Студенты</th>
                        <th>Статус</th>
                        <th style="width: 100px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups_page as $group_item): ?>
                        <tr>
                            <td>
                                <div>
                                    <div style="font-weight: 500;"><?php echo htmlspecialchars($group_item['name']); ?></div>
                                    <code style="background: var(--content-bg); padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.75rem;">
                                        <?php echo htmlspecialchars($group_item['code']); ?>
                                    </code>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($group_item['department_name'])): ?>
                                    <span class="badge badge-secondary"><?php echo htmlspecialchars($group_item['department_name']); ?></span>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary); font-size: 0.875rem;">Не привязана</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div style="max-width: 200px;">
                                    <div style="font-size: 0.875rem;" title="<?php echo htmlspecialchars($group_item['specialty']); ?>">
                                        <?php echo htmlspecialchars(mb_substr($group_item['specialty'], 0, 40)); ?><?php echo mb_strlen($group_item['specialty']) > 40 ? '...' : ''; ?>
                                    </div>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($group_item['qualification']); ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-primary"><?php echo htmlspecialchars($group_item['course']); ?></span>
                            </td>
                            <td>
                                <?php if ($group_item['curator_first_name']): ?>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="list-avatar" style="width: 28px; height: 28px; font-size: 0.75rem;">
                                            <?php echo mb_substr($group_item['curator_first_name'], 0, 1); ?>
                                        </div>
                                        <span style="font-size: 0.875rem;">
                                            <?php echo htmlspecialchars($group_item['curator_first_name'] . ' ' . $group_item['curator_last_name']); ?>
                                        </span>
                                    </div>
                                <?php else: ?>
                                    <span style="color: var(--text-muted); font-size: 0.875rem;">Не назначен</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div style="width: 60px; height: 6px; background: var(--content-bg); border-radius: 3px; overflow: hidden;">
                                        <?php 
                                        $percent = $group_item['max_students'] > 0 ? ($group_item['current_students'] / $group_item['max_students']) * 100 : 0;
                                        $color = $percent >= 90 ? 'var(--danger)' : ($percent >= 70 ? 'var(--warning)' : 'var(--success)');
                                        ?>
                                        <div style="width: <?php echo $percent; ?>%; height: 100%; background: <?php echo $color; ?>;"></div>
                                    </div>
                                    <span style="font-size: 0.875rem; font-weight: 500;">
                                        <?php echo $group_item['current_students']; ?>/<?php echo $group_item['max_students']; ?>
                                    </span>
                                </div>
                            </td>
                            <td>
                                <span class="badge <?php echo $group_item['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $group_item['is_active'] ? 'Активна' : 'Неактивна'; ?>
                                </span>
                                <?php if (isGraduatingGroup($group_item)): ?>
                                    <span class="badge badge-warning">Выпускная группа</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-icon btn-sm btn-outline" title="Редактировать"
                                            onclick="editGroup(<?php echo htmlspecialchars(json_encode($group_item)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteGroup(<?php echo $group_item['id']; ?>, '<?php echo htmlspecialchars($group_item['name']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($groups)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                <?php
                                $year_only_empty = $year_filter !== 'all'
                                    && $group_filter === 0
                                    && $course_filter === ''
                                    && $department_filter === 0
                                    && $status_filter === ''
                                    && $search === '';
                                ?>
                                <?php if ($year_only_empty): ?>
                                    Нет групп за учебный год <?php echo htmlspecialchars($year_label); ?>
                                <?php else: ?>
                                    Группы не найдены
                                <?php endif; ?>
                                <?php if ($filters_active): ?>
                                    <div class="mt-2">
                                        <a href="groups.php" class="btn btn-outline btn-sm">Сбросить фильтры</a>
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
                <nav aria-label="Страницы групп">
                    <div class="d-flex gap-1 flex-wrap align-items-center">
                        <?php if ($current_page > 1): ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($groupsPageUrl($current_page - 1)); ?>" title="Назад">
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
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($groupsPageUrl(1)); ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                            <?php if ($p === $current_page): ?>
                                <span class="btn btn-sm btn-primary"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($groupsPageUrl($p)); ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($groupsPageUrl($total_pages)); ?>"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($groupsPageUrl($current_page + 1)); ?>" title="Вперёд">
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

<!-- Add Group Modal -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-folder-plus me-2"></i>Создать группу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название группы <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Код группы <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="code" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Специальность <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="specialty" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Квалификация <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="qualification" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Курс <span class="text-danger">*</span></label>
                            <select class="form-select" name="course" required>
                                <option value="">Выберите</option>
                                <option value="1 курс">1 курс</option>
                                <option value="2 курс">2 курс</option>
                                <option value="3 курс">3 курс</option>
                                <option value="4 курс">4 курс</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Язык обучения <span class="text-danger">*</span></label>
                            <select class="form-select" name="language" required>
                                <option value="">Выберите</option>
                                <option value="казахский">Казахский</option>
                                <option value="русский">Русский</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Форма обучения <span class="text-danger">*</span></label>
                            <select class="form-select" name="study_form" required>
                                <option value="">Выберите</option>
                                <option value="очная">Очная</option>
                                <option value="заочная">Заочная</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Срок обучения <span class="text-danger">*</span></label>
                            <select class="form-select" name="study_duration" required>
                                <option value="">Выберите срок обучения</option>
                                <?php foreach ($study_duration_options as $duration): ?>
                                    <option value="<?php echo htmlspecialchars($duration); ?>"><?php echo htmlspecialchars($duration); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата начала <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="start_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата окончания</label>
                            <input type="date" class="form-control" name="end_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата прибытия <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" name="arrival_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Номер приказа <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="enrollment_order_number" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Макс. студентов <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" name="max_students" value="30" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Куратор</label>
                            <select class="form-select" name="curator_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($curators as $curator): ?>
                                    <option value="<?php echo $curator['id']; ?>">
                                        <?php echo htmlspecialchars($curator['last_name'] . ' ' . $curator['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-success">
                        <i class="bi bi-check-lg"></i> Создать
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Group Modal -->
<div class="modal fade" id="editGroupModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="group_id" id="edit_group_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать группу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название группы <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Код группы <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_code" name="code" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Специальность <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_specialty" name="specialty" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Квалификация <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_qualification" name="qualification" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Курс <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_course" name="course" required>
                                <option value="1 курс">1 курс</option>
                                <option value="2 курс">2 курс</option>
                                <option value="3 курс">3 курс</option>
                                <option value="4 курс">4 курс</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Язык обучения <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_language" name="language" required>
                                <option value="казахский">Казахский</option>
                                <option value="русский">Русский</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Форма обучения <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_study_form" name="study_form" required>
                                <option value="очная">Очная</option>
                                <option value="заочная">Заочная</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Срок обучения <span class="text-danger">*</span></label>
                            <select class="form-select" id="edit_study_duration" name="study_duration" required>
                                <option value="">Выберите срок обучения</option>
                                <?php foreach ($study_duration_options as $duration): ?>
                                    <option value="<?php echo htmlspecialchars($duration); ?>"><?php echo htmlspecialchars($duration); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата начала <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_start_date" name="start_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата окончания</label>
                            <input type="date" class="form-control" id="edit_end_date" name="end_date">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Дата прибытия <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="edit_arrival_date" name="arrival_date" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Номер приказа <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_enrollment_order_number" name="enrollment_order_number" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Макс. студентов <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="edit_max_students" name="max_students" min="1" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Куратор</label>
                            <select class="form-select" id="edit_curator_id" name="curator_id">
                                <option value="">Не назначен</option>
                                <?php foreach ($curators as $curator): ?>
                                    <option value="<?php echo $curator['id']; ?>">
                                        <?php echo htmlspecialchars($curator['last_name'] . ' ' . $curator['first_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                <label class="form-check-label" for="edit_is_active">
                                    Активная группа
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="group_id" id="delete_group_id">
</form>

<script>
    function editGroup(group) {
        document.getElementById('edit_group_id').value = group.id;
        document.getElementById('edit_name').value = group.name;
        document.getElementById('edit_code').value = group.code;
        document.getElementById('edit_specialty').value = group.specialty;
        document.getElementById('edit_qualification').value = group.qualification;
        document.getElementById('edit_course').value = group.course;
        document.getElementById('edit_language').value = group.language;
        document.getElementById('edit_study_form').value = group.study_form;
        const durationSelect = document.getElementById('edit_study_duration');
        const durationValue = group.study_duration || '';
        if (durationValue) {
            const hasOption = Array.from(durationSelect.options).some(function (option) {
                return option.value === durationValue;
            });
            if (!hasOption) {
                const extra = document.createElement('option');
                extra.value = durationValue;
                extra.textContent = durationValue;
                durationSelect.appendChild(extra);
            }
        }
        durationSelect.value = durationValue;
        document.getElementById('edit_start_date').value = group.start_date;
        document.getElementById('edit_end_date').value = group.end_date || '';
        document.getElementById('edit_arrival_date').value = group.arrival_date || '';
        document.getElementById('edit_enrollment_order_number').value = group.enrollment_order_number || '';
        document.getElementById('edit_curator_id').value = group.curator_id || '';
        document.getElementById('edit_max_students').value = group.max_students;
        document.getElementById('edit_is_active').checked = group.is_active == 1;

        if (typeof syncAllAdminSelects === 'function') {
            syncAllAdminSelects(document.getElementById('editGroupModal'));
        }
        new bootstrap.Modal(document.getElementById('editGroupModal')).show();
    }

    function deleteGroup(groupId, groupName) {
        if (confirm('Вы уверены, что хотите удалить группу "' + groupName + '"?')) {
            document.getElementById('delete_group_id').value = groupId;
            document.getElementById('deleteForm').submit();
        }
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
