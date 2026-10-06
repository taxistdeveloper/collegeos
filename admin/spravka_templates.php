<?php
require_once '../config/config.php';
require_once '../classes/SpravkaTemplate.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$template = new SpravkaTemplate();

$message = '';
$error = '';

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $data = [
                'name' => sanitize($_POST['name']),
                'type' => sanitize($_POST['type']),
                'content' => $_POST['content'], // HTML контент, не санитизируем полностью
                'description' => !empty($_POST['description']) ? sanitize($_POST['description']) : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
                'created_by' => $_SESSION['admin_id'] ?? null,
            ];
            
            if ($template->create($data)) {
                $message = 'Шаблон успешно создан!';
            } else {
                $error = 'Ошибка при создании шаблона';
            }
            break;
            
        case 'edit':
            $id = (int)$_POST['template_id'];
            $data = [
                'name' => sanitize($_POST['name']),
                'type' => sanitize($_POST['type']),
                'content' => $_POST['content'], // HTML контент
                'description' => !empty($_POST['description']) ? sanitize($_POST['description']) : null,
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ];
            
            if ($template->update($id, $data)) {
                $message = 'Шаблон успешно обновлен!';
            } else {
                $error = 'Ошибка при обновлении шаблона';
            }
            break;
            
        case 'delete':
            $id = (int)$_POST['template_id'];
            if ($template->delete($id)) {
                $message = 'Шаблон успешно удален!';
            } else {
                $error = 'Ошибка при удалении шаблона';
            }
            break;
    }
}

// Получение данных
$filter_type = isset($_GET['type']) ? $_GET['type'] : '';
$templates = $template->getAll($filter_type ?: null);
$types = SpravkaTemplate::getTypes();

// Если редактирование
$edit_template = null;
if (isset($_GET['edit'])) {
    $edit_template = $template->getById((int)$_GET['edit']);
}

// Для header
$page_title = 'Шаблоны справок';
$active_page = 'spravka_templates';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Шаблоны справок</h1>
        <p class="page-subtitle">Управление шаблонами для выдачи справок</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTemplateModal">
            <i class="bi bi-file-earmark-plus-fill"></i>
            <span>Добавить</span>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i>
        <?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        <?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<!-- Filter Card -->
<div class="card">
    <div class="card-body" style="padding: 1rem;">
        <form method="GET" action="spravka_templates.php" class="d-flex gap-3 align-items-center" style="flex-wrap: wrap;">
            <div style="min-width: 250px;">
                <select class="form-select" name="type" style="border-color: var(--border-color);">
                    <option value="">Все типы</option>
                    <?php foreach ($types as $k => $v): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo $filter_type === $k ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($v); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>
                Найти
            </button>
            <?php if ($filter_type): ?>
                <a href="spravka_templates.php" class="btn btn-outline">
                    <i class="bi bi-x-circle"></i>
                    Сбросить
                </a>
            <?php endif; ?>
        </form>
    </div>
</div>

