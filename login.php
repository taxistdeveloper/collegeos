<?php
require_once 'config/config.php';
require_once 'classes/User.php';
require_once 'includes/auth.php';

// Если уже авторизован, перенаправляем по роли
if (isset($_SESSION['user_logged_in'])) {
    $sessionRoles = $_SESSION['user_roles'] ?? [];
    if (!is_array($sessionRoles)) {
        $sessionRoles = [];
    }
    if (($_SESSION['user_role'] ?? '') === 'curator') {
        $sessionRoles[] = 'curator';
    }
    if (in_array('curator', $sessionRoles, true)) {
        $u = new User();
        $ud = $u->getUserById((int) $_SESSION['user_id']);
        if ($ud && !(int) ($ud['curator_portal_access'] ?? 0) && ($_SESSION['user_role'] ?? '') === 'curator') {
            $_SESSION['curator_portal_limited'] = true;
            header('Location: curator/access_pending.php');
            exit;
        }
        unset($_SESSION['curator_portal_limited']);
    }
    redirectByRole($_SESSION['user_role']);
}

$error = '';
$showRolePicker = false;
$rolePickerOptions = [];
$rolePickerUserName = '';

$apkFile = __DIR__ . '/downloads/KVKI.apk';
$apkAvailable = is_file($apkFile);
$apkSizeMb = $apkAvailable ? round(filesize($apkFile) / 1024 / 1024, 1) : 0;
$apkUpdated = $apkAvailable ? date('d.m.Y', filemtime($apkFile)) : '';

