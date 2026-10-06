<?php
require_once '../config/config.php';
require_once '../classes/Department.php';
require_once '../classes/User.php';
require_once '../includes/auth.php';

checkRole(['department_head']);

$departmentService = new Department();
$userService = new User();
$current_user = getCurrentUser();
$dept = $departmentService->getDepartmentByHead((int)$current_user['id']);
$message = '';
$error = '';

$roles = $userService->getAllRoles();
$staffRoleMap = [];
foreach ($roles as $role) {
    if (in_array($role['name'], ['teacher', 'curator'], true)) {
        $staffRoleMap[$role['name']] = (int)$role['id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dept) {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_existing') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $position = sanitize($_POST['position'] ?? '');
        if ($userId <= 0) {
            $error = 'Выберите сотрудника';
        } elseif ($departmentService->addMember((int)$dept['id'], $userId, $position ?: null)) {
            $message = 'Сотрудник добавлен в отделение';
        } else {
            $error = 'Не удалось добавить сотрудника';
        }
    }

    if ($action === 'create_staff') {
        $fioParts = $userService->parseFio(sanitize($_POST['fio'] ?? ''));
        $login = sanitize($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';
        $position = sanitize($_POST['position'] ?? '');
        $roleIds = [];
        foreach ((array)($_POST['role_ids'] ?? []) as $rid) {
            $rid = (int)$rid;
            if (in_array($rid, $staffRoleMap, true)) {
                $roleIds[] = $rid;
            }
        }
        if ($fioParts['last_name'] === '' || $fioParts['first_name'] === '') {
            $error = 'Укажите ФИО (минимум фамилию и имя)';
        } elseif ($login === '' || $password === '') {
            $error = 'Укажите логин и пароль';
        } elseif (empty($roleIds)) {
            $error = 'Выберите роль: преподаватель и/или куратор';
        } elseif (!$userService->isLoginUnique($login)) {
            $error = 'Логин уже занят';
        } else {
            $created = $userService->createUser([
                'first_name' => $fioParts['first_name'],
                'last_name' => $fioParts['last_name'],
                'middle_name' => $fioParts['middle_name'],
                'login' => $login,
                'password' => $password,
                'email' => null,
                'phone' => null,
                'role_ids' => $roleIds,
            ]);
            if ($created) {
                $newUser = $userService->getUserByLogin($login);
                if ($newUser) {
                    $departmentService->addMember((int)$dept['id'], (int)$newUser['id'], $position ?: null);
                }
                $message = 'Сотрудник создан и добавлен в отделение';
            } else {
                $error = 'Ошибка создания пользователя';
            }
        }
    }

    if ($action === 'remove') {
        $userId = (int)($_POST['user_id'] ?? 0);
        if ($departmentService->removeMember((int)$dept['id'], $userId)) {
            $message = 'Сотрудник удалён из отделения';
        } else {
            $error = 'Нельзя удалить заведующего или произошла ошибка';
        }
    }
}

$members = $dept ? $departmentService->getMembers((int)$dept['id']) : [];
$memberIds = array_map(static function ($m) {
    return (int)$m['id'];
}, $members);

$candidates = [];
if ($dept) {
    $allUsers = $userService->getAllUsers();
    foreach ($allUsers as $u) {
        $uid = (int)$u['id'];
        if ($uid === (int)$current_user['id']) {
            continue;
        }
        if (in_array($uid, $memberIds, true)) {
            continue;
        }
        $names = $u['role_names'] ?? [];
        if (empty($names) && !empty($u['role_name'])) {
            $names = [$u['role_name']];
        }
        if (array_intersect($names, ['admin', 'director', 'department_head'])) {
            continue;
        }
        $candidates[] = $u;
    }
}

$page_title = 'Сотрудники отделения';
$page_subtitle = $dept ? $dept['name'] : 'Сначала создайте отделение';
include 'includes/header.php';
?>

<?php if (!$dept): ?>
    <div class="card">
        <div class="card-body dept-empty">
            <p class="mb-3">Сначала создайте отделение.</p>
            <a href="department.php" class="btn btn-primary">Создать отделение</a>
        </div>
    </div>
    <?php include 'includes/footer.php'; exit; ?>
<?php endif; ?>

<?php if ($message): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
<?php endif; ?>
<?php if ($error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="d-flex gap-2 flex-wrap mb-3">
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExistingModal">
        <i class="bi bi-person-check me-1"></i>Добавить существующего
    </button>
    <button class="btn btn-outline-primary" data-bs-toggle="modal" data-bs-target="#createStaffModal">
        <i class="bi bi-person-plus me-1"></i>Создать сотрудника
    </button>
</div>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>ФИО</th>
                        <th>Логин</th>
                        <th>Должность</th>
                        <th>Роль</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(trim($m['last_name'] . ' ' . $m['first_name'] . ' ' . $m['middle_name'])); ?></td>
                            <td><code><?php echo htmlspecialchars($m['login']); ?></code></td>
                            <td><?php echo htmlspecialchars($m['position'] ?: '—'); ?></td>
                            <td>
                                <?php
                                $label = $m['role_description'] ?: ($m['role_name'] ?? '—');
                                $parts = preg_split('/\s*[—\-]\s*/u', (string)$label, 2);
                                echo htmlspecialchars(trim($parts[0]));
                                ?>
                            </td>
                            <td class="text-end">
                                <?php if ((int)$m['id'] !== (int)$dept['head_user_id']): ?>
                                    <form method="POST" class="d-inline" onsubmit="return confirm('Убрать сотрудника из отделения?');">
                                        <input type="hidden" name="action" value="remove">
                                        <input type="hidden" name="user_id" value="<?php echo (int)$m['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-outline-danger">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <span class="badge text-bg-primary">Заведующий</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="5" class="dept-empty">Сотрудников пока нет</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="modal fade" id="addExistingModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_existing">
                <div class="modal-header">
                    <h5 class="modal-title">Добавить сотрудника</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Пользователь</label>
                        <select class="form-select" name="user_id" required>
                            <option value="">Выберите…</option>
                            <?php foreach ($candidates as $c): ?>
                                <option value="<?php echo (int)$c['id']; ?>">
                                    <?php
                                    echo htmlspecialchars(trim($c['last_name'] . ' ' . $c['first_name'] . ' ' . $c['middle_name']) . ' (' . $c['login'] . ')');
                                    ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Должность в отделении</label>
                        <input type="text" class="form-control" name="position" placeholder="Преподаватель, куратор…">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Добавить</button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="modal fade" id="createStaffModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_staff">
                <div class="modal-header">
                    <h5 class="modal-title">Создать сотрудника</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">ФИО <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="fio" required placeholder="Фамилия Имя Отчество">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Логин <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="login" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Пароль <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="password" required autocomplete="new-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Роли <span class="text-danger">*</span></label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach ($staffRoleMap as $name => $rid): ?>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="role_ids[]" value="<?php echo $rid; ?>" id="staff_role_<?php echo $rid; ?>"
                                        <?php echo $name === 'teacher' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="staff_role_<?php echo $rid; ?>">
                                        <?php echo $name === 'teacher' ? 'Преподаватель' : 'Куратор'; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="mb-0">
                        <label class="form-label">Должность в отделении</label>
                        <input type="text" class="form-control" name="position" placeholder="Преподаватель">
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">Создать</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
