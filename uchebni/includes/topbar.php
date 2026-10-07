<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}

$role = $current_user['role'] ?? '';
$default_title = ($role === 'teacher') ? 'Преподаватель' : 'Учебная часть';
$page_title = $page_title ?? $default_title;
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
    $initials = ($role === 'teacher') ? 'П' : 'У';
}
?>

<header class="curator-header">
    <div class="curator-header-left d-flex align-items-center gap-2">
        <button type="button" class="curator-header-menu-btn" id="curatorSidebarToggle" aria-label="Меню">
            <i class="bi bi-list"></i>
        </button>
        <div>
            <h1 class="curator-header-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <?php if ($page_subtitle): ?>
                <p class="curator-header-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
            <?php endif; ?>
        </div>
    </div>
    <div class="curator-header-right">
        <?php appThemeToggle(); ?>
        <div class="curator-header-avatar" title="<?php echo htmlspecialchars($current_user['name']); ?>">
            <?php echo htmlspecialchars($initials); ?>
        </div>
    </div>
</header>
