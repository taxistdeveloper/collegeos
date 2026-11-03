<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}
$role = $current_user['role'] ?? '';
$page_title = $page_title ?? 'Практика';
$page_subtitle = $page_subtitle ?? '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> — <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="../curator/assets/css/curator-ui.css" rel="stylesheet">
    <link href="../uchebni/assets/css/uchebni-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
    <style>
        .practice-badge-assigned { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .practice-badge-in_progress { background: #fef3c7; color: #b45309; border: 1px solid #fde68a; }
        .practice-badge-submitted { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .practice-badge-overdue { background: #fee2e2; color: #b91c1c; border: 1px solid #fecaca; }
        .schedule-row-excluded { background-color: #fef2f2 !important; }
        .schedule-row-counted { background-color: #f0fdf4 !important; }
    </style>
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="curator-main">
        <?php include __DIR__ . '/topbar.php'; ?>
        <div class="curator-content">

