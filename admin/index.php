<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$user = new User();
$group = new Group();

$all_users = $user->getAllUsers();
$user_stats = [
    'total' => count($all_users),
    'active' => count(array_filter($all_users, function ($u) {
        return $u['is_active'];
    }))
];

$group_stats = $group->getGroupStats();
$recent_users = array_slice($all_users, 0, 5);
$recent_groups = array_slice($group->getAllGroups(), 0, 5);

$curators_count = 0;
foreach ($all_users as $u) {
    if (stripos($u['role_name'], 'куратор') !== false || stripos($u['role_name'], 'curator') !== false) {
        $curators_count++;
    }
}

$page_title = 'Дашборд';
$page_subtitle = APP_TAGLINE;
$active_page = 'dashboard';
include 'includes/admin_header.php';

$admin_name = $_SESSION['admin_name'] ?? 'Администратор';
$first_name = explode(' ', $admin_name)[0];
?>

            <div class="welcome-banner animate-fade-in">
                <div class="welcome-banner-inner">
                    <div>
                        <h1>Добро пожаловать, <?php echo htmlspecialchars($first_name); ?>!</h1>
                        <p>Управляйте пользователями, группами и справками из одной панели</p>
                    </div>
                    <div class="page-actions">
                        <a href="users.php?action=add" class="btn btn-primary">
                            <i class="bi bi-person-plus-fill"></i>
                            <span>Добавить пользователя</span>
                        </a>
                        <a href="groups.php?action=add" class="btn">
                            <i class="bi bi-plus-lg"></i>
                            <span>Создать группу</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="stat-grid">
                <div class="stat-card animate-fade-in">
                    <div class="stat-icon blue">
                        <i class="bi bi-people-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $user_stats['total']; ?></div>
                        <div class="stat-label">Всего пользователей</div>
                    </div>
                </div>

                <div class="stat-card animate-fade-in" style="animation-delay: 0.05s">
                    <div class="stat-icon green">
                        <i class="bi bi-person-check-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $user_stats['active']; ?></div>
                        <div class="stat-label">Активных пользователей</div>
                    </div>
                </div>

                <div class="stat-card animate-fade-in" style="animation-delay: 0.1s">
                    <div class="stat-icon purple">
                        <i class="bi bi-collection-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $group_stats['total_groups']; ?></div>
                        <div class="stat-label">Всего групп</div>
                    </div>
                </div>

                <div class="stat-card animate-fade-in" style="animation-delay: 0.15s">
                    <div class="stat-icon orange">
                        <i class="bi bi-mortarboard-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $group_stats['total_students']; ?></div>
                        <div class="stat-label">Студентов в группах</div>
                    </div>
                </div>

                <div class="stat-card animate-fade-in" style="animation-delay: 0.2s">
                    <div class="stat-icon cyan">
                        <i class="bi bi-person-workspace"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $curators_count; ?></div>
                        <div class="stat-label">Кураторов</div>
                    </div>
                </div>

                <div class="stat-card animate-fade-in" style="animation-delay: 0.25s">
                    <div class="stat-icon green">
                        <i class="bi bi-check-circle-fill"></i>
                    </div>
                    <div class="stat-content">
                        <div class="stat-value"><?php echo $group_stats['active_groups']; ?></div>
                        <div class="stat-label">Активных групп</div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="bi bi-clock-history"></i>
                                Последние пользователи
                            </h2>
                            <a href="users.php" class="btn btn-sm btn-outline">Все</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_users)): ?>
                                <p class="text-muted text-center py-4">Нет пользователей</p>
                            <?php else: ?>
                                <?php foreach ($recent_users as $user_item): ?>
                                    <div class="list-item">
                                        <div class="list-avatar">
                                            <i class="bi bi-person-fill"></i>
                                        </div>
                                        <div class="list-content">
                                            <div class="list-title"><?php echo htmlspecialchars($user_item['last_name'] . ' ' . $user_item['first_name']); ?></div>
                                            <div class="list-subtitle"><?php echo htmlspecialchars($user_item['login']); ?> • <?php echo htmlspecialchars($user_item['role_name']); ?></div>
                                        </div>
                                        <div class="list-meta">
                                            <span class="badge <?php echo $user_item['is_active'] ? 'badge-success' : 'badge-secondary'; ?>">
                                                <?php echo $user_item['is_active'] ? 'Активен' : 'Неактивен'; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h2 class="card-title">
                                <i class="bi bi-collection"></i>
                                Последние группы
                            </h2>
                            <a href="groups.php" class="btn btn-sm btn-outline">Все</a>
                        </div>
                        <div class="card-body">
                            <?php if (empty($recent_groups)): ?>
                                <p class="text-muted text-center py-4">Нет групп</p>
                            <?php else: ?>
                                <?php foreach ($recent_groups as $group_item): ?>
                                    <div class="list-item">
                                        <div class="list-avatar" style="background: rgba(99, 102, 241, 0.12); color: var(--purple);">
                                            <i class="bi bi-folder-fill"></i>
                                        </div>
                                        <div class="list-content">
                                            <div class="list-title"><?php echo htmlspecialchars($group_item['name']); ?></div>
                                            <div class="list-subtitle">
                                                <?php echo htmlspecialchars($group_item['specialty']); ?> •
                                                <?php echo $group_item['curator_first_name'] ? htmlspecialchars($group_item['curator_first_name'] . ' ' . $group_item['curator_last_name']) : 'Без куратора'; ?>
                                            </div>
                                        </div>
                                        <div class="list-meta">
                                            <span class="badge badge-primary">
                                                <?php echo $group_item['current_students']; ?>/<?php echo $group_item['max_students']; ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="bi bi-lightning-fill"></i>
                        Быстрые действия
                    </h2>
                </div>
                <div class="card-body">
                    <div class="quick-actions">
                        <a href="users.php?action=add" class="quick-action-btn">
                            <div class="quick-action-icon" style="background: var(--primary-light); color: var(--primary);">
                                <i class="bi bi-person-plus-fill"></i>
                            </div>
                            <span class="quick-action-text">Добавить пользователя</span>
                        </a>

                        <a href="groups.php?action=add" class="quick-action-btn">
                            <div class="quick-action-icon" style="background: rgba(16, 185, 129, 0.12); color: var(--success);">
                                <i class="bi bi-folder-plus"></i>
                            </div>
                            <span class="quick-action-text">Создать группу</span>
                        </a>

                        <a href="students.php" class="quick-action-btn">
                            <div class="quick-action-icon" style="background: rgba(99, 102, 241, 0.12); color: var(--purple);">
                                <i class="bi bi-person-badge-fill"></i>
                            </div>
                            <span class="quick-action-text">Управление студентами</span>
                        </a>

                        <button type="button" class="quick-action-btn" onclick="openImportModal()">
                            <div class="quick-action-icon" style="background: rgba(6, 182, 212, 0.12); color: var(--info);">
                                <i class="bi bi-cloud-upload-fill"></i>
                            </div>
                            <span class="quick-action-text">Импорт студентов</span>
                        </button>

                        <a href="dynamic_fields.php" class="quick-action-btn">
                            <div class="quick-action-icon" style="background: rgba(245, 158, 11, 0.12); color: var(--warning);">
                                <i class="bi bi-sliders"></i>
                            </div>
                            <span class="quick-action-text">Настройка полей</span>
                        </a>

                        <a href="reports.php" class="quick-action-btn">
                            <div class="quick-action-icon" style="background: rgba(239, 68, 68, 0.12); color: var(--danger);">
                                <i class="bi bi-graph-up"></i>
                            </div>
                            <span class="quick-action-text">Просмотр отчетов</span>
                        </a>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2 class="card-title">
                        <i class="bi bi-info-circle"></i>
                        Информация о системе
                    </h2>
                </div>
                <div class="card-body">
                    <div class="system-info">
                        <div class="system-info-item">
                            <span class="system-info-label">Версия системы</span>
                            <span class="system-info-value"><?php echo APP_VERSION; ?></span>
                        </div>
                        <div class="system-info-item">
                            <span class="system-info-label">База данных</span>
                            <span class="system-info-value">MySQL</span>
                        </div>
                        <div class="system-info-item">
                            <span class="system-info-label">PHP версия</span>
                            <span class="system-info-value"><?php echo PHP_VERSION; ?></span>
                        </div>
                        <div class="system-info-item">
                            <span class="system-info-label">Активных групп</span>
                            <span class="system-info-value"><?php echo $group_stats['active_groups']; ?></span>
                        </div>
                        <div class="system-info-item">
                            <span class="system-info-label">Средн. студентов в группе</span>
                            <span class="system-info-value"><?php echo round($group_stats['avg_students_per_group'], 1); ?></span>
                        </div>
                        <div class="system-info-item">
                            <span class="system-info-label">Последнее обновление</span>
                            <span class="system-info-value"><?php echo date('d.m.Y H:i'); ?></span>
                        </div>
                    </div>
                </div>
            </div>

    <div id="importModal" class="modal-overlay" style="display: none;">
        <div class="modal-container">
            <div class="modal-header-custom">
                <h3><i class="bi bi-cloud-upload"></i> Импорт студентов из CSV/Excel</h3>
                <button class="modal-close" type="button" onclick="closeImportModal()">&times;</button>
            </div>
            <div class="modal-body-custom">
                <div class="alert-info-custom">
                    <i class="bi bi-info-circle"></i>
                    <div>
                        <strong>Инструкции по импорту:</strong>
                        <ul>
                            <li>Поддерживаются файлы CSV и Excel (.csv, .xlsx)</li>
                            <li>Первая строка должна содержать заголовки</li>
                            <li>Обязательное поле: ИИН (12 цифр)</li>
                        </ul>
                    </div>
                </div>

                <div class="form-group-custom">
                    <label for="csvFile">Выберите файл для импорта</label>
                    <input type="file" id="csvFile" accept=".csv,.xlsx,.xls">
                    <small>Поддерживаются форматы: CSV, Excel (.xlsx, .xls)</small>
                </div>

                <div class="button-group">
                    <button class="btn btn-outline" type="button" onclick="downloadCSVTemplate()">
                        <i class="bi bi-download"></i>
                        Скачать шаблон
                    </button>
                </div>

                <div id="importProgress"></div>
            </div>
            <div class="modal-footer-custom">
                <button class="btn btn-outline" type="button" onclick="closeImportModal()">Отмена</button>
                <button class="btn btn-primary" type="button" onclick="handleCSVImport()">
                    <i class="bi bi-upload"></i>
                    Импортировать
                </button>
            </div>
        </div>
    </div>

