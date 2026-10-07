<?php
require_once '../config/config.php';
require_once '../classes/Library.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$library = new Library();
$library->expireOldReservations();
$admin_id = (int)($_SESSION['admin_id'] ?? 0);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reserve') {
        $student_id = (int)($_POST['student_id'] ?? 0);
        if (!$student_id) {
            $error = 'Выберите студента из списка поиска';
        } else {
            $result = $library->createReservation(
                (int)$_POST['book_id'],
                $student_id,
                $admin_id ?: null,
                !empty($_POST['expires_at']) ? sanitize($_POST['expires_at']) : null,
                sanitize($_POST['note'] ?? '')
            );
            if ($result['success']) {
                $message = 'Бронь создана';
            } else {
                $error = $result['error'];
            }
        }
    }

    if ($action === 'cancel') {
        if ($library->cancelReservation((int)$_POST['reservation_id'])) {
            $message = 'Бронь отменена';
        } else {
            $error = 'Не удалось отменить бронь';
        }
    }
}

$tab = $_GET['tab'] ?? 'pending';
$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $tab === 'all' ? '' : $tab,
];
$reservations = $library->getReservations($filters);
$books = $library->getBooks(['status' => 'active']);

$page_title = 'Бронирование';
$page_subtitle = count($reservations) . ' записей';
$active_page = 'library';
$library_nav = 'reservations';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Бронирование</h1>
        <p class="page-subtitle">Резервирование книг за студентами</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reserveModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Новая бронь</span>
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
        <a class="nav-link <?php echo $tab === 'pending' ? 'active' : ''; ?>" href="?tab=pending">Активные</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'fulfilled' ? 'active' : ''; ?>" href="?tab=fulfilled">Выдано</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'cancelled' ? 'active' : ''; ?>" href="?tab=cancelled">Отменённые</a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?php echo $tab === 'expired' ? 'active' : ''; ?>" href="?tab=expired">Истекшие</a>
    </li>
</ul>

<div class="card mb-4">
    <div class="card-body">
        <form method="get" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
            <div class="col-md-9">
                <label class="form-label">Поиск</label>
                <input type="text" name="search" class="form-control" placeholder="ФИО или название книги..." value="<?php echo htmlspecialchars($filters['search']); ?>">
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
        <h2 class="card-title"><i class="bi bi-bookmark"></i> Брони</h2>
        <span class="badge badge-primary"><?php echo count($reservations); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>Книга</th>
                        <th>Забронировано</th>
                        <th>Действует до</th>
                        <th>Статус</th>
                        <th style="width: 80px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($reservations)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Броней нет
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($reservations as $res): ?>
                            <?php
                            $badge_map = [
                                'pending' => 'badge-warning',
                                'fulfilled' => 'badge-success',
                                'cancelled' => 'badge-secondary',
                                'expired' => 'badge-danger',
                            ];
                            $labels = [
                                'pending' => 'Ожидает',
                                'fulfilled' => 'Выдано',
                                'cancelled' => 'Отменена',
                                'expired' => 'Истекла',
                            ];
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars(Library::formatStudentFio($res)); ?></strong>
                                    <?php if (!empty($res['group_name'])): ?>
                                        <div><span class="badge badge-primary"><?php echo htmlspecialchars($res['group_name']); ?></span></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($res['title']); ?>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                        доступно: <?php echo (int)$res['copies_available']; ?>
                                    </div>
                                </td>
                                <td><?php echo date('d.m.Y H:i', strtotime($res['reserved_at'])); ?></td>
                                <td><?php echo formatDate($res['expires_at']); ?></td>
                                <td>
                                    <span class="badge <?php echo $badge_map[$res['status']] ?? 'badge-secondary'; ?>">
                                        <?php echo $labels[$res['status']] ?? $res['status']; ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($res['status'] === 'pending'): ?>
                                        <form method="post" class="d-inline" onsubmit="return confirm('Отменить бронь?');">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int)$res['id']; ?>">
                                            <button type="submit" class="btn btn-icon btn-sm btn-outline" title="Отменить" style="color: var(--danger);">
                                                <i class="bi bi-x-lg"></i>
                                            </button>
                                        </form>
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

<div class="modal fade" id="reserveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="reserve">
                <input type="hidden" name="student_id" id="reserve_student_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-bookmark-plus me-2"></i>Забронировать книгу</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3 library-student-search-wrap">
                        <label class="form-label">Студент (ФИО) <span class="text-danger">*</span></label>
                        <input type="text" id="reserve_student_search" class="form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                        <div id="reserve_student_results" class="list-group library-student-search-results d-none"></div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Книга <span class="text-danger">*</span></label>
                        <select name="book_id" class="form-select" required>
                            <option value="">— выберите —</option>
                            <?php foreach ($books as $b): ?>
                                <option value="<?php echo (int)$b['id']; ?>">
                                    <?php echo htmlspecialchars($b['title'] . ' — ' . $b['author']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Бронь действует до</label>
                        <input type="date" name="expires_at" class="form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Примечание</label>
                        <textarea name="note" class="form-control" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Забронировать</button>
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
    initStudentSearch('reserve_student_search', 'reserve_student_id', 'reserve_student_results');
});
</script>

<?php include 'includes/admin_footer.php'; ?>
