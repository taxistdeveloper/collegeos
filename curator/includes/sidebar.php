<?php
if (!isset($current_user)) {
    throw new Exception('$current_user не определен. Убедитесь, что файл подключен после getCurrentUser()');
}

$current_page = basename($_SERVER['PHP_SELF']);
$menu_items = getMenuByRole('curator');

$active_pages = [
    'group_students.php' => 'my_groups.php',
    'group_details.php' => 'my_groups.php',
    'group_reports.php' => 'group_reports.php',
    'view_student.php' => 'my_students.php',
    'view_student_new.php' => 'my_students.php',
    'edit_student.php' => 'my_students.php',
    'edit_student_new.php' => 'my_students.php',
    'graduate_group.php' => 'my_groups.php',
];
$active_page = $active_pages[$current_page] ?? $current_page;
?>

<aside class="curator-sidebar" id="curatorSidebar">
    <div class="curator-sidebar-brand">
        <a href="dashboard.php" class="curator-sidebar-brand-link">
            <div class="curator-sidebar-logo">
                <i class="bi bi-mortarboard-fill"></i>
            </div>
            <div class="curator-sidebar-brand-text">
                <div class="curator-sidebar-brand-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                <div class="curator-sidebar-brand-sub"><?php echo htmlspecialchars(APP_TAGLINE); ?></div>
            </div>
        </a>
    </div>

    <nav class="curator-sidebar-nav">
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

    <div class="curator-sidebar-footer">
        <div class="curator-sidebar-card">
            <?php
            $sidebar_user_name = $current_user['name']
                ?? trim(($current_user['first_name'] ?? '') . ' ' . ($current_user['last_name'] ?? ''));
            if ($sidebar_user_name === '') {
                $sidebar_user_name = 'Куратор';
            }
            ?>
            <div class="curator-sidebar-card-title"><?php echo htmlspecialchars($sidebar_user_name); ?></div>
            <div class="curator-sidebar-card-text">Куратор</div>
            <a href="../logout.php" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Выход
            </a>
        </div>
    </div>
</aside>
<div class="curator-sidebar-overlay" id="curatorSidebarOverlay"></div>
