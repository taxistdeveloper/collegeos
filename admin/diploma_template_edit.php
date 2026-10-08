<?php
require_once '../config/config.php';
require_once '../classes/DiplomaSupplement.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$diploma = new DiplomaSupplement();
$id = isset($_REQUEST['id']) ? (int)$_REQUEST['id'] : 0;
$error = '';
$notice = isset($_GET['saved']) ? 'Шаблон сохранён' : '';

$form = [
    'title' => '',
    'institution' => DiplomaSupplement::defaultInstitution(),
    'specialty_code' => '',
    'specialty_name' => '',
    'qualification_code' => '',
    'qualification_name' => '',
];
$editorRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['title'] = trim((string)($_POST['title'] ?? ''));
    $form['institution'] = trim((string)($_POST['institution'] ?? ''));
    $form['specialty_code'] = trim((string)($_POST['specialty_code'] ?? ''));
    $form['specialty_name'] = trim((string)($_POST['specialty_name'] ?? ''));
    $form['qualification_code'] = trim((string)($_POST['qualification_code'] ?? ''));
    $form['qualification_name'] = trim((string)($_POST['qualification_name'] ?? ''));
    $postedRows = json_decode((string)($_POST['rows_json'] ?? '[]'), true);
    $editorRows = is_array($postedRows) ? $postedRows : [];

    $result = $diploma->saveTemplate($id, $_POST, DiplomaSupplement::parseRowsJson($_POST['rows_json'] ?? '[]', false));
    if (!empty($result['ok'])) {
        header('Location: diploma_template_edit.php?id=' . (int)$result['id'] . '&saved=1');
        exit;
    }
    $error = $result['error'] ?? 'Не удалось сохранить шаблон';
} elseif ($id > 0) {
    $existing = $diploma->getTemplate($id);
    if (!$existing) {
        header('Location: diploma_templates.php');
        exit;
    }
    $form['title'] = $existing['title'];
    $form['institution'] = $existing['institution'];
    $form['specialty_code'] = $existing['specialty_code'];
    $form['specialty_name'] = $existing['specialty_name'];
    $form['qualification_code'] = $existing['qualification_code'];
    $form['qualification_name'] = $existing['qualification_name'];
    $editorRows = DiplomaSupplement::rowsToEditor($existing['rows'], false);
}

$page_title = $id > 0 ? 'Шаблон приложения' : 'Новый шаблон';
$active_page = 'diplomas';
include 'includes/admin_header.php';
?>
<link href="assets/css/diploma.css" rel="stylesheet">

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo $id > 0 ? 'Шаблон дисциплин' : 'Новый шаблон'; ?></h1>
        <p class="page-subtitle">Список дисциплин специальности без оценок</p>
    </div>
    <div class="page-actions">
        <a href="diploma_templates.php" class="btn"><i class="bi bi-arrow-left"></i> <span>К шаблонам</span></a>
    </div>
</div>

<?php if ($notice): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert" style="background: rgba(16, 185, 129, 0.1); color: var(--success); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: var(--radius);">
        <i class="bi bi-check-circle-fill me-2"></i><?php echo htmlspecialchars($notice); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert" style="background: rgba(239, 68, 68, 0.1); color: var(--danger); border: 1px solid rgba(239, 68, 68, 0.2); border-radius: var(--radius);">
        <i class="bi bi-exclamation-triangle-fill me-2"></i><?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<form method="post" id="diplomaForm">
    <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
    <input type="hidden" name="rows_json" id="rows_json" value="">

    <div class="card mb-3">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="title">Название шаблона</label>
                    <input type="text" class="form-control" id="title" name="title" required value="<?php echo htmlspecialchars($form['title']); ?>" placeholder="Электрооборудование, выпуск 2024">
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="institution">Учебное заведение</label>
                    <input type="text" class="form-control" id="institution" name="institution" value="<?php echo htmlspecialchars($form['institution']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="specialty_code">Код специальности</label>
                    <input type="text" class="form-control" id="specialty_code" name="specialty_code" value="<?php echo htmlspecialchars($form['specialty_code']); ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label" for="specialty_name">Специальность</label>
                    <input type="text" class="form-control" id="specialty_name" name="specialty_name" value="<?php echo htmlspecialchars($form['specialty_name']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="qualification_code">Код квалификации</label>
                    <input type="text" class="form-control" id="qualification_code" name="qualification_code" value="<?php echo htmlspecialchars($form['qualification_code']); ?>">
                </div>
                <div class="col-md-9">
                    <label class="form-label" for="qualification_name">Квалификация</label>
                    <input type="text" class="form-control" id="qualification_name" name="qualification_name" value="<?php echo htmlspecialchars($form['qualification_name']); ?>">
                </div>
            </div>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title"><i class="bi bi-list-ol"></i> Дисциплины</h2>
            <div class="d-flex gap-2">
                <button type="button" class="btn btn-sm btn-outline" id="addGrade">Дисциплина</button>
                <button type="button" class="btn btn-sm btn-outline" id="addPass">Зачёт</button>
                <button type="button" class="btn btn-sm btn-outline" id="addSection">Раздел</button>
            </div>
        </div>
        <div class="card-body" style="padding-top: 0.5rem;">
            <p style="color: var(--text-secondary); font-size: 0.8125rem;">
                Раздел — заголовок вроде «Профессиональные модули». Зачёт — факультатив без балла. Кредиты считаются из часов: 24 часа = 1 кредит.
            </p>
            <div class="table-wrapper">
                <table class="diploma-editor-table">
                    <thead>
                        <tr>
                            <th></th>
                            <th>Тип</th>
                            <th>Наименование</th>
                            <th>Часы</th>
                            <th>Кредиты</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="diplomaRows"></tbody>
                </table>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <span>Сохранить</span></button>
</form>

<script src="assets/js/diploma-rows.js"></script>
<script>
var diplomaEditor = DiplomaRows.init({
    tableBody: document.getElementById('diplomaRows'),
    jsonInput: document.getElementById('rows_json'),
    mode: 'template',
    rows: <?php echo json_encode($editorRows, JSON_UNESCAPED_UNICODE); ?>
});
document.getElementById('addGrade').addEventListener('click', function () { diplomaEditor.add('grade'); });
document.getElementById('addPass').addEventListener('click', function () { diplomaEditor.add('pass'); });
document.getElementById('addSection').addEventListener('click', function () { diplomaEditor.add('section'); });
</script>

<?php include 'includes/admin_footer.php'; ?>
