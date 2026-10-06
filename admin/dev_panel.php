<?php
require_once '../config/config.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Защита - только localhost
$allowedHosts = ['localhost', '127.0.0.1', '::1'];
$isLocalhost = in_array($_SERVER['SERVER_NAME'], $allowedHosts) || in_array($_SERVER['REMOTE_ADDR'], $allowedHosts);

$flagFile = dirname(__DIR__) . '/sync.enabled';
$isSyncEnabled = file_exists($flagFile);
$deployResult = null;
$deployOutput = [];

// Обработка действий
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $isLocalhost) {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        if ($isSyncEnabled) {
            @unlink($flagFile);
            $isSyncEnabled = false;
        } else {
            @file_put_contents($flagFile, date('Y-m-d H:i:s'));
            $isSyncEnabled = file_exists($flagFile);
        }
        header('Location: dev_panel.php');
        exit;
    }

    if ($action === 'deploy') {
        // Определяем операционную систему
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        if ($isWindows) {
            // Windows - используем .bat файл
            $scriptPath = dirname(__DIR__) . '/sync-silent.bat';
            if (file_exists($scriptPath)) {
                $command = 'cmd /c "' . $scriptPath . '" 2>&1';
                @exec($command, $deployOutput, $exitCode);
                $deployResult = $exitCode === 0 ? 'success' : 'error';
            } else {
                $deployResult = 'error';
                $deployOutput = ['sync-silent.bat not found'];
            }
        } else {
            // macOS/Linux - используем .sh файл
            $scriptPath = dirname(__DIR__) . '/sync-silent.sh';
            if (file_exists($scriptPath)) {
                // Убеждаемся, что скрипт исполняемый
                @chmod($scriptPath, 0755);
                // Выполняем через bash
                $command = 'bash "' . $scriptPath . '" 2>&1';
                @exec($command, $deployOutput, $exitCode);
                $deployResult = $exitCode === 0 ? 'success' : 'error';
            } else {
                $deployResult = 'error';
                $deployOutput = ['sync-silent.sh not found'];
            }
        }
    }
}

// Получаем время последней синхронизации
$lastSyncTime = null;
if (file_exists($flagFile)) {
    $lastSyncTime = file_get_contents($flagFile);
}

// Для header
$page_title = 'Dev Panel';
$active_page = 'dev_panel';
include 'includes/admin_header.php';
?>

