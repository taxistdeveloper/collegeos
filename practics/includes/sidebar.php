<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}

$current_page = basename($_SERVER['PHP_SELF']);
$role = $current_user['role'] ?? '';
$isTeacher = ($role === 'teacher');
$isAdminOrMethodist = in_array($role, ['admin', 'methodist', 'director', 'manager', 'department_head', 'practicant'], true);

$role_labels = [
    'admin' => 'Администратор',
    'methodist' => 'Учебная часть',
    'teacher' => 'Преподаватель',
    'curator' => 'Куратор',
    'department_head' => 'Зав. отделением',
    'practicant' => 'Практикант',
];
$role_label = $role_labels[$role] ?? 'Практика';

$menu_items = [];
$menu_items[] = ['name' => 'Обзор', 'url' => 'index.php', 'icon' => 'grid-1x2-fill'];

if ($isAdminOrMethodist) {
    $menu_items[] = ['name' => 'Нагрузки', 'url' => 'workloads.php', 'icon' => 'journal-bookmark'];
    $menu_items[] = ['name' => 'Сданные графики', 'url' => 'submissions.php', 'icon' => 'check2-square'];
    $menu_items[] = ['name' => 'Отчеты', 'url' => 'reports.php', 'icon' => 'calendar-event'];
    $menu_items[] = ['name' => 'Преподаватели', 'url' => 'teachers.php', 'icon' => 'people'];
} else {
    $menu_items[] = ['name' => 'Моя нагрузка', 'url' => 'index.php', 'icon' => 'list-task'];
}
?>

<aside class="curator-sidebar" id="curatorSidebar">
    <div class="curator-sidebar-brand">
        <a href="index.php" class="curator-sidebar-brand-link">
            <div class="curator-sidebar-logo">
                <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>">
            </div>
            <div class="curator-sidebar-brand-text">
                <div class="curator-sidebar-brand-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                <div class="curator-sidebar-brand-sub">Модуль практики</div>
            </div>
        </a>
    </div>

    <nav class="curator-sidebar-nav">
        <?php foreach ($menu_items as $item):
            $is_active = ($current_page === $item['url']);
        ?>
            <a href="<?php echo htmlspecialchars($item['url']); ?>"
               class="nav-link<?php echo $is_active ? ' active' : ''; ?>">
                <i class="bi bi-<?php echo htmlspecialchars($item['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($item['name']); ?></span>
            </a>
        <?php endforeach; ?>

        <div class="mt-4 pt-3 border-top border-secondary-subtle">
            <a href="../uchebni/dashboard.php" class="nav-link text-muted">
                <i class="bi bi-box-arrow-left"></i>
                <span>Портал колледжа</span>
            </a>
        </div>
    </nav>

    <div class="curator-sidebar-footer">
        <div class="curator-sidebar-card">
            <div class="curator-sidebar-card-title"><?php echo htmlspecialchars($current_user['name']); ?></div>
            <div class="curator-sidebar-card-text"><?php echo htmlspecialchars($role_label); ?> · <?php echo date('d.m.Y'); ?></div>
            <?php renderPortalSwitcher(); ?>
            <a href="../logout.php" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Выход
            </a>
        </div>
    </div>
</aside>
<div class="curator-sidebar-overlay" id="curatorSidebarOverlay"></div>

