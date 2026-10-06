<?php
if (!isset($current_user)) {
    throw new Exception('$current_user не определен. Убедитесь, что файл подключен после getCurrentUser()');
}

$current_page = basename($_SERVER['PHP_SELF']);
$menu_items = [
    ['name' => 'Дашборд', 'url' => 'dashboard.php', 'icon' => 'speedometer2'],
    ['name' => 'Студенты', 'url' => 'students.php', 'icon' => 'people-fill'],
    ['name' => 'Группы', 'url' => 'groups.php', 'icon' => 'collection-fill'],
];

$active_pages = [
    'view_student.php' => 'students.php',
    'edit_student.php' => 'students.php',
    'group_details.php' => 'groups.php',
    'group_reports.php' => 'groups.php',
    'reports.php' => 'groups.php',
];
$active_page = $active_pages[$current_page] ?? $current_page;
?>

<aside class="manager-sidebar" id="managerSidebar">
    <div class="manager-sidebar-brand">
        <a href="dashboard.php" class="manager-sidebar-brand-link">
            <div class="manager-sidebar-logo">
                <i class="bi bi-briefcase-fill"></i>
            </div>
            <div class="manager-sidebar-brand-text">
                <div class="manager-sidebar-brand-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                <div class="manager-sidebar-brand-sub">Панель менеджера</div>
            </div>
        </a>
    </div>

    <nav class="manager-sidebar-nav">
        <?php foreach ($menu_items as $menu_item):
            $item_page = basename($menu_item['url']);
            $is_active = ($active_page === $item_page);
        ?>
            <a href="<?php echo htmlspecialchars($menu_item['url']); ?>"
               class="nav-link<?php echo $is_active ? ' active' : ''; ?>">
                <i class="bi bi-<?php echo htmlspecialchars($menu_item['icon']); ?>"></i>
                <span><?php echo htmlspecialchars($menu_item['name']); ?></span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="manager-sidebar-footer">
        <div class="manager-sidebar-card">
            <?php
            $sidebar_user_name = $current_user['name']
                ?? trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? ''));
            if ($sidebar_user_name === '') {
                $sidebar_user_name = 'Менеджер';
            }
            ?>
            <div class="manager-sidebar-card-title"><?php echo htmlspecialchars($sidebar_user_name); ?></div>
            <div class="manager-sidebar-card-text">Менеджер</div>
            <a href="../logout.php" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Выход
            </a>
        </div>
    </div>
</aside>
<div class="manager-sidebar-overlay" id="managerSidebarOverlay"></div>