<!-- Templates Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-file-earmark-code"></i>
            Список шаблонов
        </h2>
        <span class="badge badge-primary"><?php echo count($templates); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Тип</th>
                        <th>Описание</th>
                        <th>Статус</th>
                        <th>Создан</th>
                        <th style="width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($templates as $row): ?>
                        <tr>
                            <td>
                                <div style="font-weight: 500;">
                                    <?php echo htmlspecialchars($row['name']); ?>
                                </div>
                            </td>
                            <td>
                                <span class="badge badge-secondary">
                                    <?php echo htmlspecialchars($types[$row['type']] ?? $row['type']); ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php echo htmlspecialchars(mb_substr($row['description'] ?? '', 0, 60)); ?><?php echo mb_strlen($row['description'] ?? '') > 60 ? '…' : ''; ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?php echo $row['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $row['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php echo $row['created_at'] ? date('d.m.Y', strtotime($row['created_at'])) : '—'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-icon btn-sm btn-outline" 
                                            title="Редактировать" 
                                            onclick="editTemplate(<?php echo htmlspecialchars(json_encode($row)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" 
                                            title="Удалить" 
                                            style="color: var(--danger);"
                                            onclick="deleteTemplate(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars(addslashes($row['name'])); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($templates)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Шаблоны не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Template Modal -->
<div class="modal fade" id="addTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-file-earmark-plus-fill me-2"></i>Добавить шаблон</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" required>
                                <option value="">Выберите тип</option>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>">
                                        <?php echo htmlspecialchars($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" rows="2" 
                                      placeholder="Краткое описание шаблона"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Содержимое шаблона (HTML) <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="content" id="add_content" rows="15" required 
                                      style="font-family: 'Courier New', monospace; font-size: 0.875rem;"
                                      placeholder="HTML код шаблона. Используйте плейсхолдеры: {FIO}, {LAST_NAME}, {FIRST_NAME}, {MIDDLE_NAME}, {IIN}, {BIRTH_DATE}, {GROUP_NAME}, {SPECIALTY}, {ISSUED_AT}, и т.д."></textarea>
                            <small class="text-muted">
                                Доступные плейсхолдеры: {FIO}, {LAST_NAME}, {FIRST_NAME}, {MIDDLE_NAME}, {IIN}, {BIRTH_DATE}, {GENDER}, {GROUP_NAME}, {SPECIALTY}, {QUALIFICATION}, {STUDY_FORM}, {LANGUAGE}, {ISSUED_AT}, {NOTE}
                            </small>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="add_is_active" name="is_active" checked>
                                <label class="form-check-label" for="add_is_active">
                                    Активный шаблон
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Добавить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Template Modal -->
<div class="modal fade" id="editTemplateModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="template_id" id="edit_template_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать шаблон</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" id="edit_name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип <span class="text-danger">*</span></label>
                            <select class="form-select" name="type" id="edit_type" required>
                                <?php foreach ($types as $k => $v): ?>
                                    <option value="<?php echo htmlspecialchars($k); ?>">
                                        <?php echo htmlspecialchars($v); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Описание</label>
                            <textarea class="form-control" name="description" id="edit_description" rows="2" 
                                      placeholder="Краткое описание шаблона"></textarea>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Содержимое шаблона (HTML) <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="content" id="edit_content" rows="15" required 
                                      style="font-family: 'Courier New', monospace; font-size: 0.875rem;"
                                      placeholder="HTML код шаблона. Используйте плейсхолдеры: {FIO}, {LAST_NAME}, {FIRST_NAME}, {MIDDLE_NAME}, {IIN}, {BIRTH_DATE}, {GROUP_NAME}, {SPECIALTY}, {ISSUED_AT}, и т.д."></textarea>
                            <small class="text-muted">
                                Доступные плейсхолдеры: {FIO}, {LAST_NAME}, {FIRST_NAME}, {MIDDLE_NAME}, {IIN}, {BIRTH_DATE}, {GENDER}, {GROUP_NAME}, {SPECIALTY}, {QUALIFICATION}, {STUDY_FORM}, {LANGUAGE}, {ISSUED_AT}, {NOTE}
                            </small>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                <label class="form-check-label" for="edit_is_active">
                                    Активный шаблон
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="template_id" id="delete_template_id">
</form>

<script>
    function editTemplate(template) {
        document.getElementById('edit_template_id').value = template.id;
        document.getElementById('edit_name').value = template.name || '';
        document.getElementById('edit_type').value = template.type || '';
        document.getElementById('edit_description').value = template.description || '';
        document.getElementById('edit_content').value = template.content || '';
        document.getElementById('edit_is_active').checked = template.is_active == 1;
        
        new bootstrap.Modal(document.getElementById('editTemplateModal')).show();
    }
    
    function deleteTemplate(templateId, name) {
        if (confirm('Вы уверены, что хотите удалить шаблон "' + name + '"?')) {
            document.getElementById('delete_template_id').value = templateId;
            document.getElementById('deleteForm').submit();
        }
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
