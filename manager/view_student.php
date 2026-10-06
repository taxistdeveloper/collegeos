<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../classes/DynamicField.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

checkAuth();

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_own_students');

$user = new User();
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
    header('Location: students.php');
    exit;
}

$sql = "SELECT s.*, g.name AS group_name, g.id AS group_id
        FROM students s
        LEFT JOIN `groups` g ON s.group_id = g.id
        WHERE s.id = ?";
$stmt = $db->prepare($sql);
if (!$stmt) {
    header('Location: students.php');
    exit;
}
$stmt->bind_param('i', $student_id);
$stmt->execute();
$result = $stmt->get_result();
$student = $result->fetch_assoc();
if (!$student) {
    header('Location: students.php');
    exit;
}

$student_status = getStudentStatus($student);
$is_graduated = ($student_status === 'graduated');
$is_graduating = ($student_status === 'graduating');
$is_academic_leave = ($student_status === 'academic_leave');

$can_view_all = $permissionChecker->hasPermission($current_user['id'], 'view_all_students')
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

$full_name = trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
$page_title = $full_name;
$page_subtitle = 'Профиль студента';
$document_title = 'Студент: ' . $full_name;
$extra_head = '<link href="assets/css/view-student.css" rel="stylesheet">';

$parent_field_keys = [
    'father_full_name', 'father_phone', 'father_workplace',
    'mother_full_name', 'mother_phone', 'mother_workplace',
    'emergency_contact',
];

function vs_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

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

function vs_row(string $label, $value, array $opts = []): string
{
    $force = !empty($opts['force']);
    $html = $opts['html'] ?? false;
    $raw = $value;

    if (!$html) {
        if (!vs_filled($raw) && !$force) {
            return '';
        }
        $display = vs_filled($raw)
            ? vs_h(is_string($raw) ? trim($raw) : $raw)
            : '<span class="vs-priority-empty">Не указано</span>';
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

include 'includes/layout_start.php';
?>

<div class="vs-page" role="main">

    <nav aria-label="breadcrumb" class="vs-breadcrumb no-print">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="dashboard.php">Главная</a></li>
            <li class="breadcrumb-item"><a href="students.php">Студенты</a></li>
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
            <strong>Ошибка.</strong> <?php echo $error; ?>
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
                    <span class="vs-chip" role="listitem"><i class="bi bi-mortarboard" aria-hidden="true"></i><?php echo vs_h($student['course']); ?></span>
                <?php endif; ?>
                <?php if (vs_filled($student['birth_date'] ?? null)): ?>
                    <span class="vs-chip" role="listitem"><i class="bi bi-calendar3" aria-hidden="true"></i><?php echo vs_h($student['birth_date']); ?></span>
                <?php endif; ?>
                <span class="vs-pill <?php echo vs_status_pill_class($student_status); ?>" role="status"><?php echo vs_h($status_label); ?></span>
            </div>
        </div>
        <div class="vs-actions no-print" role="group" aria-label="Действия со студентом">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">
                <i class="bi bi-printer me-1" aria-hidden="true"></i>Печать
            </button>
            <a class="btn btn-outline-secondary" href="students.php">
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

    <div class="vs-toolbar no-print">
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

<?php
$extra_scripts = <<<'HTML'
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
        const saved = localStorage.getItem('managerStudentViewDensity') || 'compact';
        applyDensity(saved);
        densityToggle.addEventListener('click', () => {
            const current = document.documentElement.getAttribute('data-density');
            const next = current === 'compact' ? 'comfortable' : 'compact';
            localStorage.setItem('managerStudentViewDensity', next);
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

    document.addEventListener('keydown', function (e) {
        if (e.altKey && e.key === 'b') {
            e.preventDefault();
            window.location.href = 'students.php';
        }
        if ((e.ctrlKey || e.metaKey) && e.key === 'f' && rowFilter) {
            e.preventDefault();
            rowFilter.focus();
        }
    });
});
</script>
HTML;

include 'includes/layout_end.php';
