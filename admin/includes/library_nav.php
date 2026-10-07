<?php
/**
 * Подменю раздела «Библиотека»
 * $library_nav - books | loans | reservations | dashboard
 */
if (!isset($library_nav)) {
    $library_nav = 'dashboard';
}
?>
<ul class="nav library-admin-tabs mb-4">
    <li class="nav-item">
        <a class="nav-link <?php echo $library_nav === 'dashboard' ? 'active' : ''; ?>" href="library.php">
            <i class="bi bi-grid-1x2 me-1"></i>Обзор
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $library_nav === 'books' ? 'active' : ''; ?>" href="library_books.php">
            <i class="bi bi-journal-plus me-1"></i>Книги
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $library_nav === 'loans' ? 'active' : ''; ?>" href="library_loans.php">
            <i class="bi bi-arrow-left-right me-1"></i>Выдача / приём
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $library_nav === 'reservations' ? 'active' : ''; ?>" href="library_reservations.php">
            <i class="bi bi-bookmark me-1"></i>Бронирование
        </a>
    </li>
</ul>
