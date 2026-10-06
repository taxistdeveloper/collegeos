<?php
require_once '../config/config.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['cos']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_spravki');

$current_user = getCurrentUser();
$page_title = 'Главная';
require_once 'includes/header.php';
?>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h1 class="h4 mb-3"><i class="bi bi-house-fill text-primary me-2"></i>Добро пожаловать</h1>
                <p class="text-muted mb-4">
                    Панель центра обслуживания студентов. Здесь вы можете работать со справками.
                </p>
                <a href="spravki.php" class="btn btn-primary">
                    <i class="bi bi-file-earmark-text me-2"></i>Перейти к справкам
                </a>
            </div>
        </div>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
