<?php
require_once '../config/config.php';
require_once '../includes/auth.php';
require_once '../classes/DynamicField.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$dynamicField = new DynamicField();
$message = '';
$error = '';

// Обработка добавления нового поля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add') {
    $field_name = trim($_POST['field_name']);
    $field_label = trim($_POST['field_label']);
    $field_type = $_POST['field_type'];
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $field_order = (int)$_POST['field_order'];
    
    $field_options = null;
    if ($field_type === 'select' && !empty($_POST['field_options'])) {
        $options = array_filter(array_map('trim', explode("\n", $_POST['field_options'])));
        $field_options = $options;
    }
    
    if (empty($field_name) || empty($field_label)) {
        $error = 'Название поля и метка обязательны';
    } elseif (!$dynamicField->isFieldNameUnique($field_name)) {
        $error = 'Поле с таким названием уже существует';
    } else {
        $data = [
            'field_name' => $field_name,
            'field_label' => $field_label,
            'field_type' => $field_type,
            'field_options' => $field_options,
            'is_required' => $is_required,
            'is_active' => $is_active,
            'field_order' => $field_order
        ];
        
        if ($dynamicField->createField($data)) {
            $message = 'Поле успешно добавлено';
        } else {
            $error = 'Ошибка при добавлении поля';
        }
    }
}

// Обработка редактирования поля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit') {
    $id = (int)$_POST['field_id'];
    $field_name = trim($_POST['field_name']);
    $field_label = trim($_POST['field_label']);
    $field_type = $_POST['field_type'];
    $is_required = isset($_POST['is_required']) ? 1 : 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $field_order = (int)$_POST['field_order'];
    
    $field_options = null;
    if ($field_type === 'select' && !empty($_POST['field_options'])) {
        $options = array_filter(array_map('trim', explode("\n", $_POST['field_options'])));
        $field_options = $options;
    }
    
    if (empty($field_name) || empty($field_label)) {
        $error = 'Название поля и метка обязательны';
    } elseif (!$dynamicField->isFieldNameUnique($field_name, $id)) {
        $error = 'Поле с таким названием уже существует';
    } else {
        $data = [
            'field_name' => $field_name,
            'field_label' => $field_label,
            'field_type' => $field_type,
            'field_options' => $field_options,
            'is_required' => $is_required,
            'is_active' => $is_active,
            'field_order' => $field_order
        ];
        
        if ($dynamicField->updateField($id, $data)) {
            $message = 'Поле успешно обновлено';
        } else {
            $error = 'Ошибка при обновлении поля';
        }
    }
}

// Обработка удаления поля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $id = (int)$_POST['field_id'];
    
    if ($dynamicField->deleteField($id)) {
        $message = 'Поле успешно удалено';
    } else {
        $error = 'Ошибка при удалении поля';
    }
}

// Получение всех полей формы
$fields = $dynamicField->getAllFormFields();

// Получение поля для редактирования
$edit_field = null;
if (isset($_GET['edit'])) {
    $edit_field = $dynamicField->getFieldById((int)$_GET['edit']);
}

// Для header
$page_title = 'Поля формы';
$active_page = 'fields';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Поля формы</h1>
        <p class="page-subtitle">Настройка полей для формы добавления студента</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addFieldModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Добавить поле</span>
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

<!-- Info Alert -->
<div style="background: rgba(59, 130, 246, 0.1); color: var(--primary); padding: 1rem; border-radius: var(--radius); margin-bottom: 1.5rem; display: flex; align-items: flex-start; gap: 0.75rem;">
    <i class="bi bi-info-circle" style="font-size: 1.25rem;"></i>
    <div>
        <strong>Информация:</strong> 
        <span class="badge badge-primary ms-2">Стандартные</span> поля нельзя редактировать, 
        <span class="badge badge-success ms-2">Динамические</span> поля можно добавлять, редактировать и удалять.
    </div>
</div>

