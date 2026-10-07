<?php
require_once 'config/config.php';
require_once 'includes/auth.php';

// Если пользователь не авторизован, перенаправляем на страницу входа
if (!isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$current_user = getCurrentUser();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ запрещен - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
</head>
<body>
    <div class="container mt-5">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white text-center">
                        <i class="bi bi-shield-exclamation fs-1"></i>
                        <h4 class="mt-2 mb-0">Доступ запрещен</h4>
                    </div>
                    <div class="card-body text-center">
                        <p class="card-text">
                            У вас нет прав для доступа к этой странице.
                        </p>
                        <p class="text-muted">
                            Если вы считаете, что это ошибка, обратитесь к администратору.
                        </p>
                        <a href="">WhatSapp</a>
                        <div class="mt-4">
                            <a href="javascript:history.back()" class="btn btn-secondary me-2">
                                <i class="bi bi-arrow-left me-1"></i>Назад
                            </a>
                            <a href="index.php" class="btn btn-primary">
                                <i class="bi bi-house me-1"></i>На главную
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
</body>
</html>
