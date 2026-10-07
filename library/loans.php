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
        $borrower_type = ($_POST['borrower_type'] ?? 'student') === 'teacher' ? 'teacher' : 'student';
        $borrower_id = $borrower_type === 'teacher'
            ? (int)($_POST['teacher_id'] ?? 0)
            : (int)($_POST['student_id'] ?? 0);
        $book_id = (int)($_POST['book_id'] ?? 0);
        if (!$borrower_id) {
            $error = $borrower_type === 'teacher'
                ? 'Выберите преподавателя из списка поиска'
                : 'Выберите студента из списка поиска';
        } elseif (!$book_id) {
            $error = 'Выберите книгу из списка поиска';
        } else {
            $result = $library->issueBook(
                $book_id,
                $borrower_id,
                $current_user['id'],
                !empty($_POST['due_date']) ? sanitize($_POST['due_date']) : null,
                sanitize($_POST['note'] ?? ''),
                $borrower_type
            );
            if ($result['success']) {
                $message = $borrower_type === 'teacher'
                    ? 'Книга выдана преподавателю!'
                    : 'Книга выдана студенту!';
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
            <input type="text" name="search" id="librarySearchInput" class="curator-form-control" placeholder="ФИО студента/преподавателя, название книги..." value="<?php echo htmlspecialchars($filters['search']); ?>">
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
                    <th>Читатель</th>
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
                        $fio = Library::formatBorrowerFio($loan);
                        $is_teacher = ($loan['borrower_type'] ?? 'student') === 'teacher';
                        $is_overdue = $loan['status'] === 'active' && $loan['due_date'] < date('Y-m-d');
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($fio); ?></strong>
                                <br>
                                <?php if ($is_teacher): ?>
                                    <span class="curator-badge badge-info">Преподаватель</span>
                                <?php elseif ($loan['group_name']): ?>
                                    <span class="curator-badge badge-primary"><?php echo htmlspecialchars($loan['group_name']); ?></span>
                                <?php else: ?>
                                    <span class="curator-badge badge-primary">Студент</span>
                                <?php endif; ?>
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
        <form method="post" class="modal-content" id="issueForm">
            <input type="hidden" name="action" value="issue">
            <input type="hidden" name="student_id" id="issue_student_id">
            <input type="hidden" name="teacher_id" id="issue_teacher_id">
            <div class="modal-header">
                <h5 class="modal-title">Выдать книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="curator-form-label">Кому выдать *</label>
                    <div class="d-flex gap-3 flex-wrap">
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="borrower_type" id="borrower_type_student" value="student" checked>
                            <span class="form-check-label">Студенту</span>
                        </label>
                        <label class="form-check">
                            <input class="form-check-input" type="radio" name="borrower_type" id="borrower_type_teacher" value="teacher">
                            <span class="form-check-label">Преподавателю</span>
                        </label>
                    </div>
                </div>
                <div class="mb-3 library-student-search-wrap" id="issue_student_block">
                    <label class="curator-form-label">Студент (ФИО) *</label>
                    <input type="text" id="issue_student_search" class="curator-form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                    <div id="issue_student_results" class="list-group library-student-search-results d-none"></div>
                    <div class="form-text">Выберите студента из списка результатов</div>
                </div>
                <div class="mb-3 library-student-search-wrap d-none" id="issue_teacher_block">
                    <label class="curator-form-label">Преподаватель (ФИО) *</label>
                    <input type="text" id="issue_teacher_search" class="curator-form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                    <div id="issue_teacher_results" class="list-group library-student-search-results d-none"></div>
                    <div class="form-text">Выберите преподавателя из списка результатов</div>
                </div>
                <div class="mb-3 library-student-search-wrap">
                    <label class="curator-form-label">Книга *</label>
                    <input type="hidden" name="book_id" id="issue_book_id">
                    <div class="library-book-search-field">
                        <i class="bi bi-search library-book-search-icon" aria-hidden="true"></i>
                        <input type="text" id="issue_book_search" class="curator-form-control library-book-search-input" placeholder="Название, автор или инв. номер..." autocomplete="off">
                        <button type="button" class="library-book-clear" id="issue_book_clear" title="Очистить" aria-label="Очистить">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <div id="issue_book_results" class="list-group library-student-search-results d-none"></div>
                    <div id="issue_book_selected" class="library-book-selected d-none"></div>
                    <div class="form-text">Начните вводить — выберите книгу из доступных</div>
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
    initTeacherSearch('issue_teacher_search', 'issue_teacher_id', 'issue_teacher_results');
    initBookSearch({
        inputId: 'issue_book_search',
        hiddenId: 'issue_book_id',
        resultsId: 'issue_book_results',
        selectedId: 'issue_book_selected',
        clearId: 'issue_book_clear'
    });

    const studentBlock = document.getElementById('issue_student_block');
    const teacherBlock = document.getElementById('issue_teacher_block');
    const studentId = document.getElementById('issue_student_id');
    const teacherId = document.getElementById('issue_teacher_id');
    const studentSearch = document.getElementById('issue_student_search');
    const teacherSearch = document.getElementById('issue_teacher_search');
    const radios = document.querySelectorAll('input[name="borrower_type"]');
    const issueForm = document.getElementById('issueForm');

    function syncBorrowerType() {
        const isTeacher = document.getElementById('borrower_type_teacher').checked;
        studentBlock.classList.toggle('d-none', isTeacher);
        teacherBlock.classList.toggle('d-none', !isTeacher);
        if (isTeacher) {
            studentId.value = '';
            studentSearch.value = '';
        } else {
            teacherId.value = '';
            teacherSearch.value = '';
        }
    }

    radios.forEach(function(radio) {
        radio.addEventListener('change', syncBorrowerType);
    });
    syncBorrowerType();

    if (issueForm) {
        issueForm.addEventListener('submit', function(e) {
            const isTeacher = document.getElementById('borrower_type_teacher').checked;
            const personOk = isTeacher ? !!teacherId.value : !!studentId.value;
            const bookOk = !!document.getElementById('issue_book_id').value;
            if (!personOk) {
                e.preventDefault();
                alert(isTeacher ? 'Выберите преподавателя из списка' : 'Выберите студента из списка');
                return;
            }
            if (!bookOk) {
                e.preventDefault();
                alert('Выберите книгу из списка поиска');
            }
        });
    }
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
