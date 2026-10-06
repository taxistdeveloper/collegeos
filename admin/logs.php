<?php
require_once '../config/config.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

AdminLog::ensureTable();

$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear') {
    AdminLog::clear();
    $message = 'Журнал очищен';
}

$level_filter = isset($_GET['level']) ? trim((string)$_GET['level']) : '';
if (!in_array($level_filter, ['', 'info', 'warning', 'error'], true)) {
    $level_filter = '';
}
$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';

$per_page = 30;
$total = AdminLog::count($level_filter, $search);
$total_pages = max(1, (int)ceil($total / $per_page));
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) {
    $current_page = 1;
}
if ($current_page > $total_pages) {
    $current_page = $total_pages;
}
$offset = ($current_page - 1) * $per_page;
$logs = AdminLog::getList($level_filter, $search, $per_page, $offset);

$filter_query = [];
if ($level_filter !== '') {
    $filter_query['level'] = $level_filter;
}
if ($search !== '') {
    $filter_query['search'] = $search;
}
$logsPageUrl = function ($page) use ($filter_query) {
    $query = $filter_query;
    if ((int)$page > 1) {
        $query['page'] = (int)$page;
    }
    $qs = http_build_query($query);
    return 'logs.php' . ($qs !== '' ? '?' . $qs : '');
};

$level_labels = [
    'info' => 'Действие',
    'warning' => 'Предупреждение',
    'error' => 'Ошибка',
];

$page_title = 'Журнал';
$active_page = 'logs';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Журнал</h1>
        <p class="page-subtitle">Ошибки и действия в админке</p>
    </div>
    <div class="page-actions">
        <form method="POST" onsubmit="return confirm('Очистить весь журнал?');">
            <input type="hidden" name="action" value="clear">
            <button type="submit" class="btn btn-outline" style="color: var(--danger);">
                <i class="bi bi-trash"></i>
                <span>Очистить</span>
            </button>
        </form>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo htmlspecialchars($message); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-funnel"></i>
            Фильтры
        </h2>
    </div>
    <div class="card-body">
        <form method="GET" action="logs.php">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Тип</label>
                    <select class="form-select" name="level">
                        <option value="">Все записи</option>
                        <option value="error" <?php echo $level_filter === 'error' ? 'selected' : ''; ?>>Ошибки</option>
                        <option value="warning" <?php echo $level_filter === 'warning' ? 'selected' : ''; ?>>Предупреждения</option>
                        <option value="info" <?php echo $level_filter === 'info' ? 'selected' : ''; ?>>Действия</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Поиск</label>
                    <input type="text" class="form-control" name="search" placeholder="Сообщение, действие, детали" value="<?php echo htmlspecialchars($search); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label">&nbsp;</label>
                    <div>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-search"></i> Найти
                        </button>
                        <?php if ($level_filter || $search): ?>
                            <a href="logs.php" class="btn btn-outline">Сбросить</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-journal-text"></i>
            Записи
        </h2>
        <span class="badge badge-primary"><?php echo (int)$total; ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Время</th>
                        <th>Тип</th>
                        <th>Действие</th>
                        <th>Сообщение</th>
                        <th>Кто</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <?php
                        $level = $log['level'] ?? 'info';
                        $badgeClass = $level === 'error' ? 'badge-danger' : ($level === 'warning' ? 'badge-warning' : 'badge-success');
                        ?>
                        <tr>
                            <td style="white-space: nowrap; color: var(--text-secondary); font-size: 0.8125rem;">
                                <?php echo htmlspecialchars(date('d.m.Y H:i:s', strtotime($log['created_at']))); ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $badgeClass; ?>">
                                    <?php echo htmlspecialchars($level_labels[$level] ?? $level); ?>
                                </span>
                            </td>
                            <td>
                                <code style="background: var(--content-bg); padding: 0.125rem 0.375rem; border-radius: 4px; font-size: 0.75rem;">
                                    <?php echo htmlspecialchars($log['action']); ?>
                                </code>
                            </td>
                            <td>
                                <div style="font-weight: 500;"><?php echo htmlspecialchars($log['message']); ?></div>
                                <?php if (!empty($log['details'])): ?>
                                    <details style="margin-top: 0.35rem;">
                                        <summary style="cursor: pointer; color: var(--text-secondary); font-size: 0.8125rem;">Подробности</summary>
                                        <pre style="margin: 0.5rem 0 0; white-space: pre-wrap; font-size: 0.75rem; background: var(--gray-50); padding: 0.75rem; border-radius: 8px; max-height: 240px; overflow: auto;"><?php echo htmlspecialchars($log['details']); ?></pre>
                                    </details>
                                <?php endif; ?>
                                <?php if (!empty($log['url'])): ?>
                                    <div style="margin-top: 0.25rem; font-size: 0.75rem; color: var(--text-muted);">
                                        <?php echo htmlspecialchars($log['url']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--text-secondary);">
                                <?php echo htmlspecialchars($log['admin_name'] ?: '—'); ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Записей пока нет
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($total > $per_page): ?>
        <div class="card-footer d-flex justify-content-between align-items-center flex-wrap gap-2">
            <span style="font-size: 0.875rem; color: var(--text-secondary);">
                Страница <?php echo $current_page; ?> из <?php echo $total_pages; ?>
            </span>
            <div class="d-flex gap-1">
                <?php if ($current_page > 1): ?>
                    <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($logsPageUrl($current_page - 1)); ?>">Назад</a>
                <?php endif; ?>
                <?php if ($current_page < $total_pages): ?>
                    <a class="btn btn-sm btn-outline" href="<?php echo htmlspecialchars($logsPageUrl($current_page + 1)); ?>">Вперёд</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php include 'includes/admin_footer.php'; ?>
