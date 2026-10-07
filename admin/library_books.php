<?php
require_once '../config/config.php';
require_once '../classes/Library.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$library = new Library();
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
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
            'added_by' => $admin_id ?: null,
        ]);
        $message = $result ? 'Книга добавлена' : '';
        $error = $result ? '' : 'Ошибка при добавлении';
    }

    if ($action === 'edit') {
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
            $message = 'Книга обновлена';
        } else {
            $error = 'Ошибка при обновлении';
        }
    }

    if ($action === 'write_off') {
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

    if ($action === 'delete') {
        $result = $library->deleteBook((int)$_POST['book_id']);
        if ($result['success']) {
            $message = 'Книга удалена из базы';
        } else {
            $error = $result['error'] ?? 'Не удалось удалить книгу';
        }
    }
}

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
$per_page = 25;
$current_page = max(1, (int)($_GET['page'] ?? 1));
$total_books = $library->countBooks($filters);
$total_pages = max(1, (int)ceil($total_books / $per_page));
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;
$filters['limit'] = $per_page;
$filters['offset'] = $offset;
$books = $library->getBooks($filters);
$shown_from = $total_books > 0 ? $offset + 1 : 0;
$shown_to = min($offset + $per_page, $total_books);
$is_edu_view = $filters['fund'] === Library::FUND_EDUCATIONAL;

$booksPageUrl = static function ($page) use ($filters) {
    $params = [
        'page' => $page,
        'search' => $filters['search'] ?? '',
        'status' => $filters['status'] ?? '',
        'fund' => $filters['fund'] ?? '',
    ];
    return 'library_books.php?' . http_build_query(array_filter($params, static function ($v) {
        return $v !== '' && $v !== null;
    }));
};

$page_title = 'Книги';
$fund_subtitle = $filters['fund'] !== '' ? Library::fundLabel($filters['fund']) . ' фонд' : 'Все фонды';
$page_subtitle = $fund_subtitle . ' · ' . $total_books . ' записей';
$active_page = 'library';
$library_nav = 'books';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Книги</h1>
        <p class="page-subtitle">Каталог книжного фонда</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addBookModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Добавить книгу</span>
        </button>
    </div>
</div>

