<?php

/**
 * Журнал действий и ошибок админки.
 */
class AdminLog
{
    private static $handling = false;
    private static $tableReady = false;
    private static $registered = false;

    public static function register()
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;

        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);
    }

    public static function handleError($severity, $message, $file = '', $line = 0)
    {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        $fatal = in_array($severity, [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR], true);
        $level = $fatal ? 'error' : 'warning';

        if (in_array($severity, [E_NOTICE, E_USER_NOTICE, E_DEPRECATED, E_USER_DEPRECATED, E_STRICT], true)) {
            return false;
        }

        self::write($level, 'php_error', $message, [
            'file' => $file,
            'line' => $line,
            'severity' => $severity,
        ]);

        return false;
    }

    public static function handleException($exception)
    {
        $message = $exception->getMessage();
        self::write('error', 'exception', $message, [
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        self::renderErrorPage('Произошла ошибка', $message);
        exit;
    }

    public static function handleShutdown()
    {
        $last = error_get_last();
        if (!$last) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($last['type'], $fatalTypes, true)) {
            return;
        }

        self::write('error', 'fatal', $last['message'], [
            'file' => $last['file'] ?? '',
            'line' => $last['line'] ?? 0,
        ]);

        self::renderErrorPage('Критическая ошибка', $last['message']);
    }

    private static function renderErrorPage($title, $message)
    {
        $logsUrl = (defined('BASE_URL') ? rtrim(BASE_URL, '/') . '/' : '/') . 'admin/logs.php';
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        if (headers_sent()) {
            echo '<div style="margin:16px;padding:16px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:12px;font-family:Segoe UI,Arial,sans-serif;">';
            echo '<strong>' . $safeTitle . ':</strong> ' . $safeMessage;
            echo ' <a href="' . htmlspecialchars($logsUrl, ENT_QUOTES, 'UTF-8') . '">Открыть журнал</a>';
            echo '</div>';
            return;
        }

        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>' . $safeTitle . '</title>';
        echo '<style>body{font-family:Segoe UI,Arial,sans-serif;background:#f4f6fb;color:#1a1d26;padding:40px;}';
        echo '.box{max-width:720px;margin:0 auto;background:#fff;border:1px solid #e5e9f2;border-radius:14px;padding:24px;}';
        echo 'h1{font-size:20px;margin:0 0 12px;}a{color:#2c5af2;}</style></head><body>';
        echo '<div class="box"><h1>' . $safeTitle . '</h1>';
        echo '<p>' . $safeMessage . '</p>';
        echo '<p><a href="' . htmlspecialchars($logsUrl, ENT_QUOTES, 'UTF-8') . '">Открыть журнал</a></p></div></body></html>';
    }

    public static function info($action, $message, $details = null)
    {
        self::write('info', $action, $message, $details);
    }

    public static function warning($action, $message, $details = null)
    {
        self::write('warning', $action, $message, $details);
    }

    public static function error($action, $message, $details = null)
    {
        self::write('error', $action, $message, $details);
    }

    public static function write($level, $action, $message, $details = null)
    {
        if (self::$handling) {
            return;
        }
        self::$handling = true;

        $level = in_array($level, ['info', 'warning', 'error'], true) ? $level : 'info';
        $action = mb_substr((string)$action, 0, 64);
        $message = (string)$message;
        $detailsText = null;
        if ($details !== null) {
            if (is_array($details) || is_object($details)) {
                $detailsText = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            } else {
                $detailsText = (string)$details;
            }
            if (mb_strlen($detailsText) > 20000) {
                $detailsText = mb_substr($detailsText, 0, 20000) . '…';
            }
        }

        $adminName = $_SESSION['admin_name'] ?? ($_SESSION['user_name'] ?? '');
        $url = ($_SERVER['REQUEST_URI'] ?? '');
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';

        self::writeToFile($level, $action, $message, $detailsText);

        try {
            self::ensureTable();
            $db = getDB();
            $stmt = $db->prepare("INSERT INTO admin_logs (level, action, message, details, admin_name, url, ip)
                                  VALUES (?, ?, ?, ?, ?, ?, ?)");
            if ($stmt) {
                $stmt->bind_param(
                    'sssssss',
                    $level,
                    $action,
                    $message,
                    $detailsText,
                    $adminName,
                    $url,
                    $ip
                );
                $stmt->execute();
            }
        } catch (Throwable $e) {
            self::writeToFile('error', 'admin_log', 'Не удалось записать лог в БД: ' . $e->getMessage());
        }

        self::$handling = false;
    }

    public static function getList($level = '', $search = '', $limit = 50, $offset = 0)
    {
        self::ensureTable();
        $db = getDB();
        $sql = "SELECT * FROM admin_logs WHERE 1=1";
        $params = [];
        $types = '';

        if ($level !== '' && in_array($level, ['info', 'warning', 'error'], true)) {
            $sql .= " AND level = ?";
            $params[] = $level;
            $types .= 's';
        }
        if ($search !== '') {
            $sql .= " AND (message LIKE ? OR action LIKE ? OR details LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $sql .= " ORDER BY id DESC LIMIT ? OFFSET ?";
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public static function count($level = '', $search = '')
    {
        self::ensureTable();
        $db = getDB();
        $sql = "SELECT COUNT(*) AS cnt FROM admin_logs WHERE 1=1";
        $params = [];
        $types = '';

        if ($level !== '' && in_array($level, ['info', 'warning', 'error'], true)) {
            $sql .= " AND level = ?";
            $params[] = $level;
            $types .= 's';
        }
        if ($search !== '') {
            $sql .= " AND (message LIKE ? OR action LIKE ? OR details LIKE ?)";
            $like = '%' . $search . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $types .= 'sss';
        }

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            return 0;
        }
        if ($params) {
            $stmt->bind_param($types, ...$params);
        }
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }

    public static function clear()
    {
        self::ensureTable();
        $db = getDB();
        $db->query("TRUNCATE TABLE admin_logs");
        self::info('logs_clear', 'Журнал очищен');
    }

    public static function ensureTable()
    {
        if (self::$tableReady) {
            return;
        }
        self::$tableReady = true;
        $db = getDB();
        $db->query("CREATE TABLE IF NOT EXISTS admin_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            level VARCHAR(16) NOT NULL DEFAULT 'info',
            action VARCHAR(64) NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            details MEDIUMTEXT NULL,
            admin_name VARCHAR(255) NULL,
            url VARCHAR(500) NULL,
            ip VARCHAR(64) NULL,
            KEY idx_admin_logs_created (created_at),
            KEY idx_admin_logs_level (level)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    private static function writeToFile($level, $action, $message, $details = null)
    {
        $dir = dirname(__DIR__) . '/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = date('Y-m-d H:i:s') . "\t" . $level . "\t" . $action . "\t" . str_replace(["\r", "\n"], ' ', $message);
        if ($details) {
            $line .= "\t" . str_replace(["\r", "\n"], ' ', $details);
        }
        @file_put_contents($dir . '/admin.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