<style>
    .dev-panel-card {
        background: var(--card-bg);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        padding: 2rem;
        margin-bottom: 1.5rem;
    }
    
    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 0.75rem;
        padding: 0.75rem 1.5rem;
        border-radius: 9999px;
        font-weight: 600;
        font-size: 0.875rem;
    }
    
    .status-enabled {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
        border: 2px solid var(--success);
    }
    
    .status-disabled {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
        border: 2px solid var(--danger);
    }
    
    .status-dot {
        width: 10px;
        height: 10px;
        border-radius: 50%;
        animation: pulse 2s infinite;
    }
    
    .status-enabled .status-dot {
        background: var(--success);
        box-shadow: 0 0 10px var(--success);
    }
    
    .status-disabled .status-dot {
        background: var(--danger);
        box-shadow: 0 0 10px var(--danger);
    }
    
    @keyframes pulse {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.5; transform: scale(0.9); }
    }
    
    .dev-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
        margin-top: 1.5rem;
    }
    
    .dev-warning {
        background: rgba(245, 158, 11, 0.1);
        border: 1px solid rgba(245, 158, 11, 0.3);
        color: var(--warning);
        padding: 1rem;
        border-radius: var(--radius);
        margin-top: 1rem;
        display: flex;
        align-items: center;
        gap: 0.75rem;
    }
    
    .dev-info {
        background: rgba(59, 130, 246, 0.1);
        border: 1px solid rgba(59, 130, 246, 0.3);
        color: var(--primary);
        padding: 1rem;
        border-radius: var(--radius);
        margin-top: 1rem;
        font-size: 0.875rem;
    }
    
    .result-box {
        margin-top: 1.5rem;
        padding: 1rem;
        border-radius: var(--radius);
        text-align: center;
        font-weight: 500;
    }
    
    .result-success {
        background: rgba(16, 185, 129, 0.1);
        color: var(--success);
        border: 1px solid rgba(16, 185, 129, 0.3);
    }
    
    .result-error {
        background: rgba(239, 68, 68, 0.1);
        color: var(--danger);
        border: 1px solid rgba(239, 68, 68, 0.3);
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Dev Control Panel</h1>
        <p class="page-subtitle">Управление синхронизацией с продакшеном</p>
    </div>
</div>

<?php if (!$isLocalhost): ?>
    <div class="card">
        <div class="card-body">
            <div style="text-align: center; padding: 2rem; color: var(--danger);">
                <i class="bi bi-shield-exclamation fs-1 d-block mb-3"></i>
                <h5>Доступ запрещён</h5>
                <p>Эта панель доступна только на localhost</p>
            </div>
        </div>
    </div>
<?php else: ?>
    <!-- Status Card -->
    <div class="dev-panel-card">
        <div style="text-align: center;">
            <div class="status-indicator <?php echo $isSyncEnabled ? 'status-enabled' : 'status-disabled'; ?>">
                <span class="status-dot"></span>
                <span>Auto-Sync: <?php echo $isSyncEnabled ? 'ВКЛЮЧЁН' : 'ВЫКЛЮЧЕН'; ?></span>
            </div>
            <p style="margin-top: 1rem; color: var(--text-secondary); font-size: 0.875rem;">
                <?php if ($isSyncEnabled): ?>
                    Изменения автоматически отправляются на сервер
                <?php else: ?>
                    Работаете только локально, сервер не обновляется
                <?php endif; ?>
            </p>
            <?php if ($lastSyncTime): ?>
                <div class="dev-info" style="margin-top: 1rem;">
                    <i class="bi bi-clock me-2"></i>
                    Последняя синхронизация: <?php echo htmlspecialchars($lastSyncTime); ?>
                </div>
            <?php endif; ?>
        </div>
        
        <div class="dev-actions">
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="toggle">
                <button type="submit" class="btn btn-outline w-100" style="height: 100%;">
                    <?php if ($isSyncEnabled): ?>
                        <i class="bi bi-pause-circle-fill me-2"></i>
                        Выключить авто-синхронизацию
                    <?php else: ?>
                        <i class="bi bi-play-circle-fill me-2"></i>
                        Включить авто-синхронизацию
                    <?php endif; ?>
                </button>
            </form>
            
            <form method="POST" style="margin: 0;">
                <input type="hidden" name="action" value="deploy">
                <button type="submit" class="btn btn-primary w-100" style="height: 100%;">
                    <i class="bi bi-rocket-takeoff-fill me-2"></i>
                    Deploy to Production
                </button>
            </form>
        </div>
        
        <?php if ($deployResult): ?>
            <div class="result-box <?php echo $deployResult === 'success' ? 'result-success' : 'result-error'; ?>">
                <?php if ($deployResult === 'success'): ?>
                    <i class="bi bi-check-circle-fill me-2"></i>
                    Синхронизация успешно завершена!
                    <?php if ($isSyncEnabled): ?>
                        <br><small style="font-size: 0.75rem; margin-top: 0.5rem; display: block; opacity: 0.8;">
                            Авто-синхронизация активна
                        </small>
                    <?php endif; ?>
                <?php else: ?>
                    <i class="bi bi-x-circle-fill me-2"></i>
                    <strong>Ошибка синхронизации</strong>
                    <?php if (!empty($deployOutput)): ?>
                        <br><small style="font-size: 0.75rem; margin-top: 0.5rem; display: block; opacity: 0.9;">
                            <?php 
                            $errorMsg = implode("\n", array_map('htmlspecialchars', $deployOutput));
                            echo nl2br($errorMsg);
                            ?>
                        </small>
                    <?php else: ?>
                        <br><small style="font-size: 0.75rem; margin-top: 0.5rem; display: block; opacity: 0.9;">
                            Проверьте логи или попробуйте выполнить синхронизацию вручную через терминал
                        </small>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Info Card -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="bi bi-info-circle"></i>
                Информация
            </h2>
        </div>
        <div class="card-body">
            <div class="dev-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <div>
                    <strong>Внимание:</strong> Эта панель доступна только на localhost
                </div>
            </div>
            <div style="margin-top: 1rem; font-size: 0.875rem; color: var(--text-secondary);">
                <p><strong>Сервер:</strong> app.kvki.kz</p>
                <p><strong>Локальный путь:</strong> <?php echo htmlspecialchars(dirname(__DIR__)); ?></p>
                <p><strong>ОС:</strong> <?php echo PHP_OS; ?> 
                    <?php 
                    $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
                    $scriptFile = $isWindows ? 'sync-silent.bat' : 'sync-silent.sh';
                    $scriptPath = dirname(__DIR__) . '/' . $scriptFile;
                    if (file_exists($scriptPath)) {
                        echo '<span class="badge badge-success ms-2">✓ ' . $scriptFile . '</span>';
                    } else {
                        echo '<span class="badge badge-danger ms-2">✗ ' . $scriptFile . ' не найден</span>';
                    }
                    ?>
                </p>
                <p style="margin-top: 0.75rem;">
                    <strong>Как это работает:</strong>
                </p>
                <ul style="margin: 0.5rem 0 0 1.5rem; padding: 0;">
                    <li>Включите авто-синхронизацию для автоматической отправки изменений</li>
                    <li>Или используйте "Deploy to Production" для ручной синхронизации</li>
                    <li>Файл <code>sync.enabled</code> контролирует статус авто-синхронизации</li>
                    <li>Используется скрипт: <code><?php echo $scriptFile; ?></code></li>
                    <li style="margin-top: 0.5rem; color: var(--warning);">
                        <i class="bi bi-shield-exclamation me-1"></i>
                        <strong>Исключены из синхронизации:</strong> 
                        <code>config/</code> (вся папка), <code>dev-panel.php</code>
                    </li>
                </ul>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php include 'includes/admin_footer.php'; ?>
