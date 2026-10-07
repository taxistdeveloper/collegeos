<?php
require_once '../config/config.php';
require_once '../classes/Library.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$library = new Library();
$library->markOverdueLoans();
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'issue') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        if (!$student_id) {
            $error = 'Выберите студента из списка поиска';
        } else {
            $result = $library->issueBook(
                (int)$_POST['book_id'],
                $student_id,
                $admin_id ?: null,
                !empty($_POST['due_date']) ? sanitize($_POST['due_date']) : null,
                sanitize($_POST['note'] ?? '')
            );
            if ($result['success']) {
                $message = 'Книга выдана студенту';
            } else {
                $error = $result['error'];
            }
        }
    }

    if ($action === 'return') {
        $result = $library->returnBook((int)$_POST['loan_id'], $admin_id ?: null, sanitize($_POST['note'] ?? ''));
        if ($result['success']) {
            $message = 'Книга принята';
        } else {
            $error = $result['error'];
        }
    }

    if ($action === 'extend') {
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
$page_subtitle = count($loans) . ' записей';
$active_page = 'library';
$library_nav = 'loans';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Выдача / приём</h1>
        <p class="page-subtitle">Выдача книг студентам и приём возвратов</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#issueModal">
            <i class="bi bi-box-arrow-right"></i>
            <span>Выдать книгу</span>
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

<ul class="nav library-admin-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'active' ? 'active' : ''; ?>" href="?tab=active">На руках</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'overdue' ? 'active' : ''; ?>" href="?tab=overdue">Просрочено</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'returned' ? 'active' : ''; ?>" href="?tab=returned">Возвращённые</a>
    </li>
</ul>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-md-9">
                <label class="form-label">Поиск</label>
                <input type="text" name="search" class="form-control" placeholder="ФИО студента, название книги..." value="<?php echo htmlspecialchars($filters['search']); ?>">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="bi bi-search"></i> Найти
                </button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-arrow-left-right"></i> Журнал выдачи</h2>
        <span class="badge badge-primary"><?php echo count($loans); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>Книга</th>
                        <th>Выдано</th>
                        <th>Срок</th>
                        <th>Статус</th>
                        <th style="width: 120px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($loans)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Записей нет
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($loans as $loan): ?>
                            <?php
                            $fio = Library::formatStudentFio($loan);
                            $is_overdue = $loan['status'] === 'active' && $loan['due_date'] < date('Y-m-d');
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($fio); ?></strong>
                                    <?php if (!empty($loan['group_name'])): ?>
                                        <div><span class="badge badge-primary"><?php echo htmlspecialchars($loan['group_name']); ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($loan['title']); ?>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($loan['author']); ?>
                                    </div>
                                </td>
                                <td><?php echo formatDate($loan['issued_at']); ?></td>
                                <td>
                                    <?php echo formatDate($loan['due_date']); ?>
                                    <?php if ((int)$loan['extended_count'] > 0): ?>
                                        <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                            продлений: <?php echo (int)$loan['extended_count']; ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($loan['status'] === 'returned'): ?>
                                        <span class="badge badge-secondary">Возвращена</span>
                                    <?php elseif ($is_overdue || $loan['status'] === 'overdue'): ?>
                                        <span class="badge badge-danger">Просрочено</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">На руках</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($loan['status'] === 'active' || $loan['status'] === 'overdue'): ?>
                                        <div class="d-flex gap-1">
                                            <form method="post" class="d-inline" onsubmit="return confirm('Принять книгу?');">
                                                <input type="hidden" name="action" value="return">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$loan['id']; ?>">
                                                <button type="submit" class="btn btn-icon btn-sm btn-outline" title="Принять" style="color: var(--success);">
                                                    <i class="bi bi-box-arrow-in-left"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="action" value="extend">
                                                <input type="hidden" name="loan_id" value="<?php echo (int)$loan['id']; ?>">
                                                <input type="hidden" name="days" value="7">
                                                <button type="submit" class="btn btn-icon btn-sm btn-outline" title="Продлить на 7 дней">
                                                    <i class="bi bi-calendar-plus"></i>
                                                </button>
                                            </form>
                                        </div>
                                    <?php else: ?>
                                        <span style="font-size: 0.75rem; color: var(--text-secondary);">
                                            <?php echo $loan['returned_at'] ? formatDate($loan['returned_at']) : ''; ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="issueModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="issue">
                <input type="hidden" name="student_id" id="issue_student_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-box-arrow-right me-2"></i>Выдать книгу студенту</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 library-student-search-wrap">
                        <label class="form-label">Студент (ФИО) <span class="text-danger">*</span></label>
                        <input type="text" id="issue_student_search" class="form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                        <div id="issue_student_results" class="list-group library-student-search-results d-none"></div>
                        <div class="form-text">Выберите студента из списка результатов</div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Книга <span class="text-danger">*</span></label>
                        <select name="book_id" class="form-select" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($available_books as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    <?php echo htmlspecialchars($b['title'] . ' — ' . $b['author'] . ' (доступно: ' . $b['copies_available'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Срок возврата</label>
                        <input type="date" name="due_date" class="form-control" value="<?php echo date('Y-m-d', strtotime('+14 days')); ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Примечание</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Выдать</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function initStudentSearch(inputId, hiddenId, resultsId) {
    const input = document.getElementById(inputId);
    const hidden = document.getElementById(hiddenId);
    const results = document.getElementById(resultsId);
    if (!input || !hidden || !results) return;

    let timer = null;
    input.addEventListener('input', function () {
        clearTimeout(timer);
        const q = input.value.trim();
        hidden.value = '';
        if (q.length < 2) {
            results.innerHTML = '';
            results.classList.add('d-none');
            return;
        }
        timer = setTimeout(function () {
            fetch('search_library_students.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.students.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = data.students.map(s => {
                        const fio = [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');
                        const meta = [s.group_name, s.iin].filter(Boolean).join(' · ');
                        return '<button type="button" class="list-group-item list-group-item-action text-start" data-id="' + s.id + '" data-fio="' + fio.replace(/"/g, '&quot;') + '">' +
                            '<strong>' + fio + '</strong><br><small class="text-muted">' + meta + '</small></button>';
                    }).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('.list-group-item-action').forEach(btn => {
                        btn.addEventListener('click', function () {
                            hidden.value = this.dataset.id;
                            input.value = this.dataset.fio;
                            results.classList.add('d-none');
                        });
                    });
                });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.classList.add('d-none');
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {
    initStudentSearch('issue_student_search', 'issue_student_id', 'issue_student_results');
});
</script>

<?php include 'includes/admin_footer.php'; ?>
