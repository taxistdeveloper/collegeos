<?php
if (!isset($current_user)) {
    throw new Exception('$current_user не определен');
}

$page_title = $page_title ?? 'Панель менеджера';
$page_subtitle = $page_subtitle ?? '';

$name_parts = preg_split('/\s+/', trim($current_user['name'] ?? ''), 2);
$initials = '';
if (!empty($name_parts[0])) {
    $initials .= mb_strtoupper(mb_substr($name_parts[0], 0, 1));
}
if (!empty($name_parts[1])) {
    $initials .= mb_strtoupper(mb_substr($name_parts[1], 0, 1));
}
if ($initials === '') {
    $initials = 'М';
}
?>

<header class="manager-header">
    <div class="manager-header-left d-flex align-items-center gap-2">
        <button type="button" class="manager-header-menu-btn" id="managerSidebarToggle" aria-label="Меню">
            <i class="bi bi-list"></i>
        </button>
        <div>
            <h1 class="manager-header-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php if ($page_subtitle): ?>
                <p class="manager-header-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="manager-header-right">
        <?php if (empty($hide_header_search)): ?>
        <div class="manager-header-search">
            <i class="bi bi-search search-icon"></i>
            <input type="text" id="managerGlobalSearch" placeholder="Найти студента..." autocomplete="off">
        </div>
        <?php endif; ?>
        <?php appThemeToggle(); ?>
        <div class="manager-header-avatar" title="<?php echo htmlspecialchars($current_user['name'] ?? ''); ?>">
            <?php echo htmlspecialchars($initials); ?>
        </div>
    </div>
</header>
