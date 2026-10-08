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
$notice = isset($_GET['saved']) ? 'Приложение сохранено' : '';

$templatesJs = [];
foreach ($diploma->listTemplates() as $brief) {
    $full = $diploma->getTemplate((int)$brief['id']);
    if (!$full) {
        continue;
    }
    $templatesJs[] = [
        'id' => (int)$full['id'],
        'title' => $full['title'],
        'institution' => $full['institution'],
        'specialty_code' => $full['specialty_code'],
        'specialty_name' => $full['specialty_name'],
        'qualification_code' => $full['qualification_code'],
        'qualification_name' => $full['qualification_name'],
        'rows' => DiplomaSupplement::rowsToEditor($full['rows'], false),
    ];
}

$form = [
    'student_id' => 0,
    'student_label' => '',
    'student_meta' => '',
    'template_id' => isset($_GET['template_id']) ? (int)$_GET['template_id'] : 0,
    'record_number' => '',
    'display_name' => '',
    'year_from' => '',
    'year_to' => '',
    'institution' => DiplomaSupplement::defaultInstitution(),
    'specialty_code' => '',
    'specialty_name' => '',
    'qualification_code' => '',
    'qualification_name' => '',
];
$editorRows = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['student_id'] = (int)($_POST['student_id'] ?? 0);
    $form['student_label'] = trim((string)($_POST['student_label'] ?? ''));
    $form['student_meta'] = trim((string)($_POST['student_meta'] ?? ''));
    $form['template_id'] = (int)($_POST['template_id'] ?? 0);
    $form['record_number'] = trim((string)($_POST['record_number'] ?? ''));
    $form['display_name'] = trim((string)($_POST['display_name'] ?? ''));
    $form['year_from'] = trim((string)($_POST['year_from'] ?? ''));
    $form['year_to'] = trim((string)($_POST['year_to'] ?? ''));
    $form['institution'] = trim((string)($_POST['institution'] ?? ''));
    $form['specialty_code'] = trim((string)($_POST['specialty_code'] ?? ''));
    $form['specialty_name'] = trim((string)($_POST['specialty_name'] ?? ''));
    $form['qualification_code'] = trim((string)($_POST['qualification_code'] ?? ''));
    $form['qualification_name'] = trim((string)($_POST['qualification_name'] ?? ''));
    $postedRows = json_decode((string)($_POST['rows_json'] ?? '[]'), true);
    $editorRows = is_array($postedRows) ? $postedRows : [];

    $result = $diploma->saveSupplement($id, $_POST, DiplomaSupplement::parseRowsJson($_POST['rows_json'] ?? '[]', true));
    if (!empty($result['ok'])) {
        header('Location: diploma_edit.php?id=' . (int)$result['id'] . '&saved=1');
        exit;
    }
    $error = $result['error'] ?? 'Не удалось сохранить приложение';
} elseif ($id > 0) {
    $existing = $diploma->getSupplement($id);
    if (!$existing) {
        header('Location: diplomas.php');
        exit;
    }
    $form['student_id'] = (int)$existing['student_id'];
    $form['student_label'] = trim(($existing['last_name'] ?? '') . ' ' . ($existing['first_name'] ?? '') . ' ' . ($existing['middle_name'] ?? ''));
    $form['student_meta'] = trim(($existing['group_name'] ?? '') . (($existing['iin'] ?? '') !== '' ? ' · ИИН ' . $existing['iin'] : ''));
    $form['template_id'] = (int)($existing['template_id'] ?? 0);
    $form['record_number'] = $existing['record_number'];
    $form['display_name'] = $existing['display_name'];
    $form['year_from'] = $existing['year_from'] ?? '';
    $form['year_to'] = $existing['year_to'] ?? '';
    $form['institution'] = $existing['institution'];
    $form['specialty_code'] = $existing['specialty_code'];
    $form['specialty_name'] = $existing['specialty_name'];
    $form['qualification_code'] = $existing['qualification_code'];
    $form['qualification_name'] = $existing['qualification_name'];
    $editorRows = DiplomaSupplement::rowsToEditor($existing['rows'], true);
} elseif ($form['template_id'] > 0) {
    foreach ($templatesJs as $template) {
        if ((int)$template['id'] === $form['template_id']) {
            foreach (['institution', 'specialty_code', 'specialty_name', 'qualification_code', 'qualification_name'] as $key) {
                if ($template[$key] !== '') {
                    $form[$key] = $template[$key];
                }
            }
            $editorRows = $template['rows'];
            break;
        }
    }
}

$page_title = $id > 0 ? 'Приложение к диплому' : 'Новое приложение';
$active_page = 'diplomas';
include 'includes/admin_header.php';
?>
<link href="assets/css/diploma.css" rel="stylesheet">

