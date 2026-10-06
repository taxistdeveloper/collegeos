<?php
require_once '../config/config.php';
require_once '../classes/Role.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$role = new Role();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ensure_uchebni_roles') {
    $synced = $role->ensureUchebniRoles();
    $message = 'Роли обновлены: ' . implode('; ', $synced);
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'ensure_uchebni_roles':
            break;
        case 'add':
            $data = [
                'name' => sanitize($_POST['name']),
                'description' => sanitize($_POST['description']),
                'permissions' => $_POST['permissions'] ?? []
            ];
            
            if ($role->isNameUnique($data['name'])) {
                if ($role->createRole($data)) {
                    $message = 'Роль успешно создана!';
                } else {
                    $error = 'Ошибка при создании роли';
                }
            } else {
                $error = 'Роль с таким названием уже существует';
            }
            break;
            
        case 'edit':
            $id = (int)$_POST['role_id'];
            $data = [
                'name' => sanitize($_POST['name']),
                'description' => sanitize($_POST['description']),
                'permissions' => $_POST['permissions'] ?? []
            ];
            
            if ($role->isNameUnique($data['name'], $id)) {
                if ($role->updateRole($id, $data)) {
                    $message = 'Роль успешно обновлена!';
                } else {
                    $error = 'Ошибка при обновлении роли';
                }
            } else {
                $error = 'Роль с таким названием уже существует';
            }
            break;
            
        case 'delete':
            $id = (int)$_POST['role_id'];
            if ($role->deleteRole($id)) {
                $message = 'Роль успешно удалена!';
            } else {
                $error = 'Ошибка при удалении роли. Возможно, есть пользователи с этой ролью.';
            }
            break;
    }
}

// Получение данных
$roles = $role->getAllRoles();
$all_permissions = $role->getAllPermissions();

// Для header
$page_title = 'Управление ролями';
$active_page = 'roles';
include 'includes/admin_header.php';
?>

