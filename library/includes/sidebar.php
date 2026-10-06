<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}

$current_page = basename($_SERVER['PHP_SELF']);
$menu_items = getMenuByRole('librarian');
$active_page = $current_page;
?>

<aside class="curator-sidebar" id="curatorSidebar">
    <div class="curator-sidebar-brand">
        <a href="dashboard.php" class="curator-sidebar-brand-link">
            <div class="curator-sidebar-logo">
                <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>">
            </div>
            <div class="curator-sidebar-brand-text">
                <div class="curator-sidebar-brand-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                <div class="curator-sidebar-brand-sub">Цифровая библиотека</div>
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
            <div class="curator-sidebar-card-title"><?php echo htmlspecialchars($current_user['name']); ?></div>
            <div class="curator-sidebar-card-text">Библиотекарь · <?php echo date('d.m.Y'); ?></div>
            <a href="../logout.php" class="btn btn-primary btn-sm">
                <i class="bi bi-box-arrow-right me-1"></i>Выход
            </a>
        </div>
    </div>
</aside>
<div class="curator-sidebar-overlay" id="curatorSidebarOverlay"></div>
