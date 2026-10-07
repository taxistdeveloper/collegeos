<?php
/**
 * Запуск сидера книг библиотеки на сервере (после git pull).
 * Данные лежат в database/seeders/library_books_data.php — без .xlsx.
 */
require_once '../config/config.php';
require_once __DIR__ . '/../database/seeders/seed_library_books.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$result = null;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'seed') {
        $force = !empty($_POST['force']);
        $result = seed_library_books([
            'force' => $force,
            'added_by' => (int)($_SESSION['admin_id'] ?? 0) ?: null,
        ]);
        if (!$result['success'] && !empty($result['message']) && $result['imported'] === 0 && $result['skipped'] === 0) {
            $error = $result['message'];
        }
    }
}

$db = getDB();
$currentCount = 0;
$r = $db->query('SELECT COUNT(*) AS c FROM library_books');
if ($r && $row = $r->fetch_assoc()) {
    $currentCount = (int)$row['c'];
}

$dataFile = __DIR__ . '/../database/seeders/library_books_data.php';
$dataReady = is_readable($dataFile);
$dataCount = 0;
if ($dataReady) {
    $head = file_get_contents($dataFile, false, null, 0, 400);
    if (preg_match('/Записей:\s*(\d+)/u', $head, $m)) {
        $dataCount = (int)$m[1];
    }
}

$library_nav = 'seed';
include 'includes/admin_header.php';
include 'includes/library_nav.php';
?>

<div class="container-fluid py-3">
    <h1 class="h3 mb-3">Сидер библиотеки</h1>
    <p class="text-muted mb-4">
        Excel (.xlsx) в GitHub не попадает. Можно залить через PHP-сидер ниже
        или импортировать <code>database/seeders/library_books.sql</code> в phpMyAdmin / mysql CLI.
    </p>

    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($result): ?>
        <div class="alert <?= $result['errors'] > 0 ? 'alert-warning' : 'alert-success' ?>">
            <?= htmlspecialchars($result['message'] ?? '') ?><br>
            Всего в файле: <?= (int)$result['total'] ?>,
            добавлено: <?= (int)$result['imported'] ?>,
            пропущено: <?= (int)$result['skipped'] ?>,
            ошибок: <?= (int)$result['errors'] ?>
        </div>
    <?php endif; ?>

    <div class="mb-3">
        <div>Сейчас в БД: <strong><?= $currentCount ?></strong> книг</div>
        <div>В сидере: <strong><?= $dataReady ? $dataCount : 'файл не найден' ?></strong></div>
    </div>

    <?php if ($dataReady): ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center"
              onsubmit="return confirm(this.force && this.force.checked ? 'Очистить все книги/выдачи/брони и залить заново?' : 'Добавить книги из сидера (дубликаты по инвентарному № будут пропущены)?');">
            <input type="hidden" name="action" value="seed">
            <label class="form-check me-3">
                <input type="checkbox" name="force" value="1" class="form-check-input">
                <span class="form-check-label">Очистить таблицу и залить заново (--force)</span>
            </label>
            <button type="submit" class="btn btn-primary">Запустить сидер</button>
            <a href="library_books.php" class="btn btn-outline-secondary">К книгам</a>
        </form>
    <?php else: ?>
        <div class="alert alert-warning">Нет файла <code>database/seeders/library_books_data.php</code>. Сделайте git pull.</div>
    <?php endif; ?>
</div>

<?php include 'includes/admin_footer.php'; ?>
