<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/ActivityLog.php';
require_once '../classes/Department.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$activityLog = new ActivityLog();
$departmentService = new Department();
$message = '';
$error = '';

$roles = $user->getAllRoles();
$curator_role_id = null;
$department_head_role_id = null;
$role_labels = [];
foreach ($roles as $role) {
    $desc = trim((string)($role['description'] ?? ''));
    if ($desc !== '') {
        $parts = preg_split('/\s*[—\-]\s*/u', $desc, 2);
        $role_labels[(int)$role['id']] = trim($parts[0]);
    } else {
        $role_labels[(int)$role['id']] = $role['name'];
    }
    if (stripos($role['name'], 'куратор') !== false || stripos($role['name'], 'curator') !== false || stripos($role['description'], 'куратор') !== false) {
        $curator_role_id = $role['id'];
    }
    if ($role['name'] === 'department_head') {
        $department_head_role_id = (int)$role['id'];
    }
}

$user_departments = [];
foreach ($departmentService->getAllDepartments() as $d) {
    $user_departments[(int)$d['head_user_id']] = $d['name'];
}

// Получение логов для конкретного куратора (если указан)
$curator_logs = [];
$curator_stats = [];
$selected_curator_id = isset($_GET['view_logs']) ? (int)$_GET['view_logs'] : null;
if ($selected_curator_id) {
    $curator_logs = $activityLog->getLogs($selected_curator_id, 50);
    $curator_stats = $activityLog->getStatistics($selected_curator_id);
}

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add':
            $roleIds = $user->normalizeRoleIds($_POST['role_ids'] ?? []);
            if (empty($roleIds)) {
                $error = 'Выберите хотя бы одну роль';
                break;
            }
            $fioParts = $user->parseFio(sanitize($_POST['fio'] ?? ''));
            if ($fioParts['last_name'] === '' || $fioParts['first_name'] === '') {
                $error = 'Укажите ФИО (минимум фамилию и имя)';
                break;
            }
            $data = [
                'first_name' => $fioParts['first_name'],
                'last_name' => $fioParts['last_name'],
                'middle_name' => $fioParts['middle_name'],
                'login' => sanitize($_POST['login']),
                'password' => $_POST['password'],
                'email' => null,
                'phone' => null,
                'role_ids' => $roleIds
            ];
            
            if ($user->isLoginUnique($data['login'])) {
                try {
                    if ($user->createUser($data)) {
                        $message = 'Пользователь успешно создан!';
                    } else {
                        $error = 'Ошибка при создании пользователя';
                    }
                } catch (Throwable $e) {
                    $error = 'Ошибка при создании пользователя';
                }
            } else {
                $error = 'Пользователь с таким логином уже существует';
            }
            break;
            
        case 'edit':
            $id = (int)$_POST['user_id'];
            $existing_user = $user->getUserById($id);
            if (!$existing_user) {
                $error = 'Пользователь не найден';
                break;
            }
            $roleIds = $user->normalizeRoleIds($_POST['role_ids'] ?? []);
            if (empty($roleIds)) {
                $error = 'Выберите хотя бы одну роль';
                break;
            }
            $fioParts = $user->parseFio(sanitize($_POST['fio'] ?? ''));
            if ($fioParts['last_name'] === '' || $fioParts['first_name'] === '') {
                $error = 'Укажите ФИО (минимум фамилию и имя)';
                break;
            }
            $data = [
                'first_name' => $fioParts['first_name'],
                'last_name' => $fioParts['last_name'],
                'middle_name' => $fioParts['middle_name'],
                'login' => $existing_user['login'],
                'email' => $existing_user['email'] ?? null,
                'phone' => $existing_user['phone'] ?? null,
                'role_ids' => $roleIds,
                'is_active' => isset($_POST['is_active']) ? 1 : 0
            ];
            $hasCurator = $curator_role_id && in_array((int)$curator_role_id, $roleIds, true);
            if ($hasCurator) {
                $data['curator_portal_access'] = isset($_POST['curator_portal_access']) ? 1 : 0;
                if ($data['curator_portal_access']) {
                    $data['curator_access_request_pending'] = 0;
                } else {
                    $data['curator_access_request_pending'] = (int)(($existing_user ?? [])['curator_access_request_pending'] ?? 0);
                }
            } else {
                $data['curator_portal_access'] = 1;
                $data['curator_access_request_pending'] = 0;
            }

            if ($user->updateUser($id, $data)) {
                $message = 'Пользователь успешно обновлен!';
            } else {
                $error = 'Ошибка при обновлении пользователя';
            }
            break;
            
        case 'delete':
            $id = (int)$_POST['user_id'];
            if ($user->deleteUser($id)) {
                $message = 'Пользователь успешно удален!';
            } else {
                $error = 'Ошибка при удалении пользователя';
            }
            break;
            
        case 'change_password':
            $id = (int)$_POST['user_id'];
            $new_password = $_POST['new_password'];
            if ($user->changePassword($id, $new_password)) {
                $message = 'Пароль успешно изменен!';
            } else {
                $error = 'Ошибка при изменении пароля';
            }
            break;
    }
}

