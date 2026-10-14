<?php
require_once 'config/config.php';
require_once 'classes/User.php';
require_once 'includes/auth.php';

checkAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectByRole($_SESSION['user_role'] ?? '');
}

$picked = trim((string)($_POST['role'] ?? ''));
$allowed = $_SESSION['user_roles'] ?? [];
if (!is_array($allowed)) {
    $allowed = [];
}

if ($picked === '' || !in_array($picked, $allowed, true)) {
    redirectByRole($_SESSION['user_role'] ?? '');
}

if ($picked === 'curator') {
    $user = new User();
    $userData = $user->getUserById((int)$_SESSION['user_id']);
    if ($userData && !(int)($userData['curator_portal_access'] ?? 0)) {
        $_SESSION['user_role'] = 'curator';
        $_SESSION['user_role_explicit'] = true;
        $_SESSION['curator_portal_limited'] = true;
        header('Location: curator/access_pending.php');
        exit;
    }
}

unset($_SESSION['curator_portal_limited']);
applyPortalRole($picked);
redirectByRole($picked);
