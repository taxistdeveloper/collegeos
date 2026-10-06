<?php
require_once '../config/config.php';
require_once '../classes/User.php';

$message = '';
$error = '';
$success = false;

// Обработка сброса пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($new_password)) {
        $error = 'Введите новый пароль';
    } elseif (strlen($new_password) < 6) {
        $error = 'Пароль должен содержать минимум 6 символов';
    } elseif ($new_password !== $confirm_password) {
        $error = 'Пароли не совпадают';
    } else {
        $user = new User();

        // Находим пользователя с логином "admin"
        $admin = $user->getUserByLogin('admin');

        if ($admin) {
            // Сбрасываем пароль
            if ($user->changePassword($admin['id'], $new_password)) {
                $message = 'Пароль администратора успешно сброшен!';
                $success = true;
            } else {
                $error = 'Ошибка при сбросе пароля';
            }
        } else {
            $error = 'Пользователь admin не найден в системе';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Сброс пароля администратора - <?php echo APP_NAME; ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Poppins', sans-serif;
            background: linear-gradient(135deg, #1e3c72 0%, #2a5298 50%, #7e22ce 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }

        /* Плавающие круги на фоне */
        body::before,
        body::after {
            content: '';
            position: absolute;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.05);
        }

        body::before {
            width: 500px;
            height: 500px;
            top: -250px;
            right: -250px;
            animation: float 15s ease-in-out infinite;
        }

        body::after {
            width: 300px;
            height: 300px;
            bottom: -150px;
            left: -150px;
            animation: float 20s ease-in-out infinite reverse;
        }

        @keyframes float {

            0%,
            100% {
                transform: translate(0, 0);
            }

            50% {
                transform: translate(50px, 50px);
            }
        }

        .reset-wrapper {
            position: relative;
            z-index: 1;
            animation: slideIn 0.8s ease-out;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .reset-container {
            background: white;
            border-radius: 24px;
            box-shadow: 0 30px 90px rgba(0, 0, 0, 0.4);
            padding: 50px 45px;
            width: 100%;
            max-width: 460px;
        }

        h1 {
            font-size: 26px;
            font-weight: 600;
            color: #2d3748;
            margin-bottom: 8px;
            text-align: center;
        }

        .subtitle {
            font-size: 14px;
            color: #718096;
            text-align: center;
            margin-bottom: 35px;
        }

        .icon-container {
            text-align: center;
            margin-bottom: 30px;
        }

        .icon-container .icon {
            font-size: 64px;
            display: inline-block;
            animation: pulse 2s ease-in-out infinite;
        }

        @keyframes pulse {

            0%,
            100% {
                transform: scale(1);
            }

            50% {
                transform: scale(1.1);
            }
        }

        .form-group {
            margin-bottom: 22px;
        }

        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #4a5568;
            margin-bottom: 10px;
        }

        .input-wrapper {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 20px;
            z-index: 1;
        }

        input[type="password"] {
            width: 100%;
            padding: 16px 20px 16px 55px;
            font-size: 15px;
            font-family: inherit;
            border: 2px solid #e2e8f0;
            border-radius: 14px;
            transition: all 0.3s ease;
            background: #f8f9fa;
        }

        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            background: white;
            box-shadow: 0 0 0 5px rgba(102, 126, 234, 0.1);
        }

        input::placeholder {
            color: #cbd5e0;
        }

        .password-wrapper {
            position: relative;
        }

        .toggle-password {
            position: absolute;
            right: 18px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            font-size: 22px;
            user-select: none;
            transition: all 0.3s;
            z-index: 2;
        }

        .toggle-password:hover {
            transform: translateY(-50%) scale(1.15);
        }

        .btn-group {
            margin-top: 30px;
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        button {
            width: 100%;
            padding: 16px 24px;
            font-size: 15px;
            font-weight: 600;
            font-family: inherit;
            border: none;
            border-radius: 14px;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }

        .btn-reset {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
        }

        .btn-reset:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.6);
        }

        .btn-reset:active {
            transform: translateY(-1px);
        }

        .btn-back {
            background: transparent;
            color: #667eea;
            border: 2px solid #e2e8f0;
        }

        .btn-back:hover {
            background: #f8f9fa;
            border-color: #667eea;
        }

        .error-message {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 14px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            box-shadow: 0 6px 20px rgba(255, 107, 107, 0.4);
            animation: shake 0.5s ease-in-out;
        }

        .success-message {
            background: linear-gradient(135deg, #48bb78 0%, #38a169 100%);
            color: white;
            padding: 16px 20px;
            border-radius: 14px;
            margin-bottom: 25px;
            font-size: 14px;
            font-weight: 500;
            text-align: center;
            box-shadow: 0 6px 20px rgba(72, 187, 120, 0.4);
        }

        @keyframes shake {

            0%,
            100% {
                transform: translateX(0);
            }

            25% {
                transform: translateX(-10px);
            }

            75% {
                transform: translateX(10px);
            }
        }

        .footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 2px solid #f0f0f0;
        }

        .footer p {
            font-size: 13px;
            color: #a0aec0;
        }

        /* Адаптивность */
        @media (max-width: 480px) {
            .reset-container {
                padding: 40px 30px;
            }

            h1 {
                font-size: 23px;
            }
        }

        /* Эффект загрузки */
        .btn-reset.loading {
            pointer-events: none;
            opacity: 0.8;
        }

        .btn-reset.loading::after {
            content: '';
            position: absolute;
            width: 18px;
            height: 18px;
            top: 50%;
            left: 50%;
            margin-left: -9px;
            margin-top: -9px;
            border: 3px solid rgba(255, 255, 255, 0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        @keyframes spin {
            to {
                transform: rotate(360deg);
            }
        }
    </style>
</head>

<body>
    <div class="reset-wrapper">
        <div class="reset-container">
            <div class="icon-container">
                <div class="icon">🔑</div>
            </div>

            <h1>Сброс пароля администратора</h1>
            <p class="subtitle">Введите новый пароль для пользователя admin</p>

            <?php if ($error): ?>
                <div class="error-message">
                    ⚠️ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <?php if ($success && $message): ?>
                <div class="success-message">
                    ✅ <?php echo htmlspecialchars($message); ?>
                </div>
                <div class="btn-group">
                    <a href="login.php" class="btn-back" style="text-decoration: none; display: block; text-align: center;">
                        Перейти к входу
                    </a>
                </div>
            <?php else: ?>
                <form method="POST" id="resetForm">
                    <div class="form-group">
                        <label for="new_password">Новый пароль</label>
                        <div class="input-wrapper password-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" id="new_password" name="new_password" required autocomplete="new-password" placeholder="Введите новый пароль" minlength="6">
                            <span class="toggle-password" onclick="togglePassword('new_password', this)" title="Показать/Скрыть пароль">👁️</span>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="confirm_password">Подтвердите пароль</label>
                        <div class="input-wrapper password-wrapper">
                            <span class="input-icon">🔒</span>
                            <input type="password" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="Повторите пароль" minlength="6">
                            <span class="toggle-password" onclick="togglePassword('confirm_password', this)" title="Показать/Скрыть пароль">👁️</span>
                        </div>
                    </div>

                    <div class="btn-group">
                        <button type="submit" class="btn-reset">
                            Сбросить пароль
                        </button>
                        <a href="login.php" class="btn-back" style="text-decoration: none; display: block; text-align: center;">
                            Вернуться к входу
                        </a>
                    </div>
                </form>
            <?php endif; ?>

            <div class="footer">
                <p>🔐 Защищенное соединение</p>
            </div>
        </div>
    </div>

    <script>
        function togglePassword(inputId, toggleIcon) {
            const passwordInput = document.getElementById(inputId);

            if (passwordInput.type === 'password') {
                passwordInput.type = 'text';
                toggleIcon.textContent = '🙈';
                toggleIcon.title = 'Скрыть пароль';
            } else {
                passwordInput.type = 'password';
                toggleIcon.textContent = '👁️';
                toggleIcon.title = 'Показать пароль';
            }
        }

        // Эффект загрузки при отправке формы
        document.getElementById('resetForm')?.addEventListener('submit', function(e) {
            const newPassword = document.getElementById('new_password').value;
            const confirmPassword = document.getElementById('confirm_password').value;

            if (newPassword !== confirmPassword) {
                e.preventDefault();
                alert('Пароли не совпадают!');
                return false;
            }

            if (newPassword.length < 6) {
                e.preventDefault();
                alert('Пароль должен содержать минимум 6 символов!');
                return false;
            }

            const submitBtn = this.querySelector('.btn-reset');
            submitBtn.classList.add('loading');
            submitBtn.textContent = '';
        });

        // Автофокус на первое поле при загрузке
        window.addEventListener('load', function() {
            const passwordInput = document.getElementById('new_password');
            if (passwordInput) {
                passwordInput.focus();
            }
        });
    </script>
</body>

</html>
