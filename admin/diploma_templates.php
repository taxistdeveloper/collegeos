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
    $id = (int)($_POST['template_id'] ?? 0);
    if ($diploma->deleteTemplate($id)) {
        $message = 'Шаблон удалён';
    } else {
        $error = 'Не удалось удалить шаблон';
    }
}

$items = $diploma->listTemplates();

$page_title = 'Шаблоны приложений';
$active_page = 'diplomas';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Шаблоны дисциплин</h1>
        <p class="page-subtitle">Учебный план, который подставляется в приложение к диплому</p>
    </div>
    <div class="page-actions">
        <a href="diplomas.php" class="btn">
            <i class="bi bi-arrow-left"></i>
            <span>К приложениям</span>
        </a>
        <a href="diploma_template_edit.php" class="btn btn-primary">
            <i class="bi bi-plus-lg"></i>
            <span>Новый шаблон</span>
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

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-collection"></i> Шаблоны</h2>
        <span class="badge badge-primary"><?php echo count($items); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Специальность</th>
                        <th>Квалификация</th>
                        <th>Строк</th>
                        <th style="width: 150px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($items as $item): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($item['title']); ?></strong></td>
                            <td><?php echo htmlspecialchars(DiplomaSupplement::formatSpecialtyLine($item['specialty_code'], $item['specialty_name']) ?: '—'); ?></td>
                            <td><?php echo htmlspecialchars(trim($item['qualification_code'] . ' ' . $item['qualification_name']) ?: '—'); ?></td>
                            <td><span class="badge badge-secondary"><?php echo (int)$item['rows_count']; ?></span></td>
                            <td>
                                <div class="d-flex gap-1">
                                    <a class="btn btn-icon btn-sm btn-outline" title="Создать приложение" href="diploma_edit.php?template_id=<?php echo (int)$item['id']; ?>">
                                        <i class="bi bi-file-earmark-plus"></i>
                                    </a>
                                    <a class="btn btn-icon btn-sm btn-outline" title="Редактировать" href="diploma_template_edit.php?id=<?php echo (int)$item['id']; ?>">
                                        <i class="bi bi-pencil"></i>
                                    </a>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteTemplate(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['title'], ENT_QUOTES); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Шаблонов пока нет. Создайте план специальностей один раз и подставляйте его в приложения.
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
    <input type="hidden" name="template_id" id="delete_template_id">
</form>

<script>
function deleteTemplate(id, name) {
    if (confirm('Удалить шаблон «' + name + '»? Уже созданные приложения не изменятся.')) {
        document.getElementById('delete_template_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
