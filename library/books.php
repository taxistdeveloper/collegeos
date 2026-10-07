<?php
require_once '../config/config.php';
require_once '../classes/Library.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['librarian']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_library');

$library = new Library();
$current_user = getCurrentUser();
$message = '';
$error = '';

$can_manage = hasPermission('manage_books');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add' && $can_manage) {
        $result = $library->addBook([
            'title' => sanitize($_POST['title']),
            'author' => sanitize($_POST['author']),
            'isbn' => sanitize($_POST['isbn'] ?? ''),
            'publisher' => sanitize($_POST['publisher'] ?? ''),
            'publish_year' => sanitize($_POST['publish_year'] ?? ''),
            'fund' => sanitize($_POST['fund'] ?? ''),
            'language' => sanitize($_POST['language'] ?? ''),
            'location' => sanitize($_POST['location'] ?? ''),
            'inventory_number' => sanitize($_POST['inventory_number'] ?? ''),
            'grade_class' => sanitize($_POST['grade_class'] ?? ''),
            'direction' => sanitize($_POST['direction'] ?? ''),
            'purpose' => sanitize($_POST['purpose'] ?? ''),
            'copies_total' => (int)($_POST['copies_total'] ?? 1),
            'note' => sanitize($_POST['note'] ?? ''),
            'added_by' => $current_user['id'],
        ]);
        $message = $result ? 'Книга добавлена!' : 'Ошибка при добавлении';
    }

    if ($action === 'edit' && $can_manage) {
        $id = (int)$_POST['book_id'];
        if ($library->updateBook($id, [
            'title' => sanitize($_POST['title']),
            'author' => sanitize($_POST['author']),
            'isbn' => sanitize($_POST['isbn'] ?? ''),
            'publisher' => sanitize($_POST['publisher'] ?? ''),
            'publish_year' => sanitize($_POST['publish_year'] ?? ''),
            'fund' => sanitize($_POST['fund'] ?? ''),
            'language' => sanitize($_POST['language'] ?? ''),
            'location' => sanitize($_POST['location'] ?? ''),
            'inventory_number' => sanitize($_POST['inventory_number'] ?? ''),
            'grade_class' => sanitize($_POST['grade_class'] ?? ''),
            'direction' => sanitize($_POST['direction'] ?? ''),
            'purpose' => sanitize($_POST['purpose'] ?? ''),
            'copies_total' => (int)($_POST['copies_total'] ?? 1),
            'note' => sanitize($_POST['note'] ?? ''),
        ])) {
            $message = 'Книга обновлена!';
        } else {
            $error = 'Ошибка при обновлении';
        }
    }

    if ($action === 'write_off' && $can_manage) {
        $result = $library->writeOffBook(
            (int)$_POST['book_id'],
            (int)($_POST['copies'] ?? 1),
            sanitize($_POST['write_off_note'] ?? '')
        );
        if ($result['success']) {
            $message = 'Списано экземпляров: ' . $result['written_off'];
        } else {
            $error = $result['error'];
        }
    }

    if ($action === 'import' && $can_manage) {
        $filePath = '';
        $eduLocalPath = Library::findEducationalFundXlsx(__DIR__);
        $allFunds = Library::getFunds();
        $importFund = Library::normalizeFund($_POST['import_fund'] ?? '');

        if ($importFund === null || !isset($allFunds[$importFund])) {
            $error = 'Выберите фонд для импорта: Профессиональный, Художественный или Учебный';
        } elseif (!empty($_FILES['import_file']['tmp_name']) && is_uploaded_file($_FILES['import_file']['tmp_name'])) {
            $ext = strtolower(pathinfo($_FILES['import_file']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'xlsx') {
                $error = 'Нужен файл Excel в формате .xlsx';
            } else {
                $filePath = $_FILES['import_file']['tmp_name'];
            }
        } elseif (!empty($_POST['use_local_edu'])) {
            if ($eduLocalPath) {
                $filePath = $eduLocalPath;
            } else {
                $error = 'Файл «Учебный фонд.xlsx» не найден в папке library';
            }
        } elseif (!empty($_POST['use_local_lib'])) {
            $local = __DIR__ . '/lib.xlsx';
            if (is_readable($local)) {
                $filePath = $local;
            } else {
                $error = 'Файл library/lib.xlsx не найден';
            }
        } else {
            $error = 'Выберите файл для импорта';
        }

        if ($filePath !== '' && $error === '') {
            $result = $library->importBooksFromXlsx($filePath, $current_user['id'], $importFund);
            if (!empty($result['success'])) {
                $message = 'Импорт в «' . Library::fundLabel($importFund) . '» фонд: добавлено ' . (int)$result['imported']
                    . ', пропущено ' . (int)$result['skipped']
                    . (!empty($result['errors']) ? ', ошибок ' . (int)$result['errors'] : '');
            } else {
                $error = $result['error'] ?? 'Ошибка импорта';
            }
        }
    }
}

