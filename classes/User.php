<?php
/**
 * Класс для работы с пользователями
 */
class User {
    private $db;

    /** Порядок основной роли для редиректа при нескольких ролях */
    private static $rolePriority = [
        'admin', 'director', 'manager', 'methodist', 'department_head', 'curator', 'teacher', 'cos', 'librarian'
    ];
    
    public function __construct() {
        $this->db = getDB();
        $this->ensureUserRolesTable();
    }

    private function ensureUserRolesTable() {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $this->db->query("CREATE TABLE IF NOT EXISTS user_roles (
            user_id INT NOT NULL,
            role_id INT NOT NULL,
            PRIMARY KEY (user_id, role_id),
            KEY idx_user_roles_role (role_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // Миграция существующих role_id
        $this->db->query("INSERT IGNORE INTO user_roles (user_id, role_id)
            SELECT id, role_id FROM users WHERE role_id IS NOT NULL AND role_id > 0");
    }

    /**
     * Нормализовать список ID ролей из запроса
     */
    public function normalizeRoleIds($roleIds) {
        if (!is_array($roleIds)) {
            $roleIds = [$roleIds];
        }
        $ids = [];
        foreach ($roleIds as $id) {
            $id = (int)$id;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        return array_values($ids);
    }

    /**
     * Выбрать основную роль (для users.role_id и редиректа)
     */
    public function pickPrimaryRoleId(array $roleIds) {
        $roleIds = $this->normalizeRoleIds($roleIds);
        if (empty($roleIds)) {
            return 0;
        }
        $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
        $types = str_repeat('i', count($roleIds));
        $stmt = $this->db->prepare("SELECT id, name FROM roles WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$roleIds);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $byName = [];
        foreach ($rows as $row) {
            $byName[$row['name']] = (int)$row['id'];
        }
        foreach (self::$rolePriority as $name) {
            if (isset($byName[$name])) {
                return $byName[$name];
            }
        }
        return (int)$roleIds[0];
    }

    public function getUserRoleIds($userId) {
        $userId = (int)$userId;
        $stmt = $this->db->prepare("SELECT role_id FROM user_roles WHERE user_id = ? ORDER BY role_id");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $ids = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $ids[] = (int)$row['role_id'];
        }
        if (empty($ids)) {
            // fallback на users.role_id
            $stmt2 = $this->db->prepare("SELECT role_id FROM users WHERE id = ?");
            $stmt2->bind_param('i', $userId);
            $stmt2->execute();
            $u = $stmt2->get_result()->fetch_assoc();
            if ($u && (int)$u['role_id'] > 0) {
                $ids[] = (int)$u['role_id'];
            }
        }
        return $ids;
    }

    public function getUserRoleNames($userId) {
        $ids = $this->getUserRoleIds($userId);
        if (empty($ids)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));
        $stmt = $this->db->prepare("SELECT name FROM roles WHERE id IN ($placeholders)");
        $stmt->bind_param($types, ...$ids);
        $stmt->execute();
        $names = [];
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $names[] = $row['name'];
        }
        return $names;
    }

    public function userHasRoleName($userId, $roleName) {
        return in_array($roleName, $this->getUserRoleNames($userId), true);
    }

    public function setUserRoles($userId, array $roleIds) {
        $userId = (int)$userId;
        $roleIds = $this->normalizeRoleIds($roleIds);
        if (empty($roleIds) || $userId <= 0) {
            return false;
        }
        $primary = $this->pickPrimaryRoleId($roleIds);

        $del = $this->db->prepare("DELETE FROM user_roles WHERE user_id = ?");
        $del->bind_param('i', $userId);
        $del->execute();

        $ins = $this->db->prepare("INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)");
        foreach ($roleIds as $rid) {
            $ins->bind_param('ii', $userId, $rid);
            $ins->execute();
        }

        $upd = $this->db->prepare("UPDATE users SET role_id = ? WHERE id = ?");
        $upd->bind_param('ii', $primary, $userId);
        return $upd->execute();
    }

    private function attachRolesToUser(?array $user) {
        if (!$user) {
            return $user;
        }
        $roleIds = $this->getUserRoleIds((int)$user['id']);
        $user['role_ids'] = $roleIds;
        $names = [];
        $labels = [];
        $byId = [];
        if (!empty($roleIds)) {
            $placeholders = implode(',', array_fill(0, count($roleIds), '?'));
            $types = str_repeat('i', count($roleIds));
            $stmt = $this->db->prepare("SELECT id, name, description FROM roles WHERE id IN ($placeholders)");
            $stmt->bind_param($types, ...$roleIds);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $byId[(int)$row['id']] = $row;
            }
            foreach ($roleIds as $rid) {
                if (!isset($byId[$rid])) {
                    continue;
                }
                $names[] = $byId[$rid]['name'];
                $desc = trim((string)($byId[$rid]['description'] ?? ''));
                if ($desc !== '') {
                    $parts = preg_split('/\s*[—\-]\s*/u', $desc, 2);
                    $labels[] = trim($parts[0]);
                } else {
                    $labels[] = $byId[$rid]['name'];
                }
            }
        }
        $user['role_names'] = $names;
        $user['role_labels'] = $labels;
        if (!empty($names)) {
            $primaryId = (int)($user['role_id'] ?? 0);
            if ($primaryId && isset($byId[$primaryId])) {
                $user['role_name'] = $byId[$primaryId]['name'];
            } else {
                $user['role_name'] = $this->pickPrimaryRoleName($names);
            }
        }
        return $user;
    }

    private function pickPrimaryRoleName(array $names) {
        foreach (self::$rolePriority as $name) {
            if (in_array($name, $names, true)) {
                return $name;
            }
        }
        return $names[0] ?? '';
    }

    public function pickPrimaryRoleNameFromList(array $names) {
        return $this->pickPrimaryRoleName($names);
    }
    
    /**
     * Получить всех пользователей
     */
    public function getAllUsers($search = null) {
        $sql = "SELECT u.*, r.name as role_name, r.description as role_description 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id";
        
        if ($search) {
            $search = '%' . $search . '%';
            $sql .= " WHERE (u.first_name LIKE ? OR u.last_name LIKE ? OR u.middle_name LIKE ? 
                    OR u.login LIKE ? OR u.email LIKE ? OR u.phone LIKE ? 
                    OR r.name LIKE ?
                    OR EXISTS (
                        SELECT 1 FROM user_roles ur2
                        JOIN roles r2 ON r2.id = ur2.role_id
                        WHERE ur2.user_id = u.id AND r2.name LIKE ?
                    ))";
        }
        
        $sql .= " ORDER BY u.created_at DESC";
        
        if ($search) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("ssssssss", $search, $search, $search, $search, $search, $search, $search, $search);
            $stmt->execute();
            $result = $stmt->get_result();
            $users = $result->fetch_all(MYSQLI_ASSOC);
        } else {
            $result = $this->db->query($sql);
            $users = $result->fetch_all(MYSQLI_ASSOC);
        }

