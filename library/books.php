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
            'category' => sanitize($_POST['category'] ?? ''),
            'location' => sanitize($_POST['location'] ?? ''),
            'inventory_number' => sanitize($_POST['inventory_number'] ?? ''),
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
            'category' => sanitize($_POST['category'] ?? ''),
            'location' => sanitize($_POST['location'] ?? ''),
            'inventory_number' => sanitize($_POST['inventory_number'] ?? ''),
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
}

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $_GET['status'] ?? 'active',
    'category' => $_GET['category'] ?? '',
];
$books = $library->getBooks($filters);
$categories = $library->getCategories();

$page_title = 'Книги';
$page_subtitle = 'Книжный фонд · ' . count($books) . ' записей';
require_once 'includes/header.php';
?>

<?php if ($can_manage): ?>
<div class="d-flex justify-content-end mb-3">
    <div class="curator-action-buttons">
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
            <input type="text" name="search" id="librarySearchInput" class="curator-form-control" placeholder="Название, автор, ISBN..." value="<?php echo htmlspecialchars($filters['search']); ?>">
        </div>
        <div class="curator-form-group">
            <label class="curator-form-label">Статус</label>
            <select name="status" class="curator-form-select">
                <option value="">Все</option>
                <option value="active" <?php echo $filters['status'] === 'active' ? 'selected' : ''; ?>>Активные</option>
                <option value="written_off" <?php echo $filters['status'] === 'written_off' ? 'selected' : ''; ?>>Списанные</option>
            </select>
        </div>
        <div class="curator-form-group">
            <label class="curator-form-label">Категория</label>
            <select name="category" class="curator-form-select">
                <option value="">Все</option>
                <?php foreach ($categories as $cat): ?>
                    <option value="<?php echo htmlspecialchars($cat); ?>" <?php echo $filters['category'] === $cat ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="curator-form-group">
            <label class="curator-form-label">&nbsp;</label>
            <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Найти</button>
        </div>
    </form>
</div>

<div class="curator-table-container">
    <div class="table-responsive">
        <table class="table curator-table mb-0">
            <thead>
                <tr>
                    <th>Название</th>
                    <th>Автор</th>
                    <th>Инв. №</th>
                    <th>Доступно</th>
                    <th>Место</th>
                    <th>Статус</th>
                    <?php if ($can_manage): ?><th>Действия</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($books)): ?>
                    <tr><td colspan="<?php echo $can_manage ? 7 : 6; ?>" class="text-center text-muted py-4">Книги не найдены</td></tr>
                <?php else: ?>
                    <?php foreach ($books as $book): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($book['title']); ?></strong>
                                <?php if ($book['category']): ?><br><small class="text-muted"><?php echo htmlspecialchars($book['category']); ?></small><?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($book['author']); ?></td>
                            <td><code><?php echo htmlspecialchars($book['inventory_number'] ?? '—'); ?></code></td>
                            <td><?php echo (int)$book['copies_available']; ?> / <?php echo (int)$book['copies_total']; ?></td>
                            <td><?php echo htmlspecialchars($book['location'] ?? '—'); ?></td>
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

<?php if ($can_manage): ?>
<div class="modal fade" id="addBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Добавить книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php include __DIR__ . '/includes/book_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<div class="modal fade" id="editBookModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="book_id" id="edit_book_id">
            <div class="modal-header">
                <h5 class="modal-title">Редактировать книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="editBookFields"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
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

<script>
const bookFormHtml = <?php echo json_encode(file_get_contents(__DIR__ . '/includes/book_form_fields.php')); ?>;
document.querySelectorAll('.btn-edit-book').forEach(btn => {
    btn.addEventListener('click', function() {
        const book = JSON.parse(this.dataset.book);
        document.getElementById('edit_book_id').value = book.id;
        const container = document.getElementById('editBookFields');
        container.innerHTML = bookFormHtml;
        container.querySelector('[name="title"]').value = book.title || '';
        container.querySelector('[name="author"]').value = book.author || '';
        container.querySelector('[name="isbn"]').value = book.isbn || '';
        container.querySelector('[name="publisher"]').value = book.publisher || '';
        container.querySelector('[name="publish_year"]').value = book.publish_year || '';
        container.querySelector('[name="category"]').value = book.category || '';
        container.querySelector('[name="location"]').value = book.location || '';
        container.querySelector('[name="inventory_number"]').value = book.inventory_number || '';
        container.querySelector('[name="copies_total"]').value = book.copies_total || 1;
        container.querySelector('[name="note"]').value = book.note || '';
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