$eduLocalPath = Library::findEducationalFundXlsx(__DIR__);

$funds = Library::getFunds();
$fund_filter = trim($_GET['fund'] ?? '');
if ($fund_filter !== '' && !isset($funds[$fund_filter])) {
    $fund_filter = '';
}

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $_GET['status'] ?? 'active',
    'fund' => $fund_filter,
];

$per_page = 50;
$total_books = $library->countBooks($filters);
$total_pages = max(1, (int)ceil($total_books / $per_page));
$books_page = isset($_GET['p']) ? (int)$_GET['p'] : (isset($_GET['page']) ? (int)$_GET['page'] : 1);
if ($books_page < 1) {
    $books_page = 1;
}
if ($books_page > $total_pages) {
    $books_page = $total_pages;
}
$offset = ($books_page - 1) * $per_page;

$books = $library->getBooks(array_merge($filters, [
    'limit' => $per_page,
    'offset' => $offset,
]));

$shown_from = $total_books === 0 ? 0 : $offset + 1;
$shown_to = min($offset + count($books), $total_books);
$is_edu_view = $filters['fund'] === Library::FUND_EDUCATIONAL;
$preselect_fund = $filters['fund'];

$filter_query = [];
if ($filters['search'] !== '') {
    $filter_query['search'] = $filters['search'];
}
if ($filters['status'] !== '') {
    $filter_query['status'] = $filters['status'];
}
if ($filters['fund'] !== '') {
    $filter_query['fund'] = $filters['fund'];
}

$fundTabUrl = static function ($fund) use ($filter_query) {
    $query = $filter_query;
    unset($query['fund']);
    if ($fund !== '') {
        $query['fund'] = $fund;
    }
    $qs = http_build_query($query);
    return 'books.php' . ($qs !== '' ? '?' . $qs : '');
};

$booksPageUrl = static function ($page) use ($filter_query) {
    $query = $filter_query;
    $page = (int)$page;
    if ($page > 1) {
        $query['p'] = $page;
    }
    $qs = http_build_query($query);
    return 'books.php' . ($qs !== '' ? '?' . $qs : '');
};

$renderBooksPagination = static function () use ($books_page, $total_pages, $total_books, $shown_from, $shown_to, $booksPageUrl) {
    if ($total_books <= 0) {
        return;
    }
    ?>
    <div class="library-pagination">
        <span class="library-pagination-info">
            Показано <?php echo (int)$shown_from; ?>–<?php echo (int)$shown_to; ?> из <?php echo (int)$total_books; ?>
            · стр. <?php echo (int)$books_page; ?>/<?php echo (int)$total_pages; ?>
        </span>
        <?php if ($total_pages > 1): ?>
            <div class="library-pagination-nav">
                <?php if ($books_page > 1): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($booksPageUrl($books_page - 1)); ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                <?php else: ?>
                    <span class="btn btn-sm btn-outline-secondary disabled"><i class="bi bi-chevron-left"></i></span>
                <?php endif; ?>

                <?php
                $page_window = 2;
                $start_page = max(1, $books_page - $page_window);
                $end_page = min($total_pages, $books_page + $page_window);
                ?>

                <?php if ($start_page > 1): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($booksPageUrl(1)); ?>">1</a>
                    <?php if ($start_page > 2): ?><span class="library-pagination-ellipsis">…</span><?php endif; ?>
                <?php endif; ?>

                <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                    <?php if ($p === $books_page): ?>
                        <span class="btn btn-sm btn-primary"><?php echo $p; ?></span>
                    <?php else: ?>
                        <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($booksPageUrl($p)); ?>"><?php echo $p; ?></a>
                    <?php endif; ?>
                <?php endfor; ?>

                <?php if ($end_page < $total_pages): ?>
                    <?php if ($end_page < $total_pages - 1): ?><span class="library-pagination-ellipsis">…</span><?php endif; ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($booksPageUrl($total_pages)); ?>"><?php echo $total_pages; ?></a>
                <?php endif; ?>

                <?php if ($books_page < $total_pages): ?>
                    <a class="btn btn-sm btn-outline-primary" href="<?php echo htmlspecialchars($booksPageUrl($books_page + 1)); ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                <?php else: ?>
                    <span class="btn btn-sm btn-outline-secondary disabled"><i class="bi bi-chevron-right"></i></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
};

