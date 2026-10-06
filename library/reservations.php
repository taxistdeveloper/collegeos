<?php
require_once '../config/config.php';
require_once '../classes/Library.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['librarian']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_library');

$library = new Library();
$library->expireOldReservations();
$current_user = getCurrentUser();
$message = '';
$error = '';

$can_reserve = hasPermission('reserve_books');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'reserve' && $can_reserve) {
        $student_id = (int)($_POST['student_id'] ?? 0);
        if (!$student_id) {
            $error = 'Выберите студента из списка поиска';
        } else {
            $result = $library->createReservation(
                (int)$_POST['book_id'],
                $student_id,
                $current_user['id'],
                !empty($_POST['expires_at']) ? sanitize($_POST['expires_at']) : null,
                sanitize($_POST['note'] ?? '')
            );
            if ($result['success']) {
                $message = 'Бронь создана!';
            } else {
                $error = $result['error'];
            }
        }
    }

    if ($action === 'cancel' && $can_reserve) {
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
$page_subtitle = count($reservations) . ' записей · ' . date('d.m.Y');
require_once 'includes/header.php';
?>

<?php if ($can_reserve): ?>
<div class="d-flex justify-content-end mb-3">
    <div class="curator-action-buttons">
        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#reserveModal">
            <i class="bi bi-plus-lg me-1"></i>Новая бронь
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
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'pending' ? 'active' : ''; ?>" href="?tab=pending">Активные</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'fulfilled' ? 'active' : ''; ?>" href="?tab=fulfilled">Выдано</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'cancelled' ? 'active' : ''; ?>" href="?tab=cancelled">Отменённые</a></li>
    <li class="nav-item"><a class="nav-link <?php echo $tab === 'expired' ? 'active' : ''; ?>" href="?tab=expired">Истекшие</a></li>
</ul>

<div class="curator-filters">
    <form method="get" class="curator-filter-row">
        <input type="hidden" name="tab" value="<?php echo htmlspecialchars($tab); ?>">
        <div class="curator-form-group">
            <label class="curator-form-label">Поиск</label>
            <input type="text" name="search" id="librarySearchInput" class="curator-form-control" placeholder="ФИО или название книги..." value="<?php echo htmlspecialchars($filters['search']); ?>">
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
                    <th>Забронировано</th>
                    <th>Действует до</th>
                    <th>Статус</th>
                    <th>Действия</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Броней нет</td></tr>
                <?php else: ?>
                    <?php foreach ($reservations as $res): ?>
                        <?php $fio = Library::formatStudentFio($res); ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($fio); ?></strong>
                                <?php if ($res['group_name']): ?><br><span class="curator-badge badge-primary"><?php echo htmlspecialchars($res['group_name']); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($res['title']); ?>
                                <br><small class="text-muted">доступно: <?php echo (int)$res['copies_available']; ?></small>
                            </td>
                            <td><?php echo date('d.m.Y H:i', strtotime($res['reserved_at'])); ?></td>
                            <td><?php echo formatDate($res['expires_at']); ?></td>
                            <td>
                                <?php
                                $badge_map = [
                                    'pending' => 'badge-warning',
                                    'fulfilled' => 'badge-success',
                                    'cancelled' => 'badge-info',
                                    'expired' => 'badge-danger',
                                ];
                                $labels = [
                                    'pending' => 'Ожидает',
                                    'fulfilled' => 'Выдано',
                                    'cancelled' => 'Отменена',
                                    'expired' => 'Истекла',
                                ];
                                ?>
                                <span class="curator-badge <?php echo $badge_map[$res['status']] ?? 'badge-info'; ?>">
                                    <?php echo $labels[$res['status']] ?? $res['status']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($res['status'] === 'pending' && $can_reserve): ?>
                                    <div class="curator-table-actions">
                                        <form method="post" class="d-inline" onsubmit="return confirm('Отменить бронь?');">
                                            <input type="hidden" name="action" value="cancel">
                                            <input type="hidden" name="reservation_id" value="<?php echo (int)$res['id']; ?>">
                                            <button type="submit" class="btn btn-outline-danger" title="Отменить"><i class="bi bi-x-lg"></i></button>
                                        </form>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($can_reserve): ?>
<div class="modal fade" id="reserveModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="reserve">
            <input type="hidden" name="student_id" id="reserve_student_id">
            <div class="modal-header">
                <h5 class="modal-title">Забронировать книгу</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3 library-student-search-wrap">
                    <label class="curator-form-label">Студент (ФИО) *</label>
                    <input type="text" id="reserve_student_search" class="curator-form-control" placeholder="Начните вводить фамилию, имя..." autocomplete="off">
                    <div id="reserve_student_results" class="list-group library-student-search-results d-none"></div>
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Книга *</label>
                    <select name="book_id" class="curator-form-select" required>
                        <option value="">— выберите —</option>
                        <?php foreach ($books as $b): ?>
                            <option value="<?php echo (int)$b['id']; ?>">
                                <?php echo htmlspecialchars($b['title'] . ' — ' . $b['author']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Бронь действует до</label>
                    <input type="date" name="expires_at" class="curator-form-control" value="<?php echo date('Y-m-d', strtotime('+7 days')); ?>">
                </div>
                <div class="mb-3">
                    <label class="curator-form-label">Примечание</label>
                    <textarea name="note" class="curator-form-control" rows="2"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Забронировать</button>
            </div>
        </form>
    </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    initStudentSearch('reserve_student_search', 'reserve_student_id', 'reserve_student_results');
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
