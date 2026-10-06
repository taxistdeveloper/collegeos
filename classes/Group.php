<?php

/**
 * Класс для работы с группами
 */
class Group
{
    private $db;
    private $lastError = '';

    public function __construct()
    {
        $this->db = getDB();
        // Нужно для JOIN с department_name в списках групп
        if (class_exists('Department')) {
            Department::ensureTablesExist();
        } elseif (is_file(__DIR__ . '/Department.php')) {
            require_once __DIR__ . '/Department.php';
            Department::ensureTablesExist();
        }
        self::ensureStudyDurationColumn();
    }

    /**
     * Новые сроки обучения (например «1 г. 10 мес.») не влезают в старый ENUM/VARCHAR.
     */
    public static function ensureStudyDurationColumn()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        try {
            $db = getDB();
            $conn = method_exists($db, 'getConnection') ? $db->getConnection() : $db;
            $modeRes = $db->query("SELECT @@SESSION.sql_mode AS m");
            $oldMode = ($modeRes && ($row = $modeRes->fetch_assoc())) ? (string)$row['m'] : '';
            $db->query("SET SESSION sql_mode = ''");

            $tables = ['groups'];
            $students = $db->query("SHOW TABLES LIKE 'students'");
            if ($students && $students->num_rows > 0) {
                $tables[] = 'students';
            }

            foreach ($tables as $table) {
                self::clearZeroDates($db, $table);

                $col = $db->query("SHOW COLUMNS FROM `{$table}` LIKE 'study_duration'");
                if (!$col || $col->num_rows === 0) {
                    continue;
                }
                $info = $col->fetch_assoc();
                $type = strtolower((string)($info['Type'] ?? ''));
                $needsWiden = strpos($type, 'enum(') !== false;
                if (preg_match('/varchar\((\d+)\)/', $type, $m) && (int)$m[1] < 64) {
                    $needsWiden = true;
                }
                if (preg_match('/char\((\d+)\)/', $type, $m) && strpos($type, 'varchar') === false && (int)$m[1] < 64) {
                    $needsWiden = true;
                }
                if ($needsWiden) {
                    $db->query("ALTER TABLE `{$table}` MODIFY `study_duration` VARCHAR(64) NULL DEFAULT NULL");
                }
            }

            if ($oldMode !== '') {
                $escaped = $conn->real_escape_string($oldMode);
                $db->query("SET SESSION sql_mode = '{$escaped}'");
            }
        } catch (Throwable $e) {
            if (class_exists('AdminLog')) {
                AdminLog::error('migration', 'Не удалось обновить study_duration: ' . $e->getMessage());
            }
        }
    }

    private static function clearZeroDates($db, $table)
    {
        $cols = $db->query("SHOW COLUMNS FROM `{$table}`");
        if (!$cols) {
            return;
        }
        while ($col = $cols->fetch_assoc()) {
            $type = strtolower((string)($col['Type'] ?? ''));
            if (strpos($type, 'date') === false && strpos($type, 'time') === false) {
                continue;
            }
            $name = $col['Field'];
            $db->query("UPDATE `{$table}` SET `{$name}` = NULL
                        WHERE `{$name}` = '0000-00-00'
                           OR `{$name}` = '0000-00-00 00:00:00'");
        }
    }

    /**
     * Получить все группы
     */
    public function getAllGroups()
    {
        $sql = "SELECT g.*,
                       u.first_name as curator_first_name,
                       u.last_name as curator_last_name,
                       u.middle_name as curator_middle_name,
                       d.name as department_name,
                       d.id as department_id_joined
                FROM `groups` g
                LEFT JOIN users u ON g.curator_id = u.id
                LEFT JOIN departments d ON d.id = g.department_id
                ORDER BY g.created_at DESC";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Получить группу по ID
     */
    public function getGroupById($id)
    {
        $sql = "SELECT g.*,
                       u.first_name as curator_first_name,
                       u.last_name as curator_last_name,
                       u.middle_name as curator_middle_name,
                       d.name as department_name
                FROM `groups` g
                LEFT JOIN users u ON g.curator_id = u.id
                LEFT JOIN departments d ON d.id = g.department_id
                WHERE g.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Получить группу по коду
     */
    public function getGroupByCode($code)
    {
        $code = trim((string)$code);
        if ($code === '') {
            return null;
        }
        $sql = "SELECT g.*, d.name as department_name
                FROM `groups` g
                LEFT JOIN departments d ON d.id = g.department_id
                WHERE g.code = ?
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('s', $code);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getLastError()
    {
        return $this->lastError;
    }

    private function dbError()
    {
        if (method_exists($this->db, 'getConnection')) {
            $conn = $this->db->getConnection();
            return $conn->error ?? '';
        }
        return $this->db->error ?? '';
    }

    private function fail($message)
    {
        $this->lastError = $message;
        if (class_exists('AdminLog')) {
            AdminLog::error('group', $message);
        }
        return false;
    }

    /**
     * Создать новую группу
     */
    public function createGroup($data)
    {
        $this->lastError = '';

        $name = (string)($data['name'] ?? '');
        $code = (string)($data['code'] ?? '');
        $specialty = (string)($data['specialty'] ?? '');
        $qualification = (string)($data['qualification'] ?? '');
        $curator_id = !empty($data['curator_id']) ? (int)$data['curator_id'] : null;
        $course = (string)($data['course'] ?? '');
        $language = (string)($data['language'] ?? '');
        $study_form = (string)($data['study_form'] ?? '');
        $study_duration = (string)($data['study_duration'] ?? '');
        $start_date = (string)($data['start_date'] ?? '');
        $end_date = !empty($data['end_date']) ? (string)$data['end_date'] : null;
        $arrival_date = (string)($data['arrival_date'] ?? '');
        $enrollment_order_number = (string)($data['enrollment_order_number'] ?? '');
        $arrival_from = (string)($data['arrival_from'] ?? '');
        $education_type = (string)($data['education_type'] ?? '');
        $residence_type = (string)($data['residence_type'] ?? '');
        $max_students = (int)($data['max_students'] ?? 0);
        $description = (string)($data['description'] ?? '');

        try {
            $checkFields = $this->db->query("SHOW COLUMNS FROM `groups` LIKE 'arrival_from'");
            $hasNewFields = $checkFields && $checkFields->num_rows > 0;

            if ($hasNewFields) {
                $sql = "INSERT INTO `groups` (name, code, specialty, qualification, curator_id, course, language, study_form, study_duration, start_date, end_date, arrival_date, enrollment_order_number, arrival_from, education_type, residence_type, max_students, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    return $this->fail('Ошибка подготовки SQL запроса: ' . $this->dbError());
                }
                $stmt->bind_param(
                    "ssssisssssssssssis",
                    $name,
                    $code,
                    $specialty,
                    $qualification,
                    $curator_id,
                    $course,
                    $language,
                    $study_form,
                    $study_duration,
                    $start_date,
                    $end_date,
                    $arrival_date,
                    $enrollment_order_number,
                    $arrival_from,
                    $education_type,
                    $residence_type,
                    $max_students,
                    $description
                );
            } else {
                $sql = "INSERT INTO `groups` (name, code, specialty, qualification, curator_id, course, language, study_form, study_duration, start_date, end_date, arrival_date, enrollment_order_number, max_students, description)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    return $this->fail('Ошибка подготовки SQL запроса: ' . $this->dbError());
                }
                $stmt->bind_param(
                    "ssssissssssssis",
                    $name,
                    $code,
                    $specialty,
                    $qualification,
                    $curator_id,
                    $course,
                    $language,
                    $study_form,
                    $study_duration,
                    $start_date,
                    $end_date,
                    $arrival_date,
                    $enrollment_order_number,
                    $max_students,
                    $description
                );
            }

            if (!$stmt->execute()) {
                return $this->fail('Ошибка сохранения группы: ' . $stmt->error);
            }

            return true;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Обновить группу
     */
    public function updateGroup($id, $data)
    {
        $this->lastError = '';
        $id = (int)$id;

        $name = (string)($data['name'] ?? '');
        $code = (string)($data['code'] ?? '');
        $specialty = (string)($data['specialty'] ?? '');
        $qualification = (string)($data['qualification'] ?? '');
        $curator_id = !empty($data['curator_id']) ? (int)$data['curator_id'] : null;
        $course = (string)($data['course'] ?? '');
        $language = (string)($data['language'] ?? '');
        $study_form = (string)($data['study_form'] ?? '');
        $study_duration = (string)($data['study_duration'] ?? '');
        $start_date = (string)($data['start_date'] ?? '');
        $end_date = !empty($data['end_date']) ? (string)$data['end_date'] : null;
        $arrival_date = (string)($data['arrival_date'] ?? '');
        $enrollment_order_number = (string)($data['enrollment_order_number'] ?? '');
        $arrival_from = (string)($data['arrival_from'] ?? '');
        $education_type = (string)($data['education_type'] ?? '');
        $residence_type = (string)($data['residence_type'] ?? '');
        $max_students = (int)($data['max_students'] ?? 0);
        $description = (string)($data['description'] ?? '');
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;

        try {
            $checkFields = $this->db->query("SHOW COLUMNS FROM `groups` LIKE 'arrival_from'");
            $hasNewFields = $checkFields && $checkFields->num_rows > 0;

            if ($hasNewFields) {
                $sql = "UPDATE `groups` SET
                        name = ?, code = ?, specialty = ?, qualification = ?,
                        curator_id = ?, course = ?, language = ?, study_form = ?,
                        study_duration = ?, start_date = ?, end_date = ?,
                        arrival_date = ?, enrollment_order_number = ?,
                        arrival_from = ?, education_type = ?, residence_type = ?,
                        max_students = ?, description = ?, is_active = ?
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    return $this->fail('Ошибка подготовки SQL запроса: ' . $this->dbError());
                }
                $stmt->bind_param(
                    "ssssisssssssssssisii",
                    $name,
                    $code,
                    $specialty,
                    $qualification,
                    $curator_id,
                    $course,
                    $language,
                    $study_form,
                    $study_duration,
                    $start_date,
                    $end_date,
                    $arrival_date,
                    $enrollment_order_number,
                    $arrival_from,
                    $education_type,
                    $residence_type,
                    $max_students,
                    $description,
                    $is_active,
                    $id
                );
            } else {
                $sql = "UPDATE `groups` SET
                        name = ?, code = ?, specialty = ?, qualification = ?,
                        curator_id = ?, course = ?, language = ?, study_form = ?,
                        study_duration = ?, start_date = ?, end_date = ?,
                        arrival_date = ?, enrollment_order_number = ?,
                        max_students = ?, description = ?, is_active = ?
                        WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                if (!$stmt) {
                    return $this->fail('Ошибка подготовки SQL запроса: ' . $this->dbError());
                }
                $stmt->bind_param(
                    "ssssissssssssisii",
                    $name,
                    $code,
                    $specialty,
                    $qualification,
                    $curator_id,
                    $course,
                    $language,
                    $study_form,
                    $study_duration,
                    $start_date,
                    $end_date,
                    $arrival_date,
                    $enrollment_order_number,
                    $max_students,
                    $description,
                    $is_active,
                    $id
                );
            }

            if (!$stmt->execute()) {
                return $this->fail('Ошибка обновления группы: ' . $stmt->error);
            }

            return true;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Удалить группу
     */
    public function deleteGroup($id)
    {
        $this->lastError = '';
        $id = (int)$id;
        try {
            $sql = "DELETE FROM `groups` WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return $this->fail('Ошибка подготовки SQL запроса: ' . $this->dbError());
            }
            $stmt->bind_param("i", $id);
            if (!$stmt->execute()) {
                return $this->fail('Ошибка удаления группы: ' . $stmt->error);
            }
            return true;
        } catch (Throwable $e) {
            return $this->fail($e->getMessage());
        }
    }

    /**
     * Проверить код группы на уникальность
     */
    public function isCodeUnique($code, $exclude_id = null)
    {
        $sql = "SELECT COUNT(*) as count FROM `groups` WHERE code = ?";
        if ($exclude_id) {
            $sql .= " AND id != ?";
        }

        $stmt = $this->db->prepare($sql);
        if ($exclude_id) {
            $stmt->bind_param("si", $code, $exclude_id);
        } else {
            $stmt->bind_param("s", $code);
        }

        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();

        return $row['count'] == 0;
    }

    /**
     * Получить количество студентов в группе
     */
    public function getStudentCount($group_id)
    {
        $sql = "SELECT COUNT(*) as count FROM students WHERE group_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return $row['count'];
    }

    /**
     * Обновить количество студентов в группе
     */
    public function updateStudentCount($group_id)
    {
        $count = $this->getStudentCount($group_id);
        $sql = "UPDATE `groups` SET current_students = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $count, $group_id);
        return $stmt->execute();
    }

    /**
     * Получить группы куратора
     */
    public function getGroupsByCurator($curator_id, $active_only = true)
    {
        $sql = "SELECT * FROM `groups` WHERE curator_id = ?";
        if ($active_only) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY is_active DESC, name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $curator_id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Проверить, принадлежит ли группа куратору
     */
    public function isCuratorGroup($group_id, $curator_id)
    {
        $sql = "SELECT COUNT(*) as count FROM `groups` WHERE id = ? AND curator_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $group_id, $curator_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        return (int)$row['count'] > 0;
    }

    /**
     * Перевести группу в выпускники: проставить дату выпуска и архивировать группу
     */
    public function graduateGroup($group_id, $curator_id)
    {
        if (!$this->isCuratorGroup($group_id, $curator_id)) {
            return ['success' => false, 'message' => 'Группа не найдена или не принадлежит вам'];
        }

        require_once __DIR__ . '/../includes/student_status.php';
        $not_graduated = sqlNotGraduatedCondition('');
        $sql = "UPDATE students SET graduation_date = CURDATE()
                WHERE group_id = ?
                AND $not_graduated";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $graduated_count = $stmt->affected_rows;

        $sql = "UPDATE `groups` SET is_active = 0 WHERE id = ? AND curator_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("ii", $group_id, $curator_id);
        $stmt->execute();

        $this->updateStudentCount($group_id);

        return [
            'success' => true,
            'graduated_count' => $graduated_count,
            'message' => 'Группа переведена в архив выпускников'
        ];
    }

    /**
     * Получить активные группы
     */
    public function getActiveGroups()
    {
        $sql = "SELECT g.*, u.first_name as curator_first_name, u.last_name as curator_last_name, u.middle_name as curator_middle_name
                FROM `groups` g 
                LEFT JOIN users u ON g.curator_id = u.id 
                WHERE g.is_active = 1 
                ORDER BY g.name";
        $result = $this->db->query($sql);
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Получить статистику по группам
     */
    public function getGroupStats()
    {
        $sql = "SELECT 
                    COUNT(*) as total_groups,
                    SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) as active_groups,
                    SUM(current_students) as total_students,
                    AVG(current_students) as avg_students_per_group
                FROM `groups`";
        $result = $this->db->query($sql);
        return $result->fetch_assoc();
    }

    /**
     * Получить группы по курсу
     */
    public function getGroupsByCourse($course)
    {
        $sql = "SELECT g.*, u.first_name as curator_first_name, u.last_name as curator_last_name, u.middle_name as curator_middle_name
                FROM `groups` g 
                LEFT JOIN users u ON g.curator_id = u.id 
                WHERE g.course = ? AND g.is_active = 1 
                ORDER BY g.name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("s", $course);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_all(MYSQLI_ASSOC);
    }

    public static function currentAcademicYear()
    {
        $year = (int)date('Y');
        $month = (int)date('n');
        return ($month >= 1 && $month <= 7) ? ($year - 1) : $year;
    }

    public static function admissionYearFromText($code, $name = '')
    {
        foreach ([$code, $name] as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (preg_match('/-(20\d{2})(?:\D+)?$/u', $value, $m)) {
                return (int)$m[1];
            }
            if (preg_match('/-\s*(\d{2})(?:\D+)?$/u', $value, $m)) {
                $yy = (int)$m[1];
                if ($yy >= 20 && $yy <= 39) {
                    return 2000 + $yy;
                }
            }
        }
        return null;
    }

    public static function courseLabelForAdmissionYear($admissionYear, $academicYear = null)
    {
        $academicYear = $academicYear !== null ? (int)$academicYear : self::currentAcademicYear();
        $course = $academicYear - (int)$admissionYear + 1;
        if ($course < 1) {
            $course = 1;
        }
        if ($course > 4) {
            $course = 4;
        }
        return $course . ' курс';
    }

    /**
     * Выставить курс по году в коде группы: -26 → 1 курс, -25 → 2 курс и т.д.
     */
    public function syncCoursesByAdmissionYear($academicYear = null)
    {
        $academicYear = $academicYear !== null ? (int)$academicYear : self::currentAcademicYear();
        $result = $this->db->query("SELECT id, name, code, course FROM `groups`");
        if (!$result) {
            return 0;
        }

        $updated = 0;
        $stmtGroup = $this->db->prepare("UPDATE `groups` SET course = ? WHERE id = ?");
        $stmtStudents = null;
        $studentCourseCol = $this->db->query("SHOW COLUMNS FROM students LIKE 'course'");
        if ($studentCourseCol && $studentCourseCol->num_rows > 0) {
            $stmtStudents = $this->db->prepare("UPDATE students SET course = ? WHERE group_id = ?");
        }

        while ($item = $result->fetch_assoc()) {
            $admission = self::admissionYearFromText($item['code'] ?? '', $item['name'] ?? '');
            if (!$admission) {
                continue;
            }
            $course = self::courseLabelForAdmissionYear($admission, $academicYear);
            if (($item['course'] ?? '') === $course) {
                continue;
            }
            $id = (int)$item['id'];
            if ($stmtGroup) {
                $stmtGroup->bind_param('si', $course, $id);
                $stmtGroup->execute();
            }
            if ($stmtStudents) {
                $stmtStudents->bind_param('si', $course, $id);
                $stmtStudents->execute();
            }
            $updated++;
        }

        return $updated;
    }
}
