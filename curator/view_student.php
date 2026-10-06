<?php
require_once '../config/config.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkAuth();

$from_admin = (isset($_GET['from']) && $_GET['from'] === 'admin') || !empty($_SESSION['admin_logged_in']);
$students_list_url = $from_admin ? '../admin/students.php' : 'my_students.php';
$view_query_suffix = $from_admin ? '&from=admin' : '';

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_own_students');

$group = new Group();
$dynamicField = new DynamicField();
$current_user = getCurrentUser();
$db = getDB();

$message = '';
$error = '';

if (isset($_SESSION['success_message'])) {
    $message = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

$student_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$student_id) {
    header('Location: ' . $students_list_url);
    exit;
}

$sql = "SELECT s.*, g.name AS group_name, g.id AS group_id
        FROM students s
        LEFT JOIN `groups` g ON s.group_id = g.id
        WHERE s.id = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    header('Location: ' . $students_list_url);
    exit;
}
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
if (!$student) {
    header('Location: ' . $students_list_url);
    exit;
}

$student_status = getStudentStatus($student);
$is_graduated = ($student_status === 'graduated');
$is_graduating = ($student_status === 'graduating');
$is_academic_leave = ($student_status === 'academic_leave');

$can_view_all = !empty($_SESSION['admin_logged_in'])
    || $permissionChecker->hasPermission($current_user['id'], 'view_all_students')
    || $permissionChecker->hasPermission($current_user['id'], 'view_students')
    || $permissionChecker->hasPermission($current_user['id'], 'all');

if (!$can_view_all) {
    $curator_groups = $group->getGroupsByCurator($current_user['id']);
    $allowed_group_ids = array_column($curator_groups, 'id');
    if (!empty($student['group_id']) && !in_array((int)$student['group_id'], $allowed_group_ids, true)) {
        header('Location: ../unauthorized.php');
        exit;
    }
}

$all_dynamic_fields = $dynamicField->getAllFields();
$student_dynamic_values = $dynamicField->getStudentDynamicFields($student_id);
$dynamic_field_labels = [];
foreach ($all_dynamic_fields as $field_def) {
    $dynamic_field_labels[$field_def['field_name']] = $field_def['field_label'];
}
$dynamic_values_map = is_array($student_dynamic_values) ? $student_dynamic_values : [];

$can_edit = $permissionChecker->hasPermission($current_user['id'], 'edit_students')
    || $permissionChecker->hasPermission($current_user['id'], 'edit_own_students')
    || !empty($_SESSION['admin_logged_in']);

$full_name = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$page_title = $full_name;
$page_subtitle = '';

$parent_field_keys = [
    'father_full_name', 'father_phone', 'father_workplace',
    'mother_full_name', 'mother_phone', 'mother_workplace',
    'emergency_contact',
];

/**
 * Escape for HTML.
 */
function vs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Non-empty trimmed string?
 */
function vs_filled($value): bool
{
    if ($value === null || $value === false) {
        return false;
    }
    if (is_string($value)) {
        return trim($value) !== '';
    }
    return true;
}

/**
 * Render a data row only if value is filled (or $force).
 */
function vs_row(string $label, $value, array $opts = []): string
{
    $force = !empty($opts['force']);
    $html = $opts['html'] ?? false;
    $raw = $value;

    if (!$html) {
        if (!vs_filled($raw) && !$force) {
            return '';
        }
        $display = vs_filled($raw) ? vs_h(is_string($raw) ? trim($raw) : $raw) : '<span class="vs-priority-empty">Не указано</span>';
    } else {
        if (($raw === null || $raw === '') && !$force) {
            return '';
        }
        $display = $raw;
    }

    $search = strtolower($label . ' ' . strip_tags((string)$display));
    return '<div class="vs-row" data-search="' . vs_h($search) . '">'
        . '<div class="vs-row-label">' . vs_h($label) . '</div>'
        . '<div class="vs-row-value">' . $display . '</div>'
        . '</div>';
}

