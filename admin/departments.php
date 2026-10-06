<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../classes/User.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$department = new Department();
$user = new User();
$message = '';
$error = '';

$roles = $user->getAllRoles();
$head_role_id = null;
foreach ($roles as $role) {
    if ($role['name'] === 'department_head') {
        $head_role_id = (int)$role['id'];
        break;
    }
}
$heads = $head_role_id ? $user->getUsersByRole($head_role_id) : [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'add':
            $headId = (int)($_POST['head_user_id'] ?? 0);
            if ($headId <= 0) {
                $error = 'Выберите заведующего';
                break;
            }
            if ($department->getDepartmentByHead($headId)) {
                $error = 'У этого заведующего уже есть отделение';
                break;
            }
            $id = $department->createDepartment([
                'name' => sanitize($_POST['name'] ?? ''),
                'code' => sanitize($_POST['code'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'head_user_id' => $headId,
            ]);
            if ($id) {
                $message = 'Отделение создано';
            } else {
                $error = 'Не удалось создать отделение';
            }
            break;

        case 'edit':
            $id = (int)($_POST['department_id'] ?? 0);
            $ok = $department->updateDepartment($id, [
                'name' => sanitize($_POST['name'] ?? ''),
                'code' => sanitize($_POST['code'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'is_active' => isset($_POST['is_active']) ? 1 : 0,
            ]);
            $newHead = (int)($_POST['head_user_id'] ?? 0);
            if ($ok && $newHead > 0) {
                if (!$department->assignHead($id, $newHead)) {
                    $error = 'Отделение обновлено, но заведующего сменить не удалось (возможно, у него уже есть другое отделение)';
                    break;
                }
            }
            $message = $ok ? 'Отделение обновлено' : 'Ошибка обновления';
            if (!$ok) {
                $error = 'Ошибка обновления отделения';
                $message = '';
            }
            break;

        case 'delete':
            $id = (int)($_POST['department_id'] ?? 0);
            if ($department->deleteDepartment($id)) {
                $message = 'Отделение удалено';
            } else {
                $error = 'Не удалось удалить отделение';
            }
            break;
    }
}

$departments = $department->getAllDepartments();

$page_title = 'Отделения';
$active_page = 'departments';
include 'includes/admin_header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title">Отделения</h1>
        <p class="page-subtitle">Привязка отделений к заведующим</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addDepartmentModal" <?php echo empty($heads) ? 'disabled' : ''; ?>>
            <i class="bi bi-plus-circle-fill"></i>
            <span>Добавить отделение</span>
        </button>
    </div>
</div>

<?php if (empty($heads)): ?>
    <div class="alert alert-warning" style="background: rgba(245, 158, 11, 0.1); color: var(--warning); border: 1px solid rgba(245, 158, 11, 0.25); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i>
        Нет пользователей с ролью «Заведующий отделением».
        Сначала назначьте роль в <a href="users.php">Пользователи</a>.
    </div>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo $message; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo $error; ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h2 class="card-title"><i class="bi bi-building"></i> Список отделений</h2>
        <span class="badge badge-primary"><?php echo count($departments); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Отделение</th>
                        <th>Код</th>
                        <th>Заведующий</th>
                        <th>Сотрудники</th>
                        <th>Группы</th>
                        <th>Статус</th>
                        <th style="width: 100px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $item): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($item['name']); ?></strong>
                                <?php if (!empty($item['description'])): ?>
                                    <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($item['description']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($item['code'])): ?>
                                    <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                        <?php echo htmlspecialchars($item['code']); ?>
                                    </code>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $headName = trim(($item['head_last_name'] ?? '') . ' ' . ($item['head_first_name'] ?? '') . ' ' . ($item['head_middle_name'] ?? ''));
                                echo htmlspecialchars($headName !== '' ? $headName : '—');
                                ?>
                                <?php if (!empty($item['head_login'])): ?>
                                    <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                        <?php echo htmlspecialchars($item['head_login']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge badge-secondary"><?php echo (int)$item['members_count']; ?></span></td>
                            <td><span class="badge badge-secondary"><?php echo (int)$item['groups_count']; ?></span></td>
                            <td>
                                <span class="badge <?php echo $item['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $item['is_active'] ? 'Активно' : 'Неактивно'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-icon btn-sm btn-outline" title="Редактировать"
                                            onclick='editDepartment(<?php echo htmlspecialchars(json_encode($item, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>)'>
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteDepartment(<?php echo (int)$item['id']; ?>, '<?php echo htmlspecialchars($item['name'], ENT_QUOTES); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($departments)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Отделения ещё не созданы
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-plus-circle-fill me-2"></i>Новое отделение</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" required placeholder="Например: ИТ-отделение">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Код</label>
                        <input type="text" class="form-control" name="code" placeholder="IT">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Заведующий <span class="text-danger">*</span></label>
                        <select class="form-select" name="head_user_id" required>
                            <option value="">Выберите…</option>
                            <?php foreach ($heads as $h): ?>
                                <option value="<?php echo (int)$h['id']; ?>">
                                    <?php echo htmlspecialchars(trim($h['last_name'] . ' ' . $h['first_name'] . ' ' . $h['middle_name']) . ' (' . $h['login'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" name="description" rows="2"></textarea>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Создать</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="editDepartmentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="department_id" id="edit_department_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать отделение</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Название <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="edit_name" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Код</label>
                        <input type="text" class="form-control" name="code" id="edit_code">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Заведующий <span class="text-danger">*</span></label>
                        <select class="form-select" name="head_user_id" id="edit_head_user_id" required>
                            <?php foreach ($heads as $h): ?>
                                <option value="<?php echo (int)$h['id']; ?>">
                                    <?php echo htmlspecialchars(trim($h['last_name'] . ' ' . $h['first_name'] . ' ' . $h['middle_name']) . ' (' . $h['login'] . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Описание</label>
                        <textarea class="form-control" name="description" id="edit_description" rows="2"></textarea>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" id="edit_is_active">
                        <label class="form-check-label" for="edit_is_active">Активно</label>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> Сохранить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="department_id" id="delete_department_id">
</form>

<script>
function editDepartment(item) {
    document.getElementById('edit_department_id').value = item.id;
    document.getElementById('edit_name').value = item.name || '';
    document.getElementById('edit_code').value = item.code || '';
    document.getElementById('edit_description').value = item.description || '';
    document.getElementById('edit_is_active').checked = parseInt(item.is_active, 10) === 1;
    const headSelect = document.getElementById('edit_head_user_id');
    if (headSelect) {
        headSelect.value = String(item.head_user_id || '');
    }
    new bootstrap.Modal(document.getElementById('editDepartmentModal')).show();
}

function deleteDepartment(id, name) {
    if (confirm('Удалить отделение «' + name + '»? Группы останутся, но без привязки к отделению.')) {
        document.getElementById('delete_department_id').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
