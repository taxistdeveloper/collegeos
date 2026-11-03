<?php
/**
 * Класс управления практикой колледжа: нагрузки, графики преподавателей, Word-документы и сдача.
 * Полностью совместим с нативным стеком CollegeOS.
 */

class Practice
{
    private $db;

    /**
     * Государственные праздники Республики Казахстан (даты Y-m-d)
     */
    const KZ_HOLIDAYS = [
        // 2024
        '2024-01-01', '2024-01-02', '2024-01-07',
        '2024-03-08', '2024-03-21', '2024-03-22', '2024-03-23',
        '2024-05-01', '2024-05-07', '2024-05-09',
        '2024-07-06', '2024-08-30', '2024-10-25', '2024-12-16',
        // 2025
        '2025-01-01', '2025-01-02', '2025-01-07',
        '2025-03-08', '2025-03-21', '2025-03-22', '2025-03-23',
        '2025-05-01', '2025-05-07', '2025-05-09',
        '2025-07-06', '2025-08-30', '2025-10-25', '2025-12-16',
        // 2026
        '2026-01-01', '2026-01-02', '2026-01-07',
        '2026-03-08', '2026-03-21', '2026-03-22', '2026-03-23',
        '2026-05-01', '2026-05-07', '2026-05-09',
        '2026-07-06', '2026-08-30', '2026-10-25', '2026-12-16',
        // 2027
        '2027-01-01', '2027-01-02', '2027-01-07',
        '2027-03-08', '2027-03-21', '2027-03-22', '2027-03-23',
        '2027-05-01', '2027-05-07', '2027-05-09',
        '2027-07-06', '2027-08-30', '2027-10-25', '2027-12-16',
    ];

    public function __construct()
    {
        $this->db = getDB();
        $this->ensureTablesExist();
    }