// Получение данных
$search_query = isset($_GET['search']) ? trim($_GET['search']) : '';
$role_filter = isset($_GET['role']) ? (int)$_GET['role'] : 0;
$all_users = $user->getAllUsers($search_query ? $search_query : null);

$role_counts = [];
foreach ($all_users as $u) {
    $ids = $u['role_ids'] ?? [((int)($u['role_id'] ?? 0))];
    foreach ($ids as $rid) {
        $rid = (int)$rid;
        if ($rid <= 0) {
            continue;
        }
        if (!isset($role_counts[$rid])) {
            $role_counts[$rid] = 0;
        }
        $role_counts[$rid]++;
    }
}

$users = $all_users;
if ($role_filter > 0) {
    $users = array_values(array_filter($all_users, function ($u) use ($role_filter) {
        $ids = $u['role_ids'] ?? [((int)($u['role_id'] ?? 0))];
        return in_array($role_filter, array_map('intval', $ids), true);
    }));
}

$edit_user = null;

// Если редактирование
if (isset($_GET['edit'])) {
    $edit_user = $user->getUserById((int)$_GET['edit']);
}

// Для header
$page_title = 'Управление пользователями';
$active_page = 'users';
include 'includes/admin_header.php';
?>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Пользователи</h1>
        <p class="page-subtitle">Управление пользователями системы</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addUserModal">
            <i class="bi bi-person-plus-fill"></i>
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

