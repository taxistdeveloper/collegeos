<?php
require_once '../config/config.php';
require_once '../classes/DiplomaSupplement.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$diploma = new DiplomaSupplement();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['supplement_id'] ?? 0);
    if ($diploma->deleteSupplement($id)) {
        $message = 'Приложение удалено';
    } else {
        $error = 'Не удалось удалить приложение';
    }
}

$search = isset($_GET['search']) ? trim((string)$_GET['search']) : '';
$items = $diploma->listSupplements($search);

$page_title = 'Приложения к диплому';
$active_page = 'diplomas';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Приложения к диплому</h1>
        <p class="page-subtitle">Ведомость дисциплин выпускника и печать бланка</p>
    </div>
    <div class="page-actions">
        <a href="diploma_templates.php" class="btn">
            <i class="bi bi-collection"></i>
            <span>Шаблоны дисциплин</span>
        </a>
        <a href="diploma_edit.php" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i>
            <span>Новое приложение</span>
        </a>
    </div>
</div>

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

<div class="card mb-3">
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <div class="col-md-6">
                <label class="form-label" for="search">Поиск</label>
                <input type="text" class="form-control" id="search" name="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="ФИО, номер, ИИН или группа">
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i> Найти</button>
            </div>
            <?php if ($search !== ''): ?>
                <div class="col-auto">
                    <a href="diplomas.php" class="btn btn-outline">Сбросить</a>
                </div>
            <?php endif; ?>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-award"></i> Выданные приложения</h2>
        <span class="badge badge-primary"><?php echo count($items); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Студент</th>
                        <th>ТКБ №</th>
                        <th>Годы</th>
                        <th>Специальность</th>
                        <th>Дисциплин</th>
                        <th style="width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['display_name']); ?></strong>
                                <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php
                                    $meta = trim(($item['group_name'] ?? '') . (($item['iin'] ?? '') !== '' ? ' · ИИН ' . $item['iin'] : ''));
                                    echo htmlspecialchars($meta !== '' ? $meta : '—');
                                    ?>
                                </div>
                            </td>
                            <td><?php echo $item['record_number'] !== '' ? htmlspecialchars($item['record_number']) : '—'; ?></td>
                            <td>
                                <?php
                                $fromYear = (string)($item['year_from'] ?? '');
                                $toYear = (string)($item['year_to'] ?? '');
                                if ($fromYear !== '' && $toYear !== '') {
                                    $years = $fromYear . ' – ' . $toYear;
                                } else {
                                    $years = $fromYear !== '' ? $fromYear : $toYear;
                                }
                                echo htmlspecialchars($years !== '' ? $years : '—');
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars(DiplomaSupplement::formatSpecialtyLine($item['specialty_code'], $item['specialty_name']) ?: '—'); ?></td>
                            <td><span class="badge badge-secondary"><?php echo (int)$item['subjects_count']; ?></span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a class="btn btn-icon btn-sm btn-outline" title="Печать" href="diploma_print.php?id=<?php echo (int)$item['id']; ?>" target="_blank">
                                        <i class="bi bi-printer"></i>
                                    </a>
                                    <a class="btn btn-icon btn-sm btn-outline" title="Редактировать" href="diploma_edit.php?id=<?php echo (int)$item['id']; ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteSupplement(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['display_name'], ENT_QUOTES); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Приложений пока нет
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<form id="deleteForm" method="post" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="supplement_id" id="delete_supplement_id">
</form>

<script>
function deleteSupplement(id, name) {
    if (confirm('Удалить приложение для «' + name + '»?')) {
        document.getElementById('delete_supplement_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
