<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../includes/auth.php';

// Проверка роли куратора
checkRole(['curator']);

$current_user = getCurrentUser();

$page_title = 'Демо UI';
$page_subtitle = 'Образец компонентов панели куратора';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo APP_NAME; ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="../assets/css/style.css" rel="stylesheet">
    <link href="assets/css/curator-ui.css" rel="stylesheet">
</head>
<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php include 'includes/header.php'; ?>
        <div class="curator-content">

            <div class="curator-section-header curator-animate-fadeInUp mb-3">
                <div class="curator-action-buttons">
                    <a href="my_groups.php" class="btn btn-primary btn-sm">
                        <i class="bi bi-collection me-2"></i>Мои группы
                    </a>
                    <a href="my_students.php" class="btn btn-warning btn-sm">
                        <i class="bi bi-mortarboard me-2"></i>Мои студенты
                    </a>
                </div>
            </div>

                <!-- Статистические карточки -->
                <div class="curator-stats-grid curator-animate-fadeInUp">
                    <div class="curator-stat-card stat-primary">
                        <div class="stat-number">12</div>
                        <div class="stat-label">Всего групп</div>
                        <div class="stat-icon">
                            <i class="bi bi-collection-fill"></i>
                        </div>
                    </div>
                    <div class="curator-stat-card stat-success">
                        <div class="stat-number">245</div>
                        <div class="stat-label">Всего студентов</div>
                        <div class="stat-icon">
                            <i class="bi bi-mortarboard"></i>
                        </div>
                    </div>
                    <div class="curator-stat-card stat-warning">
                        <div class="stat-number">8</div>
                        <div class="stat-label">В академ. отпуске</div>
                        <div class="stat-icon">
                            <i class="bi bi-pause-circle-fill"></i>
                        </div>
                    </div>
                    <div class="curator-stat-card stat-info">
                        <div class="stat-number">15</div>
                        <div class="stat-label">Выпускников</div>
                        <div class="stat-icon">
                            <i class="bi bi-award-fill"></i>
                        </div>
                    </div>
                </div>

                <!-- Демо фильтров -->
                <div class="curator-filters curator-animate-fadeInUp">
                    <div class="curator-filter-row">
                        <div class="curator-form-group">
                            <label class="curator-form-label">Статус</label>
                            <select class="form-control curator-form-control curator-form-select">
                                <option value="">Все статусы</option>
                                <option value="active">Активные</option>
                                <option value="academic_leave">В академ. отпуске</option>
                                <option value="graduated">Выпускники</option>
                            </select>
                        </div>
                        <div class="curator-form-group">
                            <label class="curator-form-label">Группа</label>
                            <select class="form-control curator-form-control curator-form-select">
                                <option value="">Все группы</option>
                                <option value="1">ИТ-21-1</option>
                                <option value="2">ИТ-21-2</option>
                                <option value="3">ИТ-22-1</option>
                            </select>
                        </div>
                        <div class="curator-form-group">
                            <label class="curator-form-label">Курс</label>
                            <select class="form-control curator-form-control curator-form-select">
                                <option value="">Все курсы</option>
                                <option value="1 курс">1 курс</option>
                                <option value="2 курс">2 курс</option>
                                <option value="3 курс">3 курс</option>
                                <option value="4 курс">4 курс</option>
                            </select>
                        </div>
                        <div class="curator-form-group">
                            <label class="curator-form-label">Поиск</label>
                            <div class="curator-search-box">
                                <i class="bi bi-search search-icon"></i>
                                <input type="text" class="form-control curator-form-control" placeholder="Поиск по ФИО или ИИН">
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Демо таблицы -->
                <div class="curator-table-container curator-animate-fadeInUp">
                    <div class="table-responsive">
                        <table class="table curator-table mb-0">
                            <thead>
                                <tr>
                                    <th>ФИО</th>
                                    <th>ИИН</th>
                                    <th>Группа</th>
                                    <th>Курс</th>
                                    <th>Статус</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><strong>Ахметов Аслан</strong></td>
                                    <td><code>123456789012</code></td>
                                    <td><span class="curator-badge badge-primary">ИТ-21-1</span></td>
                                    <td><span class="curator-badge badge-info">2 курс</span></td>
                                    <td><span class="curator-badge badge-success">Активный</span></td>
                                    <td>
                                        <div class="curator-table-actions">
                                            <a href="#" class="btn btn-outline-primary" title="Просмотр">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="#" class="btn btn-outline-warning" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                                <tr>
                                    <td><strong>Бекова Айжан</strong></td>
                                    <td><code>987654321098</code></td>
                                    <td><span class="curator-badge badge-primary">ИТ-21-2</span></td>
                                    <td><span class="curator-badge badge-info">2 курс</span></td>
                                    <td><span class="curator-badge badge-warning">Академ. отпуск</span></td>
                                    <td>
                                        <div class="curator-table-actions">
                                            <a href="#" class="btn btn-outline-primary" title="Просмотр">
                                                <i class="bi bi-eye"></i>
                                            </a>
                                            <a href="#" class="btn btn-outline-warning" title="Редактировать">
                                                <i class="bi bi-pencil"></i>
                                            </a>
                                            <button type="button" class="btn btn-outline-danger" title="Удалить">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Демо форм -->
                <div class="curator-form-container curator-animate-fadeInUp">
                    <h5 class="mb-4"><i class="bi bi-person-plus me-2"></i>Демо формы</h5>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="curator-form-group">
                                <label class="curator-form-label">Имя</label>
                                <input type="text" class="form-control curator-form-control" placeholder="Введите имя" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="curator-form-group">
                                <label class="curator-form-label">Email</label>
                                <input type="email" class="form-control curator-form-control" placeholder="Введите email" required>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="curator-form-group">
                                <label class="curator-form-label">Телефон</label>
                                <input type="tel" class="form-control curator-form-control" placeholder="Введите телефон">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="curator-form-group">
                                <label class="curator-form-label">Статус</label>
                                <select class="form-control curator-form-control curator-form-select">
                                    <option value="">Выберите статус</option>
                                    <option value="active">Активный</option>
                                    <option value="inactive">Неактивный</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="curator-form-buttons">
                        <button type="button" class="btn btn-secondary">Отмена</button>
                        <button type="submit" class="btn btn-primary">Сохранить</button>
                    </div>
                </div>

                <!-- Демо прогресс-баров -->
                <div class="curator-table-container curator-animate-fadeInUp">
                    <h5 class="mb-4"><i class="bi bi-graph-up me-2"></i>Демо прогресс-баров</h5>
                    <div class="row">
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Заполненность группы ИТ-21-1</label>
                            <div class="curator-progress">
                                <div class="curator-progress-bar" style="width: 85%"></div>
                            </div>
                            <small class="text-muted">85% (17/20)</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Заполненность группы ИТ-21-2</label>
                            <div class="curator-progress">
                                <div class="curator-progress-bar" style="width: 60%"></div>
                            </div>
                            <small class="text-muted">60% (12/20)</small>
                        </div>
                        <div class="col-md-4 mb-3">
                            <label class="form-label">Заполненность группы ИТ-22-1</label>
                            <div class="curator-progress">
                                <div class="curator-progress-bar" style="width: 100%"></div>
                            </div>
                            <small class="text-muted">100% (20/20)</small>
                        </div>
                    </div>
                </div>

                <!-- Кнопки для демонстрации уведомлений -->
                <div class="curator-form-container curator-animate-fadeInUp">
                    <h5 class="mb-4"><i class="bi bi-bell me-2"></i>Демо уведомлений</h5>
                    <div class="d-flex gap-3 flex-wrap">
                        <button type="button" class="btn btn-success" onclick="CuratorUI.showNotification('Операция выполнена успешно!', 'success')">
                            <i class="bi bi-check-circle me-2"></i>Успех
                        </button>
                        <button type="button" class="btn btn-danger" onclick="CuratorUI.showNotification('Произошла ошибка!', 'danger')">
                            <i class="bi bi-exclamation-triangle me-2"></i>Ошибка
                        </button>
                        <button type="button" class="btn btn-warning" onclick="CuratorUI.showNotification('Внимание! Проверьте данные.', 'warning')">
                            <i class="bi bi-exclamation-triangle me-2"></i>Предупреждение
                        </button>
                        <button type="button" class="btn btn-info" onclick="CuratorUI.showNotification('Информационное сообщение.', 'info')">
                            <i class="bi bi-info-circle me-2"></i>Информация
                        </button>
                    </div>
                </div>
        </div>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="assets/js/curator-ui.js"></script>
</body>
</html>
