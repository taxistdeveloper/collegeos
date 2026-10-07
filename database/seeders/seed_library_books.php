<?php
/**
 * Сидер книг библиотеки (без .xlsx — можно пушить в GitHub).
 *
 * CLI:
 *   php database/seeders/seed_library_books.php
 *   php database/seeders/seed_library_books.php --force   # очистить library_books и залить заново
 *
 * Или через админку: /admin/seed_library.php
 *
 * @return array{success:bool,imported:int,skipped:int,errors:int,total:int,message?:string}
 */
if (!function_exists('seed_library_books')) {
function seed_library_books(array $options = []): array
{
    $force = !empty($options['force']);
    $addedBy = isset($options['added_by']) ? (int)$options['added_by'] : null;

    require_once dirname(__DIR__, 2) . '/classes/Library.php';

    $library = new Library();
    $db = getDB();

    $dataFile = __DIR__ . '/library_books_data.php';
    if (!is_readable($dataFile)) {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => 0,
            'message' => 'Файл данных не найден: library_books_data.php',
        ];
    }

    /** @var array $books */
    $books = require $dataFile;
    if (!is_array($books) || empty($books)) {
        return [
            'success' => false,
            'imported' => 0,
            'skipped' => 0,
            'errors' => 0,
            'total' => 0,
            'message' => 'Файл данных пуст',
        ];
    }

    @set_time_limit(900);
    @ini_set('memory_limit', '512M');

    if ($force) {
        $db->query('SET FOREIGN_KEY_CHECKS=0');
        $db->query('TRUNCATE TABLE library_reservations');
        $db->query('TRUNCATE TABLE library_loans');
        $db->query('TRUNCATE TABLE library_books');
        $db->query('SET FOREIGN_KEY_CHECKS=1');
    }

    $existingInv = [];
    $existingFp = [];
    $r = $db->query("SELECT title, author, fund, publish_year, inventory_number FROM library_books");
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $inv = trim((string)($row['inventory_number'] ?? ''));
            if ($inv !== '') {
                $existingInv[$inv] = true;
            } else {
                $existingFp[seed_library_fingerprint($row)] = true;
            }
        }
    }

    $imported = 0;
    $skipped = 0;
    $errors = 0;
    $batch = [];
    $batchSize = 200;

    foreach ($books as $item) {
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') {
            $skipped++;
            continue;
        }

        $inv = trim((string)($item['inventory_number'] ?? ''));
        if ($inv !== '' && isset($existingInv[$inv])) {
            $skipped++;
            continue;
        }
        if ($inv === '') {
            $fp = seed_library_fingerprint($item);
            if (isset($existingFp[$fp])) {
                $skipped++;
                continue;
            }
            $existingFp[$fp] = true;
        }

        $copies = (int)($item['copies_total'] ?? 1);
        if ($copies < 1) {
            $copies = 1;
        }
        $available = isset($item['copies_available']) ? (int)$item['copies_available'] : $copies;
        if ($available < 0) {
            $available = 0;
        }
        if ($available > $copies) {
            $available = $copies;
        }

        $year = $item['publish_year'] ?? null;
        $year = ($year !== null && $year !== '' && is_numeric($year)) ? (int)$year : null;

        $batch[] = [
            'title' => $title,
            'author' => trim((string)($item['author'] ?? '')) ?: 'Без автора',
            'isbn' => seed_library_null_if_empty($item['isbn'] ?? null),
            'publisher' => seed_library_null_if_empty($item['publisher'] ?? null),
            'publish_year' => $year,
            'category' => seed_library_null_if_empty($item['category'] ?? null),
            'fund' => seed_library_null_if_empty($item['fund'] ?? null),
            'language' => seed_library_null_if_empty($item['language'] ?? null),
            'location' => seed_library_null_if_empty($item['location'] ?? null),
            'inventory_number' => $inv !== '' ? $inv : null,
            'grade_class' => seed_library_null_if_empty($item['grade_class'] ?? null),
            'direction' => seed_library_null_if_empty($item['direction'] ?? null),
            'purpose' => seed_library_null_if_empty($item['purpose'] ?? null),
            'copies_total' => $copies,
            'copies_available' => $available,
            'status' => (($item['status'] ?? 'active') === 'written_off') ? 'written_off' : 'active',
            'note' => seed_library_null_if_empty($item['note'] ?? null),
        ];

        if ($inv !== '') {
            $existingInv[$inv] = true;
        }

        if (count($batch) >= $batchSize) {
            $ok = seed_library_insert_batch($db, $batch, $addedBy);
            if ($ok === false) {
                $errors += count($batch);
            } else {
                $imported += $ok;
            }
            $batch = [];
        }
    }

    if (!empty($batch)) {
        $ok = seed_library_insert_batch($db, $batch, $addedBy);
        if ($ok === false) {
            $errors += count($batch);
        } else {
            $imported += $ok;
        }
    }

    // suppress unused $library warning — ensures tables exist
    unset($library);

    return [
        'success' => $errors === 0,
        'imported' => $imported,
        'skipped' => $skipped,
        'errors' => $errors,
        'total' => count($books),
        'message' => $force
            ? "База очищена. Добавлено: {$imported}"
            : "Добавлено: {$imported}, пропущено (уже есть): {$skipped}",
    ];
}
} // function_exists seed_library_books