<style>
    .permission-group {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: var(--radius);
        margin-bottom: 1rem;
        overflow: hidden;
    }
    .permission-group-header {
        background: var(--content-bg);
        padding: 0.75rem 1rem;
        border-bottom: 1px solid var(--border-color);
        font-weight: 600;
        font-size: 0.875rem;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }
    .permission-group-body {
        padding: 1rem;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 0.5rem;
    }
    .permission-item {
        font-size: 0.875rem;
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Роли</h1>
        <p class="page-subtitle">Управление ролями и правами доступа</p>
    </div>
    <div class="page-actions">
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="ensure_uchebni_roles">
            <button type="submit" class="btn btn-outline" title="Создать/обновить роли teacher и methodist">
                <i class="bi bi-arrow-repeat"></i>
                <span>Синхр. преподаватель</span>
            </button>
        </form>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoleModal">
            <i class="bi bi-plus-circle-fill"></i>
            <span>Добавить роль</span>
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

<!-- Roles Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-shield-lock"></i>
            Список ролей
        </h2>
        <span class="badge badge-primary"><?php echo count($roles); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Название</th>
                        <th>Описание</th>
                        <th>Права доступа</th>
                        <th>Создана</th>
                        <th style="width: 100px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($roles as $role_item): ?>
                        <?php 
                        $permissions = json_decode($role_item['permissions'], true) ?? [];
                        $permission_count = count($permissions);
                        ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="list-avatar" style="width: 32px; height: 32px; background: rgba(139, 92, 246, 0.1); color: var(--purple);">
                                        <i class="bi bi-shield-fill" style="font-size: 0.875rem;"></i>
                                    </div>
                                    <strong><?php echo htmlspecialchars($role_item['name']); ?></strong>
                                </div>
                            </td>
                            <td style="color: var(--text-secondary);">
                                <?php echo htmlspecialchars($role_item['description']); ?>
                            </td>
                            <td>
                                <span class="badge badge-primary">
                                    <?php echo $permission_count; ?> прав
                                </span>
                                <?php if (isset($permissions['all']) && $permissions['all']): ?>
                                    <span class="badge badge-danger ms-1">Полный доступ</span>
                                <?php endif; ?>
                            </td>
                            <td style="font-size: 0.8125rem; color: var(--text-secondary);">
                                <?php echo date('d.m.Y H:i', strtotime($role_item['created_at'])); ?>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-icon btn-sm btn-outline" title="Редактировать"
                                            onclick="editRole(<?php echo htmlspecialchars(json_encode($role_item)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteRole(<?php echo $role_item['id']; ?>, '<?php echo htmlspecialchars($role_item['name']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($roles)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Роли не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Role Modal -->
<div class="modal fade" id="addRoleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2"></i>Добавить роль</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Название роли <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Описание</label>
                            <input type="text" class="form-control" name="description">
                        </div>
                    </div>
                    
                    <h6 class="mb-3">Права доступа:</h6>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline" onclick="selectAllPermissions()">
                            <i class="bi bi-check-all"></i> Выбрать все
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllPermissions()">
                            <i class="bi bi-x-square"></i> Снять все
                        </button>
                    </div>
                    
                    <?php foreach ($all_permissions as $group_name => $permissions): ?>
                        <div class="permission-group">
                            <div class="permission-group-header">
                                <span><i class="bi bi-folder-fill me-2"></i><?php echo ucfirst($group_name); ?></span>
                                <button type="button" class="btn btn-sm btn-outline" onclick="toggleGroup('<?php echo $group_name; ?>')">
                                    <i class="bi bi-check-all"></i>
                                </button>
                            </div>
                            <div class="permission-group-body">
                                <?php foreach ($permissions as $permission_key => $permission_name): ?>
                                    <div class="permission-item">
                                        <div class="form-check">
                                            <input class="form-check-input permission-checkbox" type="checkbox" 
                                                   id="add_<?php echo $permission_key; ?>" 
                                                   name="permissions[<?php echo $permission_key; ?>]" 
                                                   value="1">
                                            <label class="form-check-label" for="add_<?php echo $permission_key; ?>">
                                                <?php echo $permission_name; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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

<!-- Edit Role Modal -->
<div class="modal fade" id="editRoleModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="role_id" id="edit_role_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать роль</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 mb-4">
                        <div class="col-md-6">
                            <label class="form-label">Название роли <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_name" name="name" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Описание</label>
                            <input type="text" class="form-control" id="edit_description" name="description">
                        </div>
                    </div>
                    
                    <h6 class="mb-3">Права доступа:</h6>
                    <div class="mb-3">
                        <button type="button" class="btn btn-sm btn-outline" onclick="selectAllEditPermissions()">
                            <i class="bi bi-check-all"></i> Выбрать все
                        </button>
                        <button type="button" class="btn btn-sm btn-outline" onclick="deselectAllEditPermissions()">
                            <i class="bi bi-x-square"></i> Снять все
                        </button>
                    </div>
                    
                    <?php foreach ($all_permissions as $group_name => $permissions): ?>
                        <div class="permission-group">
                            <div class="permission-group-header">
                                <span><i class="bi bi-folder-fill me-2"></i><?php echo ucfirst($group_name); ?></span>
                                <button type="button" class="btn btn-sm btn-outline" onclick="toggleEditGroup('<?php echo $group_name; ?>')">
                                    <i class="bi bi-check-all"></i>
                                </button>
                            </div>
                            <div class="permission-group-body">
                                <?php foreach ($permissions as $permission_key => $permission_name): ?>
                                    <div class="permission-item">
                                        <div class="form-check">
                                            <input class="form-check-input edit-permission-checkbox" type="checkbox" 
                                                   id="edit_<?php echo $permission_key; ?>" 
                                                   name="permissions[<?php echo $permission_key; ?>]" 
                                                   value="1">
                                            <label class="form-check-label" for="edit_<?php echo $permission_key; ?>">
                                                <?php echo $permission_name; ?>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
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
    <input type="hidden" name="role_id" id="delete_role_id">
</form>

<script>
    function editRole(role) {
        document.getElementById('edit_role_id').value = role.id;
        document.getElementById('edit_name').value = role.name;
        document.getElementById('edit_description').value = role.description || '';
        
        document.querySelectorAll('.edit-permission-checkbox').forEach(cb => cb.checked = false);
        
        if (role.permissions) {
            const permissions = typeof role.permissions === 'string' ? JSON.parse(role.permissions) : role.permissions;
            Object.keys(permissions).forEach(permission => {
                const checkbox = document.getElementById('edit_' + permission);
                if (checkbox) {
                    checkbox.checked = permissions[permission] === true || permissions[permission] === '1';
                }
            });
        }
        
        new bootstrap.Modal(document.getElementById('editRoleModal')).show();
    }
    
    function deleteRole(roleId, roleName) {
        if (confirm('Вы уверены, что хотите удалить роль "' + roleName + '"?')) {
            document.getElementById('delete_role_id').value = roleId;
            document.getElementById('deleteForm').submit();
        }
    }
    
    function selectAllPermissions() {
        document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = true);
    }
    
    function deselectAllPermissions() {
        document.querySelectorAll('.permission-checkbox').forEach(cb => cb.checked = false);
    }
    
    function selectAllEditPermissions() {
        document.querySelectorAll('.edit-permission-checkbox').forEach(cb => cb.checked = true);
    }
    
    function deselectAllEditPermissions() {
        document.querySelectorAll('.edit-permission-checkbox').forEach(cb => cb.checked = false);
    }
    
    function toggleGroup(groupName) {
        const checkboxes = document.querySelectorAll(`input[name*="permissions["][id*="${groupName}"]`);
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        checkboxes.forEach(cb => cb.checked = !allChecked);
    }
    
    function toggleEditGroup(groupName) {
        const checkboxes = document.querySelectorAll(`.edit-permission-checkbox[id*="${groupName}"]`);
        const allChecked = Array.from(checkboxes).every(cb => cb.checked);
        checkboxes.forEach(cb => cb.checked = !allChecked);
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
