<?php
require_once '../config/config.php';
require_once '../classes/Library.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['librarian']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_library');

$library = new Library();
$library->markOverdueLoans();
$library->expireOldReservations();

$stats = $library->getStats();
$current_user = getCurrentUser();
$page_title = 'Главная';
$page_subtitle = 'Обзор библиотеки · ' . date('d.m.Y');
require_once 'includes/header.php';
?>

<div class="curator-stats-grid">
    <div class="curator-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Экземпляров в фонде</div>
        <div class="stat-number"><?php echo $stats['books_total']; ?></div>
        <div class="stat-icon"><i class="bi bi-journal-bookmark"></i></div>
    </div>
    <div class="curator-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Доступно для выдачи</div>
        <div class="stat-number"><?php echo $stats['books_available']; ?></div>
        <div class="stat-icon"><i class="bi bi-book"></i></div>
    </div>
    <div class="curator-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Книг на руках</div>
        <div class="stat-number"><?php echo $stats['active_loans']; ?></div>
        <div class="stat-icon"><i class="bi bi-person-lines-fill"></i></div>
    </div>
    <div class="curator-stat-card stat-warning">
        <div class="stat-label"><span class="stat-dot"></span>Просрочено / броней</div>
        <div class="stat-number">
            <?php echo $stats['overdue_loans']; ?>
            <span class="fs-6 text-muted fw-normal">/ <?php echo $stats['pending_reservations']; ?></span>
        </div>
        <div class="stat-icon"><i class="bi bi-exclamation-circle"></i></div>
    </div>
</div>

<div class="card library-welcome-card mb-0">
    <div class="card-body p-4 p-md-5">
        <h2 class="h4 mb-2"><i class="bi bi-book-half me-2"></i>Цифровая библиотека</h2>
        <p class="mb-4 opacity-75">
            Управление книжным фондом, выдача и приём литературы, бронирование и продление сроков.
        </p>
        <div class="library-quick-links d-flex flex-wrap gap-2">
            <a href="books.php" class="btn btn-light"><i class="bi bi-journal-plus me-1"></i>Книги</a>
            <a href="loans.php" class="btn btn-light"><i class="bi bi-arrow-left-right me-1"></i>Выдача / приём</a>
            <a href="reservations.php" class="btn btn-outline-light"><i class="bi bi-bookmark me-1"></i>Бронирование</a>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
