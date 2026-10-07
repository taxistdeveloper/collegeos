<?php
require_once '../../config/config.php';
require_once '../../includes/auth.php';

checkRole(['librarian', 'admin']);

header('Content-Type: application/json; charset=utf-8');

$q = isset($_GET['q']) ? trim($_GET['q']) : '';
if (mb_strlen($q) < 2) {
    echo json_encode(['success' => true, 'books' => []], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once '../../classes/Library.php';
$library = new Library();
$books = $library->getBooks([
    'search' => $q,
    'status' => 'active',
    'available_only' => true,
    'limit' => 15,
]);

$result = array_map(static function ($b) {
    return [
        'id' => (int)$b['id'],
        'title' => $b['title'],
        'author' => $b['author'],
        'inventory_number' => $b['inventory_number'] ?? '',
        'fund' => $b['fund'] ?? '',
        'fund_label' => Library::fundLabel($b['fund'] ?? ''),
        'copies_available' => (int)$b['copies_available'],
        'publish_year' => $b['publish_year'] ?? null,
    ];
}, $books);

echo json_encode(['success' => true, 'books' => $result], JSON_UNESCAPED_UNICODE);
