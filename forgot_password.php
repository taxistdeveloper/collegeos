<?php
require_once 'config/config.php';
require_once 'classes/User.php';
require_once 'includes/auth.php';

if (isset($_SESSION['user_logged_in'])) {
    redirectByRole($_SESSION['user_role']);
}

$userModel = new User();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = $userModel->submitPasswordResetRequest([
        'last_name' => $_POST['last_name'] ?? '',
        'first_name' => $_POST['first_name'] ?? '',
        'middle_name' => $_POST['middle_name'] ?? '',
        'phone' => $_POST['phone'] ?? '',
    ]);
    if ($result['success']) {
        $success = $result['message'];
    } else {
        $error = $result['error'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Восстановление пароля — <?php echo htmlspecialchars(APP_NAME); ?></title>
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
                        <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>" class="login-logo">
                    </div>
                    <h1>Восстановить пароль</h1>
                    <p>Укажите ФИО и телефон — заявка уйдёт администратору</p>
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

                <?php if (!$success): ?>
                <div class="login-form">
                    <p class="login-form-hint">Администратор получит заявку и поможет восстановить доступ к учётной записи.</p>
                    <form method="POST">
                        <div class="row g-2">
                            <div class="col-md-4">
                                <div class="login-field">
                                    <label for="last_name">Фамилия *</label>
                                    <input type="text" class="form-control login-plain-input" id="last_name" name="last_name" required
                                           value="<?php echo htmlspecialchars($_POST['last_name'] ?? ''); ?>"
                                           autocomplete="family-name">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="login-field">
                                    <label for="first_name">Имя *</label>
                                    <input type="text" class="form-control login-plain-input" id="first_name" name="first_name" required
                                           value="<?php echo htmlspecialchars($_POST['first_name'] ?? ''); ?>"
                                           autocomplete="given-name">
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="login-field">
                                    <label for="middle_name">Отчество</label>
                                    <input type="text" class="form-control login-plain-input" id="middle_name" name="middle_name"
                                           value="<?php echo htmlspecialchars($_POST['middle_name'] ?? ''); ?>"
                                           autocomplete="additional-name">
                                </div>
                            </div>
                        </div>
                        <div class="login-field">
                            <label for="phone">Номер телефона *</label>
                            <div class="login-input-wrap">
                                <i class="bi bi-telephone input-icon"></i>
                                <input type="tel" id="phone" name="phone" required
                                       placeholder="+7 (___) ___-__-__"
                                       value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>"
                                       autocomplete="tel">
                            </div>
                        </div>
                        <button type="submit" class="login-submit">
                            <i class="bi bi-send"></i>
                            <span>Отправить заявку</span>
                        </button>
                    </form>
                </div>
                <?php endif; ?>

                <div class="login-back-link">
                    <a href="login.php"><i class="bi bi-arrow-left"></i> Вернуться ко входу</a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
