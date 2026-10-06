<?php
/**
 * Класс для работы с логами действий кураторов
 */
class ActivityLog {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Логировать действие куратора
     * 
     * @param array $data Массив с данными для логирования:
     *   - curator_id (int) - ID куратора
     *   - curator_name (string) - ФИО куратора
     *   - action_type (string) - Тип действия (add_student, edit_student, etc.)
     *   - action_status (string) - Статус (success, error, validation_error)
     *   - student_id (int|null) - ID студента
     *   - student_iin (string|null) - ИИН студента
     *   - student_name (string|null) - ФИО студента
     *   - group_id (int|null) - ID группы
     *   - group_name (string|null) - Название группы
     *   - message (string|null) - Сообщение
     *   - error_details (string|null) - Детали ошибки
     *   - validation_errors (array|null) - Массив ошибок валидации
     *   - request_data (array|null) - Данные запроса (для отладки)
     * 
     * @return bool Успешность операции
     */
    public function log($data) {
        $sql = "INSERT INTO curator_activity_logs (
            curator_id, curator_name, action_type, action_status,
            student_id, student_iin, student_name,
            group_id, group_name, message, error_details,
            validation_errors, request_data, ip_address, user_agent
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("Ошибка подготовки запроса для логирования: " . $this->db->error);
            return false;
        }
        
        // Получаем IP и User Agent
        $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        // Подготовка данных
        $curator_id = $data['curator_id'] ?? null;
        $curator_name = $data['curator_name'] ?? '';
        $action_type = $data['action_type'] ?? 'unknown';
        $action_status = $data['action_status'] ?? 'unknown';
        $student_id = $data['student_id'] ?? null;
        $student_iin = $data['student_iin'] ?? null;
        $student_name = $data['student_name'] ?? null;
        $group_id = $data['group_id'] ?? null;
        $group_name = $data['group_name'] ?? null;
        $message = $data['message'] ?? null;
        $error_details = $data['error_details'] ?? null;
        $validation_errors = isset($data['validation_errors']) && is_array($data['validation_errors']) 
            ? json_encode($data['validation_errors'], JSON_UNESCAPED_UNICODE) 
            : null;
        $request_data = isset($data['request_data']) && is_array($data['request_data'])
            ? json_encode($data['request_data'], JSON_UNESCAPED_UNICODE)
            : null;
        
        $stmt->bind_param(
            "isssisssississs",
            $curator_id,
            $curator_name,
            $action_type,
            $action_status,
            $student_id,
            $student_iin,
            $student_name,
            $group_id,
            $group_name,
            $message,
            $error_details,
            $validation_errors,
            $request_data,
            $ip_address,
            $user_agent
        );
        
        $result = $stmt->execute();
        if (!$result) {
            error_log("Ошибка выполнения запроса логирования: " . $stmt->error);
        }
        
        $stmt->close();
        return $result;
    }
    
    /**
     * Получить логи по куратору
     * 
     * @param int|null $curator_id ID куратора (null для всех)
     * @param int $limit Лимит записей
     * @param int $offset Смещение
     * @param string|null $action_type Фильтр по типу действия
     * @param string|null $action_status Фильтр по статусу
     * @return array Массив логов
     */
    public function getLogs($curator_id = null, $limit = 100, $offset = 0, $action_type = null, $action_status = null) {
        $sql = "SELECT l.*, 
                CONCAT(u.last_name, ' ', u.first_name, ' ', u.middle_name) as curator_full_name
                FROM curator_activity_logs l
                LEFT JOIN users u ON l.curator_id = u.id
                WHERE 1=1";
        
        $params = [];
        $types = '';
        
        if ($curator_id !== null) {
            $sql .= " AND l.curator_id = ?";
            $params[] = $curator_id;
            $types .= 'i';
        }
        
        if ($action_type !== null) {
            $sql .= " AND l.action_type = ?";
            $params[] = $action_type;
            $types .= 's';
        }
        
        if ($action_status !== null) {
            $sql .= " AND l.action_status = ?";
            $params[] = $action_status;
            $types .= 's';
        }
        
        $sql .= " ORDER BY l.created_at DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        $types .= 'ii';
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            error_log("Ошибка подготовки запроса получения логов: " . $this->db->error);
            return [];
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        
        // Декодируем JSON поля
        foreach ($logs as &$log) {
            if ($log['validation_errors']) {
                $log['validation_errors'] = json_decode($log['validation_errors'], true);
            }
            if ($log['request_data']) {
                $log['request_data'] = json_decode($log['request_data'], true);
            }
        }
        
        $stmt->close();
        return $logs;
    }
    
    /**
     * Получить статистику по куратору
     * 
     * @param int|null $curator_id ID куратора (null для всех)
     * @return array Статистика
     */
    public function getStatistics($curator_id = null) {
        $sql = "SELECT 
                COUNT(*) as total_actions,
                SUM(CASE WHEN action_status = 'success' THEN 1 ELSE 0 END) as success_count,
                SUM(CASE WHEN action_status = 'error' THEN 1 ELSE 0 END) as error_count,
                SUM(CASE WHEN action_status = 'validation_error' THEN 1 ELSE 0 END) as validation_error_count,
                SUM(CASE WHEN action_type = 'add_student' THEN 1 ELSE 0 END) as add_student_count,
                SUM(CASE WHEN action_type = 'edit_student' THEN 1 ELSE 0 END) as edit_student_count
                FROM curator_activity_logs
                WHERE 1=1";
        
        $params = [];
        $types = '';
        
        if ($curator_id !== null) {
            $sql .= " AND curator_id = ?";
            $params[] = $curator_id;
            $types .= 'i';
        }
        
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        
        if (!empty($params)) {
            $stmt->bind_param($types, ...$params);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $stats = $result->fetch_assoc();
        
        $stmt->close();
        return $stats;
    }
    
    /**
     * Получить последние ошибки куратора
     * 
     * @param int|null $curator_id ID куратора (null для всех)
     * @param int $limit Лимит записей
     * @return array Массив ошибок
     */
    public function getRecentErrors($curator_id = null, $limit = 10) {
        return $this->getLogs($curator_id, $limit, 0, null, 'error');
    }
}