function vs_tel_link($phone): string
{
    if (!vs_filled($phone)) {
        return '';
    }
    $phone = trim((string)$phone);
    return '<a href="tel:' . vs_h($phone) . '">' . vs_h($phone) . '</a>';
}

function vs_mail_link($email): string
{
    if (!vs_filled($email)) {
        return '';
    }
    $email = trim((string)$email);
    return '<a href="mailto:' . vs_h($email) . '">' . vs_h($email) . '</a>';
}

function vs_bool_pill(bool $yes, string $yesLabel = 'Да', string $noLabel = 'Нет'): string
{
    if ($yes) {
        return '<span class="vs-pill vs-pill-success">' . vs_h($yesLabel) . '</span>';
    }
    return '<span class="vs-pill vs-pill-neutral">' . vs_h($noLabel) . '</span>';
}

function vs_status_pill_class(string $status): string
{
    if ($status === 'academic_leave' || $status === 'graduating') {
        return 'vs-pill-warning';
    }
    if ($status === 'graduated') {
        return 'vs-pill-info';
    }
    return 'vs-pill-success';
}

$has_social_flags = (int)($student['orphan'] ?? 0)
    || (int)($student['without_parental_care'] ?? 0)
    || (int)($student['disability'] ?? 0)
    || (int)($student['large_family'] ?? 0)
    || (int)($student['social_assistance'] ?? 0);

$quota = trim((string)($student['quota_category'] ?? ''));
$is_quota_none = ($quota === '' || mb_strtolower($quota, 'UTF-8') === 'не относится ни к одной из указанных категорий');

$show_critical_banner = $is_academic_leave || $is_graduating || $is_graduated
    || vs_filled($student['academic_leave_reason'] ?? null);