<div class="page-header">
    <div>
        <h1 class="page-title"><?php echo $id > 0 ? 'Приложение к диплому' : 'Новое приложение'; ?></h1>
        <p class="page-subtitle">Шапка бланка и ведомость: часы, кредиты, балл, буква, GPA и оценка</p>
    </div>
    <div class="page-actions">
        <a href="diplomas.php" class="btn"><i class="bi bi-arrow-left"></i> <span>К списку</span></a>
        <?php if ($id > 0): ?>
            <a href="diploma_print.php?id=<?php echo (int)$id; ?>" class="btn" target="_blank">
                <i class="bi bi-printer"></i> <span>Печать</span>
            </a>
        <?php endif; ?>
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
    <input type="hidden" name="student_id" id="student_id" value="<?php echo (int)$form['student_id']; ?>">
    <input type="hidden" name="student_label" id="student_label" value="<?php echo htmlspecialchars($form['student_label']); ?>">
    <input type="hidden" name="student_meta" id="student_meta" value="<?php echo htmlspecialchars($form['student_meta']); ?>">
    <input type="hidden" name="rows_json" id="rows_json" value="">

    <div class="card mb-3">
        <div class="card-header">
            <h2 class="card-title"><i class="bi bi-person-badge"></i> Выпускник</h2>
        </div>
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label" for="student_search">Студент</label>
                    <div class="student-search">
                        <input type="text" class="form-control" id="student_search" placeholder="Фамилия, имя или ИИН" autocomplete="off">
                        <div class="student-suggest" id="student_suggest" hidden></div>
                    </div>
                    <div class="diploma-selected mt-2" id="student_selected" <?php echo $form['student_id'] ? '' : 'hidden'; ?>>
                        <div>
                            <strong id="student_selected_name"><?php echo htmlspecialchars($form['student_label']); ?></strong>
                            <div style="font-size: 0.8125rem; color: var(--text-secondary);" id="student_selected_meta"><?php echo htmlspecialchars($form['student_meta']); ?></div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" id="student_clear">Сменить</button>
                    </div>
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="record_number">Номер диплома (ТКБ)</label>
                    <input type="text" class="form-control" id="record_number" name="record_number" maxlength="32" value="<?php echo htmlspecialchars($form['record_number']); ?>" placeholder="ТКБ №">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="display_name">Имя на бланке</label>
                    <input type="text" class="form-control" id="display_name" name="display_name" required value="<?php echo htmlspecialchars($form['display_name']); ?>" placeholder="Фамилия Имя">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="year_from">Год начала</label>
                    <input type="text" class="form-control" id="year_from" name="year_from" inputmode="numeric" maxlength="4" value="<?php echo htmlspecialchars((string)$form['year_from']); ?>">
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="year_to">Год окончания</label>
                    <input type="text" class="form-control" id="year_to" name="year_to" inputmode="numeric" maxlength="4" value="<?php echo htmlspecialchars((string)$form['year_to']); ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="institution">Учебное заведение</label>
                    <input type="text" class="form-control" id="institution" name="institution" value="<?php echo htmlspecialchars($form['institution']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="specialty_code">Код специальности</label>
                    <input type="text" class="form-control" id="specialty_code" name="specialty_code" value="<?php echo htmlspecialchars($form['specialty_code']); ?>" placeholder="0910000">
                </div>
                <div class="col-md-9">
                    <label class="form-label" for="specialty_name">Специальность</label>
                    <input type="text" class="form-control" id="specialty_name" name="specialty_name" value="<?php echo htmlspecialchars($form['specialty_name']); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label" for="qualification_code">Код квалификации</label>
                    <input type="text" class="form-control" id="qualification_code" name="qualification_code" value="<?php echo htmlspecialchars($form['qualification_code']); ?>" placeholder="091002 2">
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
        </div>
        <div class="card-body">
            <div class="row g-2 align-items-end mb-3">
                <div class="col-md-6">
                    <label class="form-label" for="template_id">Шаблон</label>
                    <select class="form-select" id="template_id" name="template_id">
                        <option value="0">Без шаблона</option>
                        <?php foreach ($templatesJs as $template): ?>
                            <option value="<?php echo (int)$template['id']; ?>" <?php echo (int)$form['template_id'] === (int)$template['id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($template['title']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-auto">
                    <button type="button" class="btn btn-outline" id="applyTemplate">Подставить дисциплины</button>
                </div>
                <div class="col-auto ms-md-auto d-flex gap-2">
                    <button type="button" class="btn btn-outline" id="addGrade">Дисциплина</button>
                    <button type="button" class="btn btn-outline" id="addPass">Зачёт</button>
                    <button type="button" class="btn btn-outline" id="addSection">Раздел</button>
                </div>
            </div>
            <p style="color: var(--text-secondary); font-size: 0.8125rem;">
                Балл сам заполняет букву, GPA и оценку. 1 кредит считается как 24 часа. Для факультативов используйте строку «Зачёт».
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
                            <th>Балл</th>
                            <th>Буква</th>
                            <th>GPA</th>
                            <th>Оценка</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="diplomaRows"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="page-actions">
        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <span>Сохранить</span></button>
    </div>
</form>

<script src="assets/js/diploma-rows.js"></script>
<script>
var diplomaTemplates = <?php echo json_encode($templatesJs, JSON_UNESCAPED_UNICODE); ?>;
var diplomaEditor = DiplomaRows.init({
    tableBody: document.getElementById('diplomaRows'),
    jsonInput: document.getElementById('rows_json'),
    mode: 'supplement',
    scale: <?php echo json_encode(DiplomaSupplement::gradeScale(), JSON_UNESCAPED_UNICODE); ?>,
    rows: <?php echo json_encode($editorRows, JSON_UNESCAPED_UNICODE); ?>
});

document.getElementById('addGrade').addEventListener('click', function () { diplomaEditor.add('grade'); });
document.getElementById('addPass').addEventListener('click', function () { diplomaEditor.add('pass'); });
document.getElementById('addSection').addEventListener('click', function () { diplomaEditor.add('section'); });

document.getElementById('applyTemplate').addEventListener('click', function () {
    var id = document.getElementById('template_id').value;
    var template = diplomaTemplates.find(function (item) { return String(item.id) === String(id); });
    if (!template) {
        alert('Выберите шаблон');
        return;
    }
    if (diplomaEditor.count() && !confirm('Заменить текущий список дисциплин строками шаблона?')) {
        return;
    }
    ['institution', 'specialty_code', 'specialty_name', 'qualification_code', 'qualification_name'].forEach(function (key) {
        if (template[key]) {
            document.getElementById(key).value = template[key];
        }
    });
    diplomaEditor.replace(template.rows);
});

var searchInput = document.getElementById('student_search');
var suggest = document.getElementById('student_suggest');
var searchTimer = null;

function selectStudent(student) {
    document.getElementById('student_id').value = student.id;
    document.getElementById('student_label').value = student.label || '';
    document.getElementById('student_meta').value = [student.group_name, student.iin ? 'ИИН ' + student.iin : ''].filter(Boolean).join(' · ');
    document.getElementById('student_selected_name').textContent = student.label || '';
    document.getElementById('student_selected_meta').textContent = document.getElementById('student_meta').value;
    document.getElementById('student_selected').hidden = false;
    document.getElementById('display_name').value = student.display_name || '';
    document.getElementById('year_from').value = student.year_from || '';
    document.getElementById('year_to').value = student.year_to || '';
    ['institution', 'specialty_code', 'specialty_name', 'qualification_code', 'qualification_name'].forEach(function (key) {
        if (student[key]) {
            document.getElementById(key).value = student[key];
        }
    });
    suggest.hidden = true;
    searchInput.value = '';
}

searchInput.addEventListener('input', function () {
    clearTimeout(searchTimer);
    var query = searchInput.value.trim();
    if (query.length < 2) {
        suggest.hidden = true;
        return;
    }
    searchTimer = setTimeout(function () {
        fetch('search_diploma_students.php?q=' + encodeURIComponent(query))
            .then(function (response) { return response.json(); })
            .then(function (data) {
                suggest.innerHTML = '';
                (data.students || []).forEach(function (student) {
                    var button = document.createElement('button');
                    button.type = 'button';
                    button.innerHTML = '<span></span><small></small>';
                    button.querySelector('span').textContent = student.label;
                    button.querySelector('small').textContent = [student.group_name, student.iin].filter(Boolean).join(' · ');
                    button.addEventListener('click', function () { selectStudent(student); });
                    suggest.appendChild(button);
                });
                if (!suggest.childElementCount) {
                    suggest.innerHTML = '<div style="padding: 0.6rem 0.75rem; color: var(--text-secondary);">Никого не нашли</div>';
                }
                suggest.hidden = false;
            });
    }, 250);
});

document.getElementById('student_clear').addEventListener('click', function () {
    document.getElementById('student_id').value = '0';
    document.getElementById('student_selected').hidden = true;
    searchInput.focus();
});

document.addEventListener('click', function (event) {
    if (!event.target.closest('.student-search')) {
        suggest.hidden = true;
    }
});
</script>

<?php include 'includes/admin_footer.php'; ?>