if (!function_exists('seed_library_null_if_empty')) {
function seed_library_null_if_empty($value)
{
    if ($value === null) {
        return null;
    }
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}
}

if (!function_exists('seed_library_fingerprint')) {
function seed_library_fingerprint(array $item): string
{
    $raw = implode('|', [
        trim((string)($item['title'] ?? '')),
        trim((string)($item['author'] ?? '')),
        trim((string)($item['fund'] ?? '')),
        (string)($item['publish_year'] ?? ''),
        trim((string)($item['grade_class'] ?? '')),
    ]);
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($raw, 'UTF-8');
    }
    return strtolower($raw);
}
}

if (!function_exists('seed_library_insert_batch')) {
function seed_library_insert_batch($db, array $batch, $addedBy)
{
    if (empty($batch)) {
        return 0;
    }

    $parts = [];
    foreach ($batch as $item) {
        $title = "'" . $db->escape($item['title']) . "'";
        $author = "'" . $db->escape($item['author']) . "'";
        $isbn = $item['isbn'] !== null ? "'" . $db->escape($item['isbn']) . "'" : 'NULL';
        $publisher = $item['publisher'] !== null ? "'" . $db->escape($item['publisher']) . "'" : 'NULL';
        $year = $item['publish_year'] !== null ? (int)$item['publish_year'] : 'NULL';
        $category = $item['category'] !== null ? "'" . $db->escape($item['category']) . "'" : 'NULL';
        $fund = $item['fund'] !== null ? "'" . $db->escape($item['fund']) . "'" : 'NULL';
        $language = $item['language'] !== null ? "'" . $db->escape($item['language']) . "'" : 'NULL';
        $location = $item['location'] !== null ? "'" . $db->escape($item['location']) . "'" : 'NULL';
        $inv = $item['inventory_number'] !== null ? "'" . $db->escape($item['inventory_number']) . "'" : 'NULL';
        $grade = $item['grade_class'] !== null ? "'" . $db->escape($item['grade_class']) . "'" : 'NULL';
        $direction = $item['direction'] !== null ? "'" . $db->escape($item['direction']) . "'" : 'NULL';
        $purpose = $item['purpose'] !== null ? "'" . $db->escape($item['purpose']) . "'" : 'NULL';
        $copies = (int)$item['copies_total'];
        $available = (int)$item['copies_available'];
        $status = $item['status'] === 'written_off' ? "'written_off'" : "'active'";
        $note = $item['note'] !== null ? "'" . $db->escape($item['note']) . "'" : 'NULL';
        $by = $addedBy !== null ? (int)$addedBy : 'NULL';

        $parts[] = "($title, $author, $isbn, $publisher, $year, $category, $fund, $language, $location, $inv, $grade, $direction, $purpose, $copies, $available, $status, $note, $by)";
    }

    $sql = "INSERT INTO library_books
        (title, author, isbn, publisher, publish_year, category, fund, language, location,
         inventory_number, grade_class, direction, purpose, copies_total, copies_available, status, note, added_by)
        VALUES " . implode(",\n", $parts);

    return $db->query($sql) ? count($batch) : false;
}
} // function_exists seed_library_insert_batch

// CLI entrypoint
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $_SERVER['HTTP_HOST'] = $_SERVER['HTTP_HOST'] ?? 'localhost';
    require_once dirname(__DIR__, 2) . '/config/config.php';

    $force = in_array('--force', $argv ?? [], true);
    $result = seed_library_books(['force' => $force]);

    echo ($result['success'] ? "OK\n" : "DONE WITH ERRORS\n");
    echo "total={$result['total']} imported={$result['imported']} skipped={$result['skipped']} errors={$result['errors']}\n";
    if (!empty($result['message'])) {
        echo $result['message'] . "\n";
    }
    exit($result['errors'] > 0 ? 1 : 0);
}
