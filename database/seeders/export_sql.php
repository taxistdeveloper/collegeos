<?php
/**
 * Генерация library_books.sql из library_books_data.php
 * Run: php database/seeders/export_sql.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';

$dataFile = __DIR__ . '/library_books_data.php';
if (!is_readable($dataFile)) {
    fwrite(STDERR, "No data file\n");
    exit(1);
}

/** @var array $books */
$books = require $dataFile;
$out = __DIR__ . '/library_books.sql';

$fp = fopen($out, 'wb');
fwrite($fp, "-- Library books seed\n");
fwrite($fp, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
fwrite($fp, "-- Records: " . count($books) . "\n");
fwrite($fp, "-- Charset: utf8mb4\n\n");
fwrite($fp, "SET NAMES utf8mb4;\n");
fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\n\n");

fwrite($fp, "CREATE TABLE IF NOT EXISTS library_books (\n");
fwrite($fp, "  id INT AUTO_INCREMENT PRIMARY KEY,\n");
fwrite($fp, "  title VARCHAR(500) NOT NULL,\n");
fwrite($fp, "  author VARCHAR(300) NOT NULL,\n");
fwrite($fp, "  isbn VARCHAR(50) NULL,\n");
fwrite($fp, "  publisher VARCHAR(200) NULL,\n");
fwrite($fp, "  publish_year SMALLINT NULL,\n");
fwrite($fp, "  category VARCHAR(100) NULL,\n");
fwrite($fp, "  fund VARCHAR(50) NULL,\n");
fwrite($fp, "  language VARCHAR(50) NULL,\n");
fwrite($fp, "  location VARCHAR(100) NULL,\n");
fwrite($fp, "  inventory_number VARCHAR(50) NULL,\n");
fwrite($fp, "  grade_class VARCHAR(50) NULL,\n");
fwrite($fp, "  direction VARCHAR(200) NULL,\n");
fwrite($fp, "  purpose VARCHAR(200) NULL,\n");
fwrite($fp, "  copies_total INT NOT NULL DEFAULT 1,\n");
fwrite($fp, "  copies_available INT NOT NULL DEFAULT 1,\n");
fwrite($fp, "  status ENUM('active', 'written_off') NOT NULL DEFAULT 'active',\n");
fwrite($fp, "  note TEXT NULL,\n");
fwrite($fp, "  added_by INT NULL,\n");
fwrite($fp, "  created_at DATETIME DEFAULT CURRENT_TIMESTAMP,\n");
fwrite($fp, "  updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n");
fwrite($fp, "  INDEX idx_books_status (status),\n");
fwrite($fp, "  INDEX idx_books_title (title(100)),\n");
fwrite($fp, "  INDEX idx_books_fund (fund)\n");
fwrite($fp, ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\n\n");

$cols = "(title, author, isbn, publisher, publish_year, category, fund, language, location, inventory_number, grade_class, direction, purpose, copies_total, copies_available, status, note)";

$batchSize = 100;
$batch = [];
$total = 0;

$esc = static function ($v) {
    if ($v === null || $v === '') {
        return 'NULL';
    }
    if (is_int($v) || is_float($v)) {
        return (string)$v;
    }
    $s = (string)$v;
    $s = str_replace(["\\", "'", "\0", "\n", "\r", "\x1a"], ["\\\\", "\\'", '', '\\n', '\\r', '\\Z'], $s);
    return "'" . $s . "'";
};

foreach ($books as $b) {
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') {
        continue;
    }
    $author = trim((string)($b['author'] ?? '')) ?: 'Без автора';
    $copies = (int)($b['copies_total'] ?? 1);
    if ($copies < 1) {
        $copies = 1;
    }
    $available = isset($b['copies_available']) ? (int)$b['copies_available'] : $copies;
    if ($available < 0) {
        $available = 0;
    }
    if ($available > $copies) {
        $available = $copies;
    }
    $year = $b['publish_year'] ?? null;
    $yearSql = ($year !== null && $year !== '' && is_numeric($year)) ? (int)$year : 'NULL';
    $status = (($b['status'] ?? 'active') === 'written_off') ? 'written_off' : 'active';

    $batch[] = '(' . implode(', ', [
        $esc($title),
        $esc($author),
        $esc($b['isbn'] ?? null),
        $esc($b['publisher'] ?? null),
        $yearSql === 'NULL' ? 'NULL' : $yearSql,
        $esc($b['category'] ?? null),
        $esc($b['fund'] ?? null),
        $esc($b['language'] ?? null),
        $esc($b['location'] ?? null),
        $esc($b['inventory_number'] ?? null),
        $esc($b['grade_class'] ?? null),
        $esc($b['direction'] ?? null),
        $esc($b['purpose'] ?? null),
        $copies,
        $available,
        $esc($status),
        $esc($b['note'] ?? null),
    ]) . ')';

    if (count($batch) >= $batchSize) {
        fwrite($fp, "INSERT INTO library_books {$cols} VALUES\n" . implode(",\n", $batch) . ";\n\n");
        $total += count($batch);
        $batch = [];
    }
}

if (!empty($batch)) {
    fwrite($fp, "INSERT INTO library_books {$cols} VALUES\n" . implode(",\n", $batch) . ";\n\n");
    $total += count($batch);
}

fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
fclose($fp);

echo "Wrote {$out}\n";
echo "Rows: {$total}\n";
echo "Size: " . filesize($out) . " bytes\n";
