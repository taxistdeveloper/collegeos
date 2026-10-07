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

$nakyl_sozder = [
    ['kk' => 'Еңбек түбі — береке.', 'ru' => 'В основе труда — достаток.'],
    ['kk' => 'Білімді мыңды жығады.', 'ru' => 'Знающий одолеет тысячу.'],
    ['kk' => 'Отан отбасынан басталады.', 'ru' => 'Родина начинается с семьи.'],
    ['kk' => 'Жақсы сөз — жарым ырыс.', 'ru' => 'Доброе слово — половина счастья.'],
    ['kk' => 'Сабыр түбі — сары алтын.', 'ru' => 'Терпение в итоге — чистое золото.'],
    ['kk' => 'Бірлік бар жерде — тірлік бар.', 'ru' => 'Где есть единство, там есть жизнь.'],
    ['kk' => 'Ақыл — тозбас тон, білім — таусылмас кен.', 'ru' => 'Ум не износится, знание не иссякнет.'],
    ['kk' => 'Ұяда не көрсе, ұшқанда соны іледі.', 'ru' => 'Что видит в гнезде, то и несёт в полёт.'],
    ['kk' => 'Тәрбие — тал бесіктен.', 'ru' => 'Воспитание начинается с колыбели.'],
    ['kk' => 'Жігітке жеті өнер де аз.', 'ru' => 'Джигиту и семи ремёсел мало.'],
    ['kk' => 'Оқу — инемен құдық қазғандай.', 'ru' => 'Учёба похожа на колодец, выкопанный иглой.'],
    ['kk' => 'Елдің ертеңі — жастар.', 'ru' => 'Будущее народа — молодёжь.'],
    ['kk' => 'Адал еңбек абырой әкеледі.', 'ru' => 'Честный труд приносит честь.'],
    ['kk' => 'Батыр бір рет өледі, қорқақ мың өледі.', 'ru' => 'Храбрец умирает один раз, трус — тысячу.'],
    ['kk' => 'Көп түкірсе — көл.', 'ru' => 'Много малых усилий складываются в большое дело.'],
];
$nakyl_index = random_int(0, count($nakyl_sozder) - 1);

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

<div class="library-fund-cards mb-4">
    <?php foreach (($stats['by_fund'] ?? []) as $fundKey => $fundStat): ?>
        <a href="books.php?fund=<?php echo urlencode($fundKey); ?>" class="library-fund-card">
            <div class="library-fund-card-label"><?php echo htmlspecialchars($fundStat['label']); ?> фонд</div>
            <div class="library-fund-card-number"><?php echo (int)$fundStat['total']; ?></div>
            <div class="library-fund-card-meta"><?php echo (int)$fundStat['titles']; ?> названий</div>
        </a>
    <?php endforeach; ?>
</div>

<section class="curator-quote mb-4" aria-labelledby="nakylTitle">
    <div class="curator-quote-head">
        <h2 class="curator-section-title" id="nakylTitle">Нақыл сөздер</h2>
        <span class="curator-quote-count" id="nakylCount"></span>
    </div>
    <blockquote class="curator-quote-text" id="nakylText"></blockquote>
    <p class="curator-quote-meaning" id="nakylMeaning"></p>
    <button type="button" class="btn btn-outline-primary btn-sm" id="nakylNext">
        Келесі
    </button>
</section>

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

<script>
    const nakylSozder = <?php echo json_encode($nakyl_sozder, JSON_UNESCAPED_UNICODE); ?>;
    let nakylIndex = <?php echo (int)$nakyl_index; ?>;
    let lastNakylIndex = nakylIndex;

    function randomNakylIndex() {
        if (nakylSozder.length < 2) return 0;
        let next = Math.floor(Math.random() * nakylSozder.length);
        while (next === lastNakylIndex) {
            next = Math.floor(Math.random() * nakylSozder.length);
        }
        return next;
    }

    function showNakyl() {
        const item = nakylSozder[nakylIndex];
        lastNakylIndex = nakylIndex;
        document.getElementById('nakylText').textContent = item.kk;
        document.getElementById('nakylMeaning').textContent = item.ru;
        document.getElementById('nakylCount').textContent = 'кездейсоқ';
    }

    document.getElementById('nakylNext').addEventListener('click', function () {
        nakylIndex = randomNakylIndex();
        showNakyl();
    });

    showNakyl();
</script>

<?php require_once 'includes/footer.php'; ?>