<!-- Fields Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-sliders"></i>
            Список полей
        </h2>
        <span class="badge badge-primary"><?php echo count($fields); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">Порядок</th>
                        <th>Название</th>
                        <th>Метка</th>
                        <th>Тип</th>
                        <th style="width: 100px;">Категория</th>
                        <th style="width: 80px;">Обяз.</th>
                        <th style="width: 80px;">Активно</th>
                        <th style="width: 100px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fields as $field): ?>
                        <tr>
                            <td style="text-align: center;">
                                <span class="badge badge-secondary"><?php echo $field['field_order']; ?></span>
                            </td>
                            <td>
                                <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                    <?php echo htmlspecialchars($field['field_name']); ?>
                                </code>
                            </td>
                            <td style="font-weight: 500;">
                                <?php echo htmlspecialchars($field['field_label']); ?>
                            </td>
                            <td>
                                <span class="badge badge-secondary">
                                    <?php echo htmlspecialchars($field['field_type']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($field['is_standard']): ?>
                                    <span class="badge badge-primary">Стандартное</span>
                                <?php else: ?>
                                    <span class="badge badge-success">Динамическое</span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($field['is_required']): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: center;">
                                <?php if ($field['is_active']): ?>
                                    <i class="bi bi-check-circle-fill text-success"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle text-muted"></i>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($field['is_standard']): ?>
                                    <span style="color: var(--text-muted); font-size: 0.8125rem;">—</span>
                                <?php else: ?>
                                    <div class="d-flex gap-1">
                                        <a href="?edit=<?php echo $field['id']; ?>" class="btn btn-icon btn-sm btn-outline" title="Редактировать">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button" class="btn btn-icon btn-sm btn-outline" style="color: var(--danger);" title="Удалить"
                                                onclick="deleteField(<?php echo $field['id']; ?>, '<?php echo htmlspecialchars($field['field_label']); ?>')">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($fields)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Поля не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Field Modal -->
<div class="modal fade" id="addFieldModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2"></i>Добавить поле</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название поля <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="field_name" required pattern="[a-zA-Z0-9_]+">
                            <small class="text-muted">Только латиница, цифры и подчеркивания</small>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Метка поля <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="field_label" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип поля <span class="text-danger">*</span></label>
                            <select class="form-select" name="field_type" id="field_type" required onchange="toggleOptions(this)">
                                <option value="">Выберите тип</option>
                                <option value="text">Текст</option>
                                <option value="textarea">Многострочный текст</option>
                                <option value="select">Выпадающий список</option>
                                <option value="date">Дата</option>
                                <option value="number">Число</option>
                                <option value="email">Email</option>
                                <option value="tel">Телефон</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Порядок отображения</label>
                            <input type="number" class="form-control" name="field_order" value="0">
                        </div>
                        <div class="col-12" id="field_options_container" style="display: none;">
                            <label class="form-label">Варианты для выбора</label>
                            <textarea class="form-control" name="field_options" rows="4" placeholder="Каждый вариант на новой строке"></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_required" id="is_required">
                                <label class="form-check-label" for="is_required">Обязательное поле</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                                <label class="form-check-label" for="is_active">Активно</label>
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

<!-- Edit Field Modal (if editing) -->
<?php if ($edit_field): ?>
<div class="modal fade show" id="editFieldModal" tabindex="-1" style="display: block; background: rgba(0,0,0,0.5);">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="field_id" value="<?php echo $edit_field['id']; ?>">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать поле</h5>
                    <a href="dynamic_fields.php" class="btn-close"></a>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Название поля <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="field_name" value="<?php echo htmlspecialchars($edit_field['field_name']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Метка поля <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="field_label" value="<?php echo htmlspecialchars($edit_field['field_label']); ?>" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Тип поля <span class="text-danger">*</span></label>
                            <select class="form-select" name="field_type" id="edit_field_type" required onchange="toggleEditOptions(this)">
                                <option value="text" <?php echo $edit_field['field_type'] === 'text' ? 'selected' : ''; ?>>Текст</option>
                                <option value="textarea" <?php echo $edit_field['field_type'] === 'textarea' ? 'selected' : ''; ?>>Многострочный текст</option>
                                <option value="select" <?php echo $edit_field['field_type'] === 'select' ? 'selected' : ''; ?>>Выпадающий список</option>
                                <option value="date" <?php echo $edit_field['field_type'] === 'date' ? 'selected' : ''; ?>>Дата</option>
                                <option value="number" <?php echo $edit_field['field_type'] === 'number' ? 'selected' : ''; ?>>Число</option>
                                <option value="email" <?php echo $edit_field['field_type'] === 'email' ? 'selected' : ''; ?>>Email</option>
                                <option value="tel" <?php echo $edit_field['field_type'] === 'tel' ? 'selected' : ''; ?>>Телефон</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Порядок отображения</label>
                            <input type="number" class="form-control" name="field_order" value="<?php echo $edit_field['field_order']; ?>">
                        </div>
                        <div class="col-12" id="edit_field_options_container" style="<?php echo $edit_field['field_type'] === 'select' ? '' : 'display: none;'; ?>">
                            <label class="form-label">Варианты для выбора</label>
                            <textarea class="form-control" name="field_options" rows="4"><?php 
                                if (!empty($edit_field['field_options'])) {
                                    echo htmlspecialchars(implode("\n", $edit_field['field_options']));
                                }
                            ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_required" id="edit_is_required" <?php echo $edit_field['is_required'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_is_required">Обязательное поле</label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active" <?php echo $edit_field['is_active'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="edit_is_active">Активно</label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <a href="dynamic_fields.php" class="btn btn-outline">Отмена</a>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Сохранить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="field_id" id="delete_field_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-trash me-2"></i>Удалить поле</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Удалить поле <strong id="delete_field_name"></strong>?</p>
                    <p class="text-danger" style="font-size: 0.875rem;">Это действие нельзя отменить.</p>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash"></i> Удалить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function toggleOptions(select) {
        const container = document.getElementById('field_options_container');
        container.style.display = select.value === 'select' ? 'block' : 'none';
    }
    
    function toggleEditOptions(select) {
        const container = document.getElementById('edit_field_options_container');
        container.style.display = select.value === 'select' ? 'block' : 'none';
    }
    
    function deleteField(fieldId, fieldName) {
        document.getElementById('delete_field_id').value = fieldId;
        document.getElementById('delete_field_name').textContent = fieldName;
        new bootstrap.Modal(document.getElementById('deleteModal')).show();
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
