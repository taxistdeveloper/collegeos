<?php
/**
 * Отделения и заведующие отделениями
 */
class Department
{
    private $db;

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function ensureTablesExist()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;

        $db = getDB();

        $db->query("CREATE TABLE IF NOT EXISTS departments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            code VARCHAR(64) DEFAULT NULL,
            description TEXT NULL,
            head_user_id INT NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_departments_head (head_user_id),
            KEY idx_departments_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS department_members (
            department_id INT NOT NULL,
            user_id INT NOT NULL,
            position VARCHAR(120) DEFAULT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (department_id, user_id),
            KEY idx_department_members_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $col = $db->query("SHOW COLUMNS FROM `groups` LIKE 'department_id'");
        if ($col && $col->num_rows === 0) {
            $db->query("ALTER TABLE `groups` ADD COLUMN department_id INT NULL DEFAULT NULL AFTER curator_id");
            $db->query("ALTER TABLE `groups` ADD KEY idx_groups_department (department_id)");
        }

        $db->query("CREATE TABLE IF NOT EXISTS department_grade_sheets (
            id INT AUTO_INCREMENT PRIMARY KEY,
            department_id INT NOT NULL,
            group_id INT NOT NULL,
            subject_id INT NOT NULL,
            teacher_id INT NULL,
            period_id INT NULL,
            semester TINYINT NOT NULL DEFAULT 1,
            academic_year INT NOT NULL,
            created_by INT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_dept_grade_sheet (group_id, subject_id, semester, academic_year),
            KEY idx_dept_grade_sheets_dept (department_id),
            KEY idx_dept_grade_sheets_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS department_student_grades (
            id INT AUTO_INCREMENT PRIMARY KEY,
            sheet_id INT NOT NULL,
            student_id INT NOT NULL,
            m1 DECIMAL(5,2) NULL,
            m2 DECIMAL(5,2) NULL,
            m3 DECIMAL(5,2) NULL,
            m4 DECIMAL(5,2) NULL,
            attendance DECIMAL(5,2) NULL,
            exam DECIMAL(5,2) NULL,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_dept_student_grade (sheet_id, student_id),
            KEY idx_dept_student_grades_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    /** Месяцы семестра для ведомости оценок */
    public static function gradeMonthLabels($semester)
    {
        $semester = ((int)$semester === 2) ? 2 : 1;
        if ($semester === 2) {
            return ['фев.', 'мар.', 'апр.', 'май'];
        }
        return ['сент.', 'окт.', 'нояб.', 'дек.'];
    }

    /** Номера месяцев календаря (1–12) для колонок m1–m4 */
    public static function gradeMonthNumbers($semester)
    {
        $semester = ((int)$semester === 2) ? 2 : 1;
        return $semester === 2 ? [2, 3, 4, 5] : [9, 10, 11, 12];
    }

    /**
     * Календарный год месяца ведомости.
     * Учебный год Y/Y+1: I сем. = сент–дек Y, II сем. = фев–май Y+1.
     */
    public static function gradeMonthCalendarYear($academicYear, $semester, $monthIndex)
    {
        $academicYear = (int)$academicYear;
        $semester = ((int)$semester === 2) ? 2 : 1;
        return $semester === 2 ? ($academicYear + 1) : $academicYear;
    }

    /**
     * Месяц закрыт (можно ставить оценку), если он полностью закончился.
     * @param int $monthIndex 0..3
     */
    public static function isGradeMonthOpen($academicYear, $semester, $monthIndex, $now = null)
    {
        $monthIndex = (int)$monthIndex;
        if ($monthIndex < 0 || $monthIndex > 3) {
            return false;
        }
        $nums = self::gradeMonthNumbers($semester);
        $month = (int)$nums[$monthIndex];
        $year = self::gradeMonthCalendarYear($academicYear, $semester, $monthIndex);
        $now = $now !== null ? (int)$now : time();
        $nextMonthStart = strtotime(sprintf('%04d-%02d-01 00:00:00', $year, $month) . ' +1 month');
        return $nextMonthStart !== false && $now >= $nextMonthStart;
    }

    /**
     * @return array{m1:bool,m2:bool,m3:bool,m4:bool,attendance:bool,exam:bool}
     */
    public static function gradeEditableFlags($academicYear, $semester, $now = null)
    {
        $open = [];
        for ($i = 0; $i < 4; $i++) {
            $open[$i] = self::isGradeMonthOpen($academicYear, $semester, $i, $now);
        }
        $anyMonth = in_array(true, $open, true);
        $allMonths = !in_array(false, $open, true);
        return [
            'm1' => $open[0],
            'm2' => $open[1],
            'm3' => $open[2],
            'm4' => $open[3],
            'attendance' => $anyMonth,
            'exam' => $allMonths,
        ];
    }

    /** Итого = рейтинг×60% + экзамен×40% */
    public static function calcGradeTotal($rating, $exam)
    {
        if ($rating === null || $exam === null || $rating === '' || $exam === '') {
            return null;
        }
        return round(((float)$rating * 0.6) + ((float)$exam * 0.4), 2);
    }

    public static function calcGradeRating(array $months)
    {
        $vals = [];
        foreach ($months as $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $vals[] = (float)$v;
        }
        if (!$vals) {
            return null;
        }
        return round(array_sum($vals) / count($vals), 2);
    }

    public function getGroupStudents($groupId)
    {
        require_once __DIR__ . '/../includes/student_status.php';
        $groupId = (int)$groupId;
        $notGrad = sqlNotGraduatedCondition('s');
        $sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin, s.academic_leave
                FROM students s
                WHERE s.group_id = ? AND $notGrad
                ORDER BY s.last_name, s.first_name, s.middle_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getOrCreateGradeSheet($data)
    {
        $departmentId = (int)($data['department_id'] ?? 0);
        $groupId = (int)($data['group_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $semester = ((int)($data['semester'] ?? 1) === 2) ? 2 : 1;
        $academicYear = (int)($data['academic_year'] ?? date('Y'));
        $teacherId = !empty($data['teacher_id']) ? (int)$data['teacher_id'] : null;
        $periodId = !empty($data['period_id']) ? (int)$data['period_id'] : null;
        $createdBy = !empty($data['created_by']) ? (int)$data['created_by'] : null;

        if ($departmentId <= 0 || $groupId <= 0 || $subjectId <= 0) {
            return null;
        }

        $find = $this->db->prepare(
            "SELECT * FROM department_grade_sheets
             WHERE group_id = ? AND subject_id = ? AND semester = ? AND academic_year = ?
             LIMIT 1"
        );
        $find->bind_param('iiii', $groupId, $subjectId, $semester, $academicYear);
        $find->execute();
        $existing = $find->get_result()->fetch_assoc();
        if ($existing) {
            $sid = (int)$existing['id'];
            $teacherSql = $teacherId ? (int)$teacherId : 'NULL';
            $periodSql = $periodId ? (int)$periodId : 'NULL';
            $this->db->query(
                "UPDATE department_grade_sheets
                 SET department_id = " . (int)$departmentId . ",
                     teacher_id = $teacherSql,
                     period_id = $periodSql
                 WHERE id = " . $sid
            );
            return $this->getGradeSheetById($sid);
        }

        $teacherSql = $teacherId ? (int)$teacherId : 'NULL';
        $periodSql = $periodId ? (int)$periodId : 'NULL';
        $createdSql = $createdBy ? (int)$createdBy : 'NULL';
        $ok = $this->db->query(
            "INSERT INTO department_grade_sheets
                (department_id, group_id, subject_id, teacher_id, period_id, semester, academic_year, created_by)
             VALUES (
                " . (int)$departmentId . ",
                " . (int)$groupId . ",
                " . (int)$subjectId . ",
                $teacherSql,
                $periodSql,
                " . (int)$semester . ",
                " . (int)$academicYear . ",
                $createdSql
             )"
        );
        if (!$ok) {
            return null;
        }
        return $this->getGradeSheetById((int)$this->db->getLastInsertId());
    }

    public function getGradeSheetById($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT * FROM department_grade_sheets WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getStudentGradesMap($sheetId)
    {
        $sheetId = (int)$sheetId;
        $stmt = $this->db->prepare("SELECT * FROM department_student_grades WHERE sheet_id = ?");
        $stmt->bind_param('i', $sheetId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row['student_id']] = $row;
        }
        return $map;
    }

    /**
     * @param array<int,array> $rows student_id => [m1,m2,m3,m4,attendance,exam]
     */
    public function saveStudentGrades($sheetId, array $rows)
    {
        $sheetId = (int)$sheetId;
        if ($sheetId <= 0) {
            return false;
        }

        $sql = "INSERT INTO department_student_grades
                    (sheet_id, student_id, m1, m2, m3, m4, attendance, exam)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    m1 = VALUES(m1),
                    m2 = VALUES(m2),
                    m3 = VALUES(m3),
                    m4 = VALUES(m4),
                    attendance = VALUES(attendance),
                    exam = VALUES(exam)";
        $stmt = $this->db->prepare($sql);

        foreach ($rows as $studentId => $row) {
            $studentId = (int)$studentId;
            if ($studentId <= 0) {
                continue;
            }
            $m1 = self::nullableGradeSql($row['m1'] ?? null);
            $m2 = self::nullableGradeSql($row['m2'] ?? null);
            $m3 = self::nullableGradeSql($row['m3'] ?? null);
            $m4 = self::nullableGradeSql($row['m4'] ?? null);
            $attendance = self::nullableGradeSql($row['attendance'] ?? null);
            $exam = self::nullableGradeSql($row['exam'] ?? null);
            $stmt->bind_param('iissssss', $sheetId, $studentId, $m1, $m2, $m3, $m4, $attendance, $exam);
            $stmt->execute();
        }
        return true;
    }

    private static function nullableGradeSql($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $n = (float)str_replace(',', '.', (string)$value);
        if ($n < 0) {
            $n = 0;
        }
        if ($n > 100) {
            $n = 100;
        }
        return (string)$n;
    }

    /** % → традиционная оценка (шкала колледжа РК) */
    public static function percentToTraditional($pct)
    {
        if ($pct === null || $pct === '') {
            return null;
        }
        $pct = (float)$pct;
        if ($pct >= 90) {
            return '5';
        }
        if ($pct >= 70) {
            return '4';
        }
        if ($pct >= 50) {
            return '3';
        }
        return '2';
    }

    /**
     * Ячейка сводной ведомости: «5», «4 (70%)», «зачет», «н/з».
     */
    public static function formatSummaryGradeCell($totalPct, $assessment = '')
    {
        if ($totalPct === null || $totalPct === '') {
            return '';
        }
        $pct = (float)$totalPct;
        $pctRound = (int)round($pct);
        $assessment = mb_strtolower(trim((string)$assessment));
        $isPassFail = ($assessment === 'зачет')
            || (mb_strpos($assessment, 'зачет') !== false && mb_strpos($assessment, 'дифф') === false);

        if ($isPassFail) {
            return $pct >= 50 ? 'зачет' : 'н/з';
        }
        if ($pct < 50) {
            return 'н/з';
        }
        $trad = self::percentToTraditional($pct);
        return $trad . ' (' . $pctRound . '%)';
    }

    /** Учебный год по текущему периоду (год начала: сент→год, янв–июль→год−1). */
    public static function resolveAcademicYearFromPeriod($period)
    {
        $defaultYear = (int)date('Y');
        if ($period && !empty($period['start_date'])) {
            $y = (int)date('Y', strtotime($period['start_date']));
            $m = (int)date('n', strtotime($period['start_date']));
            return ($m >= 1 && $m <= 7) ? ($y - 1) : $y;
        }
        $m = (int)date('n');
        return ($m >= 1 && $m <= 7) ? ($defaultYear - 1) : $defaultYear;
    }

    /**
     * Группы с заполненными (или созданными) листами оценок за учебный год.
     * @param int|null $teacherId ограничить группами, где преподаватель ведёт дисциплину
     */
    public function getGroupsForSummaryVedomost($academicYear, $teacherId = null)
    {
        $academicYear = (int)$academicYear;
        $teacherId = $teacherId !== null ? (int)$teacherId : 0;

        $sql = "SELECT DISTINCT g.id, g.name, g.code, g.course, g.is_active,
                       d.name AS department_name, d.id AS department_id
                FROM `groups` g
                INNER JOIN department_grade_sheets sh ON sh.group_id = g.id AND sh.academic_year = ?
                LEFT JOIN departments d ON d.id = g.department_id";
        $types = 'i';
        $params = [$academicYear];

        if ($teacherId > 0) {
            $sql .= " INNER JOIN uchebni_group_subjects gs ON gs.group_id = g.id AND gs.teacher_id = ?";
            $types .= 'i';
            $params[] = $teacherId;
        }

        $sql .= " WHERE g.is_active = 1
                  ORDER BY d.name, g.name";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Все активные группы отделений (для выбора, даже без оценок).
     */
    public function getActiveDepartmentGroups($teacherId = null)
    {
        $teacherId = $teacherId !== null ? (int)$teacherId : 0;
        $sql = "SELECT g.id, g.name, g.code, g.course, g.is_active,
                       d.name AS department_name, d.id AS department_id
                FROM `groups` g
                INNER JOIN departments d ON d.id = g.department_id AND d.is_active = 1";
        $types = '';
        $params = [];
        if ($teacherId > 0) {
            $sql .= " INNER JOIN uchebni_group_subjects gs ON gs.group_id = g.id AND gs.teacher_id = ?";
            $types = 'i';
            $params[] = $teacherId;
        }
        $sql .= " WHERE g.is_active = 1
                  ORDER BY d.name, g.name";
        if ($types !== '') {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Сводная ведомость по группе за учебный год (данные заведующего отделением).
     * @return array{subjects: array, students: array, cells: array<int,array<string,string>>, year: int, semester: int|null}
     */
    public function getSummaryVedomost($groupId, $academicYear, $semester = null)
    {
        $groupId = (int)$groupId;
        $academicYear = (int)$academicYear;
        $semesterFilter = ($semester === null || $semester === '' || (int)$semester === 0)
            ? null
            : (((int)$semester === 2) ? 2 : 1);

        $subjects = [];
        $cells = [];

        if ($groupId <= 0) {
            return [
                'subjects' => [],
                'students' => [],
                'cells' => [],
                'year' => $academicYear,
                'semester' => $semesterFilter,
            ];
        }

        $sql = "SELECT sh.id AS sheet_id, sh.subject_id, sh.semester, sh.teacher_id,
                       s.name AS subject_name, s.code AS subject_code, s.assessment,
                       CONCAT_WS(' ', t.last_name, t.first_name, t.middle_name) AS teacher_name
                FROM department_grade_sheets sh
                JOIN uchebni_subjects s ON s.id = sh.subject_id
                LEFT JOIN uchebni_teachers t ON t.id = sh.teacher_id
                WHERE sh.group_id = ? AND sh.academic_year = ?";
        $types = 'ii';
        $params = [$groupId, $academicYear];
        if ($semesterFilter !== null) {
            $sql .= " AND sh.semester = ?";
            $types .= 'i';
            $params[] = $semesterFilter;
        }
        $sql .= " ORDER BY sh.semester, s.name";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $sheets = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($sheets as $sh) {
            $key = (int)$sh['sheet_id'];
            $assessment = trim((string)($sh['assessment'] ?? ''));
            $title = trim((string)($sh['subject_name'] ?? ''));
            if ($assessment !== '') {
                $title .= ' (' . $assessment . ')';
            }
            if ($semesterFilter === null) {
                $title .= ' · ' . ((int)$sh['semester'] === 2 ? 'II' : 'I') . ' сем.';
            }
            $subjects[] = [
                'key' => $key,
                'sheet_id' => $key,
                'subject_id' => (int)$sh['subject_id'],
                'semester' => (int)$sh['semester'],
                'title' => $title,
                'assessment' => $assessment,
                'teacher_name' => trim((string)($sh['teacher_name'] ?? '')),
            ];

            $gradesMap = $this->getStudentGradesMap($key);
            foreach ($gradesMap as $studentId => $g) {
                $rating = self::calcGradeRating([
                    $g['m1'] ?? null,
                    $g['m2'] ?? null,
                    $g['m3'] ?? null,
                    $g['m4'] ?? null,
                ]);
                $total = self::calcGradeTotal($rating, $g['exam'] ?? null);
                $cells[(int)$studentId][$key] = self::formatSummaryGradeCell($total, $assessment);
            }
        }

        $students = $this->getGroupStudents($groupId);

        return [
            'subjects' => $subjects,
            'students' => $students,
            'cells' => $cells,
            'year' => $academicYear,
            'semester' => $semesterFilter,
        ];
    }

    public function getAllDepartments()
    {
        $sql = "SELECT d.*,
                       u.first_name AS head_first_name,
                       u.last_name AS head_last_name,
                       u.middle_name AS head_middle_name,
                       u.login AS head_login,
                       (SELECT COUNT(*) FROM department_members dm WHERE dm.department_id = d.id) AS members_count,
                       (SELECT COUNT(*) FROM `groups` g WHERE g.department_id = d.id) AS groups_count
                FROM departments d
                LEFT JOIN users u ON u.id = d.head_user_id
                ORDER BY d.name";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getDepartmentById($id)
    {
        $id = (int)$id;
        $sql = "SELECT d.*,
                       u.first_name AS head_first_name,
                       u.last_name AS head_last_name,
                       u.middle_name AS head_middle_name,
                       u.login AS head_login
                FROM departments d
                LEFT JOIN users u ON u.id = d.head_user_id
                WHERE d.id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getDepartmentByHead($userId)
    {
        $userId = (int)$userId;
        $sql = "SELECT d.*,
                       u.first_name AS head_first_name,
                       u.last_name AS head_last_name,
                       u.middle_name AS head_middle_name
                FROM departments d
                LEFT JOIN users u ON u.id = d.head_user_id
                WHERE d.head_user_id = ?
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function createDepartment($data)
    {
        $headId = (int)($data['head_user_id'] ?? 0);
        if ($headId <= 0) {
            return false;
        }
        if ($this->getDepartmentByHead($headId)) {
            return false;
        }

        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return false;
        }
        $code = trim((string)($data['code'] ?? ''));
        $code = $code !== '' ? $code : null;
        $description = trim((string)($data['description'] ?? ''));
        $description = $description !== '' ? $description : null;

        $sql = "INSERT INTO departments (name, code, description, head_user_id) VALUES (?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssi', $name, $code, $description, $headId);
        if (!$stmt->execute()) {
            return false;
        }
        $departmentId = (int)$this->db->getLastInsertId();
        $this->addMember($departmentId, $headId, 'Заведующий отделением');
        return $departmentId;
    }

    public function updateDepartment($id, $data)
    {
        $id = (int)$id;
        $name = trim((string)($data['name'] ?? ''));
        if ($name === '') {
            return false;
        }
        $code = trim((string)($data['code'] ?? ''));
        $code = $code !== '' ? $code : null;
        $description = trim((string)($data['description'] ?? ''));
        $description = $description !== '' ? $description : null;
        $isActive = isset($data['is_active']) ? (int)(bool)$data['is_active'] : 1;

        $sql = "UPDATE departments SET name = ?, code = ?, description = ?, is_active = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssii', $name, $code, $description, $isActive, $id);
        return $stmt->execute();
    }

    public function assignHead($departmentId, $userId)
    {
        $departmentId = (int)$departmentId;
        $userId = (int)$userId;
        if ($departmentId <= 0 || $userId <= 0) {
            return false;
        }
        $existing = $this->getDepartmentByHead($userId);
        if ($existing && (int)$existing['id'] !== $departmentId) {
            return false;
        }
        $sql = "UPDATE departments SET head_user_id = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $userId, $departmentId);
        if (!$stmt->execute()) {
            return false;
        }
        $this->addMember($departmentId, $userId, 'Заведующий отделением');
        return true;
    }

    public function deleteDepartment($id)
    {
        $id = (int)$id;
        $this->db->query("UPDATE `groups` SET department_id = NULL WHERE department_id = " . $id);
        $delMembers = $this->db->prepare("DELETE FROM department_members WHERE department_id = ?");
        $delMembers->bind_param('i', $id);
        $delMembers->execute();
        $stmt = $this->db->prepare("DELETE FROM departments WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    public function getMembers($departmentId)
    {
        $departmentId = (int)$departmentId;
        $sql = "SELECT u.*, dm.position, dm.created_at AS joined_at,
                       r.name AS role_name, r.description AS role_description
                FROM department_members dm
                JOIN users u ON u.id = dm.user_id
                LEFT JOIN roles r ON r.id = u.role_id
                WHERE dm.department_id = ?
                ORDER BY u.last_name, u.first_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function isMember($departmentId, $userId)
    {
        $departmentId = (int)$departmentId;
        $userId = (int)$userId;
        $stmt = $this->db->prepare("SELECT 1 FROM department_members WHERE department_id = ? AND user_id = ? LIMIT 1");
        $stmt->bind_param('ii', $departmentId, $userId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function addMember($departmentId, $userId, $position = null)
    {
        $departmentId = (int)$departmentId;
        $userId = (int)$userId;
        if ($departmentId <= 0 || $userId <= 0) {
            return false;
        }
        $position = $position !== null ? trim((string)$position) : null;
        if ($position === '') {
            $position = null;
        }
        $sql = "INSERT INTO department_members (department_id, user_id, position)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE position = VALUES(position)";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iis', $departmentId, $userId, $position);
        return $stmt->execute();
    }

    public function removeMember($departmentId, $userId)
    {
        $departmentId = (int)$departmentId;
        $userId = (int)$userId;
        $dept = $this->getDepartmentById($departmentId);
        if ($dept && (int)$dept['head_user_id'] === $userId) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM department_members WHERE department_id = ? AND user_id = ?");
        $stmt->bind_param('ii', $departmentId, $userId);
        return $stmt->execute();
    }

    public function getGroups($departmentId)
    {
        $departmentId = (int)$departmentId;
        $sql = "SELECT g.*,
                       u.first_name AS curator_first_name,
                       u.last_name AS curator_last_name,
                       u.middle_name AS curator_middle_name
                FROM `groups` g
                LEFT JOIN users u ON u.id = g.curator_id
                WHERE g.department_id = ?
                ORDER BY g.is_active DESC, g.name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Привязать группу к отделению.
     * @return true|string true при успехе, иначе текст ошибки
     */
    public function attachGroup($groupId, $departmentId)
    {
        $groupId = (int)$groupId;
        $departmentId = (int)$departmentId;
        if ($groupId <= 0 || $departmentId <= 0) {
            return 'Некорректные данные группы или отделения';
        }

        $sql = "SELECT g.id, g.name, g.department_id, d.name AS department_name
                FROM `groups` g
                LEFT JOIN departments d ON d.id = g.department_id
                WHERE g.id = ?
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $group = $stmt->get_result()->fetch_assoc();

        if (!$group) {
            return 'Группа не найдена в портале';
        }

        $currentDeptId = (int)($group['department_id'] ?? 0);
        if ($currentDeptId > 0 && $currentDeptId !== $departmentId) {
            $deptName = trim((string)($group['department_name'] ?? ''));
            if ($deptName === '') {
                $deptName = 'другое отделение';
            }
            return 'Эта группа уже добавлена в отделение «' . $deptName . '»';
        }
        if ($currentDeptId === $departmentId) {
            return 'Эта группа уже есть в вашем отделении';
        }

        if (!$this->setGroupDepartment($groupId, $departmentId)) {
            return 'Не удалось добавить группу';
        }
        return true;
    }

    public function setGroupDepartment($groupId, $departmentId)
    {
        $groupId = (int)$groupId;
        $departmentId = $departmentId !== null ? (int)$departmentId : null;
        if ($departmentId === null || $departmentId === 0) {
            $stmt = $this->db->prepare("UPDATE `groups` SET department_id = NULL WHERE id = ?");
            $stmt->bind_param('i', $groupId);
            return $stmt->execute();
        }
        $stmt = $this->db->prepare("UPDATE `groups` SET department_id = ? WHERE id = ?");
        $stmt->bind_param('ii', $departmentId, $groupId);
        return $stmt->execute();
    }

    public function ownsGroup($departmentId, $groupId)
    {
        $departmentId = (int)$departmentId;
        $groupId = (int)$groupId;
        $stmt = $this->db->prepare("SELECT 1 FROM `groups` WHERE id = ? AND department_id = ? LIMIT 1");
        $stmt->bind_param('ii', $groupId, $departmentId);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    public function getStats($departmentId)
    {
        $departmentId = (int)$departmentId;
        $members = 0;
        $groups = 0;
        $students = 0;

        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM department_members WHERE department_id = ?");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $members = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $this->db->prepare("SELECT COUNT(*) AS c FROM `groups` WHERE department_id = ?");
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $groups = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS c FROM students s
             JOIN `groups` g ON g.id = s.group_id
             WHERE g.department_id = ?"
        );
        $stmt->bind_param('i', $departmentId);
        $stmt->execute();
        $students = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);

        return [
            'members' => $members,
            'groups' => $groups,
            'students' => $students,
        ];
    }

    public static function rolePermissions()
    {
        return [
            'view_own_department' => true,
            'manage_own_department' => true,
            'add_department_staff' => true,
            'view_own_groups' => true,
            'add_groups' => true,
            'edit_groups' => true,
            'view_users' => true,
            'add_users' => true,
            'manage_department_grades' => true,
        ];
    }
}
