<?php
/**
 * Класс для работы с ролями и правами доступа
 */
class Role {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Получить все роли
     */
    public function getAllRoles() {
        $sql = "SELECT * FROM roles ORDER BY name";
        $result = $this->db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }
    
    /**
     * Получить роль по ID
     */
    public function getRoleById($id) {
        $sql = "SELECT * FROM roles WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }
    
    /**
     * Создать новую роль
     */
    public function createRole($data) {
        $sql = "INSERT INTO roles (name, description, permissions) VALUES (?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        
        $permissions_json = json_encode($data['permissions']);
        
        $stmt->bind_param("sss", 
            $data['name'], 
            $data['description'], 
            $permissions_json
        );
        
        return $stmt->execute();
    }

    /**
     * Базовые роли учебной части (создать или обновить права).
     * @return list<string>
     */
    public function ensureUchebniRoles()
    {
        $defs = [
            'methodist' => [
                'description' => 'Учебная часть — расписание, преподаватели, аналитика',
                'permissions' => [
                    'view_uchebni' => true,
                    'manage_teachers' => true,
                    'manage_subjects' => true,
                    'manage_classrooms' => true,
                    'manage_schedule' => true,
                    'auto_schedule' => true,
                    'view_schedule' => true,
                    'view_workload' => true,
                    'manage_workload' => true,
                    'manage_sections' => true,
                    'view_journal' => true,
                    'edit_journal' => true,
                    'view_uchebni_analytics' => true,
                    'view_groups' => true,
                ],
            ],
            'teacher' => [
                'description' => 'Преподаватель — свои оценки, часы и расписание',
                'permissions' => [
                    'view_uchebni' => true,
                    'view_schedule' => true,
                    'view_workload' => true,
                    'view_journal' => true,
                    'edit_own_grades' => true,
                    'edit_journal' => true,
                ],
            ],
        ];

        $messages = [];
        foreach ($defs as $name => $meta) {
            $check = $this->db->prepare("SELECT id FROM roles WHERE name = ?");
            $check->bind_param('s', $name);
            $check->execute();
            $existing = $check->get_result()->fetch_assoc();
            $permissions = json_encode($meta['permissions'], JSON_UNESCAPED_UNICODE);
            $description = $meta['description'];

            if ($existing) {
                $id = (int)$existing['id'];
                $stmt = $this->db->prepare("UPDATE roles SET description = ?, permissions = ? WHERE id = ?");
                $stmt->bind_param('ssi', $description, $permissions, $id);
                $stmt->execute();
                $messages[] = "Роль «{$name}» обновлена";
            } else {
                $stmt = $this->db->prepare("INSERT INTO roles (name, description, permissions) VALUES (?, ?, ?)");
                $stmt->bind_param('sss', $name, $description, $permissions);
                $stmt->execute();
                $messages[] = "Роль «{$name}» создана";
            }
        }

        return $messages;
    }
    
    /**
     * Обновить роль
     */
    public function updateRole($id, $data) {
        $sql = "UPDATE roles SET name = ?, description = ?, permissions = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        
        $permissions_json = json_encode($data['permissions']);
        
        $stmt->bind_param("sssi", 
            $data['name'], 
            $data['description'], 
            $permissions_json,
            $id
        );
        
        return $stmt->execute();
    }
    
    /**
     * Удалить роль
     */
    public function deleteRole($id) {
        // Проверяем, есть ли пользователи с этой ролью
        $sql = "SELECT COUNT(*) as count FROM user_roles WHERE role_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            return false; // Нельзя удалить роль, если есть пользователи с этой ролью
        }

        // fallback на старое поле
        $sql = "SELECT COUNT(*) as count FROM users WHERE role_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['count'] > 0) {
            return false;
        }
        
