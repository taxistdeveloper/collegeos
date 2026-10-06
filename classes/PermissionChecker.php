<?php
/**
 * Класс для проверки прав доступа пользователей
 */
class PermissionChecker {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Проверить права пользователя
     */
    public function hasPermission($user_id, $permission) {
        $sql = "SELECT r.permissions FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                JOIN users u ON u.id = ur.user_id
                WHERE ur.user_id = ? AND u.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $merged = [];
        $found = false;
        while ($row = $result->fetch_assoc()) {
            $found = true;
            if (!$row['permissions']) {
                continue;
            }
            $permissions = json_decode($row['permissions'], true);
            if (!is_array($permissions)) {
                continue;
            }
            if (!empty($permissions['all'])) {
                return true;
            }
            foreach ($permissions as $key => $val) {
                if ($val) {
                    $merged[$key] = true;
                }
            }
        }

        if (!$found) {
            $sql = "SELECT r.permissions FROM users u 
                    LEFT JOIN roles r ON u.role_id = r.id 
                    WHERE u.id = ? AND u.is_active = 1";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            $row = $result->fetch_assoc();
            
            if (!$row || !$row['permissions']) {
                return false;
            }
            
            $permissions = json_decode($row['permissions'], true);
            
            if (isset($permissions['all']) && $permissions['all']) {
                return true;
            }
            
            return isset($permissions[$permission]) && $permissions[$permission];
        }

        return !empty($merged[$permission]);
    }
    
    /**
     * Проверить права текущего пользователя из сессии
     */
    public function checkPermission($permission) {
        // Сессия админ-панели — полный доступ к общим страницам (просмотр/редактирование и т.д.)
        if (!empty($_SESSION['admin_logged_in'])) {
            return true;
        }

        if (!isset($_SESSION['user_id'])) {
            return false;
        }
        
        return $this->hasPermission($_SESSION['user_id'], $permission);
    }
    
    /**
     * Проверить права и перенаправить, если нет доступа
     */
    public function requirePermission($permission, $redirect_url = null) {
        if (!$this->checkPermission($permission)) {
            // Определяем правильный путь к unauthorized.php в зависимости от текущей директории
            if ($redirect_url === null) {
                $current_dir = dirname($_SERVER['PHP_SELF']);
                if (strpos($current_dir, '/curator') !== false || strpos($current_dir, '/admin') !== false || strpos($current_dir, '/manager') !== false || strpos($current_dir, '/director') !== false || strpos($current_dir, '/cos') !== false || strpos($current_dir, '/library') !== false || strpos($current_dir, '/uchebni') !== false) {
                    $redirect_url = '../unauthorized.php';
                } else {
                    $redirect_url = 'unauthorized.php';
                }
            }
            header('Location: ' . $redirect_url);
            exit;
        }
    }
    
    /**
     * Получить все права пользователя
     */
    public function getUserPermissions($user_id) {
        $sql = "SELECT r.permissions FROM user_roles ur
                JOIN roles r ON r.id = ur.role_id
                JOIN users u ON u.id = ur.user_id
                WHERE ur.user_id = ? AND u.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $merged = [];
        $found = false;
        while ($row = $result->fetch_assoc()) {
            $found = true;
            if (!$row['permissions']) {
                continue;
            }
            $permissions = json_decode($row['permissions'], true);
            if (!is_array($permissions)) {
                continue;
            }
            foreach ($permissions as $key => $val) {
                if ($val) {
                    $merged[$key] = true;
                }
            }
        }

        if ($found) {
            return $merged;
        }

        $sql = "SELECT r.permissions FROM users u 
                LEFT JOIN roles r ON u.role_id = r.id 
                WHERE u.id = ? AND u.is_active = 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if (!$row || !$row['permissions']) {
            return [];
        }
        
        return json_decode($row['permissions'], true) ?? [];
    }
    
    /**
     * Проверить, является ли пользователь администратором
     */
    public function isAdmin($user_id) {
        return $this->hasPermission($user_id, 'admin_access') || $this->hasPermission($user_id, 'all');
    }
    
    /**
     * Проверить, может ли пользователь редактировать студента
     */
    public function canEditStudent($user_id, $student_id = null) {
        // Администратор может редактировать всех
        if ($this->hasPermission($user_id, 'edit_all_students') || $this->hasPermission($user_id, 'all')) {
            return true;
        }
        
        // Куратор может редактировать только своих студентов
        if ($this->hasPermission($user_id, 'edit_students')) {
            if ($student_id) {
                // Здесь можно добавить проверку, является ли студент подопечным куратора
                // Пока что возвращаем true для всех студентов
                return true;
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Проверить, может ли пользователь просматривать студента
     */
    public function canViewStudent($user_id, $student_id = null) {
        // Администратор может просматривать всех
        if ($this->hasPermission($user_id, 'view_all_students') || $this->hasPermission($user_id, 'all')) {
            return true;
        }
        
        // Куратор может просматривать своих студентов
        if ($this->hasPermission($user_id, 'view_own_students') || $this->hasPermission($user_id, 'view_students')) {
            if ($student_id) {
                // Здесь можно добавить проверку, является ли студент подопечным куратора
                // Пока что возвращаем true для всех студентов
                return true;
            }
            return true;
        }
        
        return false;
    }
}
?>
