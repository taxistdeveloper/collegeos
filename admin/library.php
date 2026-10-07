<?php
require_once '../config/config.php';
require_once '../classes/Library.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$library = new Library();
$library->markOverdueLoans();
$library->expireOldReservations();
$stats = $library->getStats();

$recent_loans = array_slice($library->getLoans(['status' => 'active']), 0, 8);
$overdue_loans = $library->getLoans(['overdue_only' => true]);
$pending_reservations = array_slice($library->getReservations(['status' => 'pending']), 0, 8);

$page_title = 'Библиотека';
$page_subtitle = 'Управление книжным фондом и выдачей';
$active_page = 'library';
$library_nav = 'dashboard';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Цифровая библиотека</h1>
        <p class="page-subtitle">Книжный фонд, выдача и бронирование</p>
    </div>
    <div class="page-actions">
        <a href="library_books.php" class="btn btn-primary">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Добавить книгу</span>
        </a>
        <a href="library_loans.php" class="btn btn-outline">
            <i class="bi bi-box-arrow-right"></i>
            <span>Выдать книгу</span>
        </a>
    </div>
</div>

<?php include 'includes/library_nav.php'; ?>

<div class="stat-grid">
    <div class="stat-card animate-fade-in">
        <div class="stat-icon blue">
            <i class="bi bi-journal-bookmark-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo (int)$stats['books_total']; ?></div>
            <div class="stat-label">Экземпляров в фонде</div>
        </div>
    </div>
    <div class="stat-card animate-fade-in" style="animation-delay: 0.05s">
        <div class="stat-icon green">
            <i class="bi bi-book-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo (int)$stats['books_available']; ?></div>
            <div class="stat-label">Доступно для выдачи</div>
        </div>
    </div>
    <div class="stat-card animate-fade-in" style="animation-delay: 0.1s">
        <div class="stat-icon purple">
            <i class="bi bi-person-lines-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo (int)$stats['active_loans']; ?></div>
            <div class="stat-label">Книг на руках</div>
        </div>
    </div>
    <div class="stat-card animate-fade-in" style="animation-delay: 0.15s">
        <div class="stat-icon orange">
            <i class="bi bi-exclamation-circle-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo (int)$stats['overdue_loans']; ?></div>
            <div class="stat-label">Просрочено</div>
        </div>
    </div>
    <div class="stat-card animate-fade-in" style="animation-delay: 0.2s">
        <div class="stat-icon cyan">
            <i class="bi bi-bookmark-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value"><?php echo (int)$stats['pending_reservations']; ?></div>
            <div class="stat-label">Активных броней</div>
        </div>
    </div>
</div>

<div class="stat-grid mb-4">
    <?php foreach (($stats['by_fund'] ?? []) as $fundKey => $fundStat): ?>
        <a href="library_books.php?fund=<?php echo urlencode($fundKey); ?>" class="stat-card animate-fade-in" style="text-decoration: none; color: inherit;">
            <div class="stat-icon blue">
                <i class="bi bi-collection"></i>
            </div>
            <div class="stat-content">
                <div class="stat-value"><?php echo (int)$fundStat['total']; ?></div>
                <div class="stat-label"><?php echo htmlspecialchars($fundStat['label']); ?> фонд · <?php echo (int)$fundStat['titles']; ?> назв.</div>
            </div>
        </a>
    <?php endforeach; ?>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="bi bi-arrow-left-right"></i> На руках</h2>
                <a href="library_loans.php?tab=active" class="btn btn-sm btn-outline">Все</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($recent_loans)): ?>
                    <p class="text-muted text-center py-4 mb-0">Нет активных выдач</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Студент</th>
                                    <th>Книга</th>
                                    <th>Срок</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_loans as $loan): ?>
                                    <?php
                                    $fio = Library::formatStudentFio($loan);
                                    $is_overdue = $loan['due_date'] < date('Y-m-d');
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($fio); ?></strong>
                                            <?php if (!empty($loan['group_name'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                                    <?php echo htmlspecialchars($loan['group_name']); ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($loan['title']); ?></td>
                                        <td>
                                            <span class="badge <?php echo $is_overdue ? 'badge-danger' : 'badge-primary'; ?>">
                                                <?php echo formatDate($loan['due_date']); ?>
                                            </span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card">
            <div class="card-header">
                <h2 class="card-title"><i class="bi bi-bookmark"></i> Активные брони</h2>
                <a href="library_reservations.php?tab=pending" class="btn btn-sm btn-outline">Все</a>
            </div>
            <div class="card-body" style="padding: 0;">
                <?php if (empty($pending_reservations)): ?>
                    <p class="text-muted text-center py-4 mb-0">Нет активных броней</p>
                <?php else: ?>
                    <div class="table-wrapper">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>Студент</th>
                                    <th>Книга</th>
                                    <th>До</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pending_reservations as $res): ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars(Library::formatStudentFio($res)); ?></strong></td>
                                        <td><?php echo htmlspecialchars($res['title']); ?></td>
                                        <td><?php echo formatDate($res['expires_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!empty($overdue_loans)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-exclamation-triangle-fill" style="color: var(--danger);"></i> Просроченные выдачи</h2>
        <span class="badge badge-danger"><?php echo count($overdue_loans); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>Книга</th>
                        <th>Выдано</th>
                        <th>Срок</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($overdue_loans, 0, 15) as $loan): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(Library::formatStudentFio($loan)); ?></strong></td>
                            <td><?php echo htmlspecialchars($loan['title']); ?></td>
                            <td><?php echo formatDate($loan['issued_at']); ?></td>
                            <td><span class="badge badge-danger"><?php echo formatDate($loan['due_date']); ?></span></td>
                            <td>
                                <a href="library_loans.php?tab=overdue&search=<?php echo urlencode(Library::formatStudentFio($loan)); ?>" class="btn btn-sm btn-outline">
                                    Открыть
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
