<?php
/**
 * Layout helpers for department head portal
 */
if (!isset($page_title)) {
    $page_title = 'Отделение';
}
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}
$menu_items = getMenuByRole('department_head');
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> — <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/department-ui.css" rel="stylesheet">
</head>
<body class="dept-app">
<div class="dept-shell">
    <aside class="dept-sidebar">
        <div class="dept-brand">
            <div class="dept-brand-icon"><i class="bi bi-building"></i></div>
            <div>
                <div class="dept-brand-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                <div class="dept-brand-sub">Заведующий отделением</div>
            </div>
        </div>
        <nav class="dept-nav">
            <?php foreach ($menu_items as $item):
                $item_page = basename($item['url']);
                $active = $current_page === $item_page;
            ?>
                <a href="<?php echo htmlspecialchars($item['url']); ?>" class="dept-nav-link<?php echo $active ? ' active' : ''; ?>">
                    <i class="bi bi-<?php echo htmlspecialchars($item['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($item['name']); ?></span>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="dept-sidebar-footer">
            <div class="dept-user"><?php echo htmlspecialchars($current_user['name']); ?></div>
            <a href="../logout.php" class="btn btn-sm btn-outline-light w-100">
                <i class="bi bi-box-arrow-right me-1"></i>Выход
            </a>
        </div>
    </aside>
    <main class="dept-main">
        <header class="dept-header">
            <div>
                <h1 class="dept-title"><?php echo htmlspecialchars($page_title); ?></h1>
                <?php if (!empty($page_subtitle)): ?>
                    <p class="dept-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
                <?php endif; ?>
            </div>
        </header>
        <div class="dept-content">
