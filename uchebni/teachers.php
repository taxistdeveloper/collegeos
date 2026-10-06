<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_teachers');

$uchebni = new Uchebni();
$current_user = getCurrentUser();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $user_id = !empty($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $portal_user = $user_id ? $uchebni->getPortalUserById($user_id) : null;

    if ($action === 'add') {
        if (!$portal_user) {
            $error = 'Выберите пользователя портала по ФИО';
        } else {
            $dup = $uchebni->getTeacherByUserId($user_id, false);
            if ($dup) {
                $error = 'Этот пользователь уже есть в базе преподавателей'
                    . (!empty($dup['is_active']) ? '' : ' (неактивен)');
            } else {
                $id = $uchebni->addTeacher([
                    'user_id' => $user_id,
                    'last_name' => $portal_user['last_name'],
                    'first_name' => $portal_user['first_name'],
                    'middle_name' => $portal_user['middle_name'] ?? '',
                    'phone' => $portal_user['phone'] ?? '',
                    'email' => $portal_user['email'] ?? '',
                    'max_hours_per_week' => 36,
                    'specialization' => '',
                ]);
                $message = $id ? 'Преподаватель добавлен!' : 'Ошибка при добавлении';
            }
        }
    }

    if ($action === 'edit') {
        $id = (int)$_POST['teacher_id'];
        $existing = $uchebni->getTeacherById($id);
        if (!$portal_user) {
            $error = 'Выберите пользователя портала по ФИО';
        } elseif (!$existing) {
            $error = 'Преподаватель не найден';
        } else {
            $dup = $uchebni->getTeacherByUserId($user_id, false);
            if ($dup && (int)$dup['id'] !== $id) {
                $error = 'Этот пользователь уже привязан к другому преподавателю';
            } else {
                $ok = $uchebni->updateTeacher($id, [
                    'user_id' => $user_id,
                    'last_name' => $portal_user['last_name'],
                    'first_name' => $portal_user['first_name'],
                    'middle_name' => $portal_user['middle_name'] ?? '',
                    'phone' => $portal_user['phone'] ?? '',
                    'email' => $portal_user['email'] ?? '',
                    'max_hours_per_week' => (int)($existing['max_hours_per_week'] ?? 36),
                    'specialization' => $existing['specialization'] ?? '',
                    'is_active' => isset($_POST['is_active']) ? 1 : 0,
                ]);
                $message = $ok ? 'Данные обновлены!' : 'Ошибка при обновлении';
            }
        }
    }

    if ($action === 'delete') {
        $result = $uchebni->deleteTeacher((int)($_POST['teacher_id'] ?? 0));
        if (!empty($result['success'])) {
            $message = 'Преподаватель удалён';
        } else {
            $error = $result['error'] ?? 'Ошибка при удалении';
        }
    }
}

$teachers = $uchebni->getTeachers(false);
$period = $uchebni->getCurrentPeriod();
$period_id = $period ? (int)$period['id'] : 0;

