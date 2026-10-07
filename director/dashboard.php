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

// Получение статистики по студентам
$db = getDB();

$not_graduated = sqlNotGraduatedCondition('');
$graduated = sqlGraduatedCondition('');

// Общая статистика студентов
$sql = "SELECT 
    COUNT(*) as total_students,
    SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active_students,
    SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students,
    SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated_students,
    SUM(CASE WHEN disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
    SUM(CASE WHEN without_parental_care = 1 THEN 1 ELSE 0 END) as without_parental_care_students,
    SUM(CASE WHEN large_family = 1 THEN 1 ELSE 0 END) as large_family_students,
    SUM(CASE WHEN TIMESTAMPDIFF(YEAR, birth_date, CURDATE()) < 18 THEN 1 ELSE 0 END) as minor_students
    FROM students";
$result = $db->query($sql);
$stats = $result->fetch_assoc();

// Статистика по группам с кураторами
$sql = "SELECT 
    g.id as group_id,
    g.name as group_name,
    g.code as group_code,
    g.course,
    g.specialty,
    CONCAT(u.last_name, ' ', u.first_name, ' ', IFNULL(u.middle_name, '')) as curator_name,
    u.email as curator_email,
    COUNT(s.id) as student_count,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_count,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_count,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_count
    FROM `groups` g
    LEFT JOIN students s ON g.id = s.group_id
    LEFT JOIN users u ON g.curator_id = u.id
    GROUP BY g.id, g.name, g.code, g.course, g.specialty, u.id, u.first_name, u.last_name, u.middle_name, u.email
    ORDER BY g.course, g.name";
$result = $db->query($sql);
$groups_stats = $result->fetch_all(MYSQLI_ASSOC);

// Получение списка всех студентов с подробной информацией, сгруппированных по группам
$sql = "SELECT 
    s.id,
    s.first_name,
    s.last_name,
    s.middle_name,
    s.course,
    s.phone,
    s.email,
    s.birth_date,
    TIMESTAMPDIFF(YEAR, s.birth_date, CURDATE()) as age,
    s.disability,
    s.orphan,
    s.academic_leave,
    s.without_parental_care,
    s.large_family,
    s.primary_violation,
    g.id as group_id,
    g.name as group_name,
    g.code as group_code,
    g.specialty as group_specialty,
    CONCAT(u.last_name, ' ', u.first_name, ' ', IFNULL(u.middle_name, '')) as curator_name
    FROM students s
    LEFT JOIN `groups` g ON s.group_id = g.id
    LEFT JOIN users u ON g.curator_id = u.id
    ORDER BY g.course, g.name, s.last_name, s.first_name";
$result = $db->query($sql);
$all_students = $result->fetch_all(MYSQLI_ASSOC);

// Группируем студентов по группам
$students_by_group = [];
foreach ($all_students as $student) {
    $group_id = $student['group_id'] ?? 'no_group';
    if (!isset($students_by_group[$group_id])) {
        $students_by_group[$group_id] = [
            'group_name' => $student['group_name'] ?? 'Без группы',
            'group_code' => $student['group_code'] ?? '',
            'group_specialty' => $student['group_specialty'] ?? '',
            'course' => $student['course'] ?? '',
            'curator_name' => $student['curator_name'] ?? '',
            'students' => []
        ];
    }
    $students_by_group[$group_id]['students'][] = $student;
}

// Статистика по курсам
$sql = "SELECT 
    course,
    COUNT(DISTINCT s.id) as total_students,
    SUM(CASE WHEN s.disability = 1 THEN 1 ELSE 0 END) as disabled_students,
    SUM(CASE WHEN s.orphan = 1 THEN 1 ELSE 0 END) as orphan_students,
    SUM(CASE WHEN s.academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave_students
    FROM students s
    GROUP BY course
    ORDER BY course";
$result = $db->query($sql);
$courses_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по инвалидности
$sql = "SELECT 
    primary_violation,
    COUNT(*) as count
    FROM students 
    WHERE disability = 1 AND primary_violation IS NOT NULL
    GROUP BY primary_violation
    ORDER BY count DESC";
$result = $db->query($sql);
$disability_stats = $result->fetch_all(MYSQLI_ASSOC);

// Статистика по социальным категориям
$sql = "SELECT 
    'Дети-сироты' as category,
    COUNT(*) as count
    FROM students WHERE orphan = 1
    UNION ALL
    SELECT 
    'Дети без попечения родителей' as category,
    COUNT(*) as count
    FROM students WHERE without_parental_care = 1
    UNION ALL
    SELECT 
    'Из многодетных семей' as category,
    COUNT(*) as count
    FROM students WHERE large_family = 1
    UNION ALL
    SELECT 
    'С инвалидностью' as category,
    COUNT(*) as count
    FROM students WHERE disability = 1";
$result = $db->query($sql);
$social_stats = $result->fetch_all(MYSQLI_ASSOC);

$user_name = htmlspecialchars($current_user['name'] ?? $current_user['first_name'] ?? '');
$user_role = htmlspecialchars($current_user['role'] ?? '');
$role_names = [
    'admin' => 'Администратор',
    'director' => 'Директор',
    'manager' => 'Менеджер',
    'curator' => 'Куратор'
];
$role_label = $role_names[$user_role] ?? ucfirst($user_role);

$course_filter_options = array_values(array_unique(array_filter(array_map(
    static fn($g) => $g['course'] ?? '',
    $groups_stats
))));
sort($course_filter_options, SORT_NATURAL);

$nakyl_sozder = [
    ['kk' => 'Еңбек түбі — береке.', 'ru' => 'В основе труда — достаток.'],
    ['kk' => 'Білімді мыңды жығады.', 'ru' => 'Знающий одолеет тысячу.'],
    ['kk' => 'Отан отбасынан басталады.', 'ru' => 'Родина начинается с семьи.'],
    ['kk' => 'Жақсы сөз — жарым ырыс.', 'ru' => 'Доброе слово — половина счастья.'],
    ['kk' => 'Сабыр түбі — сары алтын.', 'ru' => 'Терпение в итоге — чистое золото.'],
    ['kk' => 'Бірлік бар жерде — тірлік бар.', 'ru' => 'Где есть единство, там есть жизнь.'],
    ['kk' => 'Ақыл — тозбас тон, білім — таусылмас кен.', 'ru' => 'Ум не износится, знание не иссякнет.'],
    ['kk' => 'Ұяда не көрсе, ұшқанда соны іледі.', 'ru' => 'Что видит в гнезде, то и несёт в полёт.'],
    ['kk' => 'Тәрбие — тал бесіктен.', 'ru' => 'Воспитание начинается с колыбели.'],
    ['kk' => 'Жігітке жеті өнер де аз.', 'ru' => 'Джигиту и семи ремёсел мало.'],
    ['kk' => 'Оқу — инемен құдық қазғандай.', 'ru' => 'Учёба похожа на колодец, выкопанный иглой.'],
    ['kk' => 'Елдің ертеңі — жастар.', 'ru' => 'Будущее народа — молодёжь.'],
    ['kk' => 'Адал еңбек абырой әкеледі.', 'ru' => 'Честный труд приносит честь.'],
    ['kk' => 'Батыр бір рет өледі, қорқақ мың өледі.', 'ru' => 'Храбрец умирает один раз, трус — тысячу.'],
    ['kk' => 'Көп түкірсе — көл.', 'ru' => 'Много малых усилий складываются в большое дело.'],
];
$nakyl_index = random_int(0, count($nakyl_sozder) - 1);
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Отчеты директора — <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
    <style>
        :root {
            --dir-bg: #f1f5f9;
            --dir-surface: #ffffff;
            --dir-border: #e2e8f0;
            --dir-text: #0f172a;
            --dir-muted: #64748b;
            --dir-primary: #1d4ed8;
            --dir-primary-soft: #eff6ff;
            --dir-success: #059669;
            --dir-warning: #d97706;
            --dir-danger: #dc2626;
            --dir-info: #0891b2;
            --dir-radius: 12px;
            --dir-shadow: 0 1px 2px rgba(15, 23, 42, 0.06), 0 4px 12px rgba(15, 23, 42, 0.04);
        }

        * {
            box-sizing: border-box;
        }

        body {
            font-family: 'Manrope', system-ui, sans-serif;
            background:
                radial-gradient(ellipse 80% 50% at 10% -10%, rgba(29, 78, 216, 0.08), transparent),
                radial-gradient(ellipse 60% 40% at 100% 0%, rgba(8, 145, 178, 0.06), transparent),
                var(--dir-bg);
            color: var(--dir-text);
            min-height: 100vh;
            -webkit-font-smoothing: antialiased;
        }

        .dir-nav {
            background: color-mix(in srgb, var(--dir-surface) 92%, transparent);
            border-bottom: 1px solid var(--dir-border);
            backdrop-filter: blur(10px);
            position: sticky;
            top: 0;
            z-index: 1030;
        }

        .dir-nav .navbar-brand {
            font-weight: 800;
            color: var(--dir-text);
            letter-spacing: -0.02em;
        }

        .dir-nav-logo {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: #fff;
            border: 1px solid var(--dir-border);
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px;
            box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
            flex-shrink: 0;
        }

        .dir-nav-logo img {
            width: 100%;
            height: 100%;
            object-fit: contain;
            display: block;
        }

        .dir-user {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-left: auto;
            margin-right: 0.75rem;
        }

        .dir-user-meta {
            text-align: right;
            line-height: 1.2;
        }

        .dir-user-name {
            font-weight: 700;
            font-size: 0.95rem;
        }

        .dir-user-role {
            font-size: 0.75rem;
            color: var(--dir-muted);
        }

        .dir-avatar {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            background: var(--dir-primary-soft);
            color: var(--dir-primary);
            display: grid;
            place-items: center;
            font-weight: 800;
        }

        .btn-logout {
            border: 1px solid var(--dir-border);
            background: var(--dir-surface);
            color: var(--dir-muted);
            font-weight: 600;
            border-radius: 10px;
            padding: 0.45rem 0.9rem;
        }

        .btn-logout:hover {
            background: #fef2f2;
            border-color: #fecaca;
            color: var(--dir-danger);
        }

        .dir-wrap {
            max-width: 1400px;
            margin: 0 auto;
            padding: 1.5rem 1.25rem 3rem;
        }

        .dir-header {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 1.25rem;
        }

        .dir-header h1 {
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            margin: 0 0 0.25rem;
        }

        .dir-header p {
            margin: 0;
            color: var(--dir-muted);
            font-size: 0.95rem;
        }

        .kpi-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.85rem;
            margin-bottom: 1.25rem;
        }

        .kpi {
            background: var(--dir-surface);
            border: 1px solid var(--dir-border);
            border-radius: var(--dir-radius);
            box-shadow: var(--dir-shadow);
            padding: 1rem 1.1rem;
            display: flex;
            align-items: center;
            gap: 0.85rem;
            min-height: 88px;
        }

        .kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: grid;
            place-items: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }

        .kpi-icon.blue { background: #eff6ff; color: var(--dir-primary); }
        .kpi-icon.green { background: #ecfdf5; color: var(--dir-success); }
        .kpi-icon.amber { background: #fffbeb; color: var(--dir-warning); }
        .kpi-icon.cyan { background: #ecfeff; color: var(--dir-info); }
        .kpi-icon.red { background: #fef2f2; color: var(--dir-danger); }
        .kpi-icon.slate { background: #f8fafc; color: #475569; }

        .kpi-value {
            font-size: 1.65rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            line-height: 1;
            margin-bottom: 0.2rem;
        }

        .kpi-label {
            font-size: 0.8rem;
            color: var(--dir-muted);
            font-weight: 600;
            line-height: 1.25;
        }

        .dir-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.4rem;
            background: var(--dir-surface);
            border: 1px solid var(--dir-border);
            border-radius: var(--dir-radius);
            padding: 0.4rem;
            margin-bottom: 1.25rem;
            box-shadow: var(--dir-shadow);
        }

        .dir-tabs .nav-link {
            border: none;
            border-radius: 9px;
            color: var(--dir-muted);
            font-weight: 700;
            font-size: 0.9rem;
            padding: 0.65rem 1rem;
        }

        .dir-tabs .nav-link:hover {
            background: #f8fafc;
            color: var(--dir-text);
        }

        .dir-tabs .nav-link.active {
            background: var(--dir-primary);
            color: #fff;
        }

        .dir-tabs .nav-link i {
            margin-right: 0.35rem;
        }

        .panel {
            background: var(--dir-surface);
            border: 1px solid var(--dir-border);
            border-radius: var(--dir-radius);
            box-shadow: var(--dir-shadow);
            margin-bottom: 1rem;
            overflow: hidden;
        }

        .panel-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            padding: 1rem 1.15rem;
            border-bottom: 1px solid var(--dir-border);
            background: #fafbfc;
        }

        .panel-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .panel-title i {
            color: var(--dir-primary);
        }

        .panel-body {
            padding: 1rem 1.15rem;
        }

        .panel-body.p-0 {
            padding: 0;
        }

        .table {
            margin: 0;
            color: var(--dir-text);
        }

        .table thead th {
            background: #f8fafc;
            border-bottom: 1px solid var(--dir-border);
            color: var(--dir-muted);
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            padding: 0.75rem 1rem;
            white-space: nowrap;
        }

        .table tbody td {
            padding: 0.75rem 1rem;
            border-bottom: 1px solid #f1f5f9;
            vertical-align: middle;
            font-size: 0.92rem;
        }

        .table tbody tr:hover {
            background: #f8fafc;
        }

        .table-striped > tbody > tr:nth-of-type(odd) > * {
            --bs-table-bg-type: transparent;
        }

        .badge-soft {
            display: inline-flex;
            align-items: center;
            gap: 0.25rem;
            border-radius: 999px;
            padding: 0.25rem 0.65rem;
            font-size: 0.75rem;
            font-weight: 700;
            border: 1px solid transparent;
        }

        .badge-soft.blue { background: #eff6ff; color: var(--dir-primary); border-color: #bfdbfe; }
        .badge-soft.green { background: #ecfdf5; color: var(--dir-success); border-color: #a7f3d0; }
        .badge-soft.amber { background: #fffbeb; color: var(--dir-warning); border-color: #fde68a; }
        .badge-soft.cyan { background: #ecfeff; color: var(--dir-info); border-color: #a5f3fc; }
        .badge-soft.red { background: #fef2f2; color: var(--dir-danger); border-color: #fecaca; }
        .badge-soft.slate { background: #f1f5f9; color: #475569; border-color: #e2e8f0; }

        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.6rem;
            align-items: center;
        }

        .toolbar .form-control,
        .toolbar .form-select {
            border: 1px solid var(--dir-border);
            border-radius: 10px;
            min-height: 40px;
            font-size: 0.9rem;
            box-shadow: none;
        }

        .toolbar .form-control:focus,
        .toolbar .form-select:focus {
            border-color: var(--dir-primary);
            box-shadow: 0 0 0 3px rgba(29, 78, 216, 0.12);
        }

        .search-box {
            position: relative;
            min-width: min(280px, 100%);
            flex: 1;
        }

        .search-box i {
            position: absolute;
            left: 0.85rem;
            top: 50%;
            transform: translateY(-50%);
            color: var(--dir-muted);
        }

        .search-box .form-control {
            padding-left: 2.35rem;
        }

        .btn-soft {
            border: 1px solid var(--dir-border);
            background: var(--dir-surface);
            color: var(--dir-text);
            font-weight: 700;
            border-radius: 10px;
            padding: 0.45rem 0.85rem;
            font-size: 0.875rem;
        }

        .btn-soft:hover {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: var(--dir-text);
        }

        .btn-primary-dir {
            background: var(--dir-primary);
            border: none;
            color: #fff;
            font-weight: 700;
            border-radius: 10px;
            padding: 0.5rem 1rem;
        }

        .btn-primary-dir:hover {
            background: #1e40af;
            color: #fff;
        }

        .social-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 0.85rem;
        }

        .social-tile {
            border: 1px solid var(--dir-border);
            border-radius: var(--dir-radius);
            padding: 1.25rem 1rem;
            text-align: center;
            background: linear-gradient(180deg, #fff 0%, #f8fafc 100%);
        }

        .social-tile .n {
            font-size: 2rem;
            font-weight: 800;
            letter-spacing: -0.03em;
            color: var(--dir-primary);
            line-height: 1;
            margin-bottom: 0.4rem;
        }

        .social-tile .l {
            color: var(--dir-muted);
            font-weight: 700;
            font-size: 0.85rem;
        }

        .group-item {
            border: 1px solid var(--dir-border);
            border-radius: 10px;
            margin-bottom: 0.65rem;
            overflow: hidden;
            background: #fff;
        }

        .group-item .accordion-button {
            background: #fafbfc;
            font-weight: 700;
            box-shadow: none;
            padding: 0.9rem 1rem;
        }

        .group-item .accordion-button:not(.collapsed) {
            background: var(--dir-primary-soft);
            color: var(--dir-text);
        }

        .group-item .accordion-button:focus {
            box-shadow: none;
            border-color: transparent;
        }

        .group-item .accordion-button::after {
            margin-left: 0.75rem;
        }

        .meta-line {
            color: var(--dir-muted);
            font-size: 0.85rem;
            margin: 0.5rem 0 0;
        }

        .empty-hint {
            color: var(--dir-muted);
            font-size: 0.9rem;
            padding: 1.5rem;
            text-align: center;
        }

        #searchResultHint {
            font-size: 0.85rem;
            color: var(--dir-muted);
            font-weight: 600;
            min-height: 1.25rem;
        }

        .dir-quote {
            background: var(--dir-surface);
            border: 1px solid var(--dir-border);
            border-radius: var(--dir-radius);
            box-shadow: var(--dir-shadow);
            padding: 1.25rem 1.35rem 1.15rem;
            margin-bottom: 1.25rem;
        }

        .dir-quote-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            margin-bottom: 0.85rem;
        }

        .dir-quote-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            letter-spacing: -0.02em;
        }

        .dir-quote-count {
            font-size: 0.75rem;
            font-weight: 600;
            color: var(--dir-muted);
        }

        .dir-quote-text {
            margin: 0 0 0.65rem;
            font-size: 1.35rem;
            font-weight: 800;
            line-height: 1.45;
            letter-spacing: -0.02em;
            color: var(--dir-text);
        }

        .dir-quote-meaning {
            margin: 0 0 1rem;
            font-size: 0.95rem;
            color: var(--dir-muted);
            font-weight: 600;
        }

        @media (max-width: 1100px) {
            .kpi-grid,
            .social-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        @media (max-width: 640px) {
            .kpi-grid,
            .social-grid {
                grid-template-columns: 1fr;
            }

            .dir-user-meta {
                display: none;
            }

            .dir-wrap {
                padding: 1rem 0.75rem 2rem;
            }

            .dir-tabs .nav-link {
                flex: 1 1 auto;
                text-align: center;
                font-size: 0.8rem;
                padding: 0.55rem 0.5rem;
            }
        }

        @media print {
            .dir-nav,
            .dir-tabs,
            .toolbar,
            .btn,
            .btn-soft,
            .btn-primary-dir {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .tab-pane {
                display: block !important;
                opacity: 1 !important;
            }

            .panel,
            .kpi {
                box-shadow: none;
                break-inside: avoid;
            }
        }
    </style>
</head>

<body>
    <nav class="navbar navbar-expand-lg dir-nav">
        <div class="container-fluid px-3 px-lg-4">
            <a class="navbar-brand d-flex align-items-center gap-2" href="dashboard.php">
                <span class="dir-nav-logo">
                    <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>">
                </span>
                <span>Панель директора</span>
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#dirNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="dirNav">
                <div class="dir-user">
                    <div class="dir-user-meta">
                        <div class="dir-user-name"><?php echo $user_name; ?></div>
                        <div class="dir-user-role"><?php echo htmlspecialchars($role_label); ?></div>
                    </div>
                    <div class="dir-avatar" aria-hidden="true">
                        <?php echo mb_strtoupper(mb_substr($user_name !== '' ? $user_name : 'Д', 0, 1)); ?>
                    </div>
                </div>
                <?php appThemeToggle(); ?>
                <a class="btn btn-logout" href="../logout.php">
                    <i class="bi bi-box-arrow-right me-1"></i>Выход
                </a>
            </div>
        </div>
    </nav>

    <div class="dir-wrap">
        <div class="dir-header">
            <div>
                <h1>Отчеты по студентам</h1>
                <p>Сводка по контингенту, группам и социальным категориям</p>
            </div>
            <button type="button" class="btn btn-primary-dir" onclick="window.print()">
                <i class="bi bi-printer me-1"></i>Печать
            </button>
        </div>

        <div class="kpi-grid">
            <div class="kpi">
                <div class="kpi-icon blue"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['total_students']; ?></div>
                    <div class="kpi-label">Всего студентов</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon green"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['active_students']; ?></div>
                    <div class="kpi-label">Активных</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon amber"><i class="bi bi-person-wheelchair"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['disabled_students']; ?></div>
                    <div class="kpi-label">С инвалидностью</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon cyan"><i class="bi bi-heart-fill"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['orphan_students']; ?></div>
                    <div class="kpi-label">Дети-сироты</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon red"><i class="bi bi-person-badge"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['minor_students']; ?></div>
                    <div class="kpi-label">Несовершеннолетних</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon slate"><i class="bi bi-pause-circle"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['academic_leave_students']; ?></div>
                    <div class="kpi-label">В академ. отпуске</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon amber"><i class="bi bi-shield-exclamation"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['without_parental_care_students']; ?></div>
                    <div class="kpi-label">Без попечения</div>
                </div>
            </div>
            <div class="kpi">
                <div class="kpi-icon blue"><i class="bi bi-house-heart"></i></div>
                <div>
                    <div class="kpi-value"><?php echo (int)$stats['large_family_students']; ?></div>
                    <div class="kpi-label">Многодетные семьи</div>
                </div>
            </div>
        </div>

        <section class="dir-quote" aria-labelledby="nakylTitle">
            <div class="dir-quote-head">
                <h2 class="dir-quote-title" id="nakylTitle">Нақыл сөздер</h2>
                <span class="dir-quote-count" id="nakylCount"></span>
            </div>
            <blockquote class="dir-quote-text" id="nakylText"></blockquote>
            <p class="dir-quote-meaning" id="nakylMeaning"></p>
            <button type="button" class="btn btn-soft btn-sm" id="nakylNext">
                Келесі
            </button>
        </section>

        <ul class="nav dir-tabs" id="dirTabs" role="tablist">
            <li class="nav-item" role="presentation">
                <button class="nav-link active" id="tab-overview" data-bs-toggle="tab" data-bs-target="#pane-overview" type="button" role="tab">
                    <i class="bi bi-bar-chart"></i>Обзор
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-groups" data-bs-toggle="tab" data-bs-target="#pane-groups" type="button" role="tab">
                    <i class="bi bi-collection"></i>Группы
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-students" data-bs-toggle="tab" data-bs-target="#pane-students" type="button" role="tab">
                    <i class="bi bi-people"></i>Студенты
                </button>
            </li>
            <li class="nav-item" role="presentation">
                <button class="nav-link" id="tab-social" data-bs-toggle="tab" data-bs-target="#pane-social" type="button" role="tab">
                    <i class="bi bi-heart"></i>Соц. категории
                </button>
            </li>
        </ul>

        <div class="tab-content">
            <!-- Обзор -->
            <div class="tab-pane fade show active" id="pane-overview" role="tabpanel">
                <div class="panel">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-mortarboard-fill me-2"></i>Статистика по курсам</h2>
                    </div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Курс</th>
                                        <th>Всего</th>
                                        <th>Инвалидность</th>
                                        <th>Сироты</th>
                                        <th>Академ. отпуск</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($courses_stats)): ?>
                                        <tr><td colspan="5" class="empty-hint">Нет данных</td></tr>
                                    <?php else: ?>
                                        <?php foreach ($courses_stats as $course): ?>
                                            <tr>
                                                <td><strong><?php echo htmlspecialchars((string)$course['course']); ?></strong></td>
                                                <td><?php echo (int)$course['total_students']; ?></td>
                                                <td>
                                                    <?php if ((int)$course['disabled_students'] > 0): ?>
                                                        <span class="badge-soft amber"><?php echo (int)$course['disabled_students']; ?></span>
                                                    <?php else: ?>0<?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$course['orphan_students'] > 0): ?>
                                                        <span class="badge-soft cyan"><?php echo (int)$course['orphan_students']; ?></span>
                                                    <?php else: ?>0<?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ((int)$course['academic_leave_students'] > 0): ?>
                                                        <span class="badge-soft slate"><?php echo (int)$course['academic_leave_students']; ?></span>
                                                    <?php else: ?>0<?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <?php if (!empty($disability_stats)): ?>
                    <div class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title"><i class="bi bi-wheelchair me-2"></i>Статистика по инвалидности</h2>
                        </div>
                        <div class="panel-body p-0">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Тип нарушения</th>
                                            <th>Количество</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($disability_stats as $disability): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($disability['primary_violation']); ?></td>
                                                <td><span class="badge-soft amber"><?php echo (int)$disability['count']; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Группы -->
            <div class="tab-pane fade" id="pane-groups" role="tabpanel">
                <div class="panel">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-collection me-2"></i>Группы и кураторы</h2>
                        <div class="toolbar">
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="groupSearch" class="form-control" placeholder="Поиск группы, куратора…">
                            </div>
                            <select id="groupCourseFilter" class="form-select" style="min-width: 140px;">
                                <option value="">Все курсы</option>
                                <?php foreach ($course_filter_options as $course_opt): ?>
                                    <option value="<?php echo htmlspecialchars((string)$course_opt); ?>">
                                        <?php echo htmlspecialchars((string)$course_opt); ?> курс
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="panel-body p-0">
                        <div class="table-responsive">
                            <table class="table" id="groupsTable">
                                <thead>
                                    <tr>
                                        <th>Группа</th>
                                        <th>Код</th>
                                        <th>Курс</th>
                                        <th>Специальность</th>
                                        <th>Куратор</th>
                                        <th>Студентов</th>
                                        <th>Инвалидность</th>
                                        <th>Сироты</th>
                                        <th>Академ.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groups_stats as $g): ?>
                                        <tr class="group-row"
                                            data-course="<?php echo htmlspecialchars((string)$g['course']); ?>"
                                            data-text="<?php echo htmlspecialchars(mb_strtolower(
                                                ($g['group_name'] ?? '') . ' ' .
                                                ($g['group_code'] ?? '') . ' ' .
                                                ($g['specialty'] ?? '') . ' ' .
                                                ($g['curator_name'] ?? '')
                                            )); ?>">
                                            <td><strong><?php echo htmlspecialchars($g['group_name']); ?></strong></td>
                                            <td>
                                                <?php if (!empty($g['group_code'])): ?>
                                                    <span class="badge-soft blue"><?php echo htmlspecialchars($g['group_code']); ?></span>
                                                <?php else: ?>
                                                    <span class="badge-soft slate">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?php echo htmlspecialchars((string)$g['course']); ?></td>
                                            <td><?php echo htmlspecialchars((string)$g['specialty']); ?></td>
                                            <td>
                                                <?php if (!empty(trim((string)$g['curator_name']))): ?>
                                                    <div><?php echo htmlspecialchars(trim($g['curator_name'])); ?></div>
                                                    <?php if (!empty($g['curator_email'])): ?>
                                                        <small class="text-muted"><?php echo htmlspecialchars($g['curator_email']); ?></small>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    <span class="badge-soft slate">Не назначен</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><strong><?php echo (int)$g['student_count']; ?></strong></td>
                                            <td>
                                                <?php if ((int)$g['disabled_count'] > 0): ?>
                                                    <span class="badge-soft amber"><?php echo (int)$g['disabled_count']; ?></span>
                                                <?php else: ?>0<?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$g['orphan_count'] > 0): ?>
                                                    <span class="badge-soft cyan"><?php echo (int)$g['orphan_count']; ?></span>
                                                <?php else: ?>0<?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ((int)$g['academic_leave_count'] > 0): ?>
                                                    <span class="badge-soft slate"><?php echo (int)$g['academic_leave_count']; ?></span>
                                                <?php else: ?>0<?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Студенты -->
            <div class="tab-pane fade" id="pane-students" role="tabpanel">
                <div class="panel">
                    <div class="panel-head">
                        <div>
                            <h2 class="panel-title"><i class="bi bi-people-fill me-2"></i>Студенты по группам</h2>
                            <p class="meta-line">
                                Групп: <strong><?php echo count($students_by_group); ?></strong>
                                · Студентов: <strong><?php echo count($all_students); ?></strong>
                            </p>
                        </div>
                        <div class="toolbar">
                            <button type="button" class="btn btn-soft" onclick="expandAllGroups()">
                                <i class="bi bi-arrows-expand me-1"></i>Развернуть
                            </button>
                            <button type="button" class="btn btn-soft" onclick="collapseAllGroups()">
                                <i class="bi bi-arrows-collapse me-1"></i>Свернуть
                            </button>
                            <div class="search-box">
                                <i class="bi bi-search"></i>
                                <input type="text" id="studentSearch" class="form-control" placeholder="ФИО, телефон, группа… (Ctrl+F)">
                            </div>
                        </div>
                    </div>
                    <div class="panel-body">
                        <div id="searchResultHint"></div>
                        <div class="accordion" id="groupsAccordion">
                            <?php foreach ($students_by_group as $group_id => $group_data):
                                $collapse_id = 'collapse_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$group_id);
                                $heading_id = 'heading_' . preg_replace('/[^a-zA-Z0-9_]/', '_', (string)$group_id);
                                $student_count = count($group_data['students']);
                            ?>
                                <div class="accordion-item group-item group-section">
                                    <h2 class="accordion-header" id="<?php echo $heading_id; ?>">
                                        <button class="accordion-button collapsed" type="button"
                                            data-bs-toggle="collapse"
                                            data-bs-target="#<?php echo $collapse_id; ?>"
                                            aria-expanded="false"
                                            aria-controls="<?php echo $collapse_id; ?>">
                                            <div class="d-flex flex-wrap align-items-center gap-2 w-100 pe-2">
                                                <span><?php echo htmlspecialchars($group_data['group_name']); ?></span>
                                                <?php if ($group_data['group_code']): ?>
                                                    <span class="badge-soft blue"><?php echo htmlspecialchars($group_data['group_code']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($group_data['course'] !== '' && $group_data['course'] !== null): ?>
                                                    <span class="badge-soft cyan"><?php echo htmlspecialchars((string)$group_data['course']); ?> курс</span>
                                                <?php endif; ?>
                                                <span class="badge-soft green"><?php echo $student_count; ?></span>
                                                <?php if (!empty(trim((string)$group_data['curator_name']))): ?>
                                                    <span class="ms-auto text-muted small fw-normal d-none d-md-inline">
                                                        <i class="bi bi-person-badge me-1"></i><?php echo htmlspecialchars(trim($group_data['curator_name'])); ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </button>
                                    </h2>
                                    <div id="<?php echo $collapse_id; ?>" class="accordion-collapse collapse"
                                        aria-labelledby="<?php echo $heading_id; ?>">
                                        <div class="accordion-body p-0">
                                            <div class="table-responsive">
                                                <table class="table mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th style="width:48px;">№</th>
                                                            <th>ФИО</th>
                                                            <th style="width:110px;">Возраст</th>
                                                            <th>Контакты</th>
                                                            <th>Особые отметки</th>
                                                            <th style="width:130px;">Статус</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php
                                                        $student_counter = 1;
                                                        foreach ($group_data['students'] as $student):
                                                            $full_name = trim(
                                                                htmlspecialchars($student['last_name']) . ' ' .
                                                                htmlspecialchars($student['first_name']) . ' ' .
                                                                htmlspecialchars($student['middle_name'] ?? '')
                                                            );
                                                            $age = (int)$student['age'];
                                                            $is_minor = $age < 18;
                                                        ?>
                                                            <tr class="student-row">
                                                                <td><?php echo $student_counter++; ?></td>
                                                                <td><strong><?php echo $full_name; ?></strong></td>
                                                                <td>
                                                                    <strong><?php echo $age; ?></strong>
                                                                    <?php if ($is_minor): ?>
                                                                        <div><span class="badge-soft red">Несов.</span></div>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php if (!empty($student['phone'])): ?>
                                                                        <div><i class="bi bi-telephone me-1 text-muted"></i><small><?php echo htmlspecialchars($student['phone']); ?></small></div>
                                                                    <?php endif; ?>
                                                                    <?php if (!empty($student['email'])): ?>
                                                                        <div><i class="bi bi-envelope me-1 text-muted"></i><small><?php echo htmlspecialchars($student['email']); ?></small></div>
                                                                    <?php endif; ?>
                                                                    <?php if (empty($student['phone']) && empty($student['email'])): ?>
                                                                        <span class="text-muted">—</span>
                                                                    <?php endif; ?>
                                                                </td>
                                                                <td>
                                                                    <?php
                                                                    $marks = [];
                                                                    if ($student['disability']) {
                                                                        $marks[] = '<span class="badge-soft amber mb-1"><i class="bi bi-person-wheelchair"></i> Инвалидность</span>'
                                                                            . (!empty($student['primary_violation'])
                                                                                ? '<div><small class="text-muted">' . htmlspecialchars($student['primary_violation']) . '</small></div>'
                                                                                : '');
                                                                    }
                                                                    if ($student['orphan']) {
                                                                        $marks[] = '<span class="badge-soft cyan mb-1">Сирота</span>';
                                                                    }
                                                                    if ($student['without_parental_care']) {
                                                                        $marks[] = '<span class="badge-soft cyan mb-1">Без попечения</span>';
                                                                    }
                                                                    if ($student['large_family']) {
                                                                        $marks[] = '<span class="badge-soft blue mb-1">Многодетная</span>';
                                                                    }
                                                                    echo $marks ? implode('<br>', $marks) : '<span class="text-muted">—</span>';
                                                                    ?>
                                                                </td>
                                                                <td>
                                                                    <?php if ($student['academic_leave']): ?>
                                                                        <span class="badge-soft slate">Академ.</span>
                                                                    <?php else: ?>
                                                                        <span class="badge-soft green">Активен</span>
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
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Соц. категории -->
            <div class="tab-pane fade" id="pane-social" role="tabpanel">
                <div class="panel">
                    <div class="panel-head">
                        <h2 class="panel-title"><i class="bi bi-heart me-2"></i>Социальные категории</h2>
                    </div>
                    <div class="panel-body">
                        <div class="social-grid">
                            <?php foreach ($social_stats as $social): ?>
                                <div class="social-tile">
                                    <div class="n"><?php echo (int)$social['count']; ?></div>
                                    <div class="l"><?php echo htmlspecialchars($social['category']); ?></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <?php if (!empty($disability_stats)): ?>
                    <div class="panel">
                        <div class="panel-head">
                            <h2 class="panel-title"><i class="bi bi-list-ul me-2"></i>Детализация по типам нарушений</h2>
                        </div>
                        <div class="panel-body p-0">
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Тип нарушения</th>
                                            <th>Количество</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($disability_stats as $disability): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($disability['primary_violation']); ?></td>
                                                <td><span class="badge-soft amber"><?php echo (int)$disability['count']; ?></span></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
    <script src="../assets/js/tooltips.js"></script>
    <script>
        function expandAllGroups() {
            document.querySelectorAll('#groupsAccordion .accordion-collapse').forEach(el => {
                bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).show();
            });
        }

        function collapseAllGroups() {
            document.querySelectorAll('#groupsAccordion .accordion-collapse').forEach(el => {
                bootstrap.Collapse.getOrCreateInstance(el, { toggle: false }).hide();
            });
        }

        function filterGroupsTable() {
            const q = (document.getElementById('groupSearch')?.value || '').toLowerCase().trim();
            const course = document.getElementById('groupCourseFilter')?.value || '';
            document.querySelectorAll('#groupsTable .group-row').forEach(row => {
                const textOk = !q || (row.dataset.text || '').includes(q);
                const courseOk = !course || row.dataset.course === course;
                row.style.display = textOk && courseOk ? '' : 'none';
            });
        }

        document.getElementById('groupSearch')?.addEventListener('input', filterGroupsTable);
        document.getElementById('groupCourseFilter')?.addEventListener('change', filterGroupsTable);

        const studentSearchInput = document.getElementById('studentSearch');
        const searchHint = document.getElementById('searchResultHint');

        studentSearchInput?.addEventListener('input', function () {
            const searchTerm = this.value.toLowerCase().trim();
            const groupSections = document.querySelectorAll('.group-section');
            let totalVisible = 0;

            groupSections.forEach(section => {
                const rows = section.querySelectorAll('.student-row');
                let hasVisible = false;

                rows.forEach(row => {
                    const match = !searchTerm || row.textContent.toLowerCase().includes(searchTerm);
                    row.style.display = match ? '' : 'none';
                    if (match) {
                        hasVisible = true;
                        if (searchTerm) totalVisible++;
                    }
                });

                if (!searchTerm) {
                    section.style.display = '';
                } else if (hasVisible) {
                    section.style.display = '';
                    const collapse = section.querySelector('.accordion-collapse');
                    if (collapse && !collapse.classList.contains('show')) {
                        bootstrap.Collapse.getOrCreateInstance(collapse, { toggle: false }).show();
                    }
                } else {
                    section.style.display = 'none';
                }
            });

            if (searchHint) {
                searchHint.textContent = searchTerm
                    ? `Найдено студентов: ${totalVisible}`
                    : '';
            }
        });

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'f') {
                const studentsTab = document.getElementById('tab-students');
                const searchInput = document.getElementById('studentSearch');
                if (studentsTab && searchInput) {
                    e.preventDefault();
                    bootstrap.Tab.getOrCreateInstance(studentsTab).show();
                    setTimeout(() => {
                        searchInput.focus();
                        searchInput.select();
                    }, 50);
                }
            }
        });

        const nakylSozder = <?php echo json_encode($nakyl_sozder, JSON_UNESCAPED_UNICODE); ?>;
        let nakylIndex = <?php echo (int)$nakyl_index; ?>;
        let lastNakylIndex = nakylIndex;

        function randomNakylIndex() {
            if (nakylSozder.length < 2) return 0;
            let next = Math.floor(Math.random() * nakylSozder.length);
            while (next === lastNakylIndex) {
                next = Math.floor(Math.random() * nakylSozder.length);
            }
            return next;
        }

        function showNakyl() {
            const item = nakylSozder[nakylIndex];
            lastNakylIndex = nakylIndex;
            document.getElementById('nakylText').textContent = item.kk;
            document.getElementById('nakylMeaning').textContent = item.ru;
            document.getElementById('nakylCount').textContent = 'кездейсоқ';
        }

        document.getElementById('nakylNext').addEventListener('click', function () {
            nakylIndex = randomNakylIndex();
            showNakyl();
        });

        showNakyl();
    </script>
</body>

</html>
