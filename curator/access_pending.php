<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../includes/auth.php';

checkAuth();

$current = getCurrentUser();
if (($current['role'] ?? '') !== 'curator') {
    redirectByRole($current['role']);
}

$userModel = new User();
$profile = $userModel->getUserById((int) $current['id']);

if (!$profile) {
    header('Location: ../logout.php');
    exit;
}

if (!empty($profile['curator_portal_access'] ?? null)) {
    header('Location: dashboard.php');
    exit;
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'request_access') {
    if ($userModel->requestCuratorPortalAccess((int) $current['id'])) {
        $message = 'Заявка отправлена. Администратор рассмотрит её и при необходимости откроет доступ к порталу для вашей учётной записи с ролью «Куратор».';
        $profile = $userModel->getUserById((int) $current['id']);
    } else {
        $error = 'Не удалось отправить заявку. Попробуйте позже.';
    }
}

$pending = !empty($profile['curator_access_request_pending'] ?? null);
$requested_at = $profile['curator_access_requested_at'] ?? null;
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Доступ к порталу — <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
    <style>
        body.curator-app {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--cur-bg, #f4f6fb);
            font-family: system-ui, -apple-system, sans-serif;
        }

        .access-pending-wrap {
            width: 100%;
            max-width: 440px;
            padding: 1rem;
        }

        .card-pending {
            border: 1px solid #e5e9f2;
            border-radius: 16px;
            box-shadow: 0 8px 30px rgba(15, 23, 42, 0.08);
        }
    </style>
</head>

<body class="curator-app">
    <div class="access-pending-wrap">
        <div class="card card-pending">
            <div class="card-body p-4 p-md-5">
                <div class="text-center mb-4">
                    <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>" style="width: 96px; height: 96px; object-fit: contain; border-radius: 16px; background: #fff; padding: 0.65rem; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);">
                    <h1 class="h4 mt-3 mb-2">Доступ к порталу куратора</h1>
                    <p class="text-muted text-start small mb-0">
                        Вы успешно вошли в систему под ролью <strong>«Куратор»</strong> — она не меняется.
                        Администратор закрыл <strong>вход в рабочий раздел</strong> (студенты, группы и т.д.),
                        поэтому сюда вы попали сразу после авторизации.
                    </p>
                    <p class="text-muted text-start small mt-3 mb-0">
                        Чтобы снова работать в портале, отправьте заявку на <strong>открытие доступа куратора</strong>.
                        Рассматривает заявку <strong>администратор</strong> (в разделе «Пользователи» включается «Доступ к порталу куратора»).
                    </p>
                </div>

                <?php if ($message): ?>
                    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <?php if ($pending): ?>
                    <div class="alert alert-info d-flex align-items-start gap-2 mb-0">
                        <i class="bi bi-hourglass-split mt-1"></i>
                        <div>
                            <strong>Заявка на рассмотрении.</strong>
                            <?php if ($requested_at): ?>
                                <div class="small mt-1 text-muted">
                                    Отправлено: <?php echo htmlspecialchars(date('d.m.Y H:i', strtotime($requested_at))); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php else: ?>
                    <form method="post" class="d-grid gap-2">
                        <input type="hidden" name="action" value="request_access">
                        <button type="submit" class="btn btn-primary btn-lg rounded-3">
                            <i class="bi bi-send me-2"></i>Отправить заявку на доступ
                        </button>
                    </form>
                <?php endif; ?>

                <div class="text-center mt-4">
                    <a href="../logout.php" class="text-decoration-none text-muted small">
                        <i class="bi bi-box-arrow-right me-1"></i>Выйти
                    </a>
                </div>
            </div>
        </div>
    </div>
    <?php appThemeScript(); ?>
</body>

</html>