$page_title = 'Книги';
$fund_subtitle = $filters['fund'] !== '' ? Library::fundLabel($filters['fund']) . ' фонд' : 'Все фонды';
$page_subtitle = $fund_subtitle . ' · ' . $total_books . ' записей';
require_once 'includes/header.php';
?>

<ul class="nav library-nav-tabs library-fund-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $filters['fund'] === '' ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($fundTabUrl('')); ?>">Все фонды</a>
    </li>
    <?php foreach ($funds as $fundKey => $fundLabel): ?>
        <li class="nav-item">
            <a class="nav-link <?php echo $filters['fund'] === $fundKey ? 'active' : ''; ?>" href="<?php echo htmlspecialchars($fundTabUrl($fundKey)); ?>">
                <?php echo htmlspecialchars($fundLabel); ?>
            </a>
        </li>
    <?php endforeach; ?>
</ul>

<?php if ($can_manage): ?>
<div class="d-flex justify-content-end mb-3">
    <div class="curator-action-buttons d-flex flex-wrap gap-2">
        <button type="button" class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#importBooksModal">
            <i class="bi bi-upload me-1"></i>Импорт Excel
        </button>
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookModal">
            <i class="bi bi-plus-lg me-1"></i>Добавить книгу
        </button>
    </div>
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show" role="alert">
    <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="curator-filters">
    <form method="get" class="curator-filter-row">
        <div class="curator-form-group">
            <label class="curator-form-label">Поиск</label>
            <input type="text" name="search" id="librarySearchInput" class="curator-form-control" placeholder="<?php echo $is_edu_view ? 'Наименование, автор, класс, направление...' : 'Название, автор, ISBN...'; ?>" value="<?php echo htmlspecialchars($filters['search']); ?>">
        </div>
        <div class="curator-form-group">
            <label class="curator-form-label">Статус</label>
            <select name="status" class="curator-form-select">
                <option value="">Все</option>
                <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Активные</option>
                <option value="written_off" <?php echo $filters['status'] === 'written_off' ? 'selected' : ''; ?>>Списанные</option>
            </select>
        </div>
        <?php if ($filters['fund'] !== ''): ?>
            <input type="hidden" name="fund" value="<?php echo htmlspecialchars($filters['fund']); ?>">
        <?php endif; ?>
        <div class="curator-form-group">
            <label class="curator-form-label">&nbsp;</label>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Найти</button>
        </div>
    </form>
</div>

<?php $renderBooksPagination(); ?>