        foreach ($users as &$u) {
            $u = $this->attachRolesToUser($u);
        }
        unset($u);
        return $users;
    }
    
    /**
     * Разобрать ФИО: «Фамилия Имя Отчество» → отдельные поля
     */
    public function parseFio($fio) {
        $parts = preg_split('/\s+/u', trim((string)$fio), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts)) {
            $parts = [];
        }
        return [
            'last_name' => $parts[0] ?? '',
            'first_name' => $parts[1] ?? '',
            'middle_name' => isset($parts[2]) ? implode(' ', array_slice($parts, 2)) : ''
        ];
    }

    /**
     * Поиск пользователей по ФИО / логину (для автодополнения)
     */
    public function searchUsersByFio($query, $limit = 10, $excludeId = 0) {
        $q = trim((string)$query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $like = '%' . $q . '%';
        $limit = max(1, min(50, (int)$limit));
        $excludeId = (int)$excludeId;

        $sql = "SELECT u.id, u.first_name, u.last_name, u.middle_name, u.login, u.is_active,
                       r.name as role_name, r.description as role_description
                FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE (
                        u.last_name LIKE ?
                     OR u.first_name LIKE ?
                     OR u.middle_name LIKE ?
                     OR u.login LIKE ?
                     OR CONCAT_WS(' ', u.last_name, u.first_name, u.middle_name) LIKE ?
                     OR CONCAT_WS(' ', u.last_name, u.first_name) LIKE ?
                )";
        if ($excludeId > 0) {
            $sql .= " AND u.id != ?";
        }
        $sql .= " ORDER BY u.last_name, u.first_name LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        if ($excludeId > 0) {
            $stmt->bind_param('ssssssi', $like, $like, $like, $like, $like, $like, $excludeId);
        } else {
            $stmt->bind_param('ssssss', $like, $like, $like, $like, $like, $like);
        }
        $stmt->execute();
        $users = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($users as &$u) {
            $u = $this->attachRolesToUser($u);
        }
        unset($u);
        return $users;
    }

    /**
     * Получить пользователя по ID
     */
    public function getUserById($id) {
        $sql = "SELECT u.*, r.name as role_name, r.description as role_description 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $this->attachRolesToUser($result->fetch_assoc());
    }
    
    /**
     * Получить пользователя по логину или email
     */
    public function getUserByLogin($login) {
        $sql = "SELECT u.*, r.name as role_name, r.permissions 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE (u.login = ? OR u.email = ?) AND u.is_active = 1
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ss", $login, $login);
        $stmt->execute();
        $result = $stmt->get_result();
        return $this->attachRolesToUser($result->fetch_assoc());
    }
    
    /**
     * Создать нового пользователя
     */
    public function createUser($data) {
        $roleIds = $this->normalizeRoleIds($data['role_ids'] ?? ($data['role_id'] ?? []));
        if (empty($roleIds)) {
            return false;
        }
        $primary = $this->pickPrimaryRoleId($roleIds);

        $sql = "INSERT INTO users (first_name, last_name, middle_name, login, password, email, phone, role_id) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        $hashed_password = password_hash($data['password'], PASSWORD_DEFAULT);
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = $email !== '' ? $email : null;
        $phone = $phone !== '' ? $phone : null;
        
        $stmt->bind_param("sssssssi", 
            $data['first_name'], 
            $data['last_name'], 
            $data['middle_name'], 
            $data['login'], 
            $hashed_password, 
            $email, 
            $phone, 
            $primary
        );
        
        if (!$stmt->execute()) {
            return false;
        }
        $newId = (int)$this->db->getLastInsertId();
        return $this->setUserRoles($newId, $roleIds);
    }
    
    /**
     * Обновить пользователя
     */
    public function updateUser($id, $data) {
        $roleIds = $this->normalizeRoleIds($data['role_ids'] ?? ($data['role_id'] ?? []));
        if (empty($roleIds)) {
            return false;
        }
        $primary = $this->pickPrimaryRoleId($roleIds);

        $sql = "UPDATE users SET 
                first_name = ?, last_name = ?, middle_name = ?, 
                login = ?, email = ?, phone = ?, role_id = ?, is_active = ?,
                curator_portal_access = ?, curator_access_request_pending = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        $email = trim((string)($data['email'] ?? ''));
        $phone = trim((string)($data['phone'] ?? ''));
        $email = $email !== '' ? $email : null;
        $phone = $phone !== '' ? $phone : null;
        
        $stmt->bind_param("ssssssiiiii", 
            $data['first_name'], 
            $data['last_name'], 
            $data['middle_name'], 
            $data['login'], 
            $email, 
            $phone, 
            $primary, 
            $data['is_active'],
            $data['curator_portal_access'],
            $data['curator_access_request_pending'],
            $id
        );
        
        if (!$stmt->execute()) {
            return false;
        }
        return $this->setUserRoles((int)$id, $roleIds);
    }

    /**
     * Получить пользователя по логину (включая неактивных)
     */
    public function getUserByLoginAny($login) {
        $sql = "SELECT u.*, r.name as role_name, r.permissions 
                FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.login = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $login);
        $stmt->execute();
        $result = $stmt->get_result();
        return $this->attachRolesToUser($result->fetch_assoc());
    }

    /**
     * Заявка на доступ к порталу от существующего куратора (с проверкой пароля).
     */
    public function requestAccessByCredentials($login, $password) {
        $user = $this->getUserByLoginAny($login);

        if (!$user) {
            return ['success' => false, 'error' => 'Пользователь с таким логином не найден'];
        }

        if (!$user['is_active']) {
            return ['success' => false, 'error' => 'Аккаунт заблокирован. Обратитесь к администратору.'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['success' => false, 'error' => 'Неверный пароль'];
        }

        if (($user['role_name'] ?? '') !== 'curator' && !in_array('curator', $user['role_names'] ?? [], true)) {
            return ['success' => false, 'error' => 'Заявка на доступ к порталу доступна только для роли «Куратор». Обратитесь к администратору.'];
        }

        if ((int) ($user['curator_portal_access'] ?? 0)) {
            return ['success' => true, 'status' => 'already_open', 'message' => 'Доступ к порталу уже открыт. Вы можете войти в систему.'];
        }

        if (!empty($user['curator_access_request_pending'])) {
            return ['success' => true, 'status' => 'pending', 'message' => 'Заявка уже отправлена и ожидает рассмотрения администратором.'];
        }

        if ($this->requestCuratorPortalAccess((int) $user['id'])) {
            return ['success' => true, 'status' => 'submitted', 'message' => 'Заявка отправлена. Администратор рассмотрит её в ближайшее время.'];
        }

        return ['success' => false, 'error' => 'Не удалось отправить заявку. Попробуйте позже.'];
    }

    /**
     * Заявка на новую учётную запись (для пользователей без логина).
     */
    public function submitNewAccountRequest($data) {
        $this->ensureAdminNotificationsTable();

        $last_name = trim($data['last_name'] ?? '');
        $first_name = trim($data['first_name'] ?? '');
        $middle_name = trim($data['middle_name'] ?? '');
        $email = trim($data['email'] ?? '');
        $phone = trim($data['phone'] ?? '');
        $comment = trim($data['comment'] ?? '');

        if ($last_name === '' || $first_name === '' || $email === '') {
            return ['success' => false, 'error' => 'Заполните фамилию, имя и email'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Некорректный email'];
        }

        $full_name = trim("$last_name $first_name $middle_name");

        $check = $this->db->prepare(
            "SELECT id FROM admin_notifications 
             WHERE type = 'access_request' AND message LIKE ? 
             AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 1"
        );
        $email_pattern = '%' . $email . '%';
        $check->bind_param('s', $email_pattern);
        $check->execute();
        if ($check->get_result()->fetch_assoc()) {
            return ['success' => true, 'status' => 'duplicate', 'message' => 'Заявка с этим email уже отправлена за последние 24 часа. Ожидайте ответа администратора.'];
        }

        $title = 'Заявка на учётную запись';
        $message = "ФИО: {$full_name}\nEmail: {$email}\nТелефон: " . ($phone ?: '—') . "\nКомментарий: " . ($comment ?: '—');

        $stmt = $this->db->prepare(
            "INSERT INTO admin_notifications (type, title, message, curator_name) VALUES ('access_request', ?, ?, ?)"
        );
        $stmt->bind_param('sss', $title, $message, $full_name);

        if ($stmt->execute()) {
            return ['success' => true, 'status' => 'submitted', 'message' => 'Заявка отправлена. Администратор создаст учётную запись и свяжется с вами.'];
        }

        return ['success' => false, 'error' => 'Не удалось отправить заявку'];
    }

    private function notifyAdminAccessRequest($title, $message, $curator_id = null, $curator_name = null) {
        $this->ensureAdminNotificationsTable();
        $stmt = $this->db->prepare(
            "INSERT INTO admin_notifications (type, title, message, curator_id, curator_name) VALUES ('access_request', ?, ?, ?, ?)"
        );
        $stmt->bind_param('ssis', $title, $message, $curator_id, $curator_name);
        $stmt->execute();
    }

    private function ensureAdminNotificationsTable() {
        $sql = "CREATE TABLE IF NOT EXISTS admin_notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type VARCHAR(50) NOT NULL,
            title VARCHAR(255) NOT NULL,
            message TEXT NOT NULL,
            iin VARCHAR(20) DEFAULT NULL,
            curator_id INT DEFAULT NULL,
            curator_name VARCHAR(255) DEFAULT NULL,
            is_read TINYINT(1) DEFAULT 0,
            read_at DATETIME DEFAULT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->db->query($sql);
    }

    /**
     * Заявка куратора на открытие доступа к порталу (ожидает решения администратора).
     */
    public function requestCuratorPortalAccess($user_id) {
        $user = $this->getUserById($user_id);
        if (!$user || !empty($user['curator_portal_access'])) {
            return false;
        }

        if (!empty($user['curator_access_request_pending'])) {
            return true;
        }

        $sql = "UPDATE users SET 
                curator_access_request_pending = 1, 
                curator_access_requested_at = NOW()
                WHERE id = ? AND COALESCE(curator_portal_access, 0) = 0";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();

        if ($stmt->affected_rows > 0) {
            $name = trim(($user['last_name'] ?? '') . ' ' . ($user['first_name'] ?? ''));
            $login = $user['login'] ?? '';
            $this->notifyAdminAccessRequest(
                'Заявка куратора на доступ',
                "Куратор {$name} (логин: {$login}) запросил доступ к порталу.",
                (int) $user_id,
                $name
            );
            return true;
        }

        return false;
    }
    
    /**
     * Изменить пароль пользователя
     */
    public function changePassword($id, $new_password) {
        $sql = "UPDATE users SET password = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
        
        $stmt->bind_param("si", $hashed_password, $id);
        return $stmt->execute();
    }
    
    /**
     * Удалить пользователя
     */
    public function deleteUser($id) {
        $sql = "DELETE FROM users WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    
    /**
     * Проверить логин на уникальность
     */
    public function isLoginUnique($login, $exclude_id = null) {
        $sql = "SELECT COUNT(*) as count FROM users WHERE login = ?";
        if ($exclude_id) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $this->db->prepare($sql);
        if ($exclude_id) {
            $stmt->bind_param("si", $login, $exclude_id);
        } else {
            $stmt->bind_param("s", $login);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] == 0;
    }
    
    /**
     * Обновить время последнего входа
     */
    public function updateLastLogin($id) {
        $sql = "UPDATE users SET last_login = NOW() WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    
    /**
     * Получить всех ролей
     */
    public function getAllRoles() {
        $sql = "SELECT * FROM roles ORDER BY name";
        $result = $this->db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Получить пользователей по роли
     */
    public function getUsersByRole($role_id) {
        $role_id = (int)$role_id;
        $sql = "SELECT DISTINCT u.* FROM users u
                INNER JOIN user_roles ur ON ur.user_id = u.id
                WHERE ur.role_id = ? AND u.is_active = 1
                ORDER BY u.last_name, u.first_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $role_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }
}
?>