// Проверяем, есть ли сообщение об ошибке в URL
if (isset($_GET['error'])) {
    switch ($_GET['error']) {
        case 'blocked':
            $error = 'Ваш аккаунт был заблокирован администратором';
            break;
        case 'session_expired':
            $error = 'Сессия истекла. Войдите в систему заново';
            break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'select_role') {
    $pickedRole = sanitize($_POST['login_role'] ?? '');
    $pending = $_SESSION['login_role_pick'] ?? null;

    if (!$pending || time() > (int)($pending['expires'] ?? 0)) {
        unset($_SESSION['login_role_pick']);
        $error = 'Время выбора роли истекло. Войдите снова.';
    } elseif ($pickedRole === '') {
        $error = 'Выберите роль для входа';
        $showRolePicker = true;
        $rolePickerUserName = (string)($pending['user_name'] ?? '');
        $user = new User();
        $user_data = $user->getUserById((int)$pending['user_id']);
        if ($user_data) {
            $rolePickerOptions = getPortalLoginRoleOptions($user_data);
        }
    } else {
        $user = new User();
        $user_data = $user->getUserById((int)$pending['user_id']);

        if (!$user_data || !(int)$user_data['is_active']) {
            unset($_SESSION['login_role_pick']);
            $error = 'Учётная запись недоступна. Войдите снова.';
        } else {
            $rolePickerOptions = getPortalLoginRoleOptions($user_data);
            $allowed = array_column($rolePickerOptions, 'name');
            if (!in_array($pickedRole, $allowed, true)) {
                $error = 'Недопустимая роль. Выберите из списка.';
                $showRolePicker = true;
                $rolePickerUserName = trim($user_data['first_name'] . ' ' . $user_data['last_name']);
            } else {
                unset($_SESSION['login_role_pick']);
                finalizePortalLogin($user_data, $pickedRole, $user);
            }
        }
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_role_pick') {
    unset($_SESSION['login_role_pick']);
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = sanitize($_POST['login']);
    $password = $_POST['password'];

    if (!empty($login) && !empty($password)) {
        $user = new User();
        $user_data = $user->getUserByLogin($login);

        if ($user_data && password_verify($password, $user_data['password'])) {
            $loginOptions = getPortalLoginRoleOptions($user_data);
            $role_names = $user_data['role_names'] ?? [];
            if (empty($role_names) && !empty($user_data['role_name'])) {
                $role_names = [$user_data['role_name']];
            }

            if (empty($loginOptions)) {
                finalizePortalLogin($user_data, 'curator', $user);
            } elseif (count($loginOptions) === 1) {
                finalizePortalLogin($user_data, $loginOptions[0]['name'], $user);
            } else {
                $_SESSION['login_role_pick'] = [
                    'user_id' => (int)$user_data['id'],
                    'user_name' => trim($user_data['first_name'] . ' ' . $user_data['last_name']),
                    'expires' => time() + 600,
                ];
                $showRolePicker = true;
                $rolePickerOptions = $loginOptions;
                $rolePickerUserName = $_SESSION['login_role_pick']['user_name'];
            }
        } else {
            $db = getDB();
            $sql = "SELECT u.*, r.name as role_name 
                    FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.id 
                    WHERE u.login = ?";
            $stmt = $db->prepare($sql);
            $stmt->bind_param("s", $login);
            $stmt->execute();
            $result = $stmt->get_result();
            $user_check = $result->fetch_assoc();

            if ($user_check && !$user_check['is_active']) {
                $error = 'Ваш аккаунт заблокирован. Обратитесь к администратору.';
            } else {
                $error = 'Неверный логин или пароль';
            }
        }
    } else {
        $error = 'Заполните все поля';
    }
}

if (!$showRolePicker && $_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['login_role_pick'])) {
    $pending = $_SESSION['login_role_pick'];
    if (time() > (int)($pending['expires'] ?? 0)) {
        unset($_SESSION['login_role_pick']);
    } else {
        $user = new User();
        $user_data = $user->getUserById((int)$pending['user_id']);
        if ($user_data && (int)$user_data['is_active']) {
            $rolePickerOptions = getPortalLoginRoleOptions($user_data);
            if (count($rolePickerOptions) > 1) {
                $showRolePicker = true;
                $rolePickerUserName = (string)($pending['user_name'] ?? '');
            } else {
                unset($_SESSION['login_role_pick']);
            }
        } else {
            unset($_SESSION['login_role_pick']);
        }
    }
}

$rolePickerIcons = [
    'admin' => 'shield-lock-fill',
    'director' => 'briefcase-fill',
    'manager' => 'person-badge-fill',
    'methodist' => 'mortarboard-fill',
    'department_head' => 'building-fill',
    'curator' => 'people-fill',
    'teacher' => 'person-workspace',
    'cos' => 'file-earmark-text-fill',
    'librarian' => 'book-fill',
];
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход — <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Outfit:wght@600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/login.css" rel="stylesheet">
</head>

<body class="login-page">
    <div class="login-shell">
        <main class="login-panel">
            <div class="login-form-wrap">
                <div class="login-form-header">
                    <div class="login-mark">
                        <img src="kvki-logo.png" alt="Қарағанды жоғары инжиниринг колледжі" class="login-logo">
                    </div>
                    <p>Введите логин и пароль для входа</p>
                </div>

                <?php if ($error && !$showRolePicker): ?>
                    <div class="login-alert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" id="loginForm" class="login-form" autocomplete="on">
                    <div class="login-field">
                        <label for="login">Логин</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-person input-icon"></i>
                            <input type="text"
                                id="login"
                                name="login"
                                value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"
                                placeholder="Ваш логин"
                                required
                                autofocus
                                autocomplete="username">
                        </div>
                    </div>

                    <div class="login-field">
                        <label for="password">Пароль</label>
                        <div class="login-input-wrap">
                            <i class="bi bi-lock input-icon"></i>
                            <input type="password"
                                id="password"
                                name="password"
                                placeholder="Ваш пароль"
                                required
                                autocomplete="current-password">
                            <button type="button" class="login-toggle-pwd" id="togglePassword" aria-label="Показать пароль">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>

                    <label class="login-remember" for="rememberPassword">
                        <input type="checkbox" id="rememberPassword" name="remember_password">
                        <span>Запомнить пароль</span>
                    </label>

                    <button type="submit" class="login-submit" id="submitBtn">
                        <i class="bi bi-box-arrow-in-right"></i>
                        <span>Войти</span>
                    </button>
                </form>

                <div class="login-links">
                    <a href="forgot_password.php" class="login-link">
                        <i class="bi bi-unlock"></i>
                        Восстановить пароль
                    </a>
                    <a href="request_access.php" class="login-link">
                        <i class="bi bi-send"></i>
                        Запросить доступ
                    </a>
                    <?php if ($apkAvailable): ?>
                        <a href="downloads/download.php" class="login-link login-link--apk">
                            <i class="bi bi-phone"></i>
                            Приложение Android
                            <span class="login-link-meta"><?php echo $apkSizeMb; ?> МБ · <?php echo htmlspecialchars($apkUpdated); ?></span>
                        </a>
                    <?php endif; ?>
                </div>

                <footer class="login-credit">
                    <span class="login-credit-line" aria-hidden="true"></span>
                    <p class="login-credit-text">
                        <span class="login-credit-label">Разработчик</span>
                        <span class="login-credit-sep" aria-hidden="true"></span>
                        <a href="https://www.instagram.com/zshotaeff/"
                           class="login-credit-name"
                           target="_blank"
                           rel="noopener noreferrer"
                           title="Instagram · @zshotaeff">SHOTAYEV</a>
                        <span class="login-credit-sep" aria-hidden="true"></span>
                        <span class="login-credit-org">КВКИ</span>
                    </p>
                </footer>
            </div>
        </main>
    </div>

    <?php if ($showRolePicker && !empty($rolePickerOptions)): ?>
        <div class="modal fade" id="rolePickModal" tabindex="-1" aria-labelledby="rolePickModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content login-role-modal">
                    <div class="modal-header border-0 pb-0">
                        <div>
                            <h2 class="modal-title fs-5" id="rolePickModalLabel">Выберите роль</h2>
                            <p class="login-role-modal-sub mb-0">
                                Учётная запись <strong><?php echo htmlspecialchars($rolePickerUserName); ?></strong> — укажите, куда войти.
                            </p>
                        </div>
                    </div>
                    <div class="modal-body pt-3">
                        <?php if ($error): ?>
                            <div class="login-alert mb-3" role="alert">
                                <i class="bi bi-exclamation-triangle-fill"></i>
                                <span><?php echo htmlspecialchars($error); ?></span>
                            </div>
                        <?php endif; ?>
                        <form method="POST" id="rolePickForm" class="login-role-picker-form">
                            <input type="hidden" name="action" value="select_role">
                            <fieldset class="login-role-picker">
                                <legend class="visually-hidden">Роль для входа</legend>
                                <?php foreach ($rolePickerOptions as $idx => $opt): ?>
                                    <?php
                                    $icon = $rolePickerIcons[$opt['name']] ?? 'person-circle';
                                    $inputId = 'login_role_' . preg_replace('/[^a-z0-9_]/', '', $opt['name']);
                                    ?>
                                    <label class="login-role-option" for="<?php echo htmlspecialchars($inputId); ?>">
                                        <input type="radio"
                                            name="login_role"
                                            id="<?php echo htmlspecialchars($inputId); ?>"
                                            value="<?php echo htmlspecialchars($opt['name']); ?>"
                                            <?php echo $idx === 0 ? 'required checked' : ''; ?>>
                                        <span class="login-role-option-icon"><i class="bi bi-<?php echo htmlspecialchars($icon); ?>"></i></span>
                                        <span class="login-role-option-text">
                                            <strong><?php echo htmlspecialchars($opt['label']); ?></strong>
                                        </span>
                                        <span class="login-role-option-check"><i class="bi bi-check-lg"></i></span>
                                    </label>
                                <?php endforeach; ?>
                            </fieldset>
                            <button type="submit" class="login-submit" id="rolePickBtn">
                                <i class="bi bi-box-arrow-in-right"></i>
                                <span>Продолжить</span>
                            </button>
                        </form>
                    </div>
                    <div class="modal-footer border-0 pt-0 justify-content-center">
                        <form method="POST" class="login-role-cancel m-0">
                            <input type="hidden" name="action" value="cancel_role_pick">
                            <button type="submit" class="login-role-back">
                                <i class="bi bi-arrow-left"></i> Другой логин
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function() {
            const STORAGE_KEY = 'portal_login_remember';
            const loginInput = document.getElementById('login');
            const passwordInput = document.getElementById('password');
            const rememberInput = document.getElementById('rememberPassword');
            const form = document.getElementById('loginForm');

            try {
                const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
                if (saved && typeof saved === 'object') {
                    if (saved.login && loginInput && !loginInput.value) {
                        loginInput.value = saved.login;
                    }
                    if (saved.password && passwordInput) {
                        passwordInput.value = saved.password;
                    }
                    if (rememberInput) {
                        rememberInput.checked = true;
                    }
                }
            } catch (e) {
                /* ignore */ }

            document.getElementById('togglePassword')?.addEventListener('click', function() {
                const icon = this.querySelector('i');
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                    icon.classList.replace('bi-eye', 'bi-eye-slash');
                    this.setAttribute('aria-label', 'Скрыть пароль');
                } else {
                    passwordInput.type = 'password';
                    icon.classList.replace('bi-eye-slash', 'bi-eye');
                    this.setAttribute('aria-label', 'Показать пароль');
                }
            });

            form?.addEventListener('submit', function() {
                try {
                    if (rememberInput.checked) {
                        localStorage.setItem(STORAGE_KEY, JSON.stringify({
                            login: loginInput.value,
                            password: passwordInput.value
                        }));
                    } else {
                        localStorage.removeItem(STORAGE_KEY);
                    }
                } catch (e) {
                    /* ignore */ }

                const btn = document.getElementById('submitBtn');
                btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span><span>Вход...</span>';
                btn.disabled = true;
            });

            document.getElementById('rolePickForm')?.addEventListener('submit', function() {
                const btn = document.getElementById('rolePickBtn');
                if (btn) {
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span><span>Переход...</span>';
                    btn.disabled = true;
                }
            });

            const roleModalEl = document.getElementById('rolePickModal');
            if (roleModalEl && window.bootstrap) {
                const modal = new bootstrap.Modal(roleModalEl);
                modal.show();
            }
        })();
    </script>
</body>

</html>