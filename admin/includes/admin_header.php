<?php
/**
 * Admin Header Include
 * Переиспользуемый header для всех страниц админки
 *
 * Переменные для передачи:
 * $page_title - заголовок страницы
 * $page_subtitle - подзаголовок (опционально)
 * $active_page - активный пункт меню (dashboard, users, groups, students, library, roles, fields, notifications)
 */

if (!isset($page_title)) $page_title = 'Админ-панель';
if (!isset($page_subtitle)) $page_subtitle = '';
if (!isset($active_page)) $active_page = '';

$admin_display_name = $_SESSION['admin_name'] ?? 'Администратор';
$admin_initial = mb_strtoupper(mb_substr($admin_display_name, 0, 1, 'UTF-8'), 'UTF-8');

// Получаем статистику для бейджей в меню (кешируем если уже загружено)
if (!isset($GLOBALS['admin_menu_stats'])) {
    require_once dirname(__DIR__) . '/../classes/User.php';
    require_once dirname(__DIR__) . '/../classes/Group.php';
    require_once dirname(__DIR__) . '/../classes/Spravka.php';
    $temp_user = new User();
    $temp_group = new Group();
    $temp_spravka = new Spravka();
    $GLOBALS['admin_menu_stats'] = [
        'users' => count($temp_user->getAllUsers()),
        'groups' => $temp_group->getGroupStats()['total_groups'] ?? 0,
        'spravki' => count($temp_spravka->getList())
    ];
}
$menu_stats = $GLOBALS['admin_menu_stats'];
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($page_title); ?> - <?php echo htmlspecialchars(APP_NAME); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/css/admin-style.css" rel="stylesheet">
    <link href="../assets/css/tooltips.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
</head>
<body>
    <!-- Sidebar Overlay (mobile) -->
    <div class="sidebar-overlay" onclick="toggleSidebar()"></div>

    <!-- Sidebar -->
    <aside class="admin-sidebar">
        <div class="sidebar-header">
            <a href="index.php" class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <img src="<?php echo htmlspecialchars(appLogoUrl()); ?>" alt="<?php echo htmlspecialchars(APP_LOGO_ALT); ?>">
                </div>
                <div class="sidebar-logo-text">
                    <div class="sidebar-logo-name"><?php echo htmlspecialchars(APP_SHORT_NAME); ?></div>
                    <div class="sidebar-logo-sub"><?php echo htmlspecialchars(APP_TAGLINE); ?></div>
                </div>
            </a>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-section">
                <div class="nav-section-title">Главное</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="index.php" class="nav-link <?php echo $active_page === 'dashboard' ? 'active' : ''; ?>">
                            <i class="bi bi-grid-1x2-fill"></i>
                            <span>Дашборд</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Управление</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="users.php" class="nav-link <?php echo $active_page === 'users' ? 'active' : ''; ?>">
                            <i class="bi bi-people-fill"></i>
                            <span>Пользователи</span>
                            <span class="badge bg-primary"><?php echo $menu_stats['users']; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="groups.php" class="nav-link <?php echo $active_page === 'groups' ? 'active' : ''; ?>">
                            <i class="bi bi-collection-fill"></i>
                            <span>Группы</span>
                            <span class="badge bg-success"><?php echo $menu_stats['groups']; ?></span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="departments.php" class="nav-link <?php echo $active_page === 'departments' ? 'active' : ''; ?>">
                            <i class="bi bi-building"></i>
                            <span>Отделения</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="students.php" class="nav-link <?php echo $active_page === 'students' ? 'active' : ''; ?>">
                            <i class="bi bi-person-badge-fill"></i>
                            <span>Студенты</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="library.php" class="nav-link <?php echo $active_page === 'library' ? 'active' : ''; ?>">
                            <i class="bi bi-journal-bookmark-fill"></i>
                            <span>Библиотека</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="spravki.php" class="nav-link <?php echo $active_page === 'spravki' ? 'active' : ''; ?>">
                            <i class="bi bi-file-earmark-text-fill"></i>
                            <span>Справки</span>
                            <?php if (isset($menu_stats['spravki'])): ?>
                                <span class="badge bg-info"><?php echo $menu_stats['spravki']; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="spravka_templates.php" class="nav-link <?php echo $active_page === 'spravka_templates' ? 'active' : ''; ?>">
                            <i class="bi bi-file-earmark-code-fill"></i>
                            <span>Шаблоны справок</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="roles.php" class="nav-link <?php echo $active_page === 'roles' ? 'active' : ''; ?>">
                            <i class="bi bi-shield-lock-fill"></i>
                            <span>Роли</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Отчеты</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="reports.php" class="nav-link <?php echo $active_page === 'reports' ? 'active' : ''; ?>">
                            <i class="bi bi-graph-up-arrow"></i>
                            <span>Отчеты</span>
                        </a>
                    </li>
                </ul>
            </div>

            <div class="nav-section">
                <div class="nav-section-title">Настройки</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="dynamic_fields.php" class="nav-link <?php echo $active_page === 'fields' ? 'active' : ''; ?>">
                            <i class="bi bi-sliders"></i>
                            <span>Поля формы</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="notifications.php" class="nav-link <?php echo $active_page === 'notifications' ? 'active' : ''; ?>">
                            <i class="bi bi-bell-fill"></i>
                            <span>Уведомления</span>
                        </a>
                    </li>
                    <li class="nav-item">
                        <a href="logs.php" class="nav-link <?php echo $active_page === 'logs' ? 'active' : ''; ?>">
                            <i class="bi bi-journal-text"></i>
                            <span>Журнал</span>
                            <?php
                            $admin_log_errors = 0;
                            try {
                                if (class_exists('AdminLog')) {
                                    $admin_log_errors = AdminLog::count('error');
                                }
                            } catch (Throwable $e) {
                                $admin_log_errors = 0;
                            }
                            if ($admin_log_errors > 0):
                            ?>
                                <span class="badge bg-danger"><?php echo (int)$admin_log_errors; ?></span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>

            <?php
            $allowedHosts = ['localhost', '127.0.0.1', '::1'];
            $isLocalhost = in_array($_SERVER['SERVER_NAME'] ?? '', $allowedHosts)
                || in_array($_SERVER['REMOTE_ADDR'] ?? '', $allowedHosts);
            if ($isLocalhost): ?>
            <div class="nav-section">
                <div class="nav-section-title">Разработка</div>
                <ul class="nav-menu">
                    <li class="nav-item">
                        <a href="dev_panel.php" class="nav-link <?php echo $active_page === 'dev_panel' ? 'active' : ''; ?>">
                            <i class="bi bi-code-slash"></i>
                            <span>Dev Panel</span>
                            <?php
                            $flagFile = dirname(__DIR__) . '/sync.enabled';
                            $syncEnabled = file_exists($flagFile);
                            if ($syncEnabled):
                            ?>
                                <span class="badge bg-success" style="font-size: 0.625rem; padding: 0.125rem 0.375rem;">ON</span>
                            <?php endif; ?>
                        </a>
                    </li>
                </ul>
            </div>
            <?php endif; ?>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-card">
                <div class="sidebar-user">
                    <div class="sidebar-avatar"><?php echo htmlspecialchars($admin_initial); ?></div>
                    <div class="sidebar-user-info">
                        <div class="sidebar-user-name"><?php echo htmlspecialchars($admin_display_name); ?></div>
                        <div class="sidebar-user-role"><?php echo htmlspecialchars($_SESSION['admin_role'] ?? 'admin'); ?></div>
                    </div>
                </div>
                <a href="logout.php" class="sidebar-logout">
                    <i class="bi bi-box-arrow-right"></i>
                    Выход
                </a>
            </div>
        </div>
    </aside>

    <!-- Main Content -->
    <main class="admin-main">
        <!-- Header -->
        <header class="admin-header">
            <div class="header-left">
                <button class="sidebar-toggle" type="button" onclick="toggleSidebar()" aria-label="Меню">
                    <i class="bi bi-list fs-4"></i>
                </button>
                <div class="header-titles">
                    <h1 class="header-title"><?php echo htmlspecialchars($page_title); ?></h1>
                    <?php if ($page_subtitle !== ''): ?>
                        <p class="header-subtitle"><?php echo htmlspecialchars($page_subtitle); ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <div class="header-right">
                <?php appThemeToggle(); ?>
                <a href="notifications.php" class="header-btn" title="Уведомления">
                    <i class="bi bi-bell"></i>
                </a>
                <div class="dropdown">
                    <div class="header-profile" onclick="toggleDropdown(this)">
                        <div class="header-avatar"><?php echo htmlspecialchars($admin_initial); ?></div>
                        <i class="bi bi-chevron-down"></i>
                    </div>
                    <div class="dropdown-menu">
                        <a href="users.php" class="dropdown-item">
                            <i class="bi bi-people"></i>
                            <span>Пользователи</span>
                        </a>
                        <a href="dynamic_fields.php" class="dropdown-item">
                            <i class="bi bi-gear"></i>
                            <span>Настройки</span>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="logout.php" class="dropdown-item text-danger">
                            <i class="bi bi-box-arrow-right"></i>
                            <span>Выход</span>
                        </a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Content -->
        <div class="admin-content">
