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
$current_user = getCurrentUser();
$message = '';
$error = '';

$can_issue = hasPermission('issue_books');
$can_return = hasPermission('return_books');
$can_extend = hasPermission('extend_loans');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue' && $can_issue) {
        $student_id = (int)($_POST['student_id'] ?? 0);
        if (!$student_id) {
            $error = 'Выберите студента из списка поиска';
        } else {
            $result = $library->issueBook(
                (int)$_POST['book_id'],
                $student_id,
                $current_user['id'],
                !empty($_POST['due_date']) ? sanitize($_POST['due_date']) : null,
                sanitize($_POST['note'] ?? '')
            );
            if ($result['success']) {
                $message = 'Книга выдана студенту!';
            } else {
                $error = $result['error'];
            }
        }
    }

    if ($action === 'return' && $can_return) {
        $result = $library->returnBook((int)$_POST['loan_id'], $current_user['id'], sanitize($_POST['note'] ?? ''));
        if ($result['success']) {
            $message = 'Книга принята!';
        } else {
            $error = $result['error'];
        }
    }

    if ($action === 'extend' && $can_extend) {
        $days = !empty($_POST['days']) ? (int)$_POST['days'] : null;
        $result = $library->extendLoan((int)$_POST['loan_id'], $days);
        if ($result['success']) {
            $message = 'Срок продлён до ' . formatDate($result['new_due_date']);
        } else {
            $error = $result['error'];
        }
    }
}

$tab = $_GET['tab'] ?? 'active';
$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $tab === 'all' ? '' : ($tab === 'overdue' ? '' : 'active'),
    'overdue_only' => $tab === 'overdue',
];

if ($tab === 'returned') {
    $filters['status'] = 'returned';
    $filters['overdue_only'] = false;
}

$loans = $library->getLoans($filters);
$available_books = $library->getBooks(['status' => 'active', 'available_only' => true]);

$page_title = 'Выдача / приём';
$page_subtitle = count($loans) . ' записей · ' . date('d.m.Y');
require_once 'includes/header.php';
?>

<?php if ($can_issue): ?>
<div class="d-flex justify-content-end mb-3">
    <div class="curator-action-buttons">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#issueModal">
            <i class="bi bi-box-arrow-right me-1"></i>Выдать книгу
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

<ul class="nav library-nav-tabs">
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'active' ? 'active' : ''; ?>" href="?tab=active">На руках</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'overdue' ? 'active' : ''; ?>" href="?tab=overdue">Просрочено</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'returned' ? 'active' : ''; ?>" href="?tab=returned">Возвращённые</a></li>
</ul>

<div class="curator-filters">
    <form method="get" class="curator-filter-row">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="curator-form-group">
            <label class="curator-form-label">Поиск</label>
            <input type="text" name="search" id="librarySearchInput" class="curator-form-control" placeholder="ФИО студента, название книги..." value="<?php echo htmlspecialchars($filters['search']); ?>">
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
                    <th>Студент</th>
                    <th>Книга</th>
                    <th>Выдано</th>
                    <th>Срок</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($loans)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Записей нет</td></tr>
                <?php else: ?>
                    <?php foreach ($loans as $loan): ?>
                        <?php
                        $fio = Library::formatStudentFio($loan);
                        $is_overdue = $loan['status'] === 'active' && $loan['due_date'] < date('Y-m-d');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($fio); ?></strong>
                                <?php if ($loan['group_name']): ?><br><span class="curator-badge badge-primary"><?php echo htmlspecialchars($loan['group_name']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($loan['title']); ?>
                                <br><small class="text-muted"><?php echo htmlspecialchars($loan['author']); ?></small>
                            </td>
                            <td><?php echo formatDate($loan['issued_at']); ?></td>
                            <td>
                                <?php echo formatDate($loan['due_date']); ?>
                                <?php if ($loan['extended_count'] > 0): ?>
                                    <br><small class="text-muted">продлений: <?php echo (int)$loan['extended_count']; ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($loan['status'] === 'returned'): ?>
                                    <span class="curator-badge badge-info">Возвращена</span>
                                <?php elseif ($is_overdue || $loan['status'] === 'overdue'): ?>
                                    <span class="curator-badge badge-danger">Просрочено</span>
                                <?php else: ?>
                                    <span class="curator-badge badge-primary">На руках</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($loan['status'] === 'active' || $loan['status'] === 'overdue'): ?>
                                    <div class="curator-table-actions">
                                        <?php if ($can_return): ?>
                                            <form method="post" class="d-inline" onsubmit="return confirm('Принять книгу?');">
                                                <input type="hidden" name="action" value="return">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$loan['id']; ?>">
                                                <button type="submit" class="btn btn-outline-success" title="Принять"><i class="bi bi-box-arrow-in-left"></i></button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($can_extend): ?>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="extend">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$loan['id']; ?>">
                                                <input type="hidden" name="days" value="7">
                                                <button type="submit" class="btn btn-outline-primary" title="Продлить на 7 дней"><i class="bi bi-calendar-plus"></i></button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <small class="text-muted"><?php echo $loan['returned_at'] ? formatDate($loan['returned_at']) : ''; ?></small>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_issue): ?>
<div class="modal fade" id="issueModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="issue">
            <input type="hidden" name="student_id" id="issue_student_id">
            <div class="modal-header">
                <h5 class="modal-title">Выдать книгу студенту</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 library-student-search-wrap">
                    <label class="curator-form-label">Студент (ФИО) *</label>
                    <input type="text" id="issue_student_search" class="curator-form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                    <div id="issue_student_results" class="list-group library-student-search-results d-none"></div>
                    <div class="form-text">Выберите студента из списка результатов</div>
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Книга *</label>
                    <select name="book_id" class="curator-form-select" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($available_books as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo htmlspecialchars($b['title'] . ' — ' . $b['author'] . ' (доступно: ' . $b['copies_available'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Срок возврата</label>
                    <input type="date" name="due_date" class="curator-form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Примечание</label>
                    <textarea name="note" class="curator-form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Выдать</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initStudentSearch('issue_student_search', 'issue_student_id', 'issue_student_results');
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