<?php include 'includes/library_nav.php'; ?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label class="form-label">Поиск</label>
                <input type="text" name="search" class="form-control" placeholder="<?php echo $is_edu_view ? 'Наименование, автор, класс...' : 'Название, автор, ISBN...'; ?>" value="<?php echo htmlspecialchars($filters['search']); ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label">Статус</label>
                <select name="status" class="form-select">
                    <option value="">Все</option>
                    <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Активные</option>
                    <option value="written_off" <?php echo $filters['status'] === 'written_off' ? 'selected' : ''; ?>>Списанные</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label">Фонд</label>
                <select name="fund" class="form-select">
                    <option value="">Все фонды</option>
                    <?php foreach ($funds as $fundKey => $fundLabel): ?>
                        <option value="<?php echo htmlspecialchars($fundKey); ?>" <?php echo $filters['fund'] === $fundKey ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($fundLabel); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Найти
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-journal-bookmark"></i> Список книг</h2>
        <span class="badge badge-primary"><?php echo $total_books; ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
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
                        <?php else: ?>
                            <th>Название</th>
                            <th>Автор</th>
                            <th>Инв. №</th>
                            <th>Доступно</th>
                            <th>Место</th>
                        <?php endif; ?>
                        <th>Статус</th>
                        <th style="width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($books)): ?>
                        <tr>
                            <td colspan="<?php echo $is_edu_view ? 10 : 7; ?>" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Книги не найдены
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($books as $book): ?>
                            <tr>
                                <?php if ($is_edu_view): ?>
                                    <td>
                                        <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                            <?php echo htmlspecialchars($book['inventory_number'] ?: '—'); ?>
                                        </code>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($book['title']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td><?php echo htmlspecialchars($book['grade_class'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($book['direction'] ?: '—'); ?></td>
                                    <td><?php echo htmlspecialchars($book['purpose'] ?: '—'); ?></td>
                                    <td><?php echo !empty($book['publish_year']) ? (int)$book['publish_year'] : '—'; ?></td>
                                    <td><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></td>
                                <?php else: ?>
                                    <td>
                                        <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                        <?php
                                        $meta = array_filter([
                                            !empty($book['fund']) ? Library::fundLabel($book['fund']) : '',
                                            $book['language'] ?? '',
                                        ]);
                                        if ($meta):
                                        ?>
                                            <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                                <?php echo htmlspecialchars(implode(' · ', $meta)); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($book['author']); ?></td>
                                    <td>
                                        <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                            <?php echo htmlspecialchars($book['inventory_number'] ?: '—'); ?>
                                        </code>
                                    </td>
                                    <td><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></td>
                                    <td><?php echo htmlspecialchars($book['location'] ?: '—'); ?></td>
                                <?php endif; ?>
                                <td>
                                    <?php if ($book['status'] === 'written_off'): ?>
                                        <span class="badge badge-danger">Списана</span>
                                    <?php else: ?>
                                        <span class="badge badge-success">В фонде</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex gap-1">
                                        <?php if ($book['status'] === 'active'): ?>
                                            <button type="button" class="btn btn-icon btn-sm btn-outline btn-edit-book"
                                                    data-book='<?php echo htmlspecialchars(json_encode($book, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                                    title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-icon btn-sm btn-outline btn-write-off"
                                                    data-id="<?php echo (int)$book['id']; ?>"
                                                    data-title="<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>"
                                                    data-available="<?php echo (int)$book['copies_available']; ?>"
                                                    title="Списать">
                                                <i class="bi bi-slash-circle"></i>
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-icon btn-sm btn-outline btn-delete-book"
                                                style="color: var(--danger);"
                                                data-id="<?php echo (int)$book['id']; ?>"
                                                data-title="<?php echo htmlspecialchars($book['title'], ENT_QUOTES); ?>"
                                                title="Удалить из базы">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total_books > 0): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span style="font-size: 0.875rem; color: var(--text-secondary);">
                Показано <?php echo $shown_from; ?>–<?php echo $shown_to; ?> из <?php echo $total_books; ?>
            </span>
            <?php if ($total_pages > 1): ?>
                <nav aria-label="Страницы книг">
                    <div class="d-flex gap-1 flex-wrap align-items-center">
                        <?php if ($current_page > 1): ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($booksPageUrl($current_page - 1)); ?>" title="Назад">
                                <i class="bi bi-chevron-left"></i>
                            </a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-outline" style="opacity: 0.5; pointer-events: none;">
                                <i class="bi bi-chevron-left"></i>
                            </span>
                        <?php endif; ?>

                        <?php
                        $page_window = 2;
                        $start_page = max(1, $current_page - $page_window);
                        $end_page = min($total_pages, $current_page + $page_window);
                        ?>

                        <?php if ($start_page > 1): ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($booksPageUrl(1)); ?>">1</a>
                            <?php if ($start_page > 2): ?>
                                <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                            <?php if ($p === $current_page): ?>
                                <span class="btn btn-sm btn-primary"><?php echo $p; ?></span>
                            <?php else: ?>
                                <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($booksPageUrl($p)); ?>"><?php echo $p; ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>

                        <?php if ($end_page < $total_pages): ?>
                            <?php if ($end_page < $total_pages - 1): ?>
                                <span style="color: var(--text-muted); padding: 0 0.25rem;">…</span>
                            <?php endif; ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($booksPageUrl($total_pages)); ?>"><?php echo $total_pages; ?></a>
                        <?php endif; ?>

                        <?php if ($current_page < $total_pages): ?>
                            <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($booksPageUrl($current_page + 1)); ?>" title="Вперёд">
                                <i class="bi bi-chevron-right"></i>
                            </a>
                        <?php else: ?>
                            <span class="btn btn-sm btn-outline" style="opacity: 0.5; pointer-events: none;">
                                <i class="bi bi-chevron-right"></i>
                            </span>
                        <?php endif; ?>
                    </div>
                </nav>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<form id="deleteBookForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="book_id" id="delete_book_id">
</form>

<div class="modal fade" id="addBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2"></i>Добавить книгу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php
                    $preselect_fund = $filters['fund'];
                    include __DIR__ . '/includes/book_form_fields.php';
                    ?>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="book_id" id="edit_book_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать книгу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="editBookFields"></div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="writeOffModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="write_off">
                <input type="hidden" name="book_id" id="writeoff_book_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Списать книгу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Книга: <strong id="writeoff_title"></strong></p>
                    <p class="small text-muted">Доступно для списания (не на руках): <span id="writeoff_available"></span> экз.</p>
                    <div class="mb-3">
                        <label class="form-label">Количество экземпляров</label>
                        <input type="number" name="copies" class="form-control" value="1" min="1">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Причина списания</label>
                        <textarea name="write_off_note" class="form-control" rows="2" placeholder="Износ, утеря..."></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary" style="background: var(--danger); border-color: var(--danger);">
                        <i class="bi bi-trash"></i> Списать
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<style>
.book-form-fields .contents-row { display: contents; }
.book-form-fields .contents-row[hidden] { display: none !important; }
</style>
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
    const isEdu = fund === eduFund;
    const std = root.querySelector('.js-book-fields-standard');
    const edu = root.querySelector('.js-book-fields-educational');
    if (!std || !edu) return;
    std.hidden = isEdu;
    edu.hidden = !isEdu;
    [std, edu].forEach(block => {
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
    const select = root.querySelector('.js-book-fund');
    if (!select) return;
    const apply = () => setBookFormMode(root, select.value);
    select.addEventListener('change', apply);
    apply();
}

function fillBookForm(root, book) {
    const fundSelect = root.querySelector('.js-book-fund');
    if (fundSelect) fundSelect.value = book.fund || '';
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
        if (langSelect) langSelect.value = book.language || '';
    }
}

document.querySelectorAll('#addBookModal .book-form-fields').forEach(bindBookFundToggle);

document.querySelectorAll('.btn-edit-book').forEach(btn => {
    btn.addEventListener('click', function () {
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
    btn.addEventListener('click', function () {
        document.getElementById('writeoff_book_id').value = this.dataset.id;
        document.getElementById('writeoff_title').textContent = this.dataset.title;
        document.getElementById('writeoff_available').textContent = this.dataset.available;
        document.querySelector('#writeOffModal [name="copies"]').max = this.dataset.available;
        new bootstrap.Modal(document.getElementById('writeOffModal')).show();
    });
});

document.querySelectorAll('.btn-delete-book').forEach(btn => {
    btn.addEventListener('click', function () {
        const title = this.dataset.title || '';
        if (confirm('Удалить книгу «' + title + '» из базы безвозвратно?\nИстория выдач и броней по этой книге тоже будет удалена.')) {
            document.getElementById('delete_book_id').value = this.dataset.id;
            document.getElementById('deleteBookForm').submit();
        }
    });
});
</script>

<?php include 'includes/admin_footer.php'; ?>