$status_label = getStudentStatusLabel($student_status);
?>
<!DOCTYPE html>
<html lang="ru" data-density="compact">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Профиль студента <?php echo vs_h($full_name); ?>">
    <title>Студент: <?php echo vs_h($full_name); ?> - <?php echo vs_h(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <link href="assets/css/view-student.css" rel="stylesheet">
</head>
<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content vs-page" role="main">

            <nav aria-label="breadcrumb" class="vs-breadcrumb">
                <ol class="breadcrumb">
                    <?php if ($from_admin): ?>
                        <li class="breadcrumb-item"><a href="../admin/index.php">Админ</a></li>
                        <li class="breadcrumb-item"><a href="../admin/students.php">Студенты</a></li>
                    <?php else: ?>
                        <li class="breadcrumb-item"><a href="dashboard.php">Главная</a></li>
                        <li class="breadcrumb-item"><a href="my_students.php">Мои студенты</a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active" aria-current="page"><?php echo vs_h(trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? ''))); ?></li>
                </ol>
            </nav>

            <?php if ($message !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show" role="alert">
                    <strong>Успешно.</strong> <?php echo vs_h($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>
            <?php if ($error !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show" role="alert">
                    <strong>Ошибка.</strong> <?php echo vs_h($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Закрыть"></button>
                </div>
            <?php endif; ?>

            <section class="vs-hero" aria-labelledby="student-name">
                <div class="vs-avatar" aria-hidden="true"><i class="bi bi-person-fill"></i></div>
                <div>
                    <h1 id="student-name"><?php echo vs_h($full_name); ?></h1>
                    <div class="vs-meta" role="list">
                        <?php if (vs_filled($student['iin'] ?? null)): ?>
                            <span class="vs-chip" role="listitem"><i class="bi bi-credit-card-2-front" aria-hidden="true"></i>ИИН <?php echo vs_h($student['iin']); ?></span>
                        <?php endif; ?>
                        <span class="vs-chip" role="listitem"><i class="bi bi-people" aria-hidden="true"></i><?php echo vs_h($student['group_name'] ?? 'Группа не назначена'); ?></span>
                        <?php if (vs_filled($student['course'] ?? null)): ?>
                            <span class="vs-chip" role="listitem"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo vs_h($student['course']); ?> курс</span>
                        <?php endif; ?>
                        <span class="vs-pill <?php echo vs_status_pill_class($student_status); ?>" role="status"><?php echo vs_h($status_label); ?></span>
                    </div>
                </div>
                <div class="vs-actions" role="group" aria-label="Действия со студентом">
                    <?php if ($can_edit): ?>
                        <a class="btn btn-primary" href="edit_student_new.php?id=<?php echo (int)$student['id']; ?><?php echo $view_query_suffix; ?>">
                            <i class="bi bi-pencil me-1" aria-hidden="true"></i>Редактировать
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-outline-secondary" id="btnMobileInvite">
                        <i class="bi bi-qr-code me-1" aria-hidden="true"></i>Код для приложения
                    </button>
                    <button type="button" class="btn btn-outline-secondary" id="btnMobileResetDevice">
                        <i class="bi bi-phone me-1" aria-hidden="true"></i>Сбросить устройство
                    </button>
                    <a class="btn btn-outline-secondary" href="<?php echo vs_h($students_list_url); ?>">
                        <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Назад
                    </a>
                </div>
            </section>

            <?php if ($show_critical_banner): ?>
                <?php
                $banner_class = 'vs-banner-warning';
                $banner_icon = 'bi-pause-circle';
                $banner_title = $status_label;
                $banner_parts = [];

                if ($is_academic_leave || vs_filled($student['academic_leave_reason'] ?? null)) {
                    $banner_class = 'vs-banner-warning';
                    $banner_icon = 'bi-pause-circle';
                    $banner_title = $is_academic_leave ? 'Академический отпуск' : 'Данные об академическом отпуске';
                    if (vs_filled($student['academic_leave_reason'] ?? null)) {
                        $banner_parts[] = 'Причина: ' . trim((string)$student['academic_leave_reason']);
                    }
                    if (vs_filled($student['academic_leave_order_date'] ?? null)) {
                        $banner_parts[] = 'Дата приказа: ' . trim((string)$student['academic_leave_order_date']);
                    }
                } elseif ($is_graduating) {
                    $banner_class = 'vs-banner-warning';
                    $banner_icon = 'bi-mortarboard';
                    $banner_title = 'Выпускная группа';
                    if (vs_filled($student['course_end_date'] ?? null)) {
                        $banner_parts[] = 'Окончание курса: ' . trim((string)$student['course_end_date']);
                    }
                } elseif ($is_graduated) {
                    $banner_class = 'vs-banner-info';
                    $banner_icon = 'bi-award';
                    $banner_title = 'Выпускник';
                    if (vs_filled($student['graduation_date'] ?? null)) {
                        $banner_parts[] = 'Дата выпуска: ' . trim((string)$student['graduation_date']);
                    }
                }
                ?>
                <aside class="vs-banner <?php echo $banner_class; ?>" role="status">
                    <i class="bi <?php echo $banner_icon; ?>" aria-hidden="true"></i>
                    <div>
                        <p class="vs-banner-title"><?php echo vs_h($banner_title); ?></p>
                        <?php if ($banner_parts): ?>
                            <p class="vs-banner-text"><?php echo vs_h(implode(' · ', $banner_parts)); ?></p>
                        <?php endif; ?>
                    </div>
                </aside>
            <?php endif; ?>

            <section class="vs-priority" aria-label="Ключевые контакты">
                <div class="vs-priority-card">
                    <div class="vs-priority-label"><i class="bi bi-telephone" aria-hidden="true"></i>Телефон</div>
                    <div class="vs-priority-value">
                        <?php if (vs_filled($student['phone'] ?? null)): ?>
                            <?php echo vs_tel_link($student['phone']); ?>
                        <?php else: ?>
                            <span class="vs-priority-empty">Не указан</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vs-priority-card">
                    <div class="vs-priority-label"><i class="bi bi-envelope" aria-hidden="true"></i>Email</div>
                    <div class="vs-priority-value">
                        <?php if (vs_filled($student['email'] ?? null)): ?>
                            <?php echo vs_mail_link($student['email']); ?>
                        <?php else: ?>
                            <span class="vs-priority-empty">Не указан</span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="vs-priority-card vs-priority-urgent">
                    <div class="vs-priority-label"><i class="bi bi-life-preserver" aria-hidden="true"></i>Экстренный контакт</div>
                    <div class="vs-priority-value">
                        <?php if (vs_filled($dynamic_values_map['emergency_contact'] ?? null)): ?>
                            <?php echo vs_tel_link($dynamic_values_map['emergency_contact']); ?>
                        <?php else: ?>
                            <span class="vs-priority-empty">Не указан</span>
                        <?php endif; ?>
                    </div>
                </div>
            </section>

            <div class="vs-toolbar">
                <div class="vs-search">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="rowFilter" placeholder="Поиск по полям профиля…" aria-label="Поиск по информации студента" autocomplete="off">
                </div>
                <span class="vs-search-count" id="searchCount" aria-live="polite"></span>
                <button type="button" class="btn btn-outline-secondary btn-sm" id="densityToggle" aria-label="Переключить плотность отображения">Свободнее</button>
            </div>

            <div class="vs-tabs">
                <ul class="nav nav-tabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="tab-contacts" data-bs-toggle="tab" data-bs-target="#pane-contacts" type="button" role="tab" aria-controls="pane-contacts" aria-selected="true">Контакты <span class="vs-tab-badge" hidden></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-study" data-bs-toggle="tab" data-bs-target="#pane-study" type="button" role="tab" aria-controls="pane-study" aria-selected="false">Учёба <span class="vs-tab-badge" hidden></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-docs" data-bs-toggle="tab" data-bs-target="#pane-docs" type="button" role="tab" aria-controls="pane-docs" aria-selected="false">Документы <span class="vs-tab-badge" hidden></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-family" data-bs-toggle="tab" data-bs-target="#pane-family" type="button" role="tab" aria-controls="pane-family" aria-selected="false">Семья и соц <span class="vs-tab-badge" hidden></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-activity" data-bs-toggle="tab" data-bs-target="#pane-activity" type="button" role="tab" aria-controls="pane-activity" aria-selected="false">Активности <span class="vs-tab-badge" hidden></span></button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="tab-extra" data-bs-toggle="tab" data-bs-target="#pane-extra" type="button" role="tab" aria-controls="pane-extra" aria-selected="false">Ещё <span class="vs-tab-badge" hidden></span></button>
                    </li>
                </ul>

                <div class="tab-content">
                    <!-- Contacts -->
                    <div class="tab-pane fade show active" id="pane-contacts" role="tabpanel" aria-labelledby="tab-contacts">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-section-title">Личные данные</div>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Дата рождения', $student['birth_date'] ?? null);
                                echo vs_row('Пол', $student['gender'] ?? null);
                                echo vs_row('Национальность', $student['nationality'] ?? null);
                                echo vs_row('Код группы', vs_filled($student['group_code'] ?? null)
                                    ? '<code>' . vs_h($student['group_code']) . '</code>'
                                    : null, ['html' => true]);
                                ?>
                            </div>
                            <div class="vs-section-title">Адреса и связь</div>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Телефон', vs_tel_link($student['phone'] ?? null), ['html' => true]);
                                echo vs_row('Email', vs_mail_link($student['email'] ?? null), ['html' => true]);
                                echo vs_row('Постоянный адрес', $student['permanent_address_ru'] ?? null);
                                echo vs_row('Временный адрес', $student['temporary_address_ru'] ?? null);
                                echo vs_row('Тип местности', $student['residence_type'] ?? null);
                                ?>
                            </div>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>

                    <!-- Study -->
                    <div class="tab-pane fade" id="pane-study" role="tabpanel" aria-labelledby="tab-study">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Курс', $student['course'] ?? null);
                                echo vs_row('Язык обучения', $student['language'] ?? null);
                                echo vs_row('Форма обучения', $student['study_form'] ?? null);
                                echo vs_row('Срок обучения', $student['study_duration'] ?? null);
                                echo vs_row('Специальность', $student['specialty'] ?? null);
                                echo vs_row('Тип образования', $student['education_type'] ?? null);
                                echo vs_row('Тип практики', $student['practice_type'] ?? null);
                                echo vs_row('Начало курса', $student['course_start_date'] ?? null);
                                echo vs_row('Окончание курса', $student['course_end_date'] ?? null);
                                echo vs_row('Дата выпуска', $student['graduation_date'] ?? null);
                                ?>
                            </div>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>

                    <!-- Documents -->
                    <div class="tab-pane fade" id="pane-docs" role="tabpanel" aria-labelledby="tab-docs">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Вид зачисления', $student['enrollment_type'] ?? null);
                                echo vs_row('Дата зачисления', $student['arrival_date'] ?? null);
                                echo vs_row('Номер приказа', vs_filled($student['enrollment_order_number'] ?? null)
                                    ? '<code>' . vs_h($student['enrollment_order_number']) . '</code>'
                                    : null, ['html' => true]);
                                echo vs_row('Прибыл из', $student['arrival_from'] ?? null);
                                ?>
                            </div>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>

                    <!-- Family & social -->
                    <div class="tab-pane fade" id="pane-family" role="tabpanel" aria-labelledby="tab-family">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-section-title">Родители</div>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Мать (ФИО)', $dynamic_values_map['mother_full_name'] ?? null);
                                echo vs_row('Телефон матери', vs_tel_link($dynamic_values_map['mother_phone'] ?? null), ['html' => true]);
                                echo vs_row('Место работы матери', $dynamic_values_map['mother_workplace'] ?? null);
                                echo vs_row('Отец (ФИО)', $dynamic_values_map['father_full_name'] ?? null);
                                echo vs_row('Телефон отца', vs_tel_link($dynamic_values_map['father_phone'] ?? null), ['html' => true]);
                                echo vs_row('Место работы отца', $dynamic_values_map['father_workplace'] ?? null);
                                echo vs_row('Экстренный контакт', vs_tel_link($dynamic_values_map['emergency_contact'] ?? null), ['html' => true]);
                                ?>
                            </div>

                            <div class="vs-section-title">Социальный статус</div>
                            <?php
                            $social_pills = '';
                            if ($has_social_flags) {
                                if (!empty($student['orphan'])) {
                                    $social_pills .= '<span class="vs-pill vs-pill-warning">Сирота</span>';
                                }
                                if (!empty($student['without_parental_care'])) {
                                    $social_pills .= '<span class="vs-pill vs-pill-warning">Без попечения</span>';
                                }
                                if (!empty($student['disability'])) {
                                    $social_pills .= '<span class="vs-pill vs-pill-warning">Инвалидность</span>';
                                }
                                if (!empty($student['large_family'])) {
                                    $social_pills .= '<span class="vs-pill vs-pill-info">Многодетная семья</span>';
                                }
                                if (!empty($student['social_assistance'])) {
                                    $social_pills .= '<span class="vs-pill vs-pill-info">Соц. помощь</span>';
                                }
                            } else {
                                $social_pills = '<span class="vs-pill vs-pill-neutral">Особых категорий нет</span>';
                            }
                            echo vs_row('Итог', '<span class="vs-pill-group">' . $social_pills . '</span>', ['html' => true, 'force' => true]);
                            echo vs_row(
                                'Категория квоты',
                                $is_quota_none
                                    ? '<span class="vs-pill vs-pill-neutral">Не указана</span>'
                                    : '<span class="vs-pill vs-pill-info">' . vs_h($quota) . '</span>',
                                ['html' => true, 'force' => true]
                            );
                            ?>

                            <?php if ($has_social_flags || !$is_quota_none): ?>
                                <div class="vs-social-grid" data-search="сирота без попечения инвалидность многодетная социальная помощь квота">
                                    <div class="vs-social-item">
                                        <div class="vs-social-item-label">Сирота</div>
                                        <?php echo vs_bool_pill(!empty($student['orphan'])); ?>
                                    </div>
                                    <div class="vs-social-item">
                                        <div class="vs-social-item-label">Без попечения</div>
                                        <?php echo vs_bool_pill(!empty($student['without_parental_care'])); ?>
                                    </div>
                                    <div class="vs-social-item">
                                        <div class="vs-social-item-label">Инвалидность</div>
                                        <?php echo vs_bool_pill(!empty($student['disability'])); ?>
                                    </div>
                                    <div class="vs-social-item">
                                        <div class="vs-social-item-label">Многодетная семья</div>
                                        <?php echo vs_bool_pill(!empty($student['large_family'])); ?>
                                    </div>
                                    <div class="vs-social-item">
                                        <div class="vs-social-item-label">Социальная помощь</div>
                                        <?php echo vs_bool_pill(!empty($student['social_assistance'])); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>

                    <!-- Activity -->
                    <div class="tab-pane fade" id="pane-activity" role="tabpanel" aria-labelledby="tab-activity">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-rows">
                                <?php
                                echo vs_row('Горячее питание', vs_bool_pill(!empty($student['hot_meal']), 'Да', 'Нет'), ['html' => true, 'force' => true]);
                                echo vs_row('Бесплатное горячее питание', vs_bool_pill(!empty($student['free_hot_meal']), 'Да', 'Нет'), ['html' => true, 'force' => true]);
                                echo vs_row('Молодёжный комитет', vs_bool_pill(!empty($student['youth_committee']), 'Участвует', 'Не участвует'), ['html' => true, 'force' => true]);
                                echo vs_row('Студенческий парламент', vs_bool_pill(!empty($student['student_parliament']), 'Участвует', 'Не участвует'), ['html' => true, 'force' => true]);
                                echo vs_row('Жас Сарбаз', vs_bool_pill(!empty($student['jas_sarbaz']), 'Да', 'Нет'), ['html' => true, 'force' => true]);
                                if (vs_filled($student['competitions'] ?? null)) {
                                    echo vs_row('Соревнования', nl2br(vs_h($student['competitions'])), ['html' => true]);
                                }
                                ?>
                            </div>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>

                    <!-- Extra -->
                    <div class="tab-pane fade" id="pane-extra" role="tabpanel" aria-labelledby="tab-extra">
                        <div class="vs-tab-pane" data-tab-pane>
                            <div class="vs-rows">
                                <?php
                                $extra_shown = false;
                                if (!empty($student_dynamic_values) && is_array($student_dynamic_values)) {
                                    foreach ($student_dynamic_values as $field_name => $field_value) {
                                        if (!vs_filled($field_value) || in_array($field_name, $parent_field_keys, true)) {
                                            continue;
                                        }
                                        $label = $dynamic_field_labels[$field_name] ?? $field_name;
                                        echo vs_row($label, $field_value);
                                        $extra_shown = true;
                                    }
                                }
                                if (!$extra_shown) {
                                    echo '<p class="text-muted mb-0" data-empty-default>Дополнительных полей нет</p>';
                                }
                                ?>
                            </div>
                            <div class="vs-tab-empty" data-empty>Ничего не найдено</div>
                        </div>
                    </div>
                </div>
            </div>

            <footer class="vs-footer">
                <span>Создано: <strong><?php echo vs_h($student['created_at'] ?? '—'); ?></strong></span>
                <span>Обновлено: <strong><?php echo vs_h($student['updated_at'] ?? '—'); ?></strong></span>
            </footer>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script src="../assets/js/main.js"></script>
<script src="assets/js/curator-ui.js"></script>

<div class="modal fade" id="mobileInviteModal" tabindex="-1" aria-labelledby="mobileInviteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="mobileInviteModalLabel">Код для приложения</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Закрыть"></button>
            </div>
            <div class="modal-body text-center">
                <p class="text-muted mb-2" id="mobileInviteStudentName"></p>
                <div id="mobileInviteQr" class="d-flex justify-content-center mb-3"></div>
                <div class="display-6 fw-bold mb-2" id="mobileInviteCode"></div>
                <p class="small text-muted mb-0">Одноразовый код. Действует до <span id="mobileInviteExpires"></span>.</p>
                <p class="small text-muted mt-2 mb-0">Студент вводит код или сканирует QR в приложении КВКИ.</p>
                <p class="small mt-3 mb-0">Устройство: <strong id="mobileDeviceStatus">—</strong></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" id="btnCopyMobileCode">Копировать код</button>
                <button type="button" class="btn btn-primary" data-bs-dismiss="modal">Готово</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const rowFilter = document.getElementById('rowFilter');
    const searchCount = document.getElementById('searchCount');
    const densityToggle = document.getElementById('densityToggle');

    function applyFilter() {
        const term = (rowFilter.value || '').trim().toLowerCase();
        let visible = 0;
        let firstMatchTab = null;

        document.querySelectorAll('[data-tab-pane]').forEach((pane) => {
            let paneVisible = 0;
            pane.querySelectorAll('.vs-row').forEach((row) => {
                const hay = (row.getAttribute('data-search') || row.innerText || '').toLowerCase();
                const match = !term || hay.includes(term);
                row.classList.toggle('is-hidden', !match);
                if (match) {
                    visible += 1;
                    paneVisible += 1;
                }
            });

            pane.querySelectorAll('.vs-social-grid').forEach((grid) => {
                const hay = (grid.getAttribute('data-search') || grid.innerText || '').toLowerCase();
                const match = !term || hay.includes(term);
                grid.style.display = match ? '' : 'none';
                if (match && term) {
                    paneVisible += 1;
                }
            });

            pane.querySelectorAll('[data-empty-default]').forEach((el) => {
                el.style.display = term ? 'none' : '';
            });

            const empty = pane.querySelector('[data-empty]');
            if (empty) {
                empty.classList.toggle('is-visible', term !== '' && paneVisible === 0);
            }

            const tabPane = pane.closest('.tab-pane');
            const tabBtn = tabPane
                ? document.querySelector('[data-bs-target="#' + tabPane.id + '"]')
                : null;
            const badge = tabBtn ? tabBtn.querySelector('.vs-tab-badge') : null;
            if (badge) {
                if (term && paneVisible > 0) {
                    badge.hidden = false;
                    badge.textContent = String(paneVisible);
                    if (!firstMatchTab) firstMatchTab = tabBtn;
                } else {
                    badge.hidden = true;
                    badge.textContent = '';
                }
            }
        });

        if (!term) {
            searchCount.textContent = '';
            searchCount.classList.remove('is-active');
        } else {
            searchCount.textContent = 'Найдено: ' + visible;
            searchCount.classList.add('is-active');
            if (firstMatchTab && window.bootstrap) {
                const active = document.querySelector('.vs-tabs .nav-link.active');
                const activePane = active && document.querySelector(active.getAttribute('data-bs-target'));
                const activeHas = activePane && activePane.querySelectorAll('.vs-row:not(.is-hidden)').length > 0;
                if (!activeHas) {
                    bootstrap.Tab.getOrCreateInstance(firstMatchTab).show();
                }
            }
        }
    }

    if (rowFilter) {
        rowFilter.addEventListener('input', applyFilter);
    }

    if (densityToggle) {
        const applyDensity = (mode) => {
            document.documentElement.setAttribute('data-density', mode);
            densityToggle.textContent = mode === 'compact' ? 'Свободнее' : 'Плотнее';
        };
        const saved = localStorage.getItem('studentViewDensity') || 'compact';
        applyDensity(saved);
        densityToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-density');
            const next = current === 'compact' ? 'comfortable' : 'compact';
            localStorage.setItem('studentViewDensity', next);
            applyDensity(next);
        });
    }

    const successAlert = document.querySelector('.alert-success');
    if (successAlert && window.bootstrap) {
        setTimeout(() => {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(successAlert);
            bsAlert.close();
        }, 5000);
    }

    const studentIdForMobile = <?php echo (int)$student_id; ?>;
    const mobileAuthApi = 'api/student_mobile_auth.php';

    async function postMobileAuth(action) {
        const res = await fetch(mobileAuthApi, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action, student_id: studentIdForMobile }),
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!res.ok || !data.success) {
            throw new Error(data.error || 'Ошибка запроса');
        }
        return data;
    }

    function formatExpires(iso) {
        if (!iso) return '—';
        const d = new Date(String(iso).replace(' ', 'T'));
        if (Number.isNaN(d.getTime())) return iso;
        return d.toLocaleString('ru-RU');
    }

    const btnInvite = document.getElementById('btnMobileInvite');
    const btnReset = document.getElementById('btnMobileResetDevice');
    const inviteModalEl = document.getElementById('mobileInviteModal');
    const inviteModal = inviteModalEl ? new bootstrap.Modal(inviteModalEl) : null;

    if (btnInvite && inviteModal) {
        btnInvite.addEventListener('click', async () => {
            btnInvite.disabled = true;
            try {
                const [inviteRes, statusRes] = await Promise.all([
                    postMobileAuth('generate_invite'),
                    postMobileAuth('status').catch(() => null),
                ]);
                document.getElementById('mobileInviteCode').textContent = inviteRes.data.code;
                document.getElementById('mobileInviteExpires').textContent = formatExpires(inviteRes.data.expires_at);
                document.getElementById('mobileInviteStudentName').textContent = inviteRes.data.student_name || '';
                const statusEl = document.getElementById('mobileDeviceStatus');
                if (statusRes && statusRes.data) {
                    statusEl.textContent = statusRes.data.device_status
                        + (statusRes.data.device_bound_at ? ' (' + formatExpires(statusRes.data.device_bound_at) + ')' : '');
                } else {
                    statusEl.textContent = '—';
                }
                const qrBox = document.getElementById('mobileInviteQr');
                qrBox.innerHTML = '';
                if (typeof QRCode !== 'undefined') {
                    new QRCode(qrBox, {
                        text: inviteRes.data.code,
                        width: 180,
                        height: 180,
                        correctLevel: QRCode.CorrectLevel.M,
                    });
                }
                inviteModal.show();
            } catch (e) {
                alert(e.message || 'Не удалось создать код');
            } finally {
                btnInvite.disabled = false;
            }
        });
    }

    const btnCopy = document.getElementById('btnCopyMobileCode');
    if (btnCopy) {
        btnCopy.addEventListener('click', async () => {
            const code = document.getElementById('mobileInviteCode').textContent.trim();
            try {
                await navigator.clipboard.writeText(code);
                btnCopy.textContent = 'Скопировано';
                setTimeout(() => { btnCopy.textContent = 'Копировать код'; }, 1500);
            } catch (_) {
                prompt('Скопируйте код:', code);
            }
        });
    }

    if (btnReset) {
        btnReset.addEventListener('click', async () => {
            if (!confirm('Сбросить привязку устройства? Студент сможет войти только с новым кодом на другом телефоне. Текущая сессия в приложении станет недействительной.')) {
                return;
            }
            btnReset.disabled = true;
            try {
                const res = await postMobileAuth('reset_device');
                alert(res.message || 'Устройство сброшено');
            } catch (e) {
                alert(e.message || 'Не удалось сбросить устройство');
            } finally {
                btnReset.disabled = false;
            }
        });
    }
});
</script>
</body>
</html>