<div class="curator-table-container">
    <div class="table-responsive">
        <table class="table curator-table mb-0">
            <thead>
                <tr>
                    <?php if ($is_edu_view): ?>
                        <th>Рег. №</th>
                        <th>Наименование</th>
                        <th>Автор</th>
                        <th>Класс</th>
                        <th>Направление</th>
                        <th>Назначение</th>
                        <th>Год</th>
                        <th>Кол-во</th>
                        <th>Статус</th>
                    <?php else: ?>
                        <th>Название</th>
                        <th>Автор</th>
                        <th>Инв. №</th>
                        <th>Доступно</th>
                        <th>Место</th>
                        <th>Статус</th>
                    <?php endif; ?>
                    <?php if ($can_manage): ?><th>Действия</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                $colspan = $is_edu_view ? ($can_manage ? 10 : 9) : ($can_manage ? 7 : 6);
                ?>
                <?php if (empty($books)): ?>
                    <tr><td colspan="<?php echo (int)$colspan; ?>" class="text-center text-muted py-4">Книги не найдены</td></tr>
                <?php else: ?>
                    <?php foreach ($books as $book): ?>
                        <tr>
                            <?php if ($is_edu_view): ?>
                                <td><code><?php echo htmlspecialchars($book['inventory_number'] ?? '—'); ?></code></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                    <?php if (!empty($book['note'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($book['note']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><?php echo htmlspecialchars($book['grade_class'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($book['direction'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($book['purpose'] ?? '—'); ?></td>
                                <td><?php echo !empty($book['publish_year']) ? (int)$book['publish_year'] : '—'; ?></td>
                                <td><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></td>
                            <?php else: ?>
                                <td>
                                    <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                    <?php
                                    $meta = array_filter([
                                        !empty($book['fund']) ? Library::fundLabel($book['fund']) : '',
                                        !empty($book['language']) ? $book['language'] : '',
                                        !empty($book['grade_class']) ? ('кл. ' . $book['grade_class']) : '',
                                    ]);
                                    if ($meta):
                                    ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars(implode(' · ', $meta)); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($book['author']); ?></td>
                                <td><code><?php echo htmlspecialchars($book['inventory_number'] ?? '—'); ?></code></td>
                                <td><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></td>
                                <td><?php echo htmlspecialchars($book['location'] ?? '—'); ?></td>
                            <?php endif; ?>
                            <td>
                                <?php if ($book['status'] === 'written_off'): ?>
                                    <span class="curator-badge badge-danger">Списана</span>
                                <?php else: ?>
                                    <span class="curator-badge badge-success">В фонде</span>
                                <?php endif; ?>
                            </td>
                            <?php if ($can_manage): ?>
                                <td>
                                    <?php if ($book['status'] === 'active'): ?>
                                        <div class="curator-table-actions">
                                            <button type="button" class="btn btn-outline-primary btn-edit-book"
                                                data-book='<?php echo htmlspecialchars(json_encode($book, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>' title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-outline-danger btn-write-off"
                                                data-id="<?php echo (int)$book['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($book['title']); ?>"
                                                data-available="<?php echo (int)$book['copies_available']; ?>"
                                                title="Списать">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php $renderBooksPagination(); ?>

<?php if ($can_manage): ?>
<div class="modal fade modal-book-form" id="addBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-journal-plus me-2"></i>Добавить книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php
                $preselect_fund = $filters['fund'];
                include __DIR__ . '/includes/book_form_fields.php';
                ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade modal-book-form" id="editBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="book_id" id="edit_book_id">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-pencil-square me-2"></i>Редактировать книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editBookFields"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i>Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="writeOffModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="write_off">
            <input type="hidden" name="book_id" id="writeoff_book_id">
            <div class="modal-header">
                <h5 class="modal-title">Списать книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Книга: <strong id="writeoff_title"></strong></p>
                <p class="small text-muted">Доступно для списания (не на руках): <span id="writeoff_available"></span> экз.</p>
                <div class="mb-3">
                    <label class="form-label">Количество экземпляров</label>
                    <input type="number" name="copies" class="form-control" value="1" min="1">
                </div>
                <div class="mb-3">
                    <label class="form-label">Причина списания</label>
                    <textarea name="write_off_note" class="form-control" rows="2" placeholder="Износ, утеря..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-danger">Списать</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="importBooksModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" enctype="multipart/form-data" class="modal-content" id="importBooksForm">
            <input type="hidden" name="action" value="import">
            <div class="modal-header">
                <h5 class="modal-title"><i class="bi bi-upload me-2"></i>Импорт книг из Excel</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label fw-semibold">Фонд <span class="text-danger">*</span></label>
                    <input type="hidden" name="import_fund" id="importFundInput" value="<?php echo htmlspecialchars($filters['fund'] ?? ''); ?>" required>
                    <div class="book-fund-picker" role="radiogroup" aria-label="Фонд для импорта">
                        <?php
                        $importFundIcons = [
                            Library::FUND_PROFESSIONAL => 'bi-briefcase',
                            Library::FUND_FICTION => 'bi-book-half',
                            Library::FUND_EDUCATIONAL => 'bi-mortarboard',
                        ];
                        foreach ($funds as $fundKey => $fundLabel):
                            $isActive = ($filters['fund'] ?? '') === $fundKey;
                        ?>
                            <button type="button"
                                class="book-fund-option js-import-fund <?php echo $isActive ? 'is-active' : ''; ?>"
                                data-fund="<?php echo htmlspecialchars($fundKey); ?>"
                                aria-pressed="<?php echo $isActive ? 'true' : 'false'; ?>">
                                <i class="bi <?php echo htmlspecialchars($importFundIcons[$fundKey] ?? 'bi-book'); ?>"></i>
                                <span><?php echo htmlspecialchars($fundLabel); ?></span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <div class="form-text mt-2 js-import-fund-hint">
                        Все строки файла будут загружены в выбранный фонд.
                    </div>
                </div>

                <p class="small text-muted mb-2">
                    <strong>Профессиональный / Художественный:</strong> Дата поступления, Инвентарный №, Экземпляров, Язык, Автор, Название, Год издания.
                </p>
                <p class="small text-muted mb-3">
                    <strong>Учебный:</strong> Регистрационный номер, Класс, Направление, Наименование издания, Автор, Назначение, Год издания, Количество, Примечание.
                </p>

                <div class="mb-3">
                    <label class="form-label">Файл .xlsx</label>
                    <input type="file" name="import_file" class="form-control" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
                </div>
                <?php if ($eduLocalPath): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" name="use_local_edu" value="1" id="useLocalEdu"
                            <?php echo (!empty($filters['fund']) && $filters['fund'] === Library::FUND_EDUCATIONAL) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="useLocalEdu">
                            Импортировать <code><?php echo htmlspecialchars(basename($eduLocalPath)); ?></code> с сервера
                        </label>
                    </div>
                <?php endif; ?>
                <?php if (is_readable(__DIR__ . '/lib.xlsx')): ?>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="use_local_lib" value="1" id="useLocalLib">
                        <label class="form-check-label" for="useLocalLib">
                            Импортировать готовый файл <code>lib.xlsx</code> с сервера
                        </label>
                    </div>
                <?php endif; ?>
                <p class="small text-warning mt-3 mb-0">
                    <i class="bi bi-hourglass-split me-1"></i>Большой файл может загружаться несколько минут.
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-upload me-1"></i>Импортировать
                </button>
            </div>
        </form>
    </div>
</div>

<script>
const bookFormHtml = <?php
$preselect_fund = '';
ob_start();
include __DIR__ . '/includes/book_form_fields.php';
echo json_encode(ob_get_clean());
?>;
const EDU_FUND = <?php echo json_encode(Library::FUND_EDUCATIONAL); ?>;

function setBookFormMode(root, fund) {
    if (!root) return;
    const eduFund = root.dataset.eduFund || EDU_FUND;
    const hasFund = !!fund;
    const isEdu = fund === eduFund;
    const std = root.querySelector('.js-book-fields-standard');
    const edu = root.querySelector('.js-book-fields-educational');
    const empty = root.querySelector('.js-book-form-empty');
    const hint = root.querySelector('.js-fund-hint');
    const fundInput = root.querySelector('.js-book-fund');

    if (fundInput) {
        fundInput.value = fund || '';
        fundInput.setCustomValidity(hasFund ? '' : 'Выберите фонд');
    }

    root.querySelectorAll('.book-fund-option').forEach(btn => {
        const active = btn.dataset.fund === fund;
        btn.classList.toggle('is-active', active);
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        if (active && hint) {
            hint.textContent = btn.dataset.hint || '';
        }
    });

    if (!hasFund && hint) {
        hint.textContent = 'Выберите фонд — откроются нужные поля';
    }

    if (empty) empty.hidden = hasFund;
    if (std) std.hidden = !hasFund || isEdu;
    if (edu) edu.hidden = !hasFund || !isEdu;

    [std, edu].forEach(block => {
        if (!block) return;
        const active = !block.hidden;
        block.querySelectorAll('input, select, textarea').forEach(el => {
            el.disabled = !active;
            if (el.dataset.required === '1') {
                el.required = active;
            }
        });
    });
}

function bindBookFundToggle(root) {
    if (!root) return;
    const fundInput = root.querySelector('.js-book-fund');
    root.querySelectorAll('.book-fund-option').forEach(btn => {
        btn.addEventListener('click', () => {
            setBookFormMode(root, btn.dataset.fund || '');
            const first = root.querySelector('.book-form-panels:not([hidden]) input:not([disabled]), .book-form-panels:not([hidden]) select:not([disabled]), .book-form-panels:not([hidden]) textarea:not([disabled])');
            if (first) first.focus();
        });
    });
    setBookFormMode(root, fundInput ? fundInput.value : '');
}

function fillBookForm(root, book) {
    setBookFormMode(root, book.fund || '');

    const active = root.querySelector(book.fund === EDU_FUND ? '.js-book-fields-educational' : '.js-book-fields-standard');
    if (!active) return;

    const set = (name, value) => {
        const el = active.querySelector('[name="' + name + '"]');
        if (el) el.value = value ?? '';
    };

    set('title', book.title || '');
    set('author', book.author || '');
    set('publish_year', book.publish_year || '');
    set('inventory_number', book.inventory_number || '');
    set('copies_total', book.copies_total || 1);
    set('note', book.note || '');

    if (book.fund === EDU_FUND) {
        set('grade_class', book.grade_class || '');
        set('direction', book.direction || '');
        set('purpose', book.purpose || '');
    } else {
        set('isbn', book.isbn || '');
        set('publisher', book.publisher || '');
        set('location', book.location || '');
        const langSelect = active.querySelector('[name="language"]');
        if (langSelect) {
            if (book.language && ![...langSelect.options].some(o => o.value === book.language)) {
                const opt = document.createElement('option');
                opt.value = book.language;
                opt.textContent = book.language;
                langSelect.appendChild(opt);
            }
            langSelect.value = book.language || '';
        }
    }
}

document.querySelectorAll('#addBookModal .book-form-fields').forEach(bindBookFundToggle);

(function () {
    const fundInput = document.getElementById('importFundInput');
    const form = document.getElementById('importBooksForm');
    if (!fundInput || !form) return;

    document.querySelectorAll('.js-import-fund').forEach(btn => {
        btn.addEventListener('click', () => {
            const fund = btn.dataset.fund || '';
            fundInput.value = fund;
            document.querySelectorAll('.js-import-fund').forEach(b => {
                const active = b === btn;
                b.classList.toggle('is-active', active);
                b.setAttribute('aria-pressed', active ? 'true' : 'false');
            });
            const eduBox = document.getElementById('useLocalEdu');
            if (eduBox && fund === EDU_FUND) {
                eduBox.checked = true;
            }
        });
    });

    form.addEventListener('submit', (e) => {
        if (!fundInput.value) {
            e.preventDefault();
            fundInput.setCustomValidity('Выберите фонд');
            fundInput.reportValidity();
            return;
        }
        fundInput.setCustomValidity('');
    });
})();

document.querySelectorAll('.btn-edit-book').forEach(btn => {
    btn.addEventListener('click', function() {
        const book = JSON.parse(this.dataset.book);
        document.getElementById('edit_book_id').value = book.id;
        const container = document.getElementById('editBookFields');
        container.innerHTML = bookFormHtml;
        const root = container.querySelector('.book-form-fields');
        bindBookFundToggle(root);
        fillBookForm(root, book);
        new bootstrap.Modal(document.getElementById('editBookModal')).show();
    });
});
document.querySelectorAll('.btn-write-off').forEach(btn => {
    btn.addEventListener('click', function() {
        document.getElementById('writeoff_book_id').value = this.dataset.id;
        document.getElementById('writeoff_title').textContent = this.dataset.title;
        document.getElementById('writeoff_available').textContent = this.dataset.available;
        document.querySelector('#writeOffModal [name="copies"]').max = this.dataset.available;
        new bootstrap.Modal(document.getElementById('writeOffModal')).show();
    });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