<script>
function openImportModal() {
    document.getElementById('importModal').style.display = 'flex';
}

function closeImportModal() {
    document.getElementById('importModal').style.display = 'none';
}

function downloadCSVTemplate() {
    const csvContent = "iin;first_name;last_name;middle_name;nationality;phone;email;permanent_address_ru\n" +
        "960325350262;Айдар;Нурланов;Айдарұлы;казах;87001234567;aidar@example.com;г.Алматы ул.Абая 1";

    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'шаблон_импорта_студентов.csv';
    link.click();
}

function handleCSVImport() {
    const fileInput = document.getElementById('csvFile');
    const file = fileInput.files[0];

    if (!file) {
        alert('Пожалуйста, выберите файл для импорта');
        return;
    }

    const progressContainer = document.getElementById('importProgress');
    progressContainer.innerHTML = '<div style="background: var(--content-bg); border-radius: var(--radius); padding: 0.5rem; margin-top: 1rem;"><div style="background: var(--primary); height: 8px; border-radius: 9999px; width: 0%; transition: width 0.3s;"></div></div>';

    readStudentCsv(file).then(function (csv) {
        processCSVImport(csv, progressContainer);
    }).catch(function (error) {
        progressContainer.innerHTML = '<div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius); margin-top: 1rem;"><strong>' + error.message + '</strong></div>';
    });
}