    /**
     * Создание таблиц при необходимости
     */
    public function ensureTablesExist()
    {
        // Таблица преподавателей практики (связка с users)
        $this->db->query("CREATE TABLE IF NOT EXISTS teachers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT(11) NOT NULL UNIQUE,
            department VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_teacher_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Таблица нагрузок практики
        $this->db->query("CREATE TABLE IF NOT EXISTS workloads (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT UNSIGNED NOT NULL,
            module_name VARCHAR(255) NOT NULL,
            study_group VARCHAR(100) DEFAULT NULL,
            practice_hours INT UNSIGNED NOT NULL DEFAULT 0,
            deadline DATE NOT NULL,
            status ENUM('assigned', 'in_progress', 'submitted', 'overdue') NOT NULL DEFAULT 'assigned',
            submitted_file_path VARCHAR(500) DEFAULT NULL,
            submitted_filename VARCHAR(255) DEFAULT NULL,
            submitted_at TIMESTAMP NULL DEFAULT NULL,
            comment TEXT DEFAULT NULL,
            document_path VARCHAR(500) DEFAULT NULL,
            form_data JSON NULL,
            progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
            assigned_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_workload_teacher (teacher_id),
            INDEX idx_workload_status (status),
            INDEX idx_workload_deadline (deadline)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Таблица версий графика
        $this->db->query("CREATE TABLE IF NOT EXISTS workload_versions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            workload_id INT UNSIGNED NOT NULL,
            form_data JSON NULL,
            file_path VARCHAR(500) DEFAULT NULL,
            original_filename VARCHAR(255) DEFAULT NULL,
            version INT UNSIGNED NOT NULL DEFAULT 1,
            action ENUM('upload', 'submit', 'resubmit') NOT NULL DEFAULT 'upload',
            created_by INT(11) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_wv_workload (workload_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Автосинхронизация преподавателей из uchebni_teachers
        $this->db->query("INSERT IGNORE INTO teachers (user_id, department)
            SELECT ut.user_id, COALESCE(ut.specialization, 'Преподаватель')
            FROM uchebni_teachers ut
            JOIN users u ON u.id = ut.user_id
            WHERE ut.user_id IS NOT NULL AND ut.user_id > 0");

        // Автоматически помечаем просроченные нагрузки
        $this->markOverdue();
    }

    /**
     * Пометить просроченные нагрузки
     */
    public function markOverdue()
    {
        $today = date('Y-m-d');
        $sql = "UPDATE workloads 
                SET status = 'overdue' 
                WHERE deadline < ? AND status IN ('assigned', 'in_progress')";
        $stmt = $this->db->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('s', $today);
            $stmt->execute();
        }
    }

    /**
     * Сводная статистика практики
     */
    public function getStats()
    {
        $stats = [
            'total'       => 0,
            'assigned'    => 0,
            'in_progress' => 0,
            'submitted'   => 0,
            'overdue'     => 0,
            'teachers'    => 0,
        ];

        $res = $this->db->query("SELECT status, COUNT(*) as cnt FROM workloads GROUP BY status");
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $st = $row['status'];
                $cnt = (int)$row['cnt'];
                $stats['total'] += $cnt;
                if (isset($stats[$st])) {
                    $stats[$st] = $cnt;
                }
            }
        }

        $resT = $this->db->query("SELECT COUNT(DISTINCT user_id) as cnt FROM teachers");
        if ($resT) {
            $rowT = $resT->fetch_assoc();
            $stats['teachers'] = (int)($rowT['cnt'] ?? 0);
        }

        return $stats;
    }

    /**
     * Получить список нагрузок с фильтрацией
     */
    public function getWorkloads($filters = [])
    {
        $sql = "SELECT w.*, 
                       TRIM(CONCAT_WS(' ', u.last_name, u.first_name, u.middle_name)) as teacher_name,
                       u.login as teacher_login,
                       t.department,
                       TRIM(CONCAT_WS(' ', ab.last_name, ab.first_name)) as assigned_by_name
                FROM workloads w
                LEFT JOIN teachers t ON t.id = w.teacher_id
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users ab ON ab.id = w.assigned_by
                WHERE 1=1";

        $params = [];
        $types = '';

        if (!empty($filters['teacher_id'])) {
            $sql .= " AND w.teacher_id = ?";
            $params[] = (int)$filters['teacher_id'];
            $types .= 'i';
        }

        if (!empty($filters['user_id'])) {
            $sql .= " AND t.user_id = ?";
            $params[] = (int)$filters['user_id'];
            $types .= 'i';
        }

        if (!empty($filters['status'])) {
            $sql .= " AND w.status = ?";
            $params[] = (string)$filters['status'];
            $types .= 's';
        }

        if (!empty($filters['study_group'])) {
            $sql .= " AND w.study_group = ?";
            $params[] = (string)$filters['study_group'];
            $types .= 's';
        }

        if (!empty($filters['search'])) {
            $search = '%' . trim($filters['search']) . '%';
            $sql .= " AND (w.module_name LIKE ? OR w.study_group LIKE ? OR u.last_name LIKE ? OR u.first_name LIKE ?)";
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
            $types .= 'ssss';
        }

        $sql .= " ORDER BY w.deadline ASC, w.id DESC";

        if (!empty($filters['limit'])) {
            $sql .= " LIMIT " . (int)$filters['limit'];
        }

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) return [];
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $this->db->query($sql);
        }

        $rows = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $rows[] = $r;
            }
        }
        return $rows;
    }

    /**
     * Получить одну нагрузку по ID
     */
    public function getWorkloadById($id)
    {
        $id = (int)$id;
        $sql = "SELECT w.*, 
                       TRIM(CONCAT_WS(' ', u.last_name, u.first_name, u.middle_name)) as teacher_name,
                       u.login as teacher_login,
                       u.id as teacher_user_id,
                       t.department,
                       TRIM(CONCAT_WS(' ', ab.last_name, ab.first_name)) as assigned_by_name
                FROM workloads w
                LEFT JOIN teachers t ON t.id = w.teacher_id
                LEFT JOIN users u ON u.id = t.user_id
                LEFT JOIN users ab ON ab.id = w.assigned_by
                WHERE w.id = ? LIMIT 1";

        $stmt = $this->db->prepare($sql);
        if (!$stmt) return null;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $res = $stmt->get_result();
        return $res->fetch_assoc() ?: null;
    }

    /**
     * Создать нагрузку практики
     */
    public function createWorkload($data)
    {
        $teacherId = (int)($data['teacher_id'] ?? 0);
        $module = trim($data['module_name'] ?? '');
        $group = trim($data['study_group'] ?? '');
        $hours = (int)($data['practice_hours'] ?? 0);
        $deadline = trim($data['deadline'] ?? '');
        $assignedBy = !empty($data['assigned_by']) ? (int)$data['assigned_by'] : null;

        if ($teacherId <= 0 || empty($module) || empty($deadline)) {
            return ['ok' => false, 'error' => 'Заполните обязательные поля'];
        }

        $sql = "INSERT INTO workloads (teacher_id, module_name, study_group, practice_hours, deadline, status, assigned_by)
                VALUES (?, ?, ?, ?, ?, 'assigned', ?)";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => $this->db->error];
        }

        $stmt->bind_param('issisi', $teacherId, $module, $group, $hours, $deadline, $assignedBy);
        if ($stmt->execute()) {
            return ['ok' => true, 'id' => $this->db->insert_id];
        }
        return ['ok' => false, 'error' => $stmt->error];
    }

    /**
     * Обновить нагрузку практики
     */
    public function updateWorkload($id, $data)
    {
        $id = (int)$id;
        $workload = $this->getWorkloadById($id);
        if (!$workload) {
            return ['ok' => false, 'error' => 'Нагрузка не найдена'];
        }

        $fields = [];
        $params = [];
        $types = '';

        if (isset($data['module_name'])) {
            $fields[] = "module_name = ?";
            $params[] = trim($data['module_name']);
            $types .= 's';
        }
        if (isset($data['study_group'])) {
            $fields[] = "study_group = ?";
            $params[] = trim($data['study_group']);
            $types .= 's';
        }
        if (isset($data['practice_hours'])) {
            $fields[] = "practice_hours = ?";
            $params[] = (int)$data['practice_hours'];
            $types .= 'i';
        }
        if (isset($data['deadline'])) {
            $fields[] = "deadline = ?";
            $params[] = trim($data['deadline']);
            $types .= 's';
        }
        if (isset($data['teacher_id'])) {
            $fields[] = "teacher_id = ?";
            $params[] = (int)$data['teacher_id'];
            $types .= 'i';
        }

        if (empty($fields)) {
            return ['ok' => true];
        }

        $sql = "UPDATE workloads SET " . implode(', ', $fields) . " WHERE id = ?";
        $params[] = $id;
        $types .= 'i';

        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => $this->db->error];
        }
        $stmt->bind_param($types, ...$params);
        return ['ok' => $stmt->execute()];
    }

    /**
     * Удалить нагрузку практики
     */
    public function deleteWorkload($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("DELETE FROM workloads WHERE id = ?");
        if (!$stmt) return false;
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Получить или создать запись в teachers для user_id
     */
    public function getOrCreateTeacherByUserId($userId)
    {
        $userId = (int)$userId;
        $stmt = $this->db->prepare("SELECT id FROM teachers WHERE user_id = ? LIMIT 1");
        if ($stmt) {
            $stmt->bind_param('i', $userId);
            $stmt->execute();
            $res = $stmt->get_result();
            if ($row = $res->fetch_assoc()) {
                return (int)$row['id'];
            }
        }

        $ins = $this->db->prepare("INSERT INTO teachers (user_id, department) VALUES (?, 'Преподаватель колледжа')");
        if ($ins) {
            $ins->bind_param('i', $userId);
            $ins->execute();
            return (int)$this->db->insert_id;
        }
        return 0;
    }

    /**
     * Получить список преподавателей для выпадающего списка
     */
    public function getTeachers()
    {
        $sql = "SELECT t.id, t.user_id, t.department,
                       TRIM(CONCAT_WS(' ', u.last_name, u.first_name, u.middle_name)) as fullname,
                       u.login, u.email
                FROM teachers t
                JOIN users u ON u.id = t.user_id
                WHERE u.is_active = 1
                ORDER BY fullname ASC";
        $res = $this->db->query($sql);
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $list[] = $r;
            }
        }
        return $list;
    }

    /**
     * Получить список групп для автоподстановки
     */
    public function getGroups()
    {
        $res = $this->db->query("SELECT id, name FROM `groups` WHERE is_active = 1 ORDER BY name ASC");
        $list = [];
        if ($res) {
            while ($r = $res->fetch_assoc()) {
                $list[] = $r;
            }
        }
        return $list;
    }

    /**
     * Проверка выходного (сб / вс)
     */
    public static function isWeekend($dateStr)
    {
        if (empty($dateStr)) return false;
        $dt = DateTimeImmutable::createFromFormat('Y-m-d', $dateStr);
        if (!$dt) return false;
        $n = (int)$dt->format('N');
        return $n >= 6;
    }

    /**
     * Проверка праздника РК
     */
    public static function isHoliday($dateStr)
    {
        if (empty($dateStr)) return false;
        return in_array($dateStr, self::KZ_HOLIDAYS, true);
    }

    /**
     * Подсчёт часов по строкам графика
     */
    public static function calculateHoursSummary($entries, $limitHours = 0)
    {
        $totalHours = 0.0;
        $countedHours = 0.0;
        $excludedHours = 0.0;
        $rowsMeta = [];

        foreach ($entries as $idx => $e) {
            $date = trim((string)($e['date'] ?? ''));
            $start = trim((string)($e['time_start'] ?? ''));
            $end = trim((string)($e['time_end'] ?? ''));

            $duration = 0.0;
            if ($start !== '' && $end !== '') {
                $sParts = explode(':', $start);
                $eParts = explode(':', $end);
                if (count($sParts) >= 2 && count($eParts) >= 2) {
                    $sMin = (int)$sParts[0] * 60 + (int)$sParts[1];
                    $eMin = (int)$eParts[0] * 60 + (int)$eParts[1];
                    if ($eMin > $sMin) {
                        $duration = round(($eMin - $sMin) / 60, 1);
                    }
                }
            }

            $reason = null;
            if ($date !== '') {
                if (self::isWeekend($date)) {
                    $reason = 'weekend';
                } elseif (self::isHoliday($date)) {
                    $reason = 'holiday';
                }
            }

            $totalHours += $duration;
            if ($reason !== null) {
                $excludedHours += $duration;
            } else {
                $countedHours += $duration;
            }

            $rowsMeta[$idx] = [
                'hours'   => $duration,
                'reason'  => $reason,
                'counted' => ($reason === null && $duration > 0),
            ];
        }

        $percent = $limitHours > 0 ? min(100, round(($countedHours / $limitHours) * 100)) : 0;

        return [
            'total'    => $totalHours,
            'counted'  => $countedHours,
            'excluded' => $excludedHours,
            'limit'    => $limitHours,
            'percent'  => $percent,
            'rows'     => $rowsMeta,
        ];
    }

    /**
     * Сохранение черновика графика
     */
    public function saveSchedule($workloadId, $entries, $comment = '')
    {
        $workloadId = (int)$workloadId;
        $workload = $this->getWorkloadById($workloadId);
        if (!$workload) {
            return ['ok' => false, 'error' => 'Нагрузка не найдена'];
        }

        $summary = self::calculateHoursSummary($entries, (int)$workload['practice_hours']);
        $json = json_encode(['entries' => $entries], JSON_UNESCAPED_UNICODE);
        $percent = (int)$summary['percent'];

        $newStatus = $workload['status'];
        if ($newStatus === 'assigned') {
            $newStatus = 'in_progress';
        }

        $sql = "UPDATE workloads 
                SET form_data = ?, 
                    progress_percent = ?, 
                    comment = ?, 
                    status = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => $this->db->error];
        }

        $stmt->bind_param('sissi', $json, $percent, $comment, $newStatus, $workloadId);
        if ($stmt->execute()) {
            return ['ok' => true, 'summary' => $summary];
        }
        return ['ok' => false, 'error' => $stmt->error];
    }

    /**
     * Отправка графика на проверку
     */
    public function submitSchedule($workloadId, $comment = '')
    {
        $workloadId = (int)$workloadId;
        $workload = $this->getWorkloadById($workloadId);
        if (!$workload) {
            return ['ok' => false, 'error' => 'Нагрузка не найдена'];
        }

        $sql = "UPDATE workloads 
                SET status = 'submitted', 
                    submitted_at = CURRENT_TIMESTAMP, 
                    comment = ? 
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return ['ok' => false, 'error' => $this->db->error];
        }

        $stmt->bind_param('si', $comment, $workloadId);
        if ($stmt->execute()) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => $stmt->error];
    }

    /**
     * Сданные графики
     */
    public function getSubmissions($search = '')
    {
        return $this->getWorkloads([
            'status' => 'submitted',
            'search' => $search,
        ]);
    }

    /**
     * Генерация DOCX файла графиков практики
     */
    public function generateDocx($workloadId)
    {
        require_once __DIR__ . '/GrafikDocx.php';
        $workload = $this->getWorkloadById($workloadId);
        if (!$workload) {
            throw new Exception("Нагрузка практики не найдена");
        }

        $renderer = new GrafikDocx();
        return $renderer->render($workload);
    }
}
