<?php

/**
 * Мобильная авторизация студентов: инвайт-коды и привязка устройства
 */
class StudentMobileAuth
{
    public const INVITE_TTL_SECONDS = 86400; // 24 часа
    public const CODE_LENGTH = 8;
    public const RATE_LIMIT_MAX = 10;
    public const RATE_LIMIT_WINDOW = 900; // 15 минут

    /** Алфавит без 0/O, 1/I */
    private const CODE_ALPHABET = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    private $db;

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function ensureTablesExist()
    {
        $db = getDB();

        $db->query("CREATE TABLE IF NOT EXISTS student_mobile_auth (
            student_id INT NOT NULL PRIMARY KEY,
            device_id VARCHAR(64) NULL,
            device_bound_at DATETIME NULL,
            token_version INT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_sma_device (device_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS student_mobile_invites (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            code_hint VARCHAR(8) NOT NULL DEFAULT '',
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_smi_student (student_id),
            INDEX idx_smi_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS mobile_auth_attempts (
            id INT AUTO_INCREMENT PRIMARY KEY,
            ip_hash VARCHAR(64) NOT NULL,
            code_prefix VARCHAR(16) NOT NULL DEFAULT '',
            attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_maa_ip_time (ip_hash, attempted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function generateCode()
    {
        $alphabet = self::CODE_ALPHABET;
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= $alphabet[random_int(0, $max)];
        }
        return $code;
    }

    /**
     * Создать одноразовый код для студента. Возвращает plaintext-код один раз.
     */
    public function createInvite($studentId, $createdBy = null)
    {
        $studentId = (int)$studentId;
        $code = $this->generateCode();
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $hint = substr($code, -4);
        $expiresAt = date('Y-m-d H:i:s', time() + self::INVITE_TTL_SECONDS);
        $createdBy = $createdBy !== null ? (int)$createdBy : null;

        // Инвалидируем неиспользованные активные коды
        $invalidate = $this->db->prepare(
            "UPDATE student_mobile_invites SET used_at = NOW()
             WHERE student_id = ? AND used_at IS NULL AND expires_at > NOW()"
        );
        $invalidate->bind_param('i', $studentId);
        $invalidate->execute();

        $stmt = $this->db->prepare(
            "INSERT INTO student_mobile_invites (student_id, code_hash, code_hint, expires_at, created_by)
             VALUES (?, ?, ?, ?, ?)"
        );
        $stmt->bind_param('isssi', $studentId, $hash, $hint, $expiresAt, $createdBy);
        $stmt->execute();

        return [
            'code' => $code,
            'expires_at' => $expiresAt,
            'invite_id' => (int)$this->db->getLastInsertId(),
        ];
    }

    public function getAuthRow($studentId)
    {
        $studentId = (int)$studentId;
        $stmt = $this->db->prepare("SELECT * FROM student_mobile_auth WHERE student_id = ? LIMIT 1");
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    public function ensureAuthRow($studentId)
    {
        $studentId = (int)$studentId;
        $row = $this->getAuthRow($studentId);
        if ($row) {
            return $row;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO student_mobile_auth (student_id, token_version) VALUES (?, 0)"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        return $this->getAuthRow($studentId);
    }

    /**
     * Сброс устройства: очистить device_id и увеличить token_version
     */
    public function resetDevice($studentId)
    {
        $studentId = (int)$studentId;
        $this->ensureAuthRow($studentId);
        $stmt = $this->db->prepare(
            "UPDATE student_mobile_auth
             SET device_id = NULL, device_bound_at = NULL, token_version = token_version + 1
             WHERE student_id = ?"
        );
        $stmt->bind_param('i', $studentId);
        $stmt->execute();

        // Пометить неиспользованные инвайты как использованные
        $inv = $this->db->prepare(
            "UPDATE student_mobile_invites SET used_at = NOW()
             WHERE student_id = ? AND used_at IS NULL"
        );
        $inv->bind_param('i', $studentId);
        $inv->execute();

        return $this->getAuthRow($studentId);
    }

    public function isRateLimited($ip, $codePrefix = '')
    {
        $ipHash = hash('sha256', $ip);
        $prefix = substr(preg_replace('/[^A-Z0-9]/i', '', strtoupper($codePrefix)), 0, 16);
        $windowStart = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS cnt FROM mobile_auth_attempts
             WHERE ip_hash = ? AND attempted_at >= ?"
        );
        $stmt->bind_param('ss', $ipHash, $windowStart);
        $stmt->execute();
        $cnt = (int)($stmt->get_result()->fetch_assoc()['cnt'] ?? 0);
        return $cnt >= self::RATE_LIMIT_MAX;
    }

    public function recordAttempt($ip, $codePrefix = '')
    {
        $ipHash = hash('sha256', $ip);
        $prefix = substr(preg_replace('/[^A-Z0-9]/i', '', strtoupper($codePrefix)), 0, 16);
        $stmt = $this->db->prepare(
            "INSERT INTO mobile_auth_attempts (ip_hash, code_prefix) VALUES (?, ?)"
        );
        $stmt->bind_param('ss', $ipHash, $prefix);
        $stmt->execute();

        // Чистим старые записи
        $cutoff = date('Y-m-d H:i:s', time() - self::RATE_LIMIT_WINDOW * 4);
        $del = $this->db->prepare("DELETE FROM mobile_auth_attempts WHERE attempted_at < ?");
        $del->bind_param('s', $cutoff);
        $del->execute();
    }

    /**
     * Найти активный инвайт по plaintext-коду.
     */
    public function findValidInviteByCode($code)
    {
        $code = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $code));
        if (strlen($code) !== self::CODE_LENGTH) {
            return null;
        }

        $hint = substr($code, -4);
        $stmt = $this->db->prepare(
            "SELECT * FROM student_mobile_invites
             WHERE code_hint = ? AND used_at IS NULL AND expires_at > NOW()
             ORDER BY id DESC
             LIMIT 20"
        );
        $stmt->bind_param('s', $hint);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as $row) {
            if (password_verify($code, $row['code_hash'])) {
                return $row;
            }
        }
        return null;
    }

    public function markInviteUsed($inviteId)
    {
        $inviteId = (int)$inviteId;
        $stmt = $this->db->prepare(
            "UPDATE student_mobile_invites SET used_at = NOW() WHERE id = ? AND used_at IS NULL"
        );
        $stmt->bind_param('i', $inviteId);
        $stmt->execute();
    }

    /**
     * Привязать устройство и увеличить token_version. Возвращает новый auth row.
     */
    public function bindDevice($studentId, $deviceId)
    {
        $studentId = (int)$studentId;
        $deviceId = trim($deviceId);
        $this->ensureAuthRow($studentId);

        $stmt = $this->db->prepare(
            "UPDATE student_mobile_auth
             SET device_id = ?, device_bound_at = NOW(), token_version = token_version + 1
             WHERE student_id = ?"
        );
        $stmt->bind_param('si', $deviceId, $studentId);
        $stmt->execute();

        return $this->getAuthRow($studentId);
    }

    public function deviceStatusLabel($authRow)
    {
        if (!$authRow || empty($authRow['device_id'])) {
            return 'не привязано';
        }
        return 'привязано';
    }
}
