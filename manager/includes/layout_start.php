<?php
$page_title = $page_title ?? 'Панель менеджера';
$page_subtitle = $page_subtitle ?? '';
$document_title = $document_title ?? $page_title;
$hide_header_search = $hide_header_search ?? false;
$extra_head = $extra_head ?? '';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($document_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/manager-ui.css" rel="stylesheet">
    <?php echo $extra_head; ?>
</head>

<body class="manager-app">
<div class="manager-shell">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="manager-main">
        <?php include __DIR__ . '/header.php'; ?>
        <div class="manager-content">
