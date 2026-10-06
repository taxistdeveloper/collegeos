<?php
if (!isset($current_user)) {
    $current_user = getCurrentUser();
}
$page_title = $page_title ?? 'ЦОС';
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
</head>

<body class="bg-light">
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
        <div class="container-fluid">
            <a class="navbar-brand" href="dashboard.php">
                <i class="bi bi-building me-2"></i>ЦОС
            </a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#cosNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="cosNav">
                <ul class="navbar-nav me-auto">
                    <?php foreach (getMenuByRole('cos') as $menu_item): ?>
                        <li class="nav-item">
                            <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) === basename($menu_item['url']) ? 'active' : ''; ?>"
                                href="<?php echo htmlspecialchars($menu_item['url']); ?>">
                                <i class="bi bi-<?php echo htmlspecialchars($menu_item['icon']); ?> me-1"></i><?php echo htmlspecialchars($menu_item['name']); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <span class="navbar-text text-white me-3 d-none d-md-inline">
                    <i class="bi bi-person-circle me-1"></i><?php echo htmlspecialchars($current_user['name']); ?>
                </span>
                <a class="btn btn-outline-light btn-sm" href="../logout.php">Выход</a>
            </div>
        </div>
    </nav>
    <div class="container pb-5">
