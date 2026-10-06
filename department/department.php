<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../includes/auth.php';

checkRole(['department_head']);

$departmentService = new Department();
$current_user = getCurrentUser();
$dept = $departmentService->getDepartmentByHead((int)$current_user['id']);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        if ($dept) {
            $error = 'У вас уже есть отделение';
        } else {
            $id = $departmentService->createDepartment([
                'name' => sanitize($_POST['name'] ?? ''),
                'code' => sanitize($_POST['code'] ?? ''),
                'description' => sanitize($_POST['description'] ?? ''),
                'head_user_id' => (int)$current_user['id'],
            ]);
            if ($id) {
                $message = 'Отделение создано';
                $dept = $departmentService->getDepartmentById($id);
            } else {
                $error = 'Не удалось создать отделение. Проверьте название.';
            }
        }
    }

    if ($action === 'update' && $dept) {
        $ok = $departmentService->updateDepartment((int)$dept['id'], [
            'name' => sanitize($_POST['name'] ?? ''),
            'code' => sanitize($_POST['code'] ?? ''),
            'description' => sanitize($_POST['description'] ?? ''),
            'is_active' => 1,
        ]);
        if ($ok) {
            $message = 'Данные отделения сохранены';
            $dept = $departmentService->getDepartmentById((int)$dept['id']);
        } else {
            $error = 'Ошибка сохранения';
        }
    }
}

$page_title = $dept ? 'Моё отделение' : 'Создать отделение';
$page_subtitle = $dept ? $dept['name'] : 'Заполните данные отделения';
include 'includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" class="row g-3">
            <input type="hidden" name="action" value="<?php echo $dept ? 'update' : 'create'; ?>">
            <div class="col-md-8">
                <label class="form-label">Название отделения <span class="text-danger">*</span></label>
                <input type="text" class="form-control" name="name" required
                       value="<?php echo htmlspecialchars($dept['name'] ?? ''); ?>"
                       placeholder="Например: Отделение информационных технологий">
            </div>
            <div class="col-md-4">
                <label class="form-label">Код</label>
                <input type="text" class="form-control" name="code"
                       value="<?php echo htmlspecialchars($dept['code'] ?? ''); ?>"
                       placeholder="IT">
            </div>
            <div class="col-12">
                <label class="form-label">Описание</label>
                <textarea class="form-control" name="description" rows="3"
                          placeholder="Кратко о направлении отделения"><?php echo htmlspecialchars($dept['description'] ?? ''); ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>
                    <?php echo $dept ? 'Сохранить' : 'Создать отделение'; ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
