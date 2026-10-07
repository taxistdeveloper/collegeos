<?php
/**
 * Выгрузка library_books → library_books_data.php (для обновления сидера).
 * Run: php database/seeders/export_from_db.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../classes/Library.php';

$library = new Library();
$db = getDB();

$r = $db->query("SELECT COUNT(*) AS c FROM library_books");
$count = $r ? (int)$r->fetch_assoc()['c'] : 0;
echo "DB books: {$count}\n";

$books = [];
if ($count > 0) {
    $q = $db->query("SELECT title, author, isbn, publisher, publish_year, category, fund, language,
        location, inventory_number, grade_class, direction, purpose,
        copies_total, copies_available, status, note
        FROM library_books ORDER BY id");
    while ($row = $q->fetch_assoc()) {
        $books[] = $row;
    }
} else {
    // Import from local xlsx into memory via Library import helpers path
    $paths = [
        ['path' => __DIR__ . '/../../library/lib.xlsx', 'fund' => null],
        ['path' => __DIR__ . '/../../library/Учебный фонд.xlsx', 'fund' => Library::FUND_EDUCATIONAL],
    ];
    foreach ($paths as $item) {
        if (!is_readable($item['path'])) {
            echo "Skip missing: {$item['path']}\n";
            continue;
        }
        echo "Importing: " . basename($item['path']) . "\n";
        $res = $library->importBooksFromXlsx($item['path'], null, $item['fund']);
        echo "  result: " . json_encode($res, JSON_UNESCAPED_UNICODE) . "\n";
    }
    $q = $db->query("SELECT title, author, isbn, publisher, publish_year, category, fund, language,
        location, inventory_number, grade_class, direction, purpose,
        copies_total, copies_available, status, note
        FROM library_books ORDER BY id");
    if ($q) {
        while ($row = $q->fetch_assoc()) {
            $books[] = $row;
        }
    }
}

echo "Exporting " . count($books) . " books\n";

$dataFile = __DIR__ . '/library_books_data.php';
$fp = fopen($dataFile, 'wb');
fwrite($fp, "<?php\n/**\n * Данные книг библиотеки для сидера.\n * Сгенерировано: " . date('Y-m-d H:i:s') . "\n * Записей: " . count($books) . "\n */\nreturn [\n");

foreach ($books as $b) {
    $row = [
        'title' => $b['title'],
        'author' => $b['author'],
        'isbn' => $b['isbn'] !== null && $b['isbn'] !== '' ? $b['isbn'] : null,
        'publisher' => $b['publisher'] !== null && $b['publisher'] !== '' ? $b['publisher'] : null,
        'publish_year' => $b['publish_year'] !== null && $b['publish_year'] !== '' ? (int)$b['publish_year'] : null,
        'category' => $b['category'] !== null && $b['category'] !== '' ? $b['category'] : null,
        'fund' => $b['fund'] !== null && $b['fund'] !== '' ? $b['fund'] : null,
        'language' => $b['language'] !== null && $b['language'] !== '' ? $b['language'] : null,
        'location' => $b['location'] !== null && $b['location'] !== '' ? $b['location'] : null,
        'inventory_number' => $b['inventory_number'] !== null && $b['inventory_number'] !== '' ? $b['inventory_number'] : null,
        'grade_class' => $b['grade_class'] !== null && $b['grade_class'] !== '' ? $b['grade_class'] : null,
        'direction' => $b['direction'] !== null && $b['direction'] !== '' ? $b['direction'] : null,
        'purpose' => $b['purpose'] !== null && $b['purpose'] !== '' ? $b['purpose'] : null,
        'copies_total' => (int)($b['copies_total'] ?? 1),
        'copies_available' => (int)($b['copies_available'] ?? $b['copies_total'] ?? 1),
        'status' => $b['status'] ?? 'active',
        'note' => $b['note'] !== null && $b['note'] !== '' ? $b['note'] : null,
    ];
    fwrite($fp, '    ' . var_export($row, true) . ",\n");
}

fwrite($fp, "];\n");
fclose($fp);

echo "Wrote: {$dataFile}\n";
echo "Size: " . filesize($dataFile) . " bytes\n";