<!-- Search Card -->
<div class="card">
    <div class="card-body" style="padding: 1rem;">
        <form method="GET" action="users.php" class="d-flex gap-3 align-items-center" style="flex-wrap: wrap;">
            <?php if ($role_filter > 0): ?>
                <input type="hidden" name="role" value="<?php echo $role_filter; ?>">
            <?php endif; ?>
            <div style="flex: 1; min-width: 250px;">
                <div class="input-group">
                    <span class="input-group-text" style="background: var(--content-bg); border-color: var(--border-color);">
                        <i class="bi bi-search text-secondary"></i>
                    </span>
                    <input type="text" class="form-control" name="search" 
                           placeholder="Поиск по ФИО, логину..." 
                           value="<?php echo htmlspecialchars($search_query); ?>"
                           style="border-color: var(--border-color);">
                </div>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-search"></i>
                Найти
            </button>
            <?php if ($search_query || $role_filter > 0): ?>
                <a href="users.php" class="btn btn-outline">
                    <i class="bi bi-x-circle"></i>
                    Сбросить
                </a>
                <span class="text-secondary" style="font-size: 0.875rem;">
                    Найдено: <?php echo count($users); ?>
                </span>
            <?php endif; ?>
        </form>
        <div class="d-flex gap-2 flex-wrap mt-3">
            <?php
            $search_qs = $search_query !== '' ? 'search=' . urlencode($search_query) : '';
            $all_href = 'users.php' . ($search_qs !== '' ? '?' . $search_qs : '');
            ?>
            <a href="<?php echo htmlspecialchars($all_href); ?>"
               class="btn btn-sm <?php echo $role_filter === 0 ? 'btn-primary' : 'btn-outline'; ?>">
                Все
                <span class="badge badge-secondary" style="margin-left: 0.25rem;<?php echo $role_filter === 0 ? ' background: rgba(255,255,255,0.25); color: #fff;' : ''; ?>">
                    <?php echo count($all_users); ?>
                </span>
            </a>
            <?php foreach ($roles as $role): ?>
                <?php
                $rid = (int)$role['id'];
                $count = $role_counts[$rid] ?? 0;
                $label = $role_labels[$rid] ?? $role['name'];
                $active = $role_filter === $rid;
                $role_href = 'users.php?role=' . $rid . ($search_qs !== '' ? '&' . $search_qs : '');
                ?>
                <a href="<?php echo htmlspecialchars($role_href); ?>"
                   class="btn btn-sm <?php echo $active ? 'btn-primary' : 'btn-outline'; ?>">
                    <?php echo htmlspecialchars($label); ?>
                    <span class="badge badge-secondary" style="margin-left: 0.25rem;<?php echo $active ? ' background: rgba(255,255,255,0.25); color: #fff;' : ''; ?>">
                        <?php echo $count; ?>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-people"></i>
            <?php if ($role_filter > 0 && isset($role_labels[$role_filter])): ?>
                <?php echo htmlspecialchars($role_labels[$role_filter]); ?>
            <?php else: ?>
                Список пользователей
            <?php endif; ?>
        </h2>
        <span class="badge badge-primary"><?php echo count($users); ?></span>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Пользователь</th>
                        <th>Логин</th>
                        <th>Роль</th>
                        <th>Отделение</th>
                        <th>Портал куратора</th>
                        <th>Статус</th>
                        <th>Последний вход</th>
                        <th style="width: 140px;">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user_item): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center gap-3">
                                    <div class="list-avatar" style="width: 36px; height: 36px; font-size: 0.875rem;">
                                        <?php echo mb_substr($user_item['first_name'], 0, 1) . mb_substr($user_item['last_name'], 0, 1); ?>
                                    </div>
                                    <div>
                                        <div style="font-weight: 500;">
                                            <?php echo htmlspecialchars($user_item['last_name'] . ' ' . $user_item['first_name']); ?>
                                        </div>
                                        <div style="font-size: 0.8125rem; color: var(--text-secondary);">
                                            <?php echo htmlspecialchars($user_item['middle_name']); ?>
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <code style="background: var(--content-bg); padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.8125rem;">
                                    <?php echo htmlspecialchars($user_item['login']); ?>
                                </code>
                            </td>
                            <td>
                                <div class="d-flex flex-wrap gap-1">
                                    <?php
                                    $labels = $user_item['role_labels'] ?? null;
                                    if (empty($labels)) {
                                        $rid = (int)($user_item['role_id'] ?? 0);
                                        $labels = [$role_labels[$rid] ?? ($user_item['role_name'] ?? '—')];
                                    }
                                    foreach ($labels as $rl):
                                    ?>
                                        <span class="badge badge-secondary"><?php echo htmlspecialchars($rl); ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                            <td>
                                <?php
                                $userRoleIds = array_map('intval', $user_item['role_ids'] ?? [(int)($user_item['role_id'] ?? 0)]);
                                $isCuratorUser = $curator_role_id && in_array((int)$curator_role_id, $userRoleIds, true);
                                $isDeptHead = $department_head_role_id && in_array((int)$department_head_role_id, $userRoleIds, true);
                                $deptName = $user_departments[(int)$user_item['id']] ?? null;
                                if ($isDeptHead && $deptName) {
                                    echo htmlspecialchars($deptName);
                                } elseif ($isDeptHead) {
                                    echo '<span style="color: var(--text-secondary);">не создано</span>';
                                } else {
                                    echo '<span style="color: var(--text-secondary);">—</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($isCuratorUser): ?>
                                    <?php if (!empty($user_item['curator_portal_access'] ?? null)): ?>
                                        <span class="badge badge-success">Открыт</span>
                                    <?php elseif (!empty($user_item['curator_access_request_pending'] ?? null)): ?>
                                        <span class="badge badge-warning">Заявка</span>
                                    <?php else: ?>
                                        <span class="badge badge-secondary">Закрыт</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span style="color: var(--text-secondary);">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?php echo $user_item['is_active'] ? 'badge-success' : 'badge-danger'; ?>">
                                    <?php echo $user_item['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                </span>
                            </td>
                            <td>
                                <span style="font-size: 0.8125rem; color: var(--text-secondary);">
                                    <?php echo $user_item['last_login'] ? formatDate($user_item['last_login']) : 'Никогда'; ?>
                                </span>
                            </td>
                            <td>
                                <div class="d-flex gap-1">
                                    <button class="btn btn-icon btn-sm btn-outline" title="Редактировать" 
                                            onclick="editUser(<?php echo htmlspecialchars(json_encode($user_item)); ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Сменить пароль"
                                            onclick="changePassword(<?php echo $user_item['id']; ?>)">
                                        <i class="bi bi-key"></i>
                                    </button>
                                    <?php if ($isCuratorUser): ?>
                                        <button class="btn btn-icon btn-sm btn-outline" title="Просмотр логов"
                                                onclick="viewLogs(<?php echo $user_item['id']; ?>, '<?php echo htmlspecialchars($user_item['last_name'] . ' ' . $user_item['first_name']); ?>')">
                                            <i class="bi bi-journal-text"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-icon btn-sm btn-outline" title="Удалить" style="color: var(--danger);"
                                            onclick="deleteUser(<?php echo $user_item['id']; ?>, '<?php echo htmlspecialchars($user_item['login']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                                Пользователи не найдены
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="add">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-person-plus-fill me-2"></i>Добавить пользователя</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">ФИО <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="fio" id="add_fio"
                                   placeholder="Фамилия Имя Отчество" autocomplete="off" required>
                            <div class="form-text">Например: Шотаев Жанабек Талгатович</div>
                            <div id="add_fio_matches" class="mt-2" style="display: none;"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Логин <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="login" id="add_login" required>
                                <button type="button" class="btn btn-outline" id="btn_gen_login" title="Сгенерировать логин по фамилии">
                                    <i class="bi bi-magic"></i>
                                </button>
                            </div>
                            <div class="form-text">Формат: portal_фамилия</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Пароль <span class="text-danger">*</span></label>
                            <div class="input-group">
                                <input type="text" class="form-control" name="password" id="add_password" required autocomplete="new-password">
                                <button type="button" class="btn btn-outline" id="btn_gen_password" title="Сгенерировать пароль">
                                    <i class="bi bi-key"></i>
                                </button>
                            </div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Роли <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3" style="padding: 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius); background: var(--content-bg);">
                                <?php foreach ($roles as $role): ?>
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="role_ids[]"
                                               value="<?php echo (int)$role['id']; ?>"
                                               id="add_role_<?php echo (int)$role['id']; ?>">
                                        <label class="form-check-label" for="add_role_<?php echo (int)$role['id']; ?>">
                                            <?php echo htmlspecialchars($role_labels[(int)$role['id']] ?? $role['name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Можно выбрать несколько ролей (например, преподаватель и куратор)</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary" id="add_user_submit">
                        <i class="bi bi-check-lg"></i> Добавить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit User Modal -->
<div class="modal fade" id="editUserModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-pencil me-2"></i>Редактировать пользователя</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3">
                        <div class="col-12">
                            <label class="form-label">ФИО <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="edit_fio" name="fio"
                                   placeholder="Фамилия Имя Отчество" autocomplete="off" required>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Логин</label>
                            <input type="text" class="form-control" id="edit_login" readonly
                                   style="background: var(--content-bg); cursor: default;">
                            <div class="form-text">Логин нельзя изменить</div>
                        </div>
                        <div class="col-12">
                            <label class="form-label">Роли <span class="text-danger">*</span></label>
                            <div class="d-flex flex-wrap gap-3" style="padding: 0.75rem; border: 1px solid var(--border-color); border-radius: var(--radius); background: var(--content-bg);">
                                <?php foreach ($roles as $role): ?>
                                    <div class="form-check">
                                        <input class="form-check-input edit-role-checkbox" type="checkbox" name="role_ids[]"
                                               value="<?php echo (int)$role['id']; ?>"
                                               id="edit_role_<?php echo (int)$role['id']; ?>"
                                               onchange="toggleCuratorFields()">
                                        <label class="form-check-label" for="edit_role_<?php echo (int)$role['id']; ?>">
                                            <?php echo htmlspecialchars($role_labels[(int)$role['id']] ?? $role['name']); ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Можно выбрать несколько ролей (например, преподаватель и куратор)</div>
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="edit_is_active" name="is_active">
                                <label class="form-check-label" for="edit_is_active">
                                    Активный пользователь
                                </label>
                            </div>
                        </div>
                        <div class="col-12" id="curator_portal_fields" style="display: none;">
                            <div class="form-check pt-2 border-top mt-2">
                                <input class="form-check-input" type="checkbox" id="edit_curator_portal_access" name="curator_portal_access">
                                <label class="form-check-label" for="edit_curator_portal_access">
                                    Доступ к порталу куратора
                                </label>
                            </div>
                            <p class="text-muted small mb-0 mt-2" id="curator_pending_hint" style="display: none;"></p>
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

<!-- Change Password Modal -->
<div class="modal fade" id="changePasswordModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <form method="POST">
                <input type="hidden" name="action" value="change_password">
                <input type="hidden" name="user_id" id="password_user_id">
                <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                    <h5 class="modal-title"><i class="bi bi-key me-2"></i>Сменить пароль</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label">Новый пароль <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="text" class="form-control" id="new_password" name="new_password" required autocomplete="new-password">
                        <button type="button" class="btn btn-outline" id="btn_gen_new_password" title="Сгенерировать пароль">
                            <i class="bi bi-key"></i>
                        </button>
                    </div>
                </div>
                <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                    <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Отмена</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-lg"></i> Сменить
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Logs Modal -->
<div class="modal fade" id="viewLogsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content" style="border-radius: var(--radius-lg);">
            <div class="modal-header" style="border-bottom: 1px solid var(--border-color);">
                <h5 class="modal-title">
                    <i class="bi bi-journal-text me-2"></i>
                    Логи действий: <span id="logs_curator_name"></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="logs_loading" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Загрузка...</span>
                    </div>
                </div>
                <div id="logs_content" style="display: none;">
                    <div id="logs_statistics" class="row g-3 mb-4"></div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Дата/Время</th>
                                    <th>Действие</th>
                                    <th>Статус</th>
                                    <th>Студент</th>
                                    <th>Группа</th>
                                    <th>Детали</th>
                                </tr>
                            </thead>
                            <tbody id="logs_table_body"></tbody>
                        </table>
                    </div>
                </div>
                <div id="logs_empty" class="text-center py-4" style="display: none; color: var(--text-secondary);">
                    <i class="bi bi-inbox fs-1 d-block mb-2"></i>
                    Логи отсутствуют
                </div>
            </div>
            <div class="modal-footer" style="border-top: 1px solid var(--border-color); background: var(--content-bg);">
                <button type="button" class="btn btn-outline" data-bs-dismiss="modal">Закрыть</button>
            </div>
        </div>
    </div>
</div>

<!-- Hidden Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="delete_user_id">
</form>

<script>
    const curatorRoleId = <?php echo $curator_role_id ? (int)$curator_role_id : 'null'; ?>;

    function escapeHtml(str) {
        return String(str || '').replace(/[&<>"']/g, function(ch) {
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
        });
    }

    function formatUserFio(user) {
        return [user.last_name, user.first_name, user.middle_name].filter(Boolean).join(' ');
    }

    function normalizeFio(str) {
        return String(str || '').trim().toLowerCase().replace(/\s+/g, ' ');
    }

    function initAddFioCheck() {
        const input = document.getElementById('add_fio');
        const box = document.getElementById('add_fio_matches');
        const form = input ? input.closest('form') : null;
        if (!input || !box) return;

        let timer = null;
        let lastQuery = '';

        function clearMatches() {
            box.style.display = 'none';
            box.innerHTML = '';
        }

        function renderMatches(users, query) {
            if (!users.length) {
                clearMatches();
                return;
            }

            const exact = users.filter(u => normalizeFio(u.fio) === normalizeFio(query));
            const list = exact.length ? exact : users;
            const isExact = exact.length > 0;

            const items = list.map(u => {
                const roles = (u.role_labels || []).join(', ') || '—';
                const status = u.is_active ? 'активен' : 'неактивен';
                return '<div style="padding: 0.5rem 0; border-top: 1px solid rgba(239,68,68,0.15);">'
                    + '<div style="font-weight: 600;">' + escapeHtml(u.fio) + '</div>'
                    + '<div style="font-size: 0.8125rem; color: var(--text-secondary);">'
                    + 'логин: <code>' + escapeHtml(u.login) + '</code>'
                    + ' · ' + escapeHtml(roles)
                    + ' · ' + status
                    + '</div></div>';
            }).join('');

            box.innerHTML = '<div style="padding: 0.75rem; border-radius: var(--radius);'
                + ' background: rgba(239, 68, 68, 0.08); border: 1px solid rgba(239, 68, 68, 0.25); color: var(--danger);">'
                + '<div style="font-weight: 600; margin-bottom: 0.25rem;">'
                + '<i class="bi bi-exclamation-triangle-fill me-1"></i>'
                + (isExact
                    ? 'Такой пользователь уже есть в базе'
                    : 'Похожие пользователи уже есть в базе')
                + '</div>'
                + items
                + '</div>';
            box.style.display = 'block';
        }

        input.addEventListener('input', function() {
            clearTimeout(timer);
            const q = input.value.trim();
            lastQuery = q;
            if (q.length < 3) {
                clearMatches();
                return;
            }
            timer = setTimeout(function() {
                fetch('search_users.php?q=' + encodeURIComponent(q))
                    .then(r => r.json())
                    .then(data => {
                        if (input.value.trim() !== lastQuery) return;
                        if (!data.success || !data.users || !data.users.length) {
                            clearMatches();
                            return;
                        }
                        renderMatches(data.users, q);
                    })
                    .catch(function() {
                        clearMatches();
                    });
            }, 300);
        });

        const modalEl = document.getElementById('addUserModal');
        if (modalEl) {
            modalEl.addEventListener('hidden.bs.modal', function() {
                clearTimeout(timer);
                clearMatches();
                if (form) form.reset();
            });
        }

        if (form) {
            form.addEventListener('submit', function(e) {
                const hasExact = box.style.display !== 'none'
                    && box.textContent.indexOf('Такой пользователь уже есть') !== -1;
                if (hasExact) {
                    if (!confirm('Пользователь с таким ФИО уже есть в базе. Всё равно создать ещё одного?')) {
                        e.preventDefault();
                    }
                }
            });
        }
    }

    function translitToLogin(str) {
        const map = {
            'а':'a','б':'b','в':'v','г':'g','д':'d','е':'e','ё':'e','ж':'zh','з':'z','и':'i','й':'y',
            'к':'k','л':'l','м':'m','н':'n','о':'o','п':'p','р':'r','с':'s','т':'t','у':'u','ф':'f',
            'х':'h','ц':'ts','ч':'ch','ш':'sh','щ':'sch','ъ':'','ы':'y','ь':'','э':'e','ю':'yu','я':'ya',
            'ә':'a','ғ':'g','қ':'q','ң':'n','ө':'o','ұ':'u','ү':'u','һ':'h','і':'i'
        };
        return String(str || '')
            .toLowerCase()
            .split('')
            .map(ch => map[ch] !== undefined ? map[ch] : ch)
            .join('')
            .replace(/[^a-z0-9]+/g, '')
            .replace(/^-+|-+$/g, '');
    }

    function generateLoginFromFio(fio) {
        const lastName = String(fio || '').trim().split(/\s+/)[0] || '';
        const base = translitToLogin(lastName);
        if (!base) return '';
        return 'portal_' + base;
    }

    function generateStrongPassword(length) {
        length = length || 12;
        const lower = 'abcdefghijklmnopqrstuvwxyz';
        const upper = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        const digits = '0123456789';
        const special = '!@#$%^&*>';
        const all = lower + upper + digits + special;
        const pick = (set) => set.charAt(Math.floor(Math.random() * set.length));

        // Гарантируем наличие всех типов символов (как в примере 3uzXUY^0i>aX)
        const required = [pick(lower), pick(upper), pick(digits), pick(special)];
        const rest = [];
        for (let i = required.length; i < length; i++) {
            rest.push(pick(all));
        }
        const chars = required.concat(rest);
        for (let i = chars.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            const tmp = chars[i];
            chars[i] = chars[j];
            chars[j] = tmp;
        }
        return chars.join('');
    }

    function initCredentialGenerators() {
        const fioInput = document.getElementById('add_fio');
        const loginInput = document.getElementById('add_login');
        const passwordInput = document.getElementById('add_password');
        const btnLogin = document.getElementById('btn_gen_login');
        const btnPassword = document.getElementById('btn_gen_password');
        const btnNewPassword = document.getElementById('btn_gen_new_password');
        const newPasswordInput = document.getElementById('new_password');

        if (btnLogin && loginInput && fioInput) {
            btnLogin.addEventListener('click', function() {
                const login = generateLoginFromFio(fioInput.value);
                if (!login) {
                    alert('Сначала укажите ФИО (фамилию)');
                    fioInput.focus();
                    return;
                }
                loginInput.value = login;
                loginInput.focus();
            });
        }

        if (btnPassword && passwordInput) {
            btnPassword.addEventListener('click', function() {
                passwordInput.value = generateStrongPassword(12);
                passwordInput.focus();
                passwordInput.select();
            });
        }

        if (btnNewPassword && newPasswordInput) {
            btnNewPassword.addEventListener('click', function() {
                newPasswordInput.value = generateStrongPassword(12);
                newPasswordInput.focus();
                newPasswordInput.select();
            });
        }
    }

    function toggleCuratorFields() {
        const curatorBlock = document.getElementById('curator_portal_fields');
        if (!curatorBlock || !curatorRoleId) return;
        const checked = Array.from(document.querySelectorAll('.edit-role-checkbox:checked'))
            .some(cb => parseInt(cb.value, 10) === curatorRoleId);
        curatorBlock.style.display = checked ? 'block' : 'none';
    }

    function editUser(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_fio').value = formatUserFio(user);
        document.getElementById('edit_login').value = user.login;
        document.getElementById('edit_is_active').checked = user.is_active == 1;

        const roleIds = (user.role_ids || [user.role_id]).map(id => parseInt(id, 10));
        document.querySelectorAll('.edit-role-checkbox').forEach(cb => {
            cb.checked = roleIds.includes(parseInt(cb.value, 10));
        });

        const curatorBlock = document.getElementById('curator_portal_fields');
        const pendingHint = document.getElementById('curator_pending_hint');
        const isCurator = curatorRoleId && roleIds.includes(curatorRoleId);
        if (curatorBlock) {
            curatorBlock.style.display = isCurator ? 'block' : 'none';
        }
        const cap = document.getElementById('edit_curator_portal_access');
        if (cap) {
            cap.checked = !!(parseInt(user.curator_portal_access, 10) === 1);
        }
        if (pendingHint && isCurator) {
            const pending = parseInt(user.curator_access_request_pending, 10) === 1;
            const open = parseInt(user.curator_portal_access, 10) === 1;
            if (pending && !open) {
                pendingHint.style.display = 'block';
                pendingHint.textContent = 'Есть заявка на открытие доступа.';
            } else {
                pendingHint.style.display = 'none';
                pendingHint.textContent = '';
            }
        } else if (pendingHint) {
            pendingHint.style.display = 'none';
        }

        new bootstrap.Modal(document.getElementById('editUserModal')).show();
    }
    
    function changePassword(userId) {
        document.getElementById('password_user_id').value = userId;
        new bootstrap.Modal(document.getElementById('changePasswordModal')).show();
    }
    
    function deleteUser(userId, login) {
        if (confirm('Вы уверены, что хотите удалить пользователя "' + login + '"?')) {
            document.getElementById('delete_user_id').value = userId;
            document.getElementById('deleteForm').submit();
        }
    }
    
    function viewLogs(curatorId, curatorName) {
        document.getElementById('logs_curator_name').textContent = curatorName;
        document.getElementById('logs_loading').style.display = 'block';
        document.getElementById('logs_content').style.display = 'none';
        document.getElementById('logs_empty').style.display = 'none';
        
        const modal = new bootstrap.Modal(document.getElementById('viewLogsModal'));
        modal.show();
        
        fetch('get_curator_logs.php?curator_id=' + curatorId)
            .then(response => response.json())
            .then(data => {
                document.getElementById('logs_loading').style.display = 'none';
                
                if (data.success && data.logs && data.logs.length > 0) {
                    // Statistics
                    if (data.stats) {
                        document.getElementById('logs_statistics').innerHTML = `
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon blue"><i class="bi bi-activity"></i></div>
                                    <div class="stat-content">
                                        <div class="stat-value">${data.stats.total_actions || 0}</div>
                                        <div class="stat-label">Всего</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon green"><i class="bi bi-check-circle"></i></div>
                                    <div class="stat-content">
                                        <div class="stat-value">${data.stats.success_count || 0}</div>
                                        <div class="stat-label">Успешных</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
                                    <div class="stat-content">
                                        <div class="stat-value">${data.stats.error_count || 0}</div>
                                        <div class="stat-label">Ошибок</div>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="stat-card">
                                    <div class="stat-icon orange"><i class="bi bi-exclamation-triangle"></i></div>
                                    <div class="stat-content">
                                        <div class="stat-value">${data.stats.validation_error_count || 0}</div>
                                        <div class="stat-label">Валидация</div>
                                    </div>
                                </div>
                            </div>
                        `;
                    }
                    
                    // Logs table
                    const tbody = document.getElementById('logs_table_body');
                    tbody.innerHTML = '';
                    
                    data.logs.forEach(log => {
                        const statusBadge = {
                            'success': '<span class="badge badge-success">Успешно</span>',
                            'error': '<span class="badge badge-danger">Ошибка</span>',
                            'validation_error': '<span class="badge badge-warning">Валидация</span>'
                        }[log.action_status] || '<span class="badge badge-secondary">' + log.action_status + '</span>';
                        
                        const actionType = {
                            'add_student': 'Добавление',
                            'edit_student': 'Редактирование',
                            'delete_student': 'Удаление'
                        }[log.action_type] || log.action_type;
                        
                        const date = new Date(log.created_at);
                        const dateStr = date.toLocaleString('ru-RU');
                        
                        const row = document.createElement('tr');
                        row.innerHTML = `
                            <td><small>${dateStr}</small></td>
                            <td>${actionType}</td>
                            <td>${statusBadge}</td>
                            <td>${log.student_name || '-'}<br><small class="text-muted">${log.student_iin || ''}</small></td>
                            <td>${log.group_name || '-'}</td>
                            <td><small>${log.message || '-'}</small></td>
                        `;
                        tbody.appendChild(row);
                    });
                    
                    document.getElementById('logs_content').style.display = 'block';
                } else {
                    document.getElementById('logs_empty').style.display = 'block';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                document.getElementById('logs_loading').style.display = 'none';
                document.getElementById('logs_content').innerHTML = '<div class="alert alert-danger">Ошибка загрузки логов</div>';
                document.getElementById('logs_content').style.display = 'block';
            });
    }

    initAddFioCheck();
    initCredentialGenerators();
</script>

<?php include 'includes/admin_footer.php'; ?>