function readStudentCsv(file) {
    return file.arrayBuffer().then(function (buffer) {
        const bytes = new Uint8Array(buffer);
        let start = 0;
        if (bytes.length >= 3 && bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
            start = 3;
        }
        const slice = bytes.subarray(start);
        try {
            return new TextDecoder('utf-8', { fatal: true }).decode(slice);
        } catch (e) {
            return new TextDecoder('windows-1251').decode(slice);
        }
    });
}

function processCSVImport(csv, progressContainer) {
    try {
        const lines = csv.split('\n').filter(line => line.trim() !== '');
        if (lines.length < 2) {
            throw new Error('CSV файл должен содержать заголовок и хотя бы одну строку данных');
        }

        const delimiter = lines[0].includes(';') ? ';' : ',';

        function parseCSVLine(line, sep) {
            const result = [];
            let current = '';
            let inQuotes = false;

            for (let i = 0; i < line.length; i++) {
                const char = line[i];
                if (char === '"') {
                    inQuotes = !inQuotes;
                } else if (char === sep && !inQuotes) {
                    result.push(current.trim());
                    current = '';
                } else {
                    current += char;
                }
            }
            result.push(current.trim());
            return result;
        }

        const headers = parseCSVLine(lines[0], delimiter).map(h => h.replace(/"/g, ''));
        const validStudents = [];
        const errors = [];

        for (let i = 1; i < lines.length; i++) {
            const line = lines[i].trim();
            if (!line) continue;

            const values = parseCSVLine(line, delimiter).map(v => v.replace(/"/g, ''));
            const studentData = {};

            headers.forEach((header, index) => {
                if (values[index]) {
                    studentData[header] = values[index].replace(/[\r\n\t\f\v]/g, '').trim();
                }
            });

            let iinField = studentData.iin || Object.values(studentData)[0];
            if (iinField) {
                studentData.iin = iinField.replace(/\D/g, '');
            }

            if (studentData.iin && studentData.iin.length === 12 && /^\d{12}$/.test(studentData.iin)) {
                validStudents.push(studentData);
            } else {
                errors.push(`Строка ${i + 1}: Неверный ИИН`);
            }
        }

        if (validStudents.length === 0) {
            throw new Error('Не найдено валидных записей');
        }

        progressContainer.querySelector('div > div').style.width = '50%';

        const formData = new FormData();
        formData.append('action', 'save_imported_data');
        formData.append('data', JSON.stringify(validStudents));

        fetch('../api/ultra_simple.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(result => {
            if (!result.success) {
                throw new Error(result.error || 'Не удалось сохранить импорт');
            }
            const failed = (result.errors || 0) + errors.length;
            progressContainer.innerHTML = `
                <div style="background: rgba(16, 185, 129, 0.1); color: var(--success); padding: 1rem; border-radius: var(--radius); margin-top: 1rem;">
                    <strong><i class="bi bi-check-circle me-2"></i>Импорт завершен!</strong>
                    <p style="margin: 0.5rem 0 0 0;">Сохранено: ${result.saved || 0} (новых ${result.inserted || 0}, обновлено ${result.updated || 0}) | Ошибок: ${failed}</p>
                </div>
            `;

            setTimeout(() => {
                closeImportModal();
                document.getElementById('csvFile').value = '';
                progressContainer.innerHTML = '';
            }, 3000);
        })
        .catch(error => {
            progressContainer.innerHTML = `
                <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius); margin-top: 1rem;">
                    <strong><i class="bi bi-exclamation-triangle me-2"></i>Ошибка</strong>
                    <p style="margin: 0.5rem 0 0 0;">${error.message}</p>
                </div>
            `;
        });

    } catch (error) {
        progressContainer.innerHTML = `
            <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius); margin-top: 1rem;">
                <strong><i class="bi bi-exclamation-triangle me-2"></i>Ошибка</strong>
                <p style="margin: 0.5rem 0 0 0;">${error.message}</p>
            </div>
        `;
    }
}
</script>

<?php include 'includes/admin_footer.php'; ?>
