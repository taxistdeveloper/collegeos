<?php

/**
 * Система авторизации и проверки ролей
 */

/**
 * Куратор без открытого доступа может находиться только на странице заявки.
 */
function curatorPortalGateIfNeeded()
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    if (strpos($script, '/curator/') === false) {
        return;
    }
    if (basename($script) === 'access_pending.php') {
        return;
    }
    if (!isset($_SESSION['user_logged_in'])) {
        return;
    }
    $roles = $_SESSION['user_roles'] ?? [];
    if (!is_array($roles)) {
        $roles = [];
    }
    if (($_SESSION['user_role'] ?? '') === 'curator') {
        $roles[] = 'curator';
    }
    if (!in_array('curator', $roles, true)) {
        return;
    }
    $user_id = $_SESSION['user_id'] ?? null;
    if (!$user_id) {
        return;
    }
    $db = getDB();
    $sql = "SELECT curator_portal_access FROM users WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    if (!$row) {
        return;
    }
    if ((int) $row['curator_portal_access']) {
        unset($_SESSION['curator_portal_limited']);
        return;
    }

    if (strpos($script, '/api/') !== false) {
        http_response_code(403);
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode([
            'success' => false,
            'error' => 'Доступ к порталу куратора закрыт. Отправьте заявку администратору.',
            'code' => 'curator_portal_closed',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $dir = rtrim(dirname($script), '/\\');
    $_SESSION['curator_portal_limited'] = true;
    header('Location: ' . $dir . '/access_pending.php');
    exit;
}

/**
 * Сессия админ-панели → портальная user-сессия (просмотр/редактирование студентов и т.п.).
 */
function bridgeAdminSessionIfNeeded()
{
    if (isset($_SESSION['user_logged_in'])) {
        return;
    }
    if (empty($_SESSION['admin_logged_in']) || empty($_SESSION['admin_id'])) {
        return;
    }

    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id'] = (int)$_SESSION['admin_id'];
    $_SESSION['user_role'] = 'admin';
    $_SESSION['user_roles'] = ['admin'];
    if (!empty($_SESSION['admin_name'])) {
        $_SESSION['user_name'] = $_SESSION['admin_name'];
    }
    $_SESSION['auth_via_admin'] = true;
}

// Функция для проверки авторизации
function checkAuth()
{
    // Безопасный запуск сессии
    startSessionSafely();
    bridgeAdminSessionIfNeeded();

    if (!isset($_SESSION['user_logged_in'])) {
        // Определяем правильный путь к login.php в зависимости от текущей директории
        $current_dir = dirname($_SERVER['PHP_SELF']);
        if (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/admin') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false || strpos($current_dir, '/department') !== false) {
            $login_url = '../login.php';
        } else {
            $login_url = 'login.php';
        }
        header('Location: ' . $login_url);
        exit;
    }

    // Дополнительная проверка статуса пользователя в базе данных
    if (isset($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $db = getDB();
        $sql = "SELECT is_active FROM users WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();

        // Если пользователь заблокирован, уничтожаем сессию и перенаправляем
        if (!$user_data || !$user_data['is_active']) {
            $was_admin = !empty($_SESSION['admin_logged_in']);
            session_destroy();
            $current_dir = dirname($_SERVER['PHP_SELF']);
            if ($was_admin || strpos($current_dir, '/admin') !== false) {
                $login_url = (strpos($current_dir, '/admin') !== false)
                    ? 'login.php?error=blocked'
                    : '../admin/login.php?error=blocked';
            } elseif (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false || strpos($current_dir, '/department') !== false) {
                $login_url = '../login.php?error=blocked';
            } else {
                $login_url = 'login.php?error=blocked';
            }
            header('Location: ' . $login_url);
            exit;
        }
    }

    // Админ из admin-панели не должен попадать в гейт заявки куратора
    if (empty($_SESSION['admin_logged_in'])) {
        curatorPortalGateIfNeeded();
    }
}



// Функция для проверки роли
function checkRole($required_roles = [])
{
    checkAuth();

    // Проверяем, активен ли пользователь в базе данных
    $user_id = $_SESSION['user_id'] ?? null;
    if ($user_id) {
        $db = getDB();
        $sql = "SELECT is_active, role_id FROM users WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $user_data = $result->fetch_assoc();

        // Если пользователь не найден или заблокирован
        if (!$user_data || !$user_data['is_active']) {
            // Уничтожаем сессию и перенаправляем на страницу входа
            session_destroy();
            // Определяем правильный путь к login.php
            $current_dir = dirname($_SERVER['PHP_SELF']);
            if (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/admin') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false || strpos($current_dir, '/department') !== false) {
                $login_url = '../login.php?error=blocked';
            } else {
                $login_url = 'login.php?error=blocked';
            }
            header('Location: ' . $login_url);
            exit;
        }

        // Актуальные роли из user_roles (+ fallback на role_id)
        $role_names = [];
        $ur = $db->prepare(
            "SELECT r.name FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = ?"
        );
        $ur->bind_param('i', $user_id);
        $ur->execute();
        $urRes = $ur->get_result();
        while ($row = $urRes->fetch_assoc()) {
            $role_names[] = $row['name'];
        }
        if (empty($role_names)) {
            $role_sql = "SELECT name FROM roles WHERE id = ?";
            $role_stmt = $db->prepare($role_sql);
            $role_stmt->bind_param("i", $user_data['role_id']);
            $role_stmt->execute();
            $role_result = $role_stmt->get_result();
            $role_data = $role_result->fetch_assoc();
            if (!empty($role_data['name'])) {
                $role_names[] = $role_data['name'];
            }
        }

        $_SESSION['user_roles'] = $role_names;

        $currentRole = $_SESSION['user_role'] ?? '';
        if (!empty($_SESSION['user_role_explicit']) && in_array($currentRole, $role_names, true)) {
            // Сохраняем роль, выбранную пользователем при входе
        } else {
            $priority = ['admin', 'director', 'manager', 'methodist', 'department_head', 'curator', 'teacher', 'cos', 'librarian'];
            $primary = $role_names[0] ?? '';
            foreach ($priority as $p) {
                if (in_array($p, $role_names, true)) {
                    $primary = $p;
                    break;
                }
            }
            $_SESSION['user_role'] = $primary;
            unset($_SESSION['user_role_explicit']);
        }
    }

    if (!empty($required_roles)) {
        $user_roles = $_SESSION['user_roles'] ?? [];
        if (!is_array($user_roles) || empty($user_roles)) {
            $user_roles = [$_SESSION['user_role'] ?? ''];
        }
        $ok = false;
        foreach ($required_roles as $req) {
            if (in_array($req, $user_roles, true)) {
                $ok = true;
                break;
            }
        }
        if (!$ok) {
            // Определяем правильный путь к unauthorized.php в зависимости от текущей директории
            $current_dir = dirname($_SERVER['PHP_SELF']);
            if (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/admin') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false || strpos($current_dir, '/department') !== false) {
                $redirect_url = '../unauthorized.php';
            } else {
                $redirect_url = 'unauthorized.php';
            }
            header('Location: ' . $redirect_url);
            exit;
        }
    }
}

// Функция для получения информации о текущем пользователе
function getCurrentUser()
{
    // Безопасный запуск сессии
    startSessionSafely();
    return [
        'id' => $_SESSION['user_id'] ?? null,
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['user_role'] ?? '',
        'roles' => $_SESSION['user_roles'] ?? [$_SESSION['user_role'] ?? ''],
        'login' => $_SESSION['user_login'] ?? ''
    ];
}

/**
 * Человекочитаемые названия ролей портала
 */
function getPortalRoleLabels()
{
    return [
        'admin' => 'Администратор',
        'director' => 'Директор',
        'manager' => 'Менеджер',
        'methodist' => 'Учебная часть',
        'department_head' => 'Заведующий отделением',
        'curator' => 'Куратор',
        'teacher' => 'Преподаватель',
        'cos' => 'ЦОС',
        'librarian' => 'Библиотека',
    ];
}

/**
 * Роли, доступные для входа (куратор без доступа к порталу исключается, если есть другие роли)
 *
 * @return list<array{name: string, label: string}>
 */
function getPortalLoginRoleOptions(array $userData)
{
    $names = $userData['role_names'] ?? [];
    if (!is_array($names)) {
        $names = [];
    }
    if (empty($names) && !empty($userData['role_name'])) {
        $names = [$userData['role_name']];
    }

    $labelsFromDb = $userData['role_labels'] ?? [];
    if (!is_array($labelsFromDb)) {
        $labelsFromDb = [];
    }
    $fallbackLabels = getPortalRoleLabels();

    $curatorOpen = (int)($userData['curator_portal_access'] ?? 0) === 1;
    $options = [];

    foreach ($names as $i => $name) {
        if ($name === 'curator' && !$curatorOpen) {
            continue;
        }
        $label = $labelsFromDb[$i] ?? ($fallbackLabels[$name] ?? $name);
        $options[] = ['name' => $name, 'label' => $label];
    }

    return $options;
}

/**
 * Завершить вход: сессия, последний вход, редирект по выбранной роли
 */
function finalizePortalLogin(array $userData, string $selectedRole, User $user)
{
    $roleNames = $userData['role_names'] ?? [];
    if (!is_array($roleNames)) {
        $roleNames = [];
    }
    if (empty($roleNames) && !empty($userData['role_name'])) {
        $roleNames = [$userData['role_name']];
    }

    $_SESSION['user_logged_in'] = true;
    $_SESSION['user_id'] = (int)$userData['id'];
    $_SESSION['user_name'] = trim($userData['first_name'] . ' ' . $userData['last_name']);
    $_SESSION['user_login'] = $userData['login'];
    $_SESSION['user_roles'] = $roleNames;
    $_SESSION['user_role'] = $selectedRole;
    $_SESSION['user_role_explicit'] = true;

    if ($selectedRole === 'admin') {
        $_SESSION['admin_logged_in'] = true;
        $_SESSION['admin_id'] = (int)$userData['id'];
        $_SESSION['admin_name'] = $_SESSION['user_name'];
        $_SESSION['admin_role'] = 'admin';
    }

    $isCurator = in_array('curator', $roleNames, true);
    $curatorOpen = (int)($userData['curator_portal_access'] ?? 0) === 1;

    if ($selectedRole === 'curator' && $isCurator && !$curatorOpen) {
        $_SESSION['curator_portal_limited'] = true;
        $user->updateLastLogin((int)$userData['id']);
        header('Location: curator/access_pending.php');
        exit;
    }

    unset($_SESSION['curator_portal_limited']);
    $user->updateLastLogin((int)$userData['id']);
    redirectByRole($selectedRole);
}

// Функция для перенаправления по роли
function redirectByRole($role)
{
    switch ($role) {
        case 'admin':
            header('Location: admin/index.php');
            break;
        case 'director':
            header('Location: director/dashboard.php');
            break;
        case 'manager':
            header('Location: manager/dashboard.php');
            break;
        case 'curator':
            header('Location: curator/dashboard.php');
            break;
        case 'cos':
            header('Location: cos/dashboard.php');
            break;
        case 'librarian':
            header('Location: library/dashboard.php');
            break;
        case 'methodist':
            header('Location: uchebni/dashboard.php');
            break;
        case 'teacher':
            header('Location: uchebni/dashboard.php');
            break;
        case 'department_head':
            header('Location: department/dashboard.php');
            break;
        default:
            header('Location: index.php');
    }
    exit;
}

// Функция для получения меню по роли
function getMenuByRole($role)
{
    $menus = [
        'admin' => [
            ['name' => 'Главная', 'url' => 'admin/index.php', 'icon' => 'house-fill'],
            ['name' => 'Пользователи', 'url' => 'admin/users.php', 'icon' => 'people-fill'],
            ['name' => 'Группы', 'url' => 'admin/groups.php', 'icon' => 'collection-fill'],
            ['name' => 'Студенты', 'url' => 'students.php', 'icon' => 'mortarboard-fill'],
            ['name' => 'Отчеты', 'url' => 'reports.php', 'icon' => 'graph-up']
        ],
        'director' => [
            ['name' => 'Дашборд', 'url' => 'director/dashboard.php', 'icon' => 'speedometer2'],
            ['name' => 'Студенты', 'url' => 'students.php', 'icon' => 'mortarboard-fill'],
            ['name' => 'Группы', 'url' => 'groups.php', 'icon' => 'collection-fill'],
            ['name' => 'Отчеты', 'url' => 'dashboard.php', 'icon' => 'graph-up'],
            ['name' => 'Аналитика', 'url' => 'director/analytics.php', 'icon' => 'bar-chart-fill']
        ],
        'manager' => [
            ['name' => 'Дашборд', 'url' => 'manager/dashboard.php', 'icon' => 'speedometer2'],
            ['name' => 'Студенты', 'url' => 'students.php', 'icon' => 'mortarboard-fill'],
            ['name' => 'Группы', 'url' => 'groups.php', 'icon' => 'collection-fill'],
            ['name' => 'Добавить студента', 'url' => 'add_student.php', 'icon' => 'person-plus-fill']
        ],
        'curator' => [
            ['name' => 'Дашборд', 'url' => 'dashboard.php', 'icon' => 'speedometer2'],
            ['name' => 'Мои группы', 'url' => 'my_groups.php', 'icon' => 'collection-fill'],
            ['name' => 'Выпускники', 'url' => 'graduates.php', 'icon' => 'archive-fill'],
            ['name' => 'Добавить студента', 'url' => 'add_student.php', 'icon' => 'person-plus-fill'],
            ['name' => 'Отчёты группы', 'url' => 'group_reports.php', 'icon' => 'graph-up']
        ],
        'cos' => [
            ['name' => 'Главная', 'url' => 'dashboard.php', 'icon' => 'house-fill'],
            ['name' => 'Справки', 'url' => 'spravki.php', 'icon' => 'file-earmark-text-fill']
        ],
        'librarian' => [
            ['name' => 'Главная', 'url' => 'dashboard.php', 'icon' => 'house-fill'],
            ['name' => 'Книги', 'url' => 'books.php', 'icon' => 'journal-text'],
            ['name' => 'Выдача / приём', 'url' => 'loans.php', 'icon' => 'arrow-left-right'],
            ['name' => 'Бронирование', 'url' => 'reservations.php', 'icon' => 'bookmark']
        ],
        'methodist' => [
            ['name' => 'Главная', 'url' => 'dashboard.php', 'icon' => 'house-fill'],
            ['name' => 'Преподаватели', 'url' => 'teachers.php', 'icon' => 'person-workspace'],
            ['name' => 'Дисциплины', 'url' => 'subjects.php', 'icon' => 'journal-text'],
            ['name' => 'Содержание', 'url' => 'content.php', 'icon' => 'list-columns'],
            ['name' => 'Учебный план', 'url' => 'curriculum.php', 'icon' => 'journal-bookmark'],
            ['name' => 'Нагрузка', 'url' => 'workload.php', 'icon' => 'calendar3'],
            ['name' => 'Ведомость', 'url' => 'vedomost.php', 'icon' => 'file-earmark-spreadsheet'],
            ['name' => 'Расписание', 'url' => 'schedule.php', 'icon' => 'calendar-week'],
            ['name' => 'Замены', 'url' => 'substitutions.php', 'icon' => 'arrow-left-right'],
            ['name' => 'Вх. замены', 'url' => 'covers.php', 'icon' => 'person-plus'],
            ['name' => 'Занятость', 'url' => 'occupancy.php', 'icon' => 'grid-3x3-gap'],
            ['name' => 'Часы', 'url' => 'hours.php', 'icon' => 'clock-history'],
            ['name' => 'Практика', 'url' => 'practice.php', 'icon' => 'briefcase'],
            ['name' => 'Аудитории', 'url' => 'classrooms.php', 'icon' => 'door-open'],
            ['name' => 'Сводная ведомость', 'url' => 'journal.php', 'icon' => 'table'],
            ['name' => 'Аналитика', 'url' => 'analytics.php', 'icon' => 'bar-chart-fill']
        ],
        'teacher' => [
            ['name' => 'Главная', 'url' => 'dashboard.php', 'icon' => 'house-fill'],
            ['name' => 'Оценки', 'url' => 'grades.php', 'icon' => 'journal-check'],
            ['name' => 'Нагрузка', 'url' => 'workload.php', 'icon' => 'calendar3'],
            ['name' => 'Ведомость', 'url' => 'vedomost.php', 'icon' => 'file-earmark-spreadsheet'],
            ['name' => 'Расписание', 'url' => 'schedule.php', 'icon' => 'calendar-week'],
            ['name' => 'Часы', 'url' => 'hours.php?view=mine', 'icon' => 'clock-history'],
            ['name' => 'Вх. замены', 'url' => 'covers.php', 'icon' => 'person-plus'],
            ['name' => 'Практика', 'url' => 'practice.php', 'icon' => 'briefcase'],
            ['name' => 'Сводная ведомость', 'url' => 'journal.php', 'icon' => 'table']
        ],
        'department_head' => [
            ['name' => 'Главная', 'url' => 'dashboard.php', 'icon' => 'house-fill'],
            ['name' => 'Отделение', 'url' => 'department.php', 'icon' => 'building'],
            ['name' => 'Сотрудники', 'url' => 'staff.php', 'icon' => 'people-fill'],
            ['name' => 'Группы', 'url' => 'groups.php', 'icon' => 'collection-fill'],
            ['name' => 'Оценки', 'url' => 'grades.php', 'icon' => 'journal-check']
        ]
    ];

    return $menus[$role] ?? [];
}

// Функция для проверки прав доступа
function hasPermission($permission)
{
    $user = getCurrentUser();
    $user_id = $user['id'];

    if (!$user_id) {
        return false;
    }

    // Получаем права пользователя из всех ролей
    $db = getDB();
    $sql = "SELECT r.permissions FROM user_roles ur
            JOIN roles r ON r.id = ur.role_id
            JOIN users u ON u.id = ur.user_id
            WHERE ur.user_id = ? AND u.is_active = 1";
    $stmt = $db->prepare($sql);
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $merged = [];
    while ($row = $result->fetch_assoc()) {
        if (!$row['permissions']) {
            continue;
        }
        $permissions = json_decode($row['permissions'], true);
        if (!is_array($permissions)) {
            continue;
        }
        if (!empty($permissions['all'])) {
            return true;
        }
        foreach ($permissions as $key => $val) {
            if ($val) {
                $merged[$key] = true;
            }
        }
    }

    // fallback на основную роль
    if (empty($merged)) {
        $sql = "SELECT r.permissions FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ? AND u.is_active = 1";
        $stmt = $db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        if (!$row || !$row['permissions']) {
            return false;
        }
        $permissions = json_decode($row['permissions'], true);
        if (isset($permissions['all']) && $permissions['all']) {
            return true;
        }
        return isset($permissions[$permission]) && $permissions[$permission];
    }

    return !empty($merged[$permission]);
}

// Функция для проверки прав и перенаправления, если доступа нет
function requirePermission($permission, $redirect_url = null)
{
    if (!hasPermission($permission)) {
        // Определяем правильный путь к unauthorized.php в зависимости от текущей директории
        if ($redirect_url === null) {
            $current_dir = dirname($_SERVER['PHP_SELF']);
            if (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/admin') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false || strpos($current_dir, '/department') !== false) {
                $redirect_url = '../unauthorized.php';
            } else {
                $redirect_url = 'unauthorized.php';
            }
        }
        header('Location: ' . $redirect_url);
        exit;
    }
}
