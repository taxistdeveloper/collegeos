<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../includes/auth.php';

checkRole(['curator']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: my_groups.php');
    exit;
}

$group_id = isset($_POST['group_id']) ? (int)$_POST['group_id'] : 0;
$redirect = isset($_POST['redirect']) ? $_POST['redirect'] : 'my_groups.php';

$allowed_redirects = ['my_groups.php', 'group_students.php', 'group_details.php', 'graduates.php'];
if (!in_array(basename($redirect), $allowed_redirects, true)) {
    $redirect = 'my_groups.php';
}

if (!$group_id) {
    $_SESSION['error_message'] = 'Не указана группа';
    header('Location: ' . $redirect . (strpos($redirect, '?') !== false ? '&' : '?') . 'id=' . $group_id);
    exit;
}

$group = new Group();
$current_user = getCurrentUser();
$result = $group->graduateGroup($group_id, $current_user['id']);

if ($result['success']) {
    $group_info = $group->getGroupById($group_id);
    $group_name = $group_info['name'] ?? 'Группа';
    $_SESSION['success_message'] = 'Группа «' . $group_name . '» переведена в архив';
    $_SESSION['success_details'] = 'Выпускников отмечено: ' . (int)$result['graduated_count'] . '. Группа больше не отображается в активных.';
    header('Location: graduates.php');
} else {
    $_SESSION['error_message'] = $result['message'];
    header('Location: ' . $redirect . (strpos($redirect, 'group_') !== false ? '?id=' . $group_id : ''));
}

exit;
