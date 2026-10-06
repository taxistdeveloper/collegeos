<?php
require_once 'config/config.php';
require_once 'classes/User.php';
require_once 'includes/auth.php';

// Куратор с ограниченным доступом использует страницу заявки в своём кабинете
if (isset($_SESSION['user_logged_in']) && ($_SESSION['user_role'] ?? '') === 'curator') {
    header('Location: curator/access_pending.php');
    exit;
}

$userModel = new User();
$mode = ($_GET['mode'] ?? 'existing') === 'new' ? 'new' : 'existing';
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'existing_access') {
        $login = sanitize($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($login === '' || $password === '') {
            $error = 'Введите логин и пароль';
        } else {
            $result = $userModel->requestAccessByCredentials($login, $password);
            if ($result['success']) {
                $success = $result['message'];
            } else {
                $error = $result['error'];
            }
        }
        $mode = 'existing';
    }

    if ($action === 'new_account') {
        $result = $userModel->submitNewAccountRequest([
            'last_name' => $_POST['last_name'] ?? '',
            'first_name' => $_POST['first_name'] ?? '',
            'middle_name' => $_POST['middle_name'] ?? '',
            'email' => $_POST['email'] ?? '',
            'phone' => $_POST['phone'] ?? '',
            'comment' => $_POST['comment'] ?? '',
        ]);
        if ($result['success']) {
            $success = $result['message'];
        } else {
            $error = $result['error'];
        }
        $mode = 'new';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Запрос доступа — <?php echo htmlspecialchars(APP_NAME); ?></title>
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
                        <i class="bi bi-key-fill"></i>
                    </div>
                    <h1>Запросить доступ</h1>
                    <p>Выберите подходящий вариант</p>
                </div>

                <div class="login-tabs">
                    <a href="?mode=existing" class="login-tab <?php echo $mode === 'existing' ? 'active' : ''; ?>">
                        <i class="bi bi-person-check"></i> Есть логин
                    </a>
                    <a href="?mode=new" class="login-tab <?php echo $mode === 'new' ? 'active' : ''; ?>">
                        <i class="bi bi-person-plus"></i> Нет учётки
                    </a>
                </div>

                <?php if ($success): ?>
                    <div class="login-alert login-alert-success" role="alert">
                        <i class="bi bi-check-circle-fill"></i>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($error): ?>
                    <div class="login-alert" role="alert">
                        <i class="bi bi-exclamation-triangle-fill"></i>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <div class="login-form">
                    <?php if ($mode === 'existing'): ?>
                        <p class="login-form-hint">Введите логин и пароль куратора. Заявка уйдёт администратору на одобрение.</p>
                        <form method="POST" autocomplete="on">
                            <input type="hidden" name="action" value="existing_access">
                            <div class="login-field">
                                <label for="login">Логин</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-person input-icon"></i>
                                    <input type="text" id="login" name="login" required placeholder="Ваш логин" value="<?php echo htmlspecialchars($_POST['login'] ?? ''); ?>" autocomplete="username">
                                </div>
                            </div>
                            <div class="login-field">
                                <label for="password">Пароль</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-lock input-icon"></i>
                                    <input type="password" id="password" name="password" required placeholder="Ваш пароль" autocomplete="current-password">
                                </div>
                            </div>
                            <button type="submit" class="login-submit">
                                <i class="bi bi-send"></i>
                                <span>Отправить заявку</span>
                            </button>
                        </form>
                    <?php else: ?>
                        <p class="login-form-hint">Заполните данные — администратор создаст учётную запись и свяжется с вами.</p>
                        <form method="POST">
                            <input type="hidden" name="action" value="new_account">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="login-field">
                                        <label for="last_name">Фамилия *</label>
                                        <input type="text" class="form-control login-plain-input" id="last_name" name="last_name" required value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="login-field">
                                        <label for="first_name">Имя *</label>
                                        <input type="text" class="form-control login-plain-input" id="first_name" name="first_name" required value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>">
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="login-field">
                                        <label for="middle_name">Отчество</label>
                                        <input type="text" class="form-control login-plain-input" id="middle_name" name="middle_name" value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>">
                                    </div>
                                </div>
                            </div>
                            <div class="login-field">
                                <label for="email">Email *</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-envelope input-icon"></i>
                                    <input type="email" id="email" name="email" required placeholder="email@example.com" value="<?php echo htmlspecialchars($_POST['email'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="login-field">
                                <label for="phone">Телефон</label>
                                <div class="login-input-wrap">
                                    <i class="bi bi-telephone input-icon"></i>
                                    <input type="tel" id="phone" name="phone" placeholder="+7 (___) ___-__-__" value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="login-field">
                                <label for="comment">Комментарий</label>
                                <textarea class="form-control login-plain-input" id="comment" name="comment" rows="3" placeholder="Группа, должность, причина запроса..."><?php echo htmlspecialchars($_POST['comment'] ?? ''); ?></textarea>
                            </div>
                            <button type="submit" class="login-submit">
                                <i class="bi bi-send"></i>
                                <span>Отправить заявку</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <div class="login-back-link">
                    <a href="login.php"><i class="bi bi-arrow-left"></i> Вернуться ко входу</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