        $sql = "DELETE FROM roles WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        return $stmt->execute();
    }
    
    /**
     * Проверить название роли на уникальность
     */
    public function isNameUnique($name, $exclude_id = null) {
        $sql = "SELECT COUNT(*) as count FROM roles WHERE name = ?";
        if ($exclude_id) {
            $sql .= " AND id != ?";
        }
        
        $stmt = $this->db->prepare($sql);
        if ($exclude_id) {
            $stmt->bind_param("si", $name, $exclude_id);
        } else {
            $stmt->bind_param("s", $name);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] == 0;
    }
    
    /**
     * Получить все доступные права
     */
    public function getAllPermissions() {
        return [
            'users' => [
                'view_users' => 'Просмотр пользователей',
                'add_users' => 'Добавление пользователей',
                'edit_users' => 'Редактирование пользователей',
                'delete_users' => 'Удаление пользователей',
                'change_passwords' => 'Смена паролей'
            ],
            'students' => [
                'view_students' => 'Просмотр студентов',
                'view_all_students' => 'Просмотр всех студентов',
                'add_students' => 'Добавление студентов',
                'edit_students' => 'Редактирование студентов',
                'edit_all_students' => 'Редактирование всех студентов',
                'delete_students' => 'Удаление студентов',
                'view_own_students' => 'Просмотр своих студентов'
            ],
            'groups' => [
                'view_groups' => 'Просмотр групп',
                'add_groups' => 'Добавление групп',
                'edit_groups' => 'Редактирование групп',
                'delete_groups' => 'Удаление групп',
                'view_own_groups' => 'Просмотр своих групп'
            ],
            'departments' => [
                'view_own_department' => 'Просмотр своего отделения',
                'manage_own_department' => 'Управление своим отделением',
                'add_department_staff' => 'Добавление сотрудников отделения',
                'view_departments' => 'Просмотр всех отделений',
                'manage_departments' => 'Управление всеми отделениями'
            ],
            'reports' => [
                'view_reports' => 'Просмотр отчетов',
                'export_reports' => 'Экспорт отчетов'
            ],
            'spravki' => [
                'view_spravki' => 'Просмотр справок',
                'issue_spravki' => 'Выдача справок',
                'add_spravki' => 'Добавление справок',
                'edit_spravki' => 'Редактирование справок',
                'delete_spravki' => 'Удаление справок'
            ],
            'library' => [
                'view_library' => 'Просмотр библиотеки',
                'manage_books' => 'Управление книгами (добавление, редактирование, списание)',
                'issue_books' => 'Выдача книг',
                'return_books' => 'Приём книг',
                'reserve_books' => 'Бронирование книг',
                'extend_loans' => 'Продление срока выдачи'
            ],
            'uchebni' => [
                'view_uchebni' => 'Просмотр учебной части',
                'manage_teachers' => 'Управление преподавателями',
                'manage_subjects' => 'Управление дисциплинами',
                'manage_classrooms' => 'Управление аудиториями',
                'manage_schedule' => 'Управление расписанием',
                'auto_schedule' => 'Автоформирование расписания',
                'view_schedule' => 'Просмотр своего/общего расписания',
                'view_workload' => 'Просмотр нагрузки',
                'manage_workload' => 'Управление нагрузкой',
                'view_journal' => 'Просмотр сводной ведомости',
                'edit_own_grades' => 'Выставление оценок по своим дисциплинам',
                'edit_journal' => 'Редактирование журнала (мобильное)',
                'view_uchebni_analytics' => 'Аналитика учебной части'
            ],
            'roles' => [
                'view_roles' => 'Просмотр ролей',
                'add_roles' => 'Добавление ролей',
                'edit_roles' => 'Редактирование ролей',
                'delete_roles' => 'Удаление ролей'
            ],
            'system' => [
                'admin_access' => 'Административный доступ',
                'view_logs' => 'Просмотр логов',
                'system_settings' => 'Настройки системы'
            ]
        ];
    }
    
    /**
     * Проверить права пользователя
     */
    public function hasPermission($user_id, $permission) {
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
        
        // Если есть право "all", то доступ ко всему
        if (isset($permissions['all']) && $permissions['all']) {
            return true;
        }
        
        // Проверяем конкретное право
        return isset($permissions[$permission]) && $permissions[$permission];
    }
}
?>
