<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}
$role = $current_user['role'] ?? '';
$page_title = $page_title ?? (($role === 'teacher') ? 'Преподаватель' : 'Учебная часть');
$page_subtitle = $page_subtitle ?? '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> — <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../curator/assets/css/curator-ui.css" rel="stylesheet">
    <link href="assets/css/uchebni-ui.css" rel="stylesheet">
    <?php if (!empty($extra_css) && is_array($extra_css)): ?>
        <?php foreach ($extra_css as $cssHref): ?>
            <link href="<?php echo htmlspecialchars($cssHref); ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="curator-main">
        <?php include __DIR__ . '/topbar.php'; ?>
        <div class="curator-content">
