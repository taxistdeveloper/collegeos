<?php
require_once '../config/config.php';
require_once '../classes/User.php';

// Если уже авторизован, перенаправляем
if (isset($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = sanitize($_POST['login']);
    $password = $_POST['password'];

    if (!empty($login) && !empty($password)) {
        $user = new User();
        $admin = $user->getUserByLogin($login);

        if ($admin && password_verify($password, $admin['password'])) {
            $roleNames = $admin['role_names'] ?? [];
            if (!is_array($roleNames)) {
                $roleNames = [];
            }
            if (($admin['role_name'] ?? '') === 'admin') {
                $roleNames[] = 'admin';
            }

            if (!in_array('admin', $roleNames, true)) {
                $error = 'Доступ в админ-панель разрешён только администраторам';
            } else {
                $_SESSION['admin_logged_in'] = true;
                $_SESSION['admin_id'] = $admin['id'];
                $_SESSION['admin_name'] = $admin['first_name'] . ' ' . $admin['last_name'];
                $_SESSION['admin_role'] = 'admin';

                $user->updateLastLogin($admin['id']);

                header('Location: index.php');
                exit;
            }
        } else {
            $error = 'Неверный логин или пароль';
        }
    } else {
        $error = 'Заполните все поля';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Вход в админ-панель — <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/admin-login.css" rel="stylesheet">
</head>
<body class="admin-login-page">
    <div class="admin-login-shell">
        <main class="admin-login-panel">
            <div class="admin-login-form-wrap">
                <header class="admin-login-form-header">
                    <div class="admin-login-mark">
                        <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
                    </div>
                    <h1>Админ-панель</h1>
                    <p>Только для учётных записей администратора</p>
                </header>

                <?php if ($error): ?>
                    <div class="admin-login-alert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form class="admin-login-form" method="POST" id="loginForm" autocomplete="on">
                    <div class="admin-login-field">
                        <label for="login">Логин</label>
                        <div class="admin-login-input-wrap">
                            <i class="bi bi-person input-icon" aria-hidden="true"></i>
                            <input type="text"
                                   id="login"
                                   name="login"
                                   value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>"
                                   placeholder="Введите логин"
                                   required
                                   autofocus
                                   autocomplete="username">
                        </div>
                    </div>

                    <div class="admin-login-field">
                        <label for="password">Пароль</label>
                        <div class="admin-login-input-wrap">
                            <i class="bi bi-lock input-icon" aria-hidden="true"></i>
                            <input type="password"
                                   id="password"
                                   name="password"
                                   placeholder="Введите пароль"
                                   required
                                   autocomplete="current-password">
                            <button type="button" class="admin-login-toggle-pwd" id="togglePassword" aria-label="Показать пароль">
                                <i class="bi bi-eye" aria-hidden="true"></i>
                            </button>
                        </div>
                    </div>

                    <label class="admin-login-remember" for="rememberPassword">
                        <input type="checkbox" id="rememberPassword" name="remember_password">
                        <span>Запомнить пароль</span>
                    </label>

                    <button type="submit" class="admin-login-submit" id="submitBtn">
                        <span>Войти</span>
                        <i class="bi bi-arrow-right" aria-hidden="true"></i>
                    </button>
                </form>

                <p class="admin-login-back">
                    <a href="../login.php">
                        <i class="bi bi-arrow-left" aria-hidden="true"></i>
                        Обычный вход для сотрудников
                    </a>
                </p>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const STORAGE_KEY = 'admin_login_remember';
            const loginInput = document.getElementById('login');
            const passwordInput = document.getElementById('password');
            const rememberInput = document.getElementById('rememberPassword');
            const form = document.getElementById('loginForm');

            try {
                const saved = JSON.parse(localStorage.getItem(STORAGE_KEY) || 'null');
                if (saved && typeof saved === 'object') {
                    if (saved.login && !loginInput.value) {
                        loginInput.value = saved.login;
                    }
                    if (saved.password) {
                        passwordInput.value = saved.password;
                    }
                    rememberInput.checked = true;
                }
            } catch (e) { /* ignore */ }

            document.getElementById('togglePassword')?.addEventListener('click', function () {
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

            form?.addEventListener('submit', function () {
                try {
                    if (rememberInput.checked) {
                        localStorage.setItem(STORAGE_KEY, JSON.stringify({
                            login: loginInput.value,
                            password: passwordInput.value
                        }));
                    } else {
                        localStorage.removeItem(STORAGE_KEY);
                    }
                } catch (e) { /* ignore */ }

                const btn = document.getElementById('submitBtn');
                btn.innerHTML = '<span class="admin-login-spinner"></span><span>Вход...</span>';
                btn.disabled = true;
            });
        })();
    </script>
</body>
</html>