$page_title = 'Преподаватели';
$page_subtitle = count($teachers) . ' записей';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-end gap-2 mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTeacherModal">
        <i class="bi bi-person-plus me-1"></i>Добавить преподавателя
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><i class="bi bi-check-circle me-2"></i><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if ($error): ?>
<div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($error); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0 align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ФИО</th>
                        <th>Дисциплины</th>
                        <th>Нагрузка</th>
                        <th>Контакты</th>
                        <th>Портал</th>
                        <th>Статус</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($teachers as $t):
                        $fio = Uchebni::formatFio($t);
                        $workload = $period_id ? $uchebni->getTeacherWorkload($t['id'], $period_id) : 0;
                        $subject_names = $uchebni->getTeacherSubjectNames($t['id']);
                    ?>
                    <tr class="<?php echo $t['is_active'] ? '' : 'table-secondary'; ?>">
                        <td><strong><?php echo htmlspecialchars($fio); ?></strong></td>
                        <td class="small">
                            <?php echo $subject_names ? htmlspecialchars(implode(', ', $subject_names)) : '—'; ?>
                        </td>
                        <td>
                            <span class="badge bg-primary"><?php echo (int)$workload; ?> ч/нед</span>
                        </td>
                        <td class="small">
                            <?php if ($t['phone']): ?><div><?php echo htmlspecialchars($t['phone']); ?></div><?php endif; ?>
                            <?php if ($t['email']): ?><div class="text-muted"><?php echo htmlspecialchars($t['email']); ?></div><?php endif; ?>
                            <?php if (!$t['phone'] && !$t['email']): ?>—<?php endif; ?>
                        </td>
                        <td><?php echo $t['user_login'] ? htmlspecialchars($t['user_login']) : '—'; ?></td>
                        <td><?php echo $t['is_active'] ? '<span class="badge bg-success">Активен</span>' : '<span class="badge bg-secondary">Неактивен</span>'; ?></td>
                        <td>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-outline-primary btn-edit-teacher"
                                    data-teacher='<?php echo htmlspecialchars(json_encode($t, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                    data-subjects='<?php echo htmlspecialchars(json_encode($subject_names, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'
                                    title="Редактировать">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <form method="post" class="d-inline" onsubmit="return confirm('Удалить преподавателя «<?php echo htmlspecialchars($fio, ENT_QUOTES); ?>»?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="teacher_id" value="<?php echo (int)$t['id']; ?>">
                                    <button type="submit" class="btn btn-outline-danger" title="Удалить">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($teachers)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Преподаватели не добавлены</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Модал добавления -->
<div class="modal fade" id="addTeacherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="addTeacherForm">
            <input type="hidden" name="action" value="add">
            <div class="modal-header">
                <h5 class="modal-title">Новый преподаватель</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php include __DIR__ . '/includes/teacher_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<!-- Модал редактирования -->
<div class="modal fade" id="editTeacherModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <form method="post" class="modal-content" id="editTeacherForm">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="teacher_id" id="edit_teacher_id">
            <div class="modal-header">
                <h5 class="modal-title">Редактирование</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <?php $is_edit = true; include __DIR__ . '/includes/teacher_form_fields.php'; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button>
                <button type="submit" class="btn btn-primary">Сохранить</button>
            </div>
        </form>
    </div>
</div>

<script>
function escapeHtml(str) {
    return String(str || '').replace(/[&<>"']/g, function(ch) {
        return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
    });
}

function formatUserFio(u) {
    return [u.last_name, u.first_name, u.middle_name].filter(Boolean).join(' ');
}

function initPortalUserSearch(opts) {
    const input = document.getElementById(opts.searchId);
    const hidden = document.getElementById(opts.hiddenId);
    const results = document.getElementById(opts.resultsId);
    const hint = document.getElementById(opts.hintId);
    if (!input || !hidden || !results) return;

    let timer = null;

    function setSelected(user) {
        hidden.value = user.id;
        input.value = formatUserFio(user);
        if (hint) {
            const meta = [user.login, user.role_name].filter(Boolean).join(' · ');
            hint.innerHTML = '<span class="text-success">Выбран:</span> ' + escapeHtml(formatUserFio(user))
                + (meta ? ' <span class="text-muted">(' + escapeHtml(meta) + ')</span>' : '');
        }
        results.classList.add('d-none');
        results.innerHTML = '';
    }

    input.addEventListener('input', function() {
        clearTimeout(timer);
        hidden.value = '';
        if (hint) hint.textContent = 'Любой пользователь портала: куратор, преподаватель и др. — роль менять не нужно';
        const q = input.value.trim();
        if (q.length < 2) {
            results.innerHTML = '';
            results.classList.add('d-none');
            return;
        }
        timer = setTimeout(function() {
            let url = 'api/search_users.php?q=' + encodeURIComponent(q);
            const excludeId = typeof opts.getExcludeTeacherId === 'function' ? opts.getExcludeTeacherId() : 0;
            if (excludeId) url += '&exclude_teacher_id=' + encodeURIComponent(excludeId);

            fetch(url)
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.users.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = data.users.map(u => {
                        const fio = formatUserFio(u);
                        const meta = [u.login, u.role_name].filter(Boolean).join(' · ');
                        const already = !!u.already_teacher;
                        const statusNote = already
                            ? (u.teacher_is_active == 0
                                ? 'Уже есть в базе (неактивен)'
                                : 'Уже есть в базе')
                            : '';

                        if (already) {
                            return '<div class="list-group-item list-group-item-secondary text-start">'
                                + '<div class="d-flex justify-content-between align-items-start gap-2">'
                                + '<div><strong>' + escapeHtml(fio) + '</strong><br>'
                                + '<small class="text-muted">' + escapeHtml(meta) + '</small></div>'
                                + '<span class="badge text-bg-warning text-dark flex-shrink-0">' + escapeHtml(statusNote) + '</span>'
                                + '</div></div>';
                        }

                        return '<button type="button" class="list-group-item list-group-item-action text-start"'
                            + ' data-id="' + escapeHtml(u.id) + '"'
                            + ' data-fio="' + escapeHtml(fio) + '"'
                            + ' data-login="' + escapeHtml(u.login || '') + '"'
                            + ' data-role="' + escapeHtml(u.role_name || '') + '">'
                            + '<strong>' + escapeHtml(fio) + '</strong><br>'
                            + '<small class="text-muted">' + escapeHtml(meta) + '</small></button>';
                    }).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('.list-group-item-action').forEach(btn => {
                        btn.addEventListener('click', function() {
                            const parts = (this.dataset.fio || '').trim().split(/\s+/);
                            setSelected({
                                id: this.dataset.id,
                                last_name: parts[0] || '',
                                first_name: parts[1] || '',
                                middle_name: parts.slice(2).join(' '),
                                login: this.dataset.login || '',
                                role_name: this.dataset.role || ''
                            });
                        });
                    });
                })
                .catch(function() {
                    results.innerHTML = '<div class="list-group-item text-danger small">Ошибка поиска</div>';
                    results.classList.remove('d-none');
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.classList.add('d-none');
        }
    });

    return { setSelected: setSelected };
}

const addSearch = initPortalUserSearch({
    searchId: 'user_search',
    hiddenId: 'user_id',
    resultsId: 'user_results',
    hintId: 'user_hint'
});

const editSearch = initPortalUserSearch({
    searchId: 'edit_user_search',
    hiddenId: 'edit_user_id',
    resultsId: 'edit_user_results',
    hintId: 'edit_user_hint',
    getExcludeTeacherId: function() {
        return document.getElementById('edit_teacher_id').value || 0;
    }
});

const defaultUserHint = 'Любой пользователь портала: куратор, преподаватель и др. — роль менять не нужно';

document.getElementById('addTeacherModal').addEventListener('hidden.bs.modal', function() {
    document.getElementById('addTeacherForm').reset();
    document.getElementById('user_id').value = '';
    document.getElementById('user_hint').textContent = defaultUserHint;
    document.getElementById('user_results').classList.add('d-none');
});

document.querySelectorAll('.btn-edit-teacher').forEach(btn => {
    btn.addEventListener('click', function() {
        const t = JSON.parse(this.dataset.teacher);
        const subjects = JSON.parse(this.dataset.subjects || '[]');
        document.getElementById('edit_teacher_id').value = t.id;
        document.getElementById('edit_is_active').checked = t.is_active == 1;

        const subjectsEl = document.getElementById('edit_subjects_readonly');
        if (subjectsEl) {
            if (subjects.length) {
                subjectsEl.innerHTML = subjects.map(function(name) {
                    return '<span class="badge text-bg-secondary me-1 mb-1">' + escapeHtml(name) + '</span>';
                }).join('') + '<div class="form-text mt-1">Изменить можно в разделе «Дисциплины»</div>';
            } else {
                subjectsEl.innerHTML = 'Пока нет. Назначьте в разделе «Дисциплины»';
            }
        }

        if (t.user_id) {
            editSearch.setSelected({
                id: t.user_id,
                last_name: t.last_name,
                first_name: t.first_name,
                middle_name: t.middle_name || '',
                login: t.user_login || '',
                role_name: ''
            });
        } else {
            document.getElementById('edit_user_id').value = '';
            document.getElementById('edit_user_search').value = '';
            document.getElementById('edit_user_hint').textContent = defaultUserHint;
        }

        new bootstrap.Modal(document.getElementById('editTeacherModal')).show();
    });
});

document.getElementById('addTeacherForm').addEventListener('submit', function(e) {
    if (!document.getElementById('user_id').value) {
        e.preventDefault();
        alert('Выберите преподавателя из списка поиска');
    }
});

document.getElementById('editTeacherForm').addEventListener('submit', function(e) {
    if (!document.getElementById('edit_user_id').value) {
        e.preventDefault();
        alert('Выберите преподавателя из списка поиска');
    }
});
</script>

<?php require_once 'includes/footer.php'; ?>
