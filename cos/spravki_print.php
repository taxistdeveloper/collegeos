<?php
require_once '../config/config.php';
require_once '../classes/Spravka.php';
require_once '../classes/SpravkaTemplate.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

$is_admin_session = !empty($_SESSION['admin_logged_in']);
$is_user_session = !empty($_SESSION['user_logged_in']);

if (!$is_admin_session && !$is_user_session) {
    header('Location: ../login.php');
    exit;
}

if ($is_user_session) {
    $permissionChecker = new PermissionChecker();
    $permissionChecker->requirePermission('view_spravki');
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id < 1) {
    header('HTTP/1.1 400 Bad Request');
    echo 'Не указана справка';
    exit;
}

$spravka = new Spravka();
$row = $spravka->getById($id);

if (!$row) {
    header('HTTP/1.1 404 Not Found');
    echo 'Справка не найдена';
    exit;
}

$types = Spravka::getTypes();
$type_label = $types[$row['type']] ?? $row['type'];
$student_data = $row;
$spravka_data = [
    'issued_at' => $row['issued_at'] ?? null,
    'note' => $row['note'] ?? '',
];

$body_html = '';
if (!empty($row['template_id'])) {
    $tpl = new SpravkaTemplate();
    $rendered = $tpl->render((int)$row['template_id'], $student_data, $spravka_data);
    if ($rendered !== false) {
        $body_html = $rendered;
    }
}

if ($body_html === '') {
    $fio = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
    $body_html = '<p><strong>Тип:</strong> ' . htmlspecialchars($type_label) . '</p>'
        . '<p><strong>Студент:</strong> ' . htmlspecialchars($fio) . '</p>'
        . '<p><strong>ИИН:</strong> ' . htmlspecialchars($row['iin'] ?? '—') . '</p>'
        . '<p><strong>Группа:</strong> ' . htmlspecialchars($row['group_name'] ?? '—') . '</p>'
        . '<p><strong>Специальность:</strong> ' . htmlspecialchars($row['specialty'] ?? '—') . '</p>'
        . '<p><strong>Дата выдачи:</strong> ' . (!empty($row['issued_at']) ? date('d.m.Y', strtotime($row['issued_at'])) : '—') . '</p>'
        . (!empty($row['note']) ? '<p><strong>Примечание:</strong> ' . nl2br(htmlspecialchars($row['note'])) . '</p>' : '');
}
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Печать справки — <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        @media print {
            .no-print {
                display: none !important;
            }
        }

        body {
            font-family: "Times New Roman", Times, serif;
        }

        .print-sheet {
            max-width: 800px;
            margin: 0 auto;
            background: #fff;
            padding: 2rem;
        }
    </style>
</head>

<body>
    <div class="no-print container py-3">
        <button type="button" class="btn btn-primary" onclick="window.print()">
            <i class="bi bi-printer"></i> Печать
        </button>
        <a href="<?php echo $is_admin_session ? '../admin/spravki.php' : 'spravki.php'; ?>" class="btn btn-outline-secondary">Назад</a>
    </div>
    <div class="print-sheet border shadow-sm">
        <div class="spravka-print-body">
            <?php echo $body_html; ?>
        </div>
    </div>
</body>

</html>
