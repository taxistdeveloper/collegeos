<?php
/**
 * Учебная часть: преподаватели, расписание, журнал, аналитика
 */
require_once __DIR__ . '/../includes/student_status.php';

class Uchebni
{
    private $db;

    /** Совместимость: weekday, смена 1 (актуальное время — из БД через getPairTimes) */
    const PAIR_TIMES = [
        1 => ['08:30:00', '09:50:00'],
        2 => ['10:00:00', '11:20:00'],
        3 => ['11:35:00', '12:55:00'],
        4 => ['13:05:00', '14:25:00'],
    ];

    /** Пн–Пт (субботы нет) */
    const DAY_NAMES = [
        1 => 'Понедельник',
        2 => 'Вторник',
        3 => 'Среда',
        4 => 'Четверг',
        5 => 'Пятница',
    ];

    const SHIFT_NAMES = [
        1 => 'I смена',
        2 => 'II смена',
    ];

    /** Неделя: каждую / числитель / знаменатель */
    const WEEK_KIND_ALL = 'all';
    const WEEK_KIND_NUM = 'num';
    const WEEK_KIND_DEN = 'den';

    const WEEK_KIND_NAMES = [
        'all' => 'Каждую неделю',
        'num' => 'Числитель (1-я неделя)',
        'den' => 'Знаменатель (2-я неделя)',
    ];

    const WEEK_KIND_SHORT = [
        'all' => '',
        'num' => 'Ч',
        'den' => 'З',
    ];

    /** Посещаемость в журнале пары */
    const ATTENDANCE_PRESENT = 'present';
    const ATTENDANCE_ABSENT = 'absent';
    const ATTENDANCE_LATE = 'late';
    const ATTENDANCE_EXCUSED = 'excused';
    const ATTENDANCE_SICK = 'sick';
    const ATTENDANCE_FLED = 'fled';

    const ATTENDANCE_VALUES = [
        self::ATTENDANCE_PRESENT,
        self::ATTENDANCE_ABSENT,
        self::ATTENDANCE_LATE,
        self::ATTENDANCE_EXCUSED,
        self::ATTENDANCE_SICK,
        self::ATTENDANCE_FLED,
    ];

    const ATTENDANCE_NAMES = [
        'present' => 'Присутствует',
        'absent' => 'Нет',
        'late' => 'Опоздал',
        'excused' => 'Уважительная',
        'sick' => 'Болеет',
        'fled' => 'Сбежал',
    ];

    const BELL_DAY_TYPES = [
        'weekday' => 'Пн, Ср, Чт, Пт',
        'tuesday' => 'Вторник',
    ];

    /** Недель в семестрах (заголовок учебного плана) */
    const WEEKS_SEM1 = 17;
    const WEEKS_SEM2 = 20;

    /** Академических часов за одну пару */
    const HOURS_PER_PAIR = 2;

    /** Академических часов за кураторский час (слот ~45 мин) */
    const CURATOR_HOURS = 1;

    /** Макс. недельная нагрузка (акад. ч) — группа и преподаватель */
    const MAX_WEEKLY_HOURS = 36;

    const MONTH_NAMES_RU = [
        1 => 'янв', 2 => 'фев', 3 => 'март', 4 => 'апр',
        5 => 'май', 6 => 'июнь', 7 => 'июль', 8 => 'авг',
        9 => 'сент', 10 => 'окт', 11 => 'нояб', 12 => 'декаб',
    ];

    const ASSESSMENT_TYPES = [
        '' => '—',
        'экзамен' => 'экзамен',
        'зачет' => 'зачет',
        'дифф. зачет' => 'дифф. зачет',
        'курсовой проект' => 'курсовой проект',
        'курсовая работа' => 'курсовая работа',
    ];

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function formatFio($row, $prefix = '')
    {
        $ln = $row[$prefix . 'last_name'] ?? '';
        $fn = $row[$prefix . 'first_name'] ?? '';
        $mn = $row[$prefix . 'middle_name'] ?? '';
        if (preg_match('/^[-–—−.]+$/u', trim((string)$mn))) {
            $mn = '';
        }
        return trim("$ln $fn $mn");
    }

    /** SQL-выражение полного ФИО (фамилия, имя, отчество). */
    public static function sqlFio($alias = 't')
    {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$alias) ?: 't';
        return "TRIM(CONCAT_WS(' ', {$a}.last_name, {$a}.first_name, NULLIF(TRIM({$a}.middle_name), '')))";
    }

    /**
     * Нормализация ФИО для сопоставления (импорт Word и т.п.):
     * регистр, пробелы, «-» вместо отчества, казахские буквы → обычные кириллические.
     */
    public static function normalizeFioForMatch($s)
    {
        $s = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$s)));
        if ($s === '' || preg_match('/^[-–—−.]+$/u', $s)) {
            return '';
        }
        $s = strtr($s, [
            'ә' => 'а', 'ғ' => 'г', 'қ' => 'к', 'ң' => 'н',
            'ө' => 'о', 'ұ' => 'у', 'ү' => 'у', 'һ' => 'х', 'і' => 'и',
        ]);
        return preg_replace('/[\s\-–—−.]+/u', '', $s);
    }

    /** «Русский язык, экзамен» */
    public static function formatSubjectTitle($row)
    {
        $name = trim((string)($row['name'] ?? ''));
        $assessment = trim((string)($row['assessment'] ?? ''));
        if ($assessment !== '') {
            return $name . ', ' . $assessment;
        }
        return $name;
    }

    /** «ООД 1» */
    public static function formatSubjectNumber($row)
    {
        $code = trim((string)($row['category_code'] ?? ''));
        $num = (int)($row['item_number'] ?? 0);
        if ($code !== '' && $num > 0) {
            return $code . ' ' . $num;
        }
        if ($code !== '') {
            return $code;
        }
        return $num > 0 ? (string)$num : '—';
    }

    /** «ООД Общеобразовательные дисциплины» */
    public static function formatCategoryHeader($row)
    {
        $code = trim((string)($row['category_code'] ?? ''));
        $title = trim((string)($row['category_name'] ?? ''));
        return trim($code . ' ' . $title);
    }

    public static function ensureTablesExist()
    {
        $db = getDB();

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_teachers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NULL,
            last_name VARCHAR(100) NOT NULL,
            first_name VARCHAR(100) NOT NULL,
            middle_name VARCHAR(100) NULL,
            phone VARCHAR(50) NULL,
            email VARCHAR(200) NULL,
            max_hours_per_week SMALLINT NOT NULL DEFAULT 36,
            specialization VARCHAR(300) NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_teacher_user (user_id),
            INDEX idx_teacher_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(300) NOT NULL,
            code VARCHAR(50) NULL,
            category_code VARCHAR(20) NULL,
            category_name VARCHAR(300) NULL,
            item_number SMALLINT NOT NULL DEFAULT 1,
            assessment VARCHAR(100) NULL,
            hours_sem1 SMALLINT NOT NULL DEFAULT 0,
            hours_sem2 SMALLINT NOT NULL DEFAULT 0,
            hours_per_semester SMALLINT NOT NULL DEFAULT 0,
            description TEXT NULL,
            pending_teacher VARCHAR(300) NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            INDEX idx_subject_name (name(100)),
            INDEX idx_subject_category (category_code, item_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::migrateSubjectsSchema($db);

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_classrooms (
            id INT AUTO_INCREMENT PRIMARY KEY,
            number VARCHAR(50) NOT NULL,
            building VARCHAR(100) NULL,
            capacity SMALLINT NOT NULL DEFAULT 30,
            equipment TEXT NULL,
            is_active TINYINT NOT NULL DEFAULT 1,
            INDEX idx_classroom_number (number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            is_current TINYINT NOT NULL DEFAULT 0,
            INDEX idx_period_current (is_current)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_teacher_subjects (
            teacher_id INT NOT NULL,
            subject_id INT NOT NULL,
            PRIMARY KEY (teacher_id, subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_teacher_busy (
            id INT AUTO_INCREMENT PRIMARY KEY,
            teacher_id INT NOT NULL,
            day_of_week TINYINT NOT NULL,
            pair_number TINYINT NOT NULL,
            reason VARCHAR(300) NULL,
            UNIQUE KEY uk_teacher_busy (teacher_id, day_of_week, pair_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_group_subjects (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            group_id INT NOT NULL,
            subject_id INT NOT NULL,
            teacher_id INT NULL,
            hours_per_week SMALLINT NOT NULL DEFAULT 2,
            lesson_type ENUM('lecture','practice','lab') NOT NULL DEFAULT 'lecture',
            INDEX idx_gs_period (period_id),
            INDEX idx_gs_group (group_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            group_id INT NOT NULL,
            subject_id INT NOT NULL,
            teacher_id INT NOT NULL,
            classroom_id INT NULL,
            day_of_week TINYINT NOT NULL,
            pair_number TINYINT NOT NULL,
            shift TINYINT NOT NULL DEFAULT 1,
            start_time TIME NULL,
            end_time TIME NULL,
            lesson_type ENUM('lecture','practice','lab','curator') NOT NULL DEFAULT 'lecture',
            is_active TINYINT NOT NULL DEFAULT 1,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_sched_period (period_id),
            INDEX idx_sched_group (group_id, day_of_week, pair_number),
            INDEX idx_sched_teacher (teacher_id, day_of_week, pair_number),
            INDEX idx_sched_room (classroom_id, day_of_week, pair_number),
            INDEX idx_sched_shift (shift, day_of_week, pair_number)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_journal (
            id INT AUTO_INCREMENT PRIMARY KEY,
            schedule_id INT NOT NULL,
            student_id INT NOT NULL,
            lesson_date DATE NOT NULL,
            grade DECIMAL(4,2) NULL,
            attendance ENUM('present','absent','late','excused','sick','fled') NOT NULL DEFAULT 'present',
            note TEXT NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_journal_entry (schedule_id, student_id, lesson_date),
            INDEX idx_journal_student (student_id),
            INDEX idx_journal_date (lesson_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_lesson_status (
            id INT AUTO_INCREMENT PRIMARY KEY,
            schedule_id INT NOT NULL,
            lesson_date DATE NOT NULL,
            status ENUM('scheduled','held','cancelled') NOT NULL DEFAULT 'scheduled',
            note TEXT NULL,
            marked_by INT NULL,
            marked_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_lesson_status (schedule_id, lesson_date),
            INDEX idx_lesson_status_date (lesson_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_substitutions (
            id INT AUTO_INCREMENT PRIMARY KEY,
            schedule_id INT NOT NULL,
            lesson_date DATE NOT NULL,
            original_teacher_id INT NOT NULL,
            substitute_teacher_id INT NOT NULL,
            substitute_subject_id INT NULL,
            reason VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uk_substitution (schedule_id, lesson_date),
            INDEX idx_subst_date (lesson_date),
            INDEX idx_subst_teacher (substitute_teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        self::migrateScheduleSchema($db);
        self::migrateJournalAttendanceSchema($db);
        self::ensureBellScheduleTable($db);
        // Кураторский час всегда «каждую неделю», без числителя/знаменателя
        $db->query(
            "UPDATE uchebni_schedule
             SET week_kind = 'all', lesson_type = 'curator'
             WHERE is_active = 1 AND (pair_number = 0 OR lesson_type = 'curator')"
        );
        self::ensureSectionsAndWorkloadTables($db);
        self::ensureHourEntryTables($db);
        self::seedDefaultPeriod($db);
        self::seedDefaultClassrooms($db);
    }

    /**
     * Редактируемые часы преподавателя (как в nagruzka) + практика и covers.
     */
    private static function ensureHourEntryTables($db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS uchebni_hour_entries (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            teacher_id INT NOT NULL,
            subject_id INT NOT NULL,
            group_id INT NOT NULL DEFAULT 0,
            entry_date DATE NOT NULL,
            hours SMALLINT NOT NULL DEFAULT 0,
            week_part ENUM('both','num','den') NOT NULL DEFAULT 'both',
            note ENUM('manual','from_schedule') NOT NULL DEFAULT 'manual',
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_hour_cell (period_id, teacher_id, subject_id, group_id, entry_date, week_part),
            INDEX idx_he_teacher_period (teacher_id, period_id),
            INDEX idx_he_date (entry_date),
            INDEX idx_he_note (period_id, teacher_id, note)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_practice_periods (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            group_id INT NULL,
            title VARCHAR(200) NOT NULL DEFAULT 'практика',
            start_date DATE NOT NULL,
            end_date DATE NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_pp_period (period_id),
            INDEX idx_pp_dates (start_date, end_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_schedule_covers (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            cover_teacher_id INT NOT NULL,
            lesson_date DATE NOT NULL,
            shift TINYINT NOT NULL DEFAULT 1,
            pair_number TINYINT NOT NULL,
            group_id INT NOT NULL,
            subject_id INT NOT NULL,
            replaced_teacher_name VARCHAR(200) NULL,
            replaced_subject_name VARCHAR(300) NULL,
            note VARCHAR(500) NULL,
            created_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cover_teacher_date (cover_teacher_id, lesson_date),
            INDEX idx_cover_date (lesson_date),
            INDEX idx_cover_period (period_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    private static function ensureSectionsAndWorkloadTables($db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS uchebni_sections (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            subject_id INT NOT NULL,
            stream TINYINT NOT NULL DEFAULT 1,
            section_count SMALLINT NOT NULL DEFAULT 1,
            headcount SMALLINT NOT NULL DEFAULT 0,
            status ENUM('active','disbanded') NOT NULL DEFAULT 'active',
            note VARCHAR(500) NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_section (period_id, subject_id, stream),
            INDEX idx_section_period (period_id),
            INDEX idx_section_subject (subject_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS uchebni_workload_months (
            id INT AUTO_INCREMENT PRIMARY KEY,
            period_id INT NOT NULL,
            teacher_id INT NOT NULL,
            group_id INT NOT NULL,
            subject_id INT NOT NULL,
            plan_month CHAR(7) NOT NULL,
            hours_plan SMALLINT NOT NULL DEFAULT 0,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uk_workload_month (period_id, teacher_id, group_id, subject_id, plan_month),
            INDEX idx_wm_teacher (teacher_id, period_id),
            INDEX idx_wm_month (plan_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Старое имя колонки year_month — зарезервировано в MySQL
        $wmCols = $db->query("SHOW COLUMNS FROM uchebni_workload_months LIKE 'year_month'");
        if ($wmCols && $wmCols->num_rows > 0) {
            $db->query("ALTER TABLE uchebni_workload_months CHANGE COLUMN year_month plan_month CHAR(7) NOT NULL");
        }
    }

    private static function migrateScheduleSchema($db)
    {
        $check = $db->query("SHOW COLUMNS FROM uchebni_schedule LIKE 'shift'");
        if ($check && $check->num_rows === 0) {
            $db->query("ALTER TABLE uchebni_schedule ADD COLUMN shift TINYINT NOT NULL DEFAULT 1 AFTER pair_number");
            $db->query("ALTER TABLE uchebni_schedule ADD INDEX idx_sched_shift (shift, day_of_week, pair_number)");
        }

        // Добавить curator в ENUM lesson_type, если ещё нет
        $col = $db->query("SHOW COLUMNS FROM uchebni_schedule LIKE 'lesson_type'");
        if ($col && ($row = $col->fetch_assoc())) {
            $type = (string)($row['Type'] ?? '');
            if (stripos($type, 'curator') === false) {
                $db->query("ALTER TABLE uchebni_schedule MODIFY COLUMN lesson_type ENUM('lecture','practice','lab','curator') NOT NULL DEFAULT 'lecture'");
            }
        }
        $colGs = $db->query("SHOW COLUMNS FROM uchebni_group_subjects LIKE 'lesson_type'");
        if ($colGs && ($row = $colGs->fetch_assoc())) {
            $type = (string)($row['Type'] ?? '');
            if (stripos($type, 'curator') === false) {
                $db->query("ALTER TABLE uchebni_group_subjects MODIFY COLUMN lesson_type ENUM('lecture','practice','lab','curator') NOT NULL DEFAULT 'lecture'");
            }
        }

        $subCol = $db->query("SHOW COLUMNS FROM uchebni_substitutions LIKE 'substitute_subject_id'");
        if ($subCol && $subCol->num_rows === 0) {
            $db->query("ALTER TABLE uchebni_substitutions ADD COLUMN substitute_subject_id INT NULL AFTER substitute_teacher_id");
        }

        $wk = $db->query("SHOW COLUMNS FROM uchebni_schedule LIKE 'week_kind'");
        if ($wk && $wk->num_rows === 0) {
            $db->query("ALTER TABLE uchebni_schedule
                ADD COLUMN week_kind ENUM('all','num','den') NOT NULL DEFAULT 'all' AFTER shift");
            $db->query("ALTER TABLE uchebni_schedule ADD INDEX idx_sched_week_kind (week_kind, day_of_week, pair_number)");
        }

        // Кабинет можно назначить вручную после автоформирования
        $roomCol = $db->query("SHOW COLUMNS FROM uchebni_schedule LIKE 'classroom_id'");
        if ($roomCol && ($row = $roomCol->fetch_assoc())) {
            $null = strtoupper((string)($row['Null'] ?? ''));
            if ($null === 'NO') {
                $db->query("ALTER TABLE uchebni_schedule MODIFY COLUMN classroom_id INT NULL");
            }
        }
    }

    /** Расширение статусов посещаемости: болеет / сбежал */
    private static function migrateJournalAttendanceSchema($db)
    {
        $col = $db->query("SHOW COLUMNS FROM uchebni_journal LIKE 'attendance'");
        if (!$col || !($row = $col->fetch_assoc())) {
            return;
        }
        $type = strtolower((string)($row['Type'] ?? ''));
        if (strpos($type, "'sick'") !== false && strpos($type, "'fled'") !== false) {
            return;
        }
        $db->query("ALTER TABLE uchebni_journal
            MODIFY COLUMN attendance ENUM('present','absent','late','excused','sick','fled')
            NOT NULL DEFAULT 'present'");
    }

    public static function normalizeAttendance($value)
    {
        $value = (string)$value;
        return in_array($value, self::ATTENDANCE_VALUES, true)
            ? $value
            : self::ATTENDANCE_PRESENT;
    }

    /** Совместимы ли две недели (числитель/знаменатель могут делить один слот) */
    public static function weekKindsConflict($a, $b)
    {
        $a = self::normalizeWeekKind($a);
        $b = self::normalizeWeekKind($b);
        if ($a === self::WEEK_KIND_ALL || $b === self::WEEK_KIND_ALL) {
            return true;
        }
        return $a === $b;
    }

    public static function normalizeWeekKind($kind)
    {
        $kind = (string)$kind;
        if ($kind === self::WEEK_KIND_NUM || $kind === self::WEEK_KIND_DEN) {
            return $kind;
        }
        return self::WEEK_KIND_ALL;
    }

    /**
     * Числитель/знаменатель относительно начала периода (неделя 0 = числитель).
     */
    public function getWeekKindForDate($date, $period = null)
    {
        if (!$period) {
            $period = $this->getCurrentPeriod();
        }
        $ts = strtotime((string)$date);
        if (!$ts) {
            return self::WEEK_KIND_NUM;
        }
        $start = $period && !empty($period['start_date'])
            ? strtotime($period['start_date'])
            : $ts;
        // Понедельник недели начала периода
        $startDow = (int)date('N', $start);
        $periodMonday = strtotime('-' . ($startDow - 1) . ' days', $start);
        $dateDow = (int)date('N', $ts);
        $dateMonday = strtotime('-' . ($dateDow - 1) . ' days', $ts);
        $weekIndex = (int)floor(($dateMonday - $periodMonday) / 604800);
        if ($weekIndex < 0) {
            $weekIndex = 0;
        }
        return ($weekIndex % 2 === 0) ? self::WEEK_KIND_NUM : self::WEEK_KIND_DEN;
    }

    public static function slotActiveOnWeekKind($slotWeekKind, $viewWeekKind)
    {
        $slotWeekKind = self::normalizeWeekKind($slotWeekKind);
        if ($slotWeekKind === self::WEEK_KIND_ALL) {
            return true;
        }
        return $slotWeekKind === self::normalizeWeekKind($viewWeekKind);
    }

    /** Слот кураторского часа (вторник, pair 0 / lesson_type curator) */
    public static function isCuratorSlot($slot)
    {
        if (!is_array($slot)) {
            return false;
        }
        if ((int)($slot['pair_number'] ?? -1) === 0) {
            return true;
        }
        return ($slot['lesson_type'] ?? '') === 'curator';
    }

    /** Часы факта за одно вхождение слота */
    public static function hoursForSlot($slot)
    {
        return self::isCuratorSlot($slot) ? self::CURATOR_HOURS : self::HOURS_PER_PAIR;
    }

    /**
     * Вклад слота в недельную нагрузку (акад. ч): каждую неделю = 2, Ч/З = 1.
     * Кураторский = 0 (в нагрузку не входит).
     */
    public static function weeklyHoursForSlot($slot)
    {
        if (self::isCuratorSlot($slot)) {
            return 0.0;
        }
        $kind = self::normalizeWeekKind($slot['week_kind'] ?? self::WEEK_KIND_ALL);
        return ($kind === self::WEEK_KIND_ALL) ? (float)self::HOURS_PER_PAIR : (float)self::HOURS_PER_PAIR / 2.0;
    }

    /** Эффективный лимит ч/нед преподавателя (потолок MAX_WEEKLY_HOURS) */
    public static function effectiveTeacherMaxHours($teacherMax)
    {
        $m = (int)$teacherMax;
        if ($m <= 0) {
            $m = self::MAX_WEEKLY_HOURS;
        }
        // Старые записи: поле хранило пары (дефолт 18) → акад. часы
        if ($m <= 18) {
            $m = $m * self::HOURS_PER_PAIR;
        }
        return min(self::MAX_WEEKLY_HOURS, $m);
    }

    /** Все кураторские слоты — каждую неделю (без Ч/З) */
    public function normalizeCuratorScheduleWeekKinds()
    {
        $this->db->query(
            "UPDATE uchebni_schedule
             SET week_kind = 'all', lesson_type = 'curator'
             WHERE is_active = 1 AND (pair_number = 0 OR lesson_type = 'curator')"
        );
    }

    private static function ensureBellScheduleTable($db)
    {
        $db->query("CREATE TABLE IF NOT EXISTS uchebni_bell_schedule (
            id INT AUTO_INCREMENT PRIMARY KEY,
            day_type ENUM('weekday','tuesday') NOT NULL,
            shift TINYINT NOT NULL,
            pair_number TINYINT NOT NULL,
            label VARCHAR(100) NOT NULL DEFAULT '',
            start_time TIME NOT NULL,
            end_time TIME NOT NULL,
            break_after SMALLINT NOT NULL DEFAULT 0,
            sort_order SMALLINT NOT NULL DEFAULT 0,
            UNIQUE KEY uk_bell_slot (day_type, shift, pair_number),
            INDEX idx_bell_lookup (day_type, shift, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $r = $db->query("SELECT COUNT(*) as cnt FROM uchebni_bell_schedule");
        if ($r && ($row = $r->fetch_assoc()) && (int)$row['cnt'] === 0) {
            self::seedDefaultBellSchedule($db);
        } else {
            // Добить 4-ю пару I смены и кураторский час вторника, если база создана по старому эталону
            self::ensureShift1FourthPair($db);
            self::ensureTuesdayCuratorBell($db);
        }
    }

    private static function ensureShift1FourthPair($db)
    {
        $rows = [
            ['weekday', 1, 4, '4 пара', '13:05:00', '14:25:00', 0, 4],
            ['tuesday', 1, 4, '4 пара', '13:00:00', '14:00:00', 0, 4],
        ];
        $stmt = $db->prepare("INSERT IGNORE INTO uchebni_bell_schedule
            (day_type, shift, pair_number, label, start_time, end_time, break_after, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $row) {
            $dayType = $row[0];
            $shift = (int)$row[1];
            $pair = (int)$row[2];
            $label = $row[3];
            $start = $row[4];
            $end = $row[5];
            $breakAfter = (int)$row[6];
            $sort = (int)$row[7];
            $stmt->bind_param('siisssii', $dayType, $shift, $pair, $label, $start, $end, $breakAfter, $sort);
            $stmt->execute();
        }
    }

    /** Вторник: первый слот — «Кураторский час» (перед 1-й парой) */
    private static function ensureTuesdayCuratorBell($db)
    {
        $rows = [
            ['tuesday', 1, 0, 'Кураторский час', '08:30:00', '09:15:00', 5, 0],
            ['tuesday', 2, 0, 'Кураторский час', '13:00:00', '13:45:00', 5, 0],
        ];
        $stmt = $db->prepare("INSERT IGNORE INTO uchebni_bell_schedule
            (day_type, shift, pair_number, label, start_time, end_time, break_after, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($rows as $row) {
            $dayType = $row[0];
            $shift = (int)$row[1];
            $pair = (int)$row[2];
            $label = $row[3];
            $start = $row[4];
            $end = $row[5];
            $breakAfter = (int)$row[6];
            $sort = (int)$row[7];
            $stmt->bind_param('siisssii', $dayType, $shift, $pair, $label, $start, $end, $breakAfter, $sort);
            $stmt->execute();
        }
    }

    /** Эталон звонков колледжа */
    public static function getDefaultBellScheduleRows()
    {
        return [
            // Пн, Ср, Чт, Пт — I смена (4-я пара как в Excel колледжа)
            ['weekday', 1, 1, '1 пара', '08:30:00', '09:50:00', 10, 1],
            ['weekday', 1, 2, '2 пара', '10:00:00', '11:20:00', 15, 2],
            ['weekday', 1, 3, '3 пара', '11:35:00', '12:55:00', 10, 3],
            ['weekday', 1, 4, '4 пара', '13:05:00', '14:25:00', 0, 4],
            // Пн, Ср, Чт, Пт — II смена
            ['weekday', 2, 1, '1 пара', '13:05:00', '14:25:00', 15, 1],
            ['weekday', 2, 2, '2 пара', '14:40:00', '16:00:00', 10, 2],
            ['weekday', 2, 3, '3 пара', '16:10:00', '17:30:00', 5, 3],
            ['weekday', 2, 4, '4 пара', '17:35:00', '18:35:00', 0, 4],
            // Вторник — I смена
            ['tuesday', 1, 0, 'Кураторский час', '08:30:00', '09:15:00', 5, 0],
            ['tuesday', 1, 1, '1 пара', '09:20:00', '10:20:00', 10, 1],
            ['tuesday', 1, 2, '2 пара', '10:30:00', '11:30:00', 15, 2],
            ['tuesday', 1, 3, '3 пара', '11:45:00', '12:45:00', 15, 3],
            ['tuesday', 1, 4, '4 пара', '13:00:00', '14:00:00', 0, 4],
            // Вторник — II смена
            ['tuesday', 2, 0, 'Кураторский час', '13:00:00', '13:45:00', 5, 0],
            ['tuesday', 2, 1, '1 пара', '13:50:00', '14:50:00', 15, 1],
            ['tuesday', 2, 2, '2 пара', '15:05:00', '16:05:00', 10, 2],
            ['tuesday', 2, 3, '3 пара', '16:15:00', '17:15:00', 5, 3],
        ];
    }

    private static function seedDefaultBellSchedule($db)
    {
        $stmt = $db->prepare("INSERT INTO uchebni_bell_schedule
            (day_type, shift, pair_number, label, start_time, end_time, break_after, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        foreach (self::getDefaultBellScheduleRows() as $row) {
            $dayType = $row[0];
            $shift = (int)$row[1];
            $pair = (int)$row[2];
            $label = $row[3];
            $start = $row[4];
            $end = $row[5];
            $break = (int)$row[6];
            $sort = (int)$row[7];
            $stmt->bind_param('siisssii', $dayType, $shift, $pair, $label, $start, $end, $break, $sort);
            $stmt->execute();
        }
    }

    public static function getBellDayType($dayOfWeek)
    {
        return ((int)$dayOfWeek === 2) ? 'tuesday' : 'weekday';
    }

    public function getBellSlots($dayType, $shift)
    {
        $dayType = $dayType === 'tuesday' ? 'tuesday' : 'weekday';
        $shift = (int)$shift === 2 ? 2 : 1;
        $stmt = $this->db->prepare("SELECT * FROM uchebni_bell_schedule
            WHERE day_type = ? AND shift = ?
            ORDER BY sort_order, pair_number");
        $stmt->bind_param('si', $dayType, $shift);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getBellSlotsForDay($dayOfWeek, $shift)
    {
        return $this->getBellSlots(self::getBellDayType($dayOfWeek), $shift);
    }

    /** @return array{0:string,1:string}|null [start, end] */
    public function getPairTimes($dayOfWeek, $shift, $pairNumber)
    {
        $dayType = self::getBellDayType($dayOfWeek);
        $shift = (int)$shift === 2 ? 2 : 1;
        $pair = (int)$pairNumber;
        $stmt = $this->db->prepare("SELECT start_time, end_time FROM uchebni_bell_schedule
            WHERE day_type = ? AND shift = ? AND pair_number = ? LIMIT 1");
        $stmt->bind_param('sii', $dayType, $shift, $pair);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        return [$row['start_time'], $row['end_time']];
    }

    public function getAllBellScheduleGrouped()
    {
        $r = $this->db->query("SELECT * FROM uchebni_bell_schedule ORDER BY day_type, shift, sort_order, pair_number");
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        $grouped = [];
        foreach ($rows as $row) {
            $grouped[$row['day_type']][(int)$row['shift']][] = $row;
        }
        return $grouped;
    }

    public function updateBellSlot($id, $data)
    {
        $id = (int)$id;
        $label = trim((string)($data['label'] ?? ''));
        $start = $data['start_time'] ?? '';
        $end = $data['end_time'] ?? '';
        $break = (int)($data['break_after'] ?? 0);
        if ($label === '' || $start === '' || $end === '') {
            return false;
        }
        // Нормализация HH:MM → HH:MM:SS
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        if (strlen($end) === 5) {
            $end .= ':00';
        }
        $stmt = $this->db->prepare("UPDATE uchebni_bell_schedule
            SET label = ?, start_time = ?, end_time = ?, break_after = ?
            WHERE id = ?");
        $stmt->bind_param('sssii', $label, $start, $end, $break, $id);
        return $stmt->execute();
    }

    public function resetBellScheduleToDefaults()
    {
        $this->db->query("DELETE FROM uchebni_bell_schedule");
        self::seedDefaultBellSchedule($this->db);
        return true;
    }

    private static function migrateSubjectsSchema($db)
    {
        $columns = [
            'category_code' => "VARCHAR(20) NULL",
            'category_name' => "VARCHAR(300) NULL",
            'item_number' => "SMALLINT NOT NULL DEFAULT 1",
            'assessment' => "VARCHAR(100) NULL",
            'hours_sem1' => "SMALLINT NOT NULL DEFAULT 0",
            'hours_sem2' => "SMALLINT NOT NULL DEFAULT 0",
            'pending_teacher' => "VARCHAR(300) NULL",
        ];

        foreach ($columns as $name => $definition) {
            $check = $db->query("SHOW COLUMNS FROM uchebni_subjects LIKE '" . $name . "'");
            if ($check && $check->num_rows === 0) {
                $db->query("ALTER TABLE uchebni_subjects ADD COLUMN {$name} {$definition}");
            }
        }

        // Перенос старых часов в 1 семестр, если новые поля пустые
        $db->query("UPDATE uchebni_subjects
            SET hours_sem1 = hours_per_semester
            WHERE hours_sem1 = 0 AND hours_sem2 = 0 AND hours_per_semester > 0");

        $idx = $db->query("SHOW INDEX FROM uchebni_subjects WHERE Key_name = 'idx_subject_category'");
        if ($idx && $idx->num_rows === 0) {
            $db->query("ALTER TABLE uchebni_subjects ADD INDEX idx_subject_category (category_code, item_number)");
        }
    }

    private static function seedDefaultPeriod($db)
    {
        $r = $db->query("SELECT COUNT(*) as cnt FROM uchebni_periods");
        if ($r && ($row = $r->fetch_assoc()) && (int)$row['cnt'] > 0) {
            return;
        }
        $year = (int)date('Y');
        $name = "Осенний семестр $year";
        $start = "$year-09-01";
        $end = "$year-12-31";
        $stmt = $db->prepare("INSERT INTO uchebni_periods (name, start_date, end_date, is_current) VALUES (?, ?, ?, 1)");
        $stmt->bind_param('sss', $name, $start, $end);
        $stmt->execute();
    }

    /** Корпуса и аудитории по умолчанию (Мастерской + Учебный) */
    public static function getDefaultBuildings()
    {
        return [
            'Мастерской' => [
                'M110', 'M111', 'M112', 'M119', 'M120',
                'M201', 'M202',
                'M301', 'M302', 'M303', 'M304', 'M306', 'M307', 'M308', 'M309',
            ],
            'Учебный' => [
                '101', '102', '104', '105', '106',
                '301', '302', '303', '304', '306', '307',
                '401', '402', '403', '404', '405', '406', '407', '408', '409',
                '501', '502', '503', '504', '506', '507', '508', '509',
                'Спортивный зал',
            ],
        ];
    }

    private static function seedDefaultClassrooms($db)
    {
        $stmt = $db->prepare(
            "INSERT INTO uchebni_classrooms (number, building, capacity, equipment)
             SELECT ?, ?, ?, ''
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM uchebni_classrooms WHERE number = ? AND building = ?
             )"
        );
        if (!$stmt) {
            return;
        }

        foreach (self::getDefaultBuildings() as $building => $numbers) {
            foreach ($numbers as $number) {
                $capacity = ($number === 'Спортивный зал') ? 100 : 30;
                $stmt->bind_param('ssiss', $number, $building, $capacity, $number, $building);
                $stmt->execute();
            }
        }
    }

    public function getCurrentPeriod()
    {
        $r = $this->db->query("SELECT * FROM uchebni_periods WHERE is_current = 1 ORDER BY id DESC LIMIT 1");
        if ($r && $row = $r->fetch_assoc()) {
            return $row;
        }
        $r = $this->db->query("SELECT * FROM uchebni_periods ORDER BY start_date DESC LIMIT 1");
        return $r ? $r->fetch_assoc() : null;
    }

    public function getPeriods()
    {
        $r = $this->db->query("SELECT * FROM uchebni_periods ORDER BY start_date DESC");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getStats()
    {
        $period = $this->getCurrentPeriod();
        $period_id = $period ? (int)$period['id'] : 0;

        $stats = [
            'teachers' => 0,
            'subjects' => 0,
            'classrooms' => 0,
            'schedule_slots' => 0,
            'groups_with_schedule' => 0,
            'conflicts' => 0,
        ];

        $r = $this->db->query("SELECT COUNT(*) as c FROM uchebni_teachers WHERE is_active = 1");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['teachers'] = (int)$row['c'];
        }
        $r = $this->db->query("SELECT COUNT(*) as c FROM uchebni_subjects WHERE is_active = 1");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['subjects'] = (int)$row['c'];
        }
        $r = $this->db->query("SELECT COUNT(*) as c FROM uchebni_classrooms WHERE is_active = 1");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['classrooms'] = (int)$row['c'];
        }
        if ($period_id) {
            $r = $this->db->query("SELECT COUNT(*) as c FROM uchebni_schedule WHERE period_id = $period_id AND is_active = 1");
            if ($r && $row = $r->fetch_assoc()) {
                $stats['schedule_slots'] = (int)$row['c'];
            }
            $r = $this->db->query("SELECT COUNT(DISTINCT group_id) as c FROM uchebni_schedule WHERE period_id = $period_id AND is_active = 1");
            if ($r && $row = $r->fetch_assoc()) {
                $stats['groups_with_schedule'] = (int)$row['c'];
            }
        }

        return $stats;
    }

    // --- Преподаватели ---

    public function getTeachers($activeOnly = true)
    {
        $sql = "SELECT t.*, u.login as user_login
                FROM uchebni_teachers t
                LEFT JOIN users u ON t.user_id = u.id";
        if ($activeOnly) {
            $sql .= " WHERE t.is_active = 1";
        }
        $sql .= " ORDER BY t.last_name, t.first_name";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getTeacherById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM uchebni_teachers WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function getTeacherByUserId($userId, $activeOnly = true)
    {
        $sql = "SELECT * FROM uchebni_teachers WHERE user_id = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Поиск преподавателя по ФИО (без учёта регистра, «-» в отчестве, қ/к и т.п.).
     */
    public function findTeacherByFio($lastName, $firstName = '', $middleName = '')
    {
        $last = self::normalizeFioForMatch($lastName);
        $first = self::normalizeFioForMatch($firstName);
        $middle = self::normalizeFioForMatch($middleName);
        if ($last === '' && $first === '') {
            return null;
        }

        $fullTarget = $last . $first . $middle;
        $lfTarget = $last . $first;
        $soft = null;

        $teachers = $this->getTeachers(false);
        foreach ($teachers as $t) {
            $cl = self::normalizeFioForMatch($t['last_name'] ?? '');
            $cf = self::normalizeFioForMatch($t['first_name'] ?? '');
            $cm = self::normalizeFioForMatch($t['middle_name'] ?? '');
            $fullCand = $cl . $cf . $cm;
            $lfCand = $cl . $cf;

            if ($fullTarget !== '' && $fullCand === $fullTarget) {
                return $t;
            }
            // Отчество пустое / «-» / другое написание (қызы vs овна) — достаточно фамилии+имени
            if ($lfTarget !== '' && $lfCand === $lfTarget) {
                return $t;
            }
            // Гали / Галий и похожие сокращения имени при той же фамилии
            if ($cl === $last && $cf !== '' && $first !== ''
                && (mb_strpos($cf, $first) === 0 || mb_strpos($first, $cf) === 0)) {
                if ($soft === null) {
                    $soft = $t;
                }
            }
        }
        return $soft;
    }

    public function addTeacher($data)
    {
        $sql = "INSERT INTO uchebni_teachers (user_id, last_name, first_name, middle_name, phone, email, max_hours_per_week, specialization)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $user_id = !empty($data['user_id']) ? (int)$data['user_id'] : null;
        $max_hours = (int)($data['max_hours_per_week'] ?? self::MAX_WEEKLY_HOURS);
        $stmt->bind_param(
            'isssssis',
            $user_id,
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'],
            $data['phone'],
            $data['email'],
            $max_hours,
            $data['specialization']
        );
        if (!$stmt->execute()) {
            return false;
        }
        $teacher_id = $this->db->getLastInsertId();
        if (!empty($data['subject_ids'])) {
            $this->setTeacherSubjects($teacher_id, $data['subject_ids']);
        }
        return $teacher_id;
    }

    public function updateTeacher($id, $data)
    {
        $sql = "UPDATE uchebni_teachers SET user_id = ?, last_name = ?, first_name = ?, middle_name = ?,
                phone = ?, email = ?, max_hours_per_week = ?, specialization = ?, is_active = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $user_id = !empty($data['user_id']) ? (int)$data['user_id'] : null;
        $max_hours = (int)($data['max_hours_per_week'] ?? self::MAX_WEEKLY_HOURS);
        $is_active = (int)($data['is_active'] ?? 1);
        $stmt->bind_param(
            'isssssisii',
            $user_id,
            $data['last_name'],
            $data['first_name'],
            $data['middle_name'],
            $data['phone'],
            $data['email'],
            $max_hours,
            $data['specialization'],
            $is_active,
            $id
        );
        $ok = $stmt->execute();
        if ($ok && isset($data['subject_ids'])) {
            $this->setTeacherSubjects($id, $data['subject_ids']);
        }
        return $ok;
    }

    /**
     * Удаление преподавателя. Блокируется, если есть записи в расписании/заменах.
     * @return array{success:bool,error?:string}
     */
    public function deleteTeacher($id)
    {
        $id = (int)$id;
        if ($id <= 0 || !$this->getTeacherById($id)) {
            return ['success' => false, 'error' => 'Преподаватель не найден'];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as c FROM uchebni_schedule WHERE teacher_id = ? AND is_active = 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $scheduleCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($scheduleCount > 0) {
            return ['success' => false, 'error' => 'Нельзя удалить: преподаватель есть в расписании (' . $scheduleCount . '). Сначала уберите занятия.'];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as c FROM uchebni_substitutions WHERE original_teacher_id = ? OR substitute_teacher_id = ?");
        $stmt->bind_param('ii', $id, $id);
        $stmt->execute();
        $substCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($substCount > 0) {
            return ['success' => false, 'error' => 'Нельзя удалить: есть замены с этим преподавателем'];
        }

        $this->db->query("DELETE FROM uchebni_teacher_subjects WHERE teacher_id = " . $id);
        $this->db->query("DELETE FROM uchebni_teacher_busy WHERE teacher_id = " . $id);
        $this->db->query("UPDATE uchebni_group_subjects SET teacher_id = NULL WHERE teacher_id = " . $id);

        $del = $this->db->prepare("DELETE FROM uchebni_teachers WHERE id = ?");
        $del->bind_param('i', $id);
        if (!$del->execute()) {
            return ['success' => false, 'error' => 'Ошибка при удалении'];
        }
        return ['success' => true];
    }

    public function setTeacherSubjects($teacherId, $subjectIds)
    {
        $this->db->query("DELETE FROM uchebni_teacher_subjects WHERE teacher_id = " . (int)$teacherId);
        $stmt = $this->db->prepare("INSERT INTO uchebni_teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
        foreach ($subjectIds as $sid) {
            $sid = (int)$sid;
            if ($sid > 0) {
                $stmt->bind_param('ii', $teacherId, $sid);
                $stmt->execute();
            }
        }
    }

    public function getTeacherSubjectIds($teacherId)
    {
        $stmt = $this->db->prepare("SELECT subject_id FROM uchebni_teacher_subjects WHERE teacher_id = ?");
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_map('intval', array_column($rows, 'subject_id'));
    }

    public function getTeacherSubjectNames($teacherId)
    {
        return array_column($this->getTeacherSubjectsList($teacherId), 'name');
    }

    /** @return array<int, array{id:int,name:string}> */
    public function getTeacherSubjectsList($teacherId)
    {
        $stmt = $this->db->prepare("SELECT s.id, s.name
            FROM uchebni_teacher_subjects ts
            JOIN uchebni_subjects s ON s.id = ts.subject_id
            WHERE ts.teacher_id = ?
            ORDER BY s.name");
        $teacherId = (int)$teacherId;
        $stmt->bind_param('i', $teacherId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
            ];
        }
        return $out;
    }

    public function getSubjectTeacherIds($subjectId)
    {
        $stmt = $this->db->prepare("SELECT teacher_id FROM uchebni_teacher_subjects WHERE subject_id = ?");
        $subjectId = (int)$subjectId;
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        return array_map('intval', array_column($rows, 'teacher_id'));
    }

    public function getSubjectTeachers($subjectId)
    {
        $stmt = $this->db->prepare("SELECT t.id, t.last_name, t.first_name, t.middle_name
            FROM uchebni_teacher_subjects ts
            JOIN uchebni_teachers t ON t.id = ts.teacher_id
            WHERE ts.subject_id = ?
            ORDER BY t.last_name, t.first_name");
        $subjectId = (int)$subjectId;
        $stmt->bind_param('i', $subjectId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function setSubjectTeachers($subjectId, $teacherIds)
    {
        $subjectId = (int)$subjectId;
        $this->db->query("DELETE FROM uchebni_teacher_subjects WHERE subject_id = " . $subjectId);
        $hasTeachers = false;
        if (!empty($teacherIds)) {
            $stmt = $this->db->prepare("INSERT INTO uchebni_teacher_subjects (teacher_id, subject_id) VALUES (?, ?)");
            foreach ($teacherIds as $tid) {
                $tid = (int)$tid;
                if ($tid > 0) {
                    $stmt->bind_param('ii', $tid, $subjectId);
                    $stmt->execute();
                    $hasTeachers = true;
                }
            }
        }
        // Если назначили реальных преподавателей — убрать подсказку из Word
        if ($hasTeachers) {
            $clear = $this->db->prepare("UPDATE uchebni_subjects SET pending_teacher = NULL WHERE id = ?");
            $clear->bind_param('i', $subjectId);
            $clear->execute();
        }
        return true;
    }

    public function setSubjectPendingTeacher($subjectId, $pendingTeacher)
    {
        $subjectId = (int)$subjectId;
        $pendingTeacher = trim((string)$pendingTeacher);
        $stmt = $this->db->prepare("UPDATE uchebni_subjects SET pending_teacher = ? WHERE id = ?");
        $stmt->bind_param('si', $pendingTeacher, $subjectId);
        return $stmt->execute();
    }

    public function searchTeachers($query, $limit = 15)
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $like = '%' . $q . '%';
        $limit = max(1, min(50, (int)$limit));

        $sql = "SELECT t.id, t.last_name, t.first_name, t.middle_name, t.max_hours_per_week, u.login as user_login
            FROM uchebni_teachers t
            LEFT JOIN users u ON u.id = t.user_id
            WHERE t.is_active = 1
              AND (
                    t.last_name LIKE ?
                 OR t.first_name LIKE ?
                 OR t.middle_name LIKE ?
                 OR CONCAT_WS(' ', t.last_name, t.first_name, t.middle_name) LIKE ?
                 OR CONCAT_WS(' ', t.last_name, t.first_name) LIKE ?
              )
            ORDER BY t.last_name, t.first_name
            LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssss', $like, $like, $like, $like, $like);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getTeacherWorkload($teacherId, $periodId)
    {
        $stmt = $this->db->prepare("SELECT week_kind FROM uchebni_schedule
            WHERE teacher_id = ? AND period_id = ? AND is_active = 1
              AND pair_number <> 0 AND lesson_type <> 'curator'");
        $stmt->bind_param('ii', $teacherId, $periodId);
        $stmt->execute();
        $sum = 0.0;
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sum += self::weeklyHoursForSlot($row);
        }
        return (int)ceil($sum);
    }

    /**
     * Недельная нагрузка группы в акад. часах (без кураторского).
     */
    public function getGroupWeeklyHours($periodId, $groupId, $shift = null, $excludeId = null)
    {
        $periodId = (int)$periodId;
        $groupId = (int)$groupId;
        if ($periodId <= 0 || $groupId <= 0) {
            return 0.0;
        }
        $sql = "SELECT week_kind, pair_number, lesson_type FROM uchebni_schedule
                WHERE period_id = ? AND group_id = ? AND is_active = 1";
        if ($shift !== null) {
            $sql .= " AND shift = " . (((int)$shift === 2) ? 2 : 1);
        }
        if ($excludeId) {
            $sql .= " AND id != " . (int)$excludeId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ii', $periodId, $groupId);
        $stmt->execute();
        $sum = 0.0;
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $sum += self::weeklyHoursForSlot($row);
        }
        return $sum;
    }

    // --- Дисциплины ---

    public function getSubjects($activeOnly = true)
    {
        $sql = "SELECT * FROM uchebni_subjects";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY
            (category_code IS NULL OR category_code = ''),
            category_code,
            item_number,
            name";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    /** Служебная дисциплина для слота «Кураторский час» */
    public function getOrCreateCuratorSubject()
    {
        $code = 'CURATOR';
        $stmt = $this->db->prepare("SELECT id FROM uchebni_subjects WHERE code = ? LIMIT 1");
        $stmt->bind_param('s', $code);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int)$row['id'];
        }
        $id = $this->addSubject([
            'name' => 'Кураторский час',
            'code' => $code,
            'category_code' => 'CUR',
            'category_name' => 'Кураторская работа',
            'item_number' => 1,
            'assessment' => '',
            'hours_sem1' => 0,
            'hours_sem2' => 0,
            'description' => 'Служебная запись для кураторского часа во вторник',
        ]);
        return $id ? (int)$id : 0;
    }

    /** Служебная аудитория, если кабинет для кураторского часа не указан */
    public function getOrCreateCuratorClassroom()
    {
        $number = 'кур.';
        $stmt = $this->db->prepare("SELECT id FROM uchebni_classrooms WHERE number = ? LIMIT 1");
        $stmt->bind_param('s', $number);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ($row) {
            return (int)$row['id'];
        }
        $id = $this->addClassroom([
            'number' => $number,
            'building' => '',
            'capacity' => 30,
            'equipment' => 'Кураторский час',
        ]);
        return $id ? (int)$id : 0;
    }

    /**
     * Преподаватель-куратор группы (из groups.curator_id → uchebni_teachers.user_id).
     * @return int|null
     */
    public function getGroupCuratorTeacherId($groupId)
    {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            return null;
        }
        $stmt = $this->db->prepare(
            "SELECT t.id
             FROM `groups` g
             INNER JOIN uchebni_teachers t ON t.user_id = g.curator_id AND t.is_active = 1
             WHERE g.id = ? AND g.curator_id IS NOT NULL
             LIMIT 1"
        );
        $stmt->bind_param('i', $groupId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? (int)$row['id'] : null;
    }

    /** Карта group_id => teacher_id куратора (если есть связка user↔преподаватель) */
    public function getGroupCuratorTeacherMap()
    {
        $map = [];
        $r = $this->db->query(
            "SELECT g.id as group_id, t.id as teacher_id
             FROM `groups` g
             INNER JOIN uchebni_teachers t ON t.user_id = g.curator_id AND t.is_active = 1
             WHERE g.is_active = 1 AND g.curator_id IS NOT NULL"
        );
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $map[(int)$row['group_id']] = (int)$row['teacher_id'];
            }
        }
        return $map;
    }

    public function addSubject($data)
    {
        $name = $data['name'] ?? '';
        $code = $data['code'] ?? ($data['category_code'] ?? '');
        $category_code = $data['category_code'] ?? '';
        $category_name = $data['category_name'] ?? '';
        $item_number = max(1, (int)($data['item_number'] ?? 1));
        $assessment = $data['assessment'] ?? '';
        $hours_sem1 = (int)($data['hours_sem1'] ?? 0);
        $hours_sem2 = (int)($data['hours_sem2'] ?? 0);
        $hours_total = $hours_sem1 + $hours_sem2;
        $description = $data['description'] ?? '';
        $pending_teacher = trim((string)($data['pending_teacher'] ?? ''));

        $stmt = $this->db->prepare("INSERT INTO uchebni_subjects
            (name, code, category_code, category_name, item_number, assessment, hours_sem1, hours_sem2, hours_per_semester, description, pending_teacher)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            'ssssisiiiss',
            $name,
            $code,
            $category_code,
            $category_name,
            $item_number,
            $assessment,
            $hours_sem1,
            $hours_sem2,
            $hours_total,
            $description,
            $pending_teacher
        );
        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    public function updateSubject($id, $data)
    {
        $name = $data['name'] ?? '';
        $code = $data['code'] ?? ($data['category_code'] ?? '');
        $category_code = $data['category_code'] ?? '';
        $category_name = $data['category_name'] ?? '';
        $item_number = max(1, (int)($data['item_number'] ?? 1));
        $assessment = $data['assessment'] ?? '';
        $hours_sem1 = (int)($data['hours_sem1'] ?? 0);
        $hours_sem2 = (int)($data['hours_sem2'] ?? 0);
        $hours_total = $hours_sem1 + $hours_sem2;
        $description = $data['description'] ?? '';
        $is_active = (int)($data['is_active'] ?? 1);
        $id = (int)$id;

        $sql = "UPDATE uchebni_subjects SET
            name = ?, code = ?, category_code = ?, category_name = ?, item_number = ?,
            assessment = ?, hours_sem1 = ?, hours_sem2 = ?, hours_per_semester = ?,
            description = ?, is_active = ?";
        $types = 'ssssisiiisi';
        $params = [
            $name,
            $code,
            $category_code,
            $category_name,
            $item_number,
            $assessment,
            $hours_sem1,
            $hours_sem2,
            $hours_total,
            $description,
            $is_active,
        ];

        if (array_key_exists('pending_teacher', $data)) {
            $pending = trim((string)$data['pending_teacher']);
            $sql .= ", pending_teacher = ?";
            $types .= 's';
            $params[] = $pending;
        }

        $sql .= " WHERE id = ?";
        $types .= 'i';
        $params[] = $id;

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        return $stmt->execute();
    }

    /**
     * Удаление дисциплины. Блокируется при наличии в расписании.
     * @return array{success:bool,error?:string}
     */
    public function deleteSubject($id)
    {
        $id = (int)$id;
        if ($id <= 0 || !$this->getSubjectById($id)) {
            return ['success' => false, 'error' => 'Дисциплина не найдена'];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) as c FROM uchebni_schedule WHERE subject_id = ? AND is_active = 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $scheduleCount = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
        if ($scheduleCount > 0) {
            return ['success' => false, 'error' => 'Нельзя удалить: дисциплина есть в расписании (' . $scheduleCount . '). Сначала уберите занятия.'];
        }

        $this->db->query("DELETE FROM uchebni_teacher_subjects WHERE subject_id = " . $id);
        $this->db->query("DELETE FROM uchebni_group_subjects WHERE subject_id = " . $id);

        $del = $this->db->prepare("DELETE FROM uchebni_subjects WHERE id = ?");
        $del->bind_param('i', $id);
        if (!$del->execute()) {
            return ['success' => false, 'error' => 'Ошибка при удалении'];
        }
        return ['success' => true];
    }

    /**
     * Массовое удаление дисциплин.
     * @param int[] $ids
     * @return array{success:bool,deleted:int,failed:int,errors:string[]}
     */
    public function deleteSubjects(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
            return $id > 0;
        })));
        $deleted = 0;
        $errors = [];
        foreach ($ids as $id) {
            $subject = $this->getSubjectById($id);
            $title = $subject ? self::formatSubjectTitle($subject) : ('#' . $id);
            $result = $this->deleteSubject($id);
            if (!empty($result['success'])) {
                $deleted++;
            } else {
                $errors[] = $title . ': ' . ($result['error'] ?? 'ошибка');
            }
        }
        return [
            'success' => $deleted > 0 || empty($ids),
            'deleted' => $deleted,
            'failed' => count($ids) - $deleted,
            'errors' => $errors,
        ];
    }

    /**
     * Удалить все дисциплины (с теми же ограничениями, что и одиночное удаление).
     * @return array{success:bool,deleted:int,failed:int,errors:string[]}
     */
    public function deleteAllSubjects()
    {
        $ids = array_map(function ($s) {
            return (int)$s['id'];
        }, $this->getSubjects(false));
        return $this->deleteSubjects($ids);
    }

    // --- Аудитории ---

    public function getClassrooms($activeOnly = true)
    {
        $sql = "SELECT * FROM uchebni_classrooms";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY building, number";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function addClassroom($data)
    {
        $stmt = $this->db->prepare("INSERT INTO uchebni_classrooms (number, building, capacity, equipment) VALUES (?, ?, ?, ?)");
        $cap = (int)($data['capacity'] ?? 30);
        $stmt->bind_param('ssis', $data['number'], $data['building'], $cap, $data['equipment']);
        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    public function updateClassroom($id, $data)
    {
        $stmt = $this->db->prepare("UPDATE uchebni_classrooms SET number = ?, building = ?, capacity = ?, equipment = ?, is_active = ? WHERE id = ?");
        $cap = (int)($data['capacity'] ?? 30);
        $is_active = (int)($data['is_active'] ?? 1);
        $stmt->bind_param('ssisii', $data['number'], $data['building'], $cap, $data['equipment'], $is_active, $id);
        return $stmt->execute();
    }

    // --- Учебный план (группа + дисциплина) ---

    /** 1 или 2 семестр по названию периода */
    public function getPeriodSemesterNumber($period)
    {
        if (!$period) {
            return 1;
        }
        $name = mb_strtolower((string)($period['name'] ?? ''));
        if (preg_match('/весен|весна|2\s*сем|spring|летн/u', $name)) {
            return 2;
        }
        return 1;
    }

    /** Часов в неделю из часов семестра дисциплины */
    public static function hoursPerWeekFromSubject($subject, $semester = 1)
    {
        $sem = ((int)$semester === 2) ? 2 : 1;
        $hours = $sem === 2 ? (int)($subject['hours_sem2'] ?? 0) : (int)($subject['hours_sem1'] ?? 0);
        $weeks = $sem === 2 ? self::WEEKS_SEM2 : self::WEEKS_SEM1;

        if ($hours <= 0) {
            $hours = (int)($subject['hours_sem1'] ?? 0) + (int)($subject['hours_sem2'] ?? 0);
            if ($hours <= 0) {
                $hours = (int)($subject['hours_per_semester'] ?? 0);
            }
            $weeks = self::WEEKS_SEM1 + self::WEEKS_SEM2;
        }

        if ($hours <= 0 || $weeks <= 0) {
            return 2;
        }
        return max(1, (int)round($hours / $weeks));
    }

    /**
     * Дисциплина с тем же названием в том же блоке.
     */
    public function findSubjectByNameAndCategory($name, $categoryCode, $categoryName = '')
    {
        $name = trim((string)$name);
        $categoryCode = trim((string)$categoryCode);
        $categoryName = trim((string)$categoryName);
        if ($name === '') {
            return null;
        }

        $stmt = $this->db->prepare("SELECT * FROM uchebni_subjects
            WHERE LOWER(TRIM(name)) = LOWER(?)
              AND LOWER(TRIM(IFNULL(category_code, ''))) = LOWER(?)
              AND LOWER(TRIM(IFNULL(category_name, ''))) = LOWER(?)
            LIMIT 1");
        $stmt->bind_param('sss', $name, $categoryCode, $categoryName);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ?: null;
    }

    /**
     * Импорт дисциплин из DOCX (шаблон subject.docx).
     * Преподавателей не создаём: если ФИО есть в системе — привяжем, иначе дисциплина без преподавателя.
     * @return array{success:bool,error?:string,added_subjects?:int,skipped_subjects?:int,linked_teachers?:string[],missing_teachers?:string[],messages?:string[]}
     */
    public function importSubjectsFromDocx($filePath)
    {
        if (!class_exists('ZipArchive')) {
            return ['success' => false, 'error' => 'Расширение ZipArchive недоступно'];
        }
        if (!is_readable($filePath)) {
            return ['success' => false, 'error' => 'Не удалось прочитать файл'];
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['success' => false, 'error' => 'Файл не является корректным DOCX'];
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false || $xml === '') {
            return ['success' => false, 'error' => 'В DOCX нет word/document.xml'];
        }

        $rows = self::parseDocxTableRows($xml);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'В документе не найдена таблица дисциплин'];
        }

        $addedSubjects = 0;
        $skippedSubjects = 0;
        $linkedTeachers = [];
        $missingTeachers = [];
        $messages = [];
        $categoryCode = '';
        $categoryName = '';
        $assessment = '';

        foreach ($rows as $cells) {
            $cells = array_map(function ($c) {
                return trim(preg_replace('/\s+/u', ' ', (string)$c));
            }, $cells);
            while (count($cells) < 5) {
                $cells[] = '';
            }

            $col0 = $cells[0];
            $col1 = $cells[1];
            $col2 = $cells[2];
            $col3 = $cells[3];
            $col4 = $cells[4];

            if ($col0 === '' && $col1 === '' && $col2 === '') {
                continue;
            }
            if (preg_match('/^№$/u', $col0) || mb_stripos($col1, 'Наименование') !== false) {
                continue;
            }
            if (preg_match('/сем/ui', $col3 . $col4) && $col1 === '' && $col2 === '') {
                continue;
            }

            // Строка дисциплины: «1» или «ООД 1» / «КМ 04»
            $itemNumber = 0;
            $rowCategoryCode = '';
            if (preg_match('/^([A-Za-zА-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүІіҺһ]+)\s+(\d+)$/u', $col0, $nm)) {
                $rowCategoryCode = mb_strtoupper($nm[1]);
                $itemNumber = max(1, (int)$nm[2]);
            } elseif (preg_match('/^\d+$/u', $col0)) {
                $itemNumber = max(1, (int)$col0);
            }

            if ($itemNumber <= 0) {
                // Строка блока: «ООД Общеобразовательные…» / «ООДОбщеобразовательные…»
                $headerText = $col0;
                if ($headerText === '' && $col1 !== '') {
                    $headerText = $col1;
                }
                $parsed = self::parseCategoryHeader($headerText);
                if ($parsed) {
                    $categoryCode = $parsed['code'];
                    $categoryName = $parsed['name'];
                    $assessment = $parsed['assessment'];
                }
                continue;
            }

            if ($col1 === '') {
                continue;
            }

            if ($rowCategoryCode !== '') {
                $categoryCode = $rowCategoryCode;
            }

            $titleInfo = self::extractSubjectTitleAndAssessment($col1);
            $name = $titleInfo['name'];
            $rowAssessment = $titleInfo['assessment'] !== '' ? $titleInfo['assessment'] : $assessment;
            if ($name === '') {
                continue;
            }

            $hours1 = self::parseHoursCell($col3);
            $hours2 = self::parseHoursCell($col4);

            $teacherIds = [];
            $pendingTeacher = '';
            $fioRaw = trim($col2);
            if ($fioRaw !== '' && $fioRaw !== '—' && mb_strtolower($fioRaw) !== 'нет') {
                $fioList = preg_split('/\s*\/\s*/u', $fioRaw, -1, PREG_SPLIT_NO_EMPTY);
                $pendingParts = [];
                foreach ($fioList as $oneFio) {
                    $parts = self::parseTeacherFio($oneFio);
                    $existing = $this->findTeacherByFio($parts['last_name'], $parts['first_name'], $parts['middle_name']);
                    if ($existing) {
                        $tid = (int)$existing['id'];
                        if (!in_array($tid, $teacherIds, true)) {
                            $teacherIds[] = $tid;
                        }
                        $label = self::formatFio($existing);
                        if (!in_array($label, $linkedTeachers, true)) {
                            $linkedTeachers[] = $label;
                        }
                    } else {
                        $label = trim(implode(' ', array_filter([
                            $parts['last_name'],
                            $parts['first_name'],
                            $parts['middle_name'],
                        ])));
                        if ($label === '') {
                            $label = trim($oneFio);
                        }
                        if ($label !== '') {
                            $pendingParts[] = $label;
                            if (!in_array($label, $missingTeachers, true)) {
                                $missingTeachers[] = $label;
                            }
                        }
                    }
                }
                if (empty($teacherIds) && $pendingParts) {
                    $pendingTeacher = implode(' / ', $pendingParts);
                } elseif ($pendingParts) {
                    // Часть нашлась — оставшихся всё равно покажем в подсказке
                    $pendingTeacher = implode(' / ', $pendingParts);
                }
            }

            $dup = $this->findSubjectByNameAndCategory($name, $categoryCode, $categoryName);
            if ($dup) {
                $skippedSubjects++;
                $messages[] = 'Уже есть: ' . $name . ($categoryCode !== '' ? ' (' . $categoryCode . ')' : '');
                continue;
            }

            $id = $this->addSubject([
                'name' => $name,
                'code' => $categoryCode,
                'category_code' => $categoryCode,
                'category_name' => $categoryName,
                'item_number' => $itemNumber,
                'assessment' => $rowAssessment,
                'hours_sem1' => $hours1,
                'hours_sem2' => $hours2,
                'description' => '',
                'pending_teacher' => $pendingTeacher,
            ]);
            if ($id) {
                if ($teacherIds) {
                    $this->setSubjectTeachers($id, $teacherIds);
                    // setSubjectTeachers очищает pending — вернём, если кто-то ещё не найден
                    if ($pendingTeacher !== '') {
                        $this->setSubjectPendingTeacher($id, $pendingTeacher);
                    }
                }
                $addedSubjects++;
            }
        }

        if ($addedSubjects === 0 && $skippedSubjects === 0) {
            return ['success' => false, 'error' => 'В файле не найдено строк дисциплин'];
        }

        return [
            'success' => true,
            'added_subjects' => $addedSubjects,
            'skipped_subjects' => $skippedSubjects,
            'linked_teachers' => $linkedTeachers,
            'missing_teachers' => $missingTeachers,
            'messages' => $messages,
        ];
    }

    /** Строки всех таблиц DOCX: массив ячеек. */
    private static function parseDocxTableRows($xml)
    {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        if (!$dom->loadXML($xml)) {
            return [];
        }
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $rows = [];
        foreach ($xpath->query('//w:tbl/w:tr') as $tr) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $tr) as $tc) {
                $texts = [];
                foreach ($xpath->query('.//w:t', $tc) as $t) {
                    $texts[] = $t->textContent;
                }
                $cells[] = implode('', $texts);
            }
            if ($cells) {
                $rows[] = $cells;
            }
        }
        return $rows;
    }

    private static function parseCategoryHeader($text)
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string)$text));
        if ($text === '' || mb_strlen($text) < 3) {
            return null;
        }

        $assessment = '';
        $assessmentKeys = array_keys(self::ASSESSMENT_TYPES);
        usort($assessmentKeys, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        foreach ($assessmentKeys as $key) {
            if ($key === '') {
                continue;
            }
            $pattern = '/[,.\s]+' . preg_quote($key, '/') . '\s*$/ui';
            if (preg_match($pattern, $text)) {
                $assessment = $key;
                $text = trim(preg_replace($pattern, '', $text));
                break;
            }
        }

        $code = '';
        $rest = $text;
        // «ООД 1 Название» / «ЖММ Название» / слитно «ООДОбщеобразовательные…»
        if (preg_match('/^([A-Za-zА-ЯЁӘҒҚҢӨҰҮІҺ]{2,6})(?:\s*(\d+))?\s+(.*)$/u', $text, $m)) {
            $code = mb_strtoupper($m[1]);
            $rest = trim($m[3]);
        } elseif (preg_match('/^([A-Za-zА-ЯЁӘҒҚҢӨҰҮІҺ]{2,6})(?=\p{Lu})(.+)$/u', $text, $m)) {
            $code = mb_strtoupper($m[1]);
            $rest = trim($m[2]);
        } elseif (preg_match('/^([A-Za-zА-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүІіҺһ]+)(?:\s*(\d+))?\s*(.*)$/u', $text, $m)) {
            $code = mb_strtoupper($m[1]);
            $rest = trim($m[3]);
        }

        $name = self::extractRussianTitle($rest !== '' ? $rest : $text);
        $name = preg_replace('/^[A-Za-zА-Яа-яЁёӘәҒғҚқҢңӨөҰұҮүІіҺһ]+\s*\d+\s*/u', '', $name);
        $name = trim($name);

        if ($code === '' && $name === '') {
            return null;
        }

        return [
            'code' => $code,
            'name' => $name !== '' ? $name : $text,
            'assessment' => $assessment,
        ];
    }

    /**
     * Название предмета + форма контроля из хвоста («Русский язык, экзамен»).
     * @return array{name:string,assessment:string}
     */
    private static function extractSubjectTitleAndAssessment($text)
    {
        $text = self::extractRussianTitle($text);
        $assessment = '';
        $assessmentKeys = array_keys(self::ASSESSMENT_TYPES);
        usort($assessmentKeys, function ($a, $b) {
            return mb_strlen($b) - mb_strlen($a);
        });
        foreach ($assessmentKeys as $key) {
            if ($key === '') {
                continue;
            }
            $pattern = '/[,.\s]+' . preg_quote($key, '/') . '\s*$/ui';
            if (preg_match($pattern, $text)) {
                $assessment = $key;
                $text = trim(preg_replace($pattern, '', $text));
                break;
            }
        }
        return ['name' => $text, 'assessment' => $assessment];
    }

    /** «Қазақша/Русское название» → русская часть */
    private static function extractRussianTitle($text)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return '';
        }
        if (strpos($text, '/') !== false) {
            $parts = explode('/', $text);
            $ru = trim(end($parts));
            return $ru !== '' ? $ru : trim($parts[0]);
        }
        return $text;
    }

    private static function parseHoursCell($value)
    {
        if (preg_match('/(\d+)/', (string)$value, $m)) {
            return (int)$m[1];
        }
        return 0;
    }

    /**
     * Разбор ФИО из Word (в т.ч. без пробелов: ТарғынАқниетОразханұлы).
     * @return array{last_name:string,first_name:string,middle_name:string}
     */
    public static function parseTeacherFio($fio)
    {
        $fio = trim(preg_replace('/\s+/u', ' ', (string)$fio));
        // Вставить пробелы перед заглавными после строчных
        if (strpos($fio, ' ') === false) {
            $fio = preg_replace('/(\p{Ll}|\p{Lm})(\p{Lu})/u', '$1 $2', $fio);
        }
        $parts = preg_split('/\s+/u', $fio, -1, PREG_SPLIT_NO_EMPTY);
        $last = $parts[0] ?? '';
        $first = $parts[1] ?? '';
        $middle = isset($parts[2]) ? implode(' ', array_slice($parts, 2)) : '';
        return [
            'last_name' => $last,
            'first_name' => $first,
            'middle_name' => $middle,
        ];
    }

    public function getSubjectById($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT * FROM uchebni_subjects WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc() ?: null;
    }

    public function getGroupSubjects($periodId, $groupId = null)
    {
        $sql = "SELECT gs.*, g.name as group_name, g.code as group_code,
                       s.name as subject_name, s.code as subject_code,
                       s.category_code, s.category_name, s.item_number, s.assessment,
                       s.hours_sem1, s.hours_sem2, s.hours_per_semester, s.description as subject_description,
                       CONCAT_WS(' ', t.last_name, t.first_name, t.middle_name) as teacher_name
                FROM uchebni_group_subjects gs
                JOIN `groups` g ON gs.group_id = g.id
                JOIN uchebni_subjects s ON gs.subject_id = s.id
                LEFT JOIN uchebni_teachers t ON gs.teacher_id = t.id
                WHERE gs.period_id = ?";
        $params = [$periodId];
        $types = 'i';
        if ($groupId) {
            $sql .= " AND gs.group_id = ?";
            $params[] = $groupId;
            $types .= 'i';
        }
        $sql .= " ORDER BY g.name,
            (s.category_code IS NULL OR s.category_code = ''),
            s.category_code,
            s.item_number,
            s.name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Группы, где преподаватель ведёт дисциплины в периоде.
     */
    public function getTeacherTeachingGroups($periodId, $teacherId)
    {
        $periodId = (int)$periodId;
        $teacherId = (int)$teacherId;
        if ($periodId <= 0 || $teacherId <= 0) {
            return [];
        }

        $stmt = $this->db->prepare(
            "SELECT DISTINCT g.id, g.name, g.code, g.course, g.department_id,
                    d.name AS department_name
             FROM uchebni_group_subjects gs
             JOIN `groups` g ON g.id = gs.group_id
             LEFT JOIN departments d ON d.id = g.department_id
             WHERE gs.period_id = ? AND gs.teacher_id = ? AND g.is_active = 1
             ORDER BY g.name"
        );
        $stmt->bind_param('ii', $periodId, $teacherId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    /**
     * Дисциплины преподавателя в группе за период.
     */
    public function getTeacherGroupSubjects($periodId, $groupId, $teacherId)
    {
        $periodId = (int)$periodId;
        $groupId = (int)$groupId;
        $teacherId = (int)$teacherId;
        if ($periodId <= 0 || $groupId <= 0 || $teacherId <= 0) {
            return [];
        }

        $sql = "SELECT gs.*, g.name as group_name, g.code as group_code, g.department_id,
                       s.name as subject_name, s.code as subject_code,
                       CONCAT_WS(' ', t.last_name, t.first_name, t.middle_name) as teacher_name
                FROM uchebni_group_subjects gs
                JOIN `groups` g ON gs.group_id = g.id
                JOIN uchebni_subjects s ON gs.subject_id = s.id
                LEFT JOIN uchebni_teachers t ON gs.teacher_id = t.id
                WHERE gs.period_id = ? AND gs.group_id = ? AND gs.teacher_id = ?
                ORDER BY s.name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('iii', $periodId, $groupId, $teacherId);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function addGroupSubject($data)
    {
        $period_id = (int)$data['period_id'];
        $group_id = (int)$data['group_id'];
        $subject_id = (int)$data['subject_id'];
        $lesson_type = $data['lesson_type'] ?? 'lecture';
        if (!in_array($lesson_type, ['lecture', 'practice', 'lab'], true)) {
            $lesson_type = 'lecture';
        }

        $dup = $this->db->prepare("SELECT id FROM uchebni_group_subjects WHERE period_id = ? AND group_id = ? AND subject_id = ? LIMIT 1");
        $dup->bind_param('iii', $period_id, $group_id, $subject_id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            return false;
        }

        $subject = $this->getSubjectById($subject_id);
        if (!$subject) {
            return false;
        }

        $teacher_id = !empty($data['teacher_id']) ? (int)$data['teacher_id'] : 0;
        if ($teacher_id <= 0) {
            $ids = $this->getSubjectTeacherIds($subject_id);
            $teacher_id = $ids[0] ?? 0;
        }

        $semester = (int)($data['semester'] ?? 1);
        $hours = isset($data['hours_per_week'])
            ? (int)$data['hours_per_week']
            : self::hoursPerWeekFromSubject($subject, $semester);
        if ($hours < 1) {
            $hours = 1;
        }

        if ($teacher_id > 0) {
            $stmt = $this->db->prepare("INSERT INTO uchebni_group_subjects (period_id, group_id, subject_id, teacher_id, hours_per_week, lesson_type)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param(
                'iiiiis',
                $period_id,
                $group_id,
                $subject_id,
                $teacher_id,
                $hours,
                $lesson_type
            );
        } else {
            $stmt = $this->db->prepare("INSERT INTO uchebni_group_subjects (period_id, group_id, subject_id, teacher_id, hours_per_week, lesson_type)
                VALUES (?, ?, ?, NULL, ?, ?)");
            $stmt->bind_param(
                'iiiis',
                $period_id,
                $group_id,
                $subject_id,
                $hours,
                $lesson_type
            );
        }
        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    public function deleteGroupSubject($id)
    {
        $stmt = $this->db->prepare("DELETE FROM uchebni_group_subjects WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Добавить в план группы все дисциплины блока (category_code).
     * @return array{added:int,skipped:int,label:string}
     */
    public function addGroupSubjectsByCategory($data)
    {
        $period_id = (int)($data['period_id'] ?? 0);
        $group_id = (int)($data['group_id'] ?? 0);
        $category_code = trim((string)($data['category_code'] ?? ''));
        $lesson_type = $data['lesson_type'] ?? 'lecture';
        $semester = (int)($data['semester'] ?? 1);

        $result = ['added' => 0, 'skipped' => 0, 'label' => $category_code];
        if ($period_id <= 0 || $group_id <= 0 || $category_code === '') {
            return $result;
        }

        $stmt = $this->db->prepare("SELECT * FROM uchebni_subjects
            WHERE is_active = 1 AND category_code = ?
            ORDER BY item_number, name");
        $stmt->bind_param('s', $category_code);
        $stmt->execute();
        $subjects = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        if (empty($subjects)) {
            return $result;
        }

        $label = self::formatCategoryHeader($subjects[0]);
        if ($label !== '') {
            $result['label'] = $label;
        }

        foreach ($subjects as $subject) {
            $id = $this->addGroupSubject([
                'period_id' => $period_id,
                'group_id' => $group_id,
                'subject_id' => (int)$subject['id'],
                'semester' => $semester,
                'lesson_type' => $lesson_type,
            ]);
            if ($id) {
                $result['added']++;
            } else {
                $result['skipped']++;
            }
        }

        return $result;
    }

    public function getActiveGroups()
    {
        $r = $this->db->query("SELECT id, name, code, course, specialty FROM `groups` WHERE is_active = 1 ORDER BY name");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    // --- Расписание ---

    public function getSchedule($filters = [])
    {
        $teacherFio = self::sqlFio('t');
        $sql = "SELECT sch.*, g.name as group_name, g.code as group_code,
                       s.name as subject_name, s.code as subject_code,
                       $teacherFio as teacher_name,
                       c.number as classroom_number, c.building as classroom_building
                FROM uchebni_schedule sch
                JOIN `groups` g ON sch.group_id = g.id
                JOIN uchebni_subjects s ON sch.subject_id = s.id
                JOIN uchebni_teachers t ON sch.teacher_id = t.id
                LEFT JOIN uchebni_classrooms c ON sch.classroom_id = c.id
                WHERE sch.is_active = 1";
        $params = [];
        $types = '';

        if (!empty($filters['period_id'])) {
            $sql .= " AND sch.period_id = ?";
            $params[] = (int)$filters['period_id'];
            $types .= 'i';
        }
        if (!empty($filters['group_id'])) {
            $sql .= " AND sch.group_id = ?";
            $params[] = (int)$filters['group_id'];
            $types .= 'i';
        }
        if (!empty($filters['teacher_id'])) {
            $sql .= " AND sch.teacher_id = ?";
            $params[] = (int)$filters['teacher_id'];
            $types .= 'i';
        }
        if (!empty($filters['day_of_week'])) {
            $sql .= " AND sch.day_of_week = ?";
            $params[] = (int)$filters['day_of_week'];
            $types .= 'i';
        }
        if (!empty($filters['shift'])) {
            $sql .= " AND sch.shift = ?";
            $params[] = (int)$filters['shift'] === 2 ? 2 : 1;
            $types .= 'i';
        }

        $sql .= " ORDER BY sch.day_of_week, sch.shift, sch.pair_number, g.name";

        if ($types) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function checkConflicts($data, $excludeId = null)
    {
        $conflicts = [];
        $period_id = (int)$data['period_id'];
        $group_id = (int)$data['group_id'];
        $teacher_id = (int)$data['teacher_id'];
        $classroom_id = (int)$data['classroom_id'];
        $day = (int)$data['day_of_week'];
        $pair = (int)$data['pair_number'];
        $shift = (int)($data['shift'] ?? 1) === 2 ? 2 : 1;
        $weekKind = self::normalizeWeekKind($data['week_kind'] ?? self::WEEK_KIND_ALL);
        $lessonType = $data['lesson_type'] ?? 'lecture';
        if ($pair === 0) {
            $lessonType = 'curator';
        }

        $base = "SELECT sch.*, g.name as group_name, sub.name as subject_name,
                        CONCAT(t.last_name, ' ', t.first_name) as teacher_name, c.number as room
                 FROM uchebni_schedule sch
                 JOIN `groups` g ON sch.group_id = g.id
                 JOIN uchebni_subjects sub ON sch.subject_id = sub.id
                 JOIN uchebni_teachers t ON sch.teacher_id = t.id
                 LEFT JOIN uchebni_classrooms c ON sch.classroom_id = c.id
                 WHERE sch.is_active = 1 AND sch.period_id = ? AND sch.day_of_week = ?
                   AND sch.pair_number = ? AND sch.shift = ?";
        if ($excludeId) {
            $base .= " AND sch.id != " . (int)$excludeId;
        }

        $filterWeek = function (array $rows) use ($weekKind) {
            $out = [];
            foreach ($rows as $row) {
                if (self::weekKindsConflict($weekKind, $row['week_kind'] ?? 'all')) {
                    $out[] = $row;
                }
            }
            return $out;
        };

        // Группа занята
        $stmt = $this->db->prepare($base . " AND sch.group_id = ?");
        $stmt->bind_param('iiiii', $period_id, $day, $pair, $shift, $group_id);
        $stmt->execute();
        foreach ($filterWeek($stmt->get_result()->fetch_all(MYSQLI_ASSOC)) as $row) {
            $conflicts[] = ['type' => 'group', 'message' => 'Группа ' . $row['group_name'] . ' уже занята (' . ($row['subject_name'] ?? '') . ')'];
        }

        // Преподаватель занят
        $stmt = $this->db->prepare($base . " AND sch.teacher_id = ?");
        $stmt->bind_param('iiiii', $period_id, $day, $pair, $shift, $teacher_id);
        $stmt->execute();
        foreach ($filterWeek($stmt->get_result()->fetch_all(MYSQLI_ASSOC)) as $row) {
            $conflicts[] = ['type' => 'teacher', 'message' => 'Преподаватель ' . $row['teacher_name'] . ' занят (гр. ' . $row['group_name'] . ')'];
        }

        // Аудитория занята (только если кабинет уже назначен)
        if ($classroom_id > 0) {
            $stmt = $this->db->prepare($base . " AND sch.classroom_id = ?");
            $stmt->bind_param('iiiii', $period_id, $day, $pair, $shift, $classroom_id);
            $stmt->execute();
            foreach ($filterWeek($stmt->get_result()->fetch_all(MYSQLI_ASSOC)) as $row) {
                $conflicts[] = ['type' => 'classroom', 'message' => 'Аудитория ' . $row['room'] . ' занята (гр. ' . $row['group_name'] . ')'];
            }
        }

        // Преподаватель недоступен
        $stmt = $this->db->prepare("SELECT reason FROM uchebni_teacher_busy WHERE teacher_id = ? AND day_of_week = ? AND pair_number = ?");
        $stmt->bind_param('iii', $teacher_id, $day, $pair);
        $stmt->execute();
        if ($row = $stmt->get_result()->fetch_assoc()) {
            $reason = $row['reason'] ? ': ' . $row['reason'] : '';
            $conflicts[] = ['type' => 'busy', 'message' => 'Преподаватель недоступен' . $reason];
        }

        // Нагрузка преподавателя (акад. ч/нед, потолок MAX_WEEKLY_HOURS)
        $teacher = $this->getTeacherById($teacher_id);
        if ($teacher && $pair !== 0 && $lessonType !== 'curator') {
            $current = (float)$this->getTeacherWorkload($teacher_id, $period_id);
            if ($excludeId) {
                $chk = $this->db->prepare("SELECT teacher_id, week_kind, pair_number, lesson_type FROM uchebni_schedule WHERE id = ?");
                $chk->bind_param('i', $excludeId);
                $chk->execute();
                $ex = $chk->get_result()->fetch_assoc();
                if ($ex && (int)$ex['teacher_id'] === $teacher_id) {
                    $current -= self::weeklyHoursForSlot($ex);
                }
            }
            $addLoad = self::weeklyHoursForSlot(['week_kind' => $weekKind, 'pair_number' => $pair, 'lesson_type' => $lessonType]);
            $maxHours = self::effectiveTeacherMaxHours($teacher['max_hours_per_week'] ?? self::MAX_WEEKLY_HOURS);
            if (($current + $addLoad) > $maxHours + 0.01) {
                $conflicts[] = [
                    'type' => 'workload',
                    'message' => 'Превышена нагрузка преподавателя: '
                        . round($current + $addLoad, 1) . '/' . $maxHours . ' ч/нед (макс. ' . self::MAX_WEEKLY_HOURS . ')',
                ];
            }
        }

        // Нагрузка группы (акад. ч/нед ≤ MAX_WEEKLY_HOURS), кураторский не считаем
        if ($pair !== 0 && ($data['lesson_type'] ?? '') !== 'curator') {
            $groupHours = $this->getGroupWeeklyHours($period_id, $group_id, $shift, $excludeId);
            $addGroup = self::weeklyHoursForSlot(['week_kind' => $weekKind, 'pair_number' => $pair, 'lesson_type' => $data['lesson_type'] ?? 'lecture']);
            if (($groupHours + $addGroup) > self::MAX_WEEKLY_HOURS + 0.01) {
                $conflicts[] = [
                    'type' => 'group_hours',
                    'message' => 'Недельная нагрузка группы превысит '
                        . self::MAX_WEEKLY_HOURS . ' ч: '
                        . round($groupHours + $addGroup, 1) . ' ч/нед',
                ];
            }
        }

        return $conflicts;
    }

    public function addScheduleSlot($data, $createdBy = null, $skipConflictCheck = false)
    {
        $periodId = (int)$data['period_id'];
        $groupId = (int)$data['group_id'];
        $subjectId = (int)$data['subject_id'];
        $teacherId = (int)$data['teacher_id'];
        $classroomId = (int)$data['classroom_id'];
        if ($classroomId <= 0) {
            $classroomId = 0;
        }
        $day = (int)$data['day_of_week'];
        $pair = (int)$data['pair_number'];
        $shift = (int)($data['shift'] ?? 1) === 2 ? 2 : 1;
        $weekKind = self::normalizeWeekKind($data['week_kind'] ?? self::WEEK_KIND_ALL);
        $lessonType = $data['lesson_type'] ?? 'lecture';
        $createdById = $createdBy !== null && $createdBy !== '' ? (int)$createdBy : 0;

        $data['shift'] = $shift;
        $data['week_kind'] = $weekKind;
        $data['period_id'] = $periodId;
        $data['group_id'] = $groupId;
        $data['subject_id'] = $subjectId;
        $data['teacher_id'] = $teacherId;
        $data['classroom_id'] = $classroomId;
        $data['day_of_week'] = $day;
        $data['pair_number'] = $pair;

        if ($pair === 0) {
            $lessonType = 'curator';
            $weekKind = self::WEEK_KIND_ALL;
            $data['week_kind'] = $weekKind;
            if ($subjectId <= 0) {
                $subjectId = $this->getOrCreateCuratorSubject();
                $data['subject_id'] = $subjectId;
            }
            // Кабинет для кураторского часа тоже можно назначить позже
            if ($day !== 2) {
                return ['success' => false, 'error' => 'Кураторский час ставится только во вторник'];
            }
        } elseif ($lessonType === 'curator') {
            $lessonType = 'lecture';
        }
        if (!in_array($lessonType, ['lecture', 'practice', 'lab', 'curator'], true)) {
            $lessonType = 'lecture';
        }
        $data['lesson_type'] = $lessonType;
        $data['classroom_id'] = $classroomId;

        $times = $this->getPairTimes($day, $shift, $pair);
        if (!$times) {
            return ['success' => false, 'error' => 'Нет такого слота в расписании звонков'];
        }
        $startTime = $times[0];
        $endTime = $times[1];

        if (!$skipConflictCheck) {
            $conflicts = $this->checkConflicts($data);
            if (!empty($conflicts)) {
                return ['success' => false, 'conflicts' => $conflicts];
            }
        }

        $classroomParam = $classroomId > 0 ? $classroomId : null;
        if ($classroomParam === null) {
            $stmt = $this->db->prepare("INSERT INTO uchebni_schedule
                (period_id, group_id, subject_id, teacher_id, classroom_id, day_of_week, pair_number, shift, week_kind, start_time, end_time, lesson_type, created_by)
                VALUES (?, ?, ?, ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                return ['success' => false, 'error' => 'Ошибка подготовки запроса'];
            }
            $stmt->bind_param(
                'iiiiiiissssi',
                $periodId,
                $groupId,
                $subjectId,
                $teacherId,
                $day,
                $pair,
                $shift,
                $weekKind,
                $startTime,
                $endTime,
                $lessonType,
                $createdById
            );
        } else {
            $stmt = $this->db->prepare("INSERT INTO uchebni_schedule
                (period_id, group_id, subject_id, teacher_id, classroom_id, day_of_week, pair_number, shift, week_kind, start_time, end_time, lesson_type, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            if (!$stmt) {
                return ['success' => false, 'error' => 'Ошибка подготовки запроса'];
            }
            $stmt->bind_param(
                'iiiiiiiissssi',
                $periodId,
                $groupId,
                $subjectId,
                $teacherId,
                $classroomParam,
                $day,
                $pair,
                $shift,
                $weekKind,
                $startTime,
                $endTime,
                $lessonType,
                $createdById
            );
        }
        if ($stmt->execute()) {
            return ['success' => true, 'id' => $this->db->getLastInsertId()];
        }
        return ['success' => false, 'error' => 'Ошибка сохранения: ' . ($stmt->error ?: 'unknown')];
    }

    /**
     * Деактивировать слоты расписания.
     * @param bool $keepCurator true — не трогать кураторский час (для автоформирования)
     * @return int число затронутых строк
     */
    public function clearScheduleSlots($periodId, $groupId = null, $shift = null, $keepCurator = true)
    {
        $periodId = (int)$periodId;
        if ($periodId <= 0) {
            return 0;
        }
        $sql = "UPDATE uchebni_schedule SET is_active = 0 WHERE period_id = ? AND is_active = 1";
        $params = [$periodId];
        $types = 'i';
        if ($keepCurator) {
            $sql .= " AND pair_number <> 0 AND lesson_type <> 'curator'";
        }
        if ($groupId) {
            $sql .= " AND group_id = ?";
            $params[] = (int)$groupId;
            $types .= 'i';
        }
        if ($shift) {
            $sql .= " AND shift = ?";
            $params[] = ((int)$shift === 2) ? 2 : 1;
            $types .= 'i';
        }
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        if (!$stmt->execute()) {
            return 0;
        }
        return (int)$this->db->getAffectedRows();
    }

    public function deleteScheduleSlot($id)
    {
        $stmt = $this->db->prepare("UPDATE uchebni_schedule SET is_active = 0 WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Удалить (деактивировать) несколько слотов по id.
     * @param list<int> $ids
     * @return int
     */
    public function deleteScheduleSlots(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return 0;
        }
        $in = implode(',', $ids);
        $this->db->query("UPDATE uchebni_schedule SET is_active = 0 WHERE is_active = 1 AND id IN ($in)");
        return (int)$this->db->getAffectedRows();
    }

    /**
     * Назначить / сменить кабинет у слота расписания вручную.
     * @return array{success:bool,error?:string,conflicts?:list}
     */
    public function updateScheduleClassroom($slotId, $classroomId)
    {
        $slotId = (int)$slotId;
        $classroomId = (int)$classroomId;
        $slot = $this->getScheduleById($slotId);
        if (!$slot) {
            return ['success' => false, 'error' => 'Слот не найден'];
        }
        if ($classroomId > 0) {
            $found = false;
            foreach ($this->getClassrooms(true) as $c) {
                if ((int)$c['id'] === $classroomId) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                return ['success' => false, 'error' => 'Аудитория не найдена'];
            }
            $data = [
                'period_id' => (int)$slot['period_id'],
                'group_id' => (int)$slot['group_id'],
                'teacher_id' => (int)$slot['teacher_id'],
                'classroom_id' => $classroomId,
                'day_of_week' => (int)$slot['day_of_week'],
                'pair_number' => (int)$slot['pair_number'],
                'shift' => (int)($slot['shift'] ?? 1),
                'week_kind' => self::normalizeWeekKind($slot['week_kind'] ?? 'all'),
            ];
            $conflicts = $this->checkConflicts($data, $slotId);
            $roomConflicts = array_values(array_filter($conflicts, function ($c) {
                return ($c['type'] ?? '') === 'classroom';
            }));
            if (!empty($roomConflicts)) {
                return [
                    'success' => false,
                    'conflicts' => $roomConflicts,
                    'error' => $roomConflicts[0]['message'] ?? 'Конфликт аудитории',
                ];
            }
            $stmt = $this->db->prepare("UPDATE uchebni_schedule SET classroom_id = ? WHERE id = ? AND is_active = 1");
            $stmt->bind_param('ii', $classroomId, $slotId);
        } else {
            $stmt = $this->db->prepare("UPDATE uchebni_schedule SET classroom_id = NULL WHERE id = ? AND is_active = 1");
            $stmt->bind_param('i', $slotId);
        }
        if ($stmt->execute()) {
            return ['success' => true];
        }
        return ['success' => false, 'error' => 'Ошибка сохранения'];
    }

    /**
     * Автоформирование расписания по учебному плану (числитель/знаменатель).
     * Кабинеты не назначаются — заполняются вручную в сетке.
     * Старое расписание по области (период/группа/смена) заменяется (кроме кураторского часа).
     * Конфликты проверяются в памяти — без тысяч запросов к БД.
     */
    public function autoGenerateSchedule($periodId, $groupId = null, $createdBy = null, $shift = 1)
    {
        @set_time_limit(120);
        $periodId = (int)$periodId;
        $shift = (int)$shift === 2 ? 2 : 1;

        $groupSubjects = $this->getGroupSubjects($periodId, $groupId);
        if (empty($groupSubjects)) {
            return ['success' => false, 'error' => 'Учебный план пуст. Добавьте дисциплины для групп.'];
        }

        $this->clearScheduleSlots($periodId, $groupId, $shift);

        $placed = 0;
        $failed = [];
        $days = array_keys(self::DAY_NAMES);

        // Пары по дням (кэш звонков)
        $pairsByDay = [];
        foreach ($days as $day) {
            $pairsByDay[$day] = [];
            foreach ($this->getBellSlotsForDay($day, $shift) as $bell) {
                $pn = (int)$bell['pair_number'];
                if ($pn > 0) {
                    $pairsByDay[$day][] = $pn;
                }
            }
        }

        // Карты занятости: key = "day|pair|weekKind"
        $busyTeacher = []; // teacherId => set of keys that conflict
        $busyGroup = [];
        $busyRoom = [];
        $teacherLoad = []; // teacherId => float weekly load
        $teacherMax = [];

        foreach ($this->getTeachers(true) as $t) {
            $teacherMax[(int)$t['id']] = self::effectiveTeacherMaxHours($t['max_hours_per_week'] ?? self::MAX_WEEKLY_HOURS);
            $teacherLoad[(int)$t['id']] = 0.0;
        }

        $groupLoad = []; // groupId => float academic hours/week


        foreach ($this->getTeacherBusySlots() as $b) {
            $tid = (int)$b['teacher_id'];
            $day = (int)$b['day_of_week'];
            $pair = (int)$b['pair_number'];
            foreach ([self::WEEK_KIND_ALL, self::WEEK_KIND_NUM, self::WEEK_KIND_DEN] as $wk) {
                $busyTeacher[$tid][$this->occKey($day, $pair, $wk)] = true;
            }
        }

        // Оставшиеся слоты других смен/групп (не очищенные) — учесть конфликты преподавателя/аудитории
        $existing = $this->getSchedule(['period_id' => $periodId]);
        foreach ($existing as $slot) {
            if ((int)($slot['shift'] ?? 1) !== $shift) {
                // другая смена — не пересекается по времени звонков обычно, но преподаватель может быть занят
                // для простоты учитываем только ту же смену
                continue;
            }
            $this->markOccupancy(
                $busyGroup,
                $busyTeacher,
                $busyRoom,
                $teacherLoad,
                $groupLoad,
                (int)$slot['group_id'],
                (int)$slot['teacher_id'],
                (int)($slot['classroom_id'] ?? 0),
                (int)$slot['day_of_week'],
                (int)$slot['pair_number'],
                self::normalizeWeekKind($slot['week_kind'] ?? 'all'),
                $slot
            );
        }

        usort($groupSubjects, function ($a, $b) {
            return ((int)$b['hours_per_week']) <=> ((int)$a['hours_per_week']);
        });

        foreach ($groupSubjects as $gs) {
            $hoursAcademic = (int)$gs['hours_per_week'];
            if ($hoursAcademic <= 0) {
                continue;
            }

            // В учебном плане hours_per_week — академические часы/нед (из hours_sem / недели).
            // 1 пара = 2 акад.ч. Нечётное → +половина на числитель.
            $fullPairs = intdiv($hoursAcademic, self::HOURS_PER_PAIR);
            $needHalf = ($hoursAcademic % self::HOURS_PER_PAIR) !== 0;
            // Защита от мусора: если вдруг записали десятки
            if ($fullPairs > 8) {
                $fullPairs = (int)max(1, round($hoursAcademic / self::HOURS_PER_PAIR));
                $needHalf = false;
                $fullPairs = min(8, $fullPairs);
            }

            $teacherId = (int)($gs['teacher_id'] ?? 0);
            $gId = (int)$gs['group_id'];
            $subjectId = (int)$gs['subject_id'];
            $lessonType = $gs['lesson_type'] ?? 'lecture';
            $targetPairs = $fullPairs + ($needHalf ? 1 : 0);

            if (!$teacherId) {
                $failed[] = $gs['group_name'] . ' / ' . $gs['subject_name'] . ': не назначен преподаватель';
                continue;
            }

            $placedSlots = 0;
            $base = [
                'period_id' => $periodId,
                'group_id' => $gId,
                'subject_id' => $subjectId,
                'teacher_id' => $teacherId,
                'classroom_id' => 0,
                'shift' => $shift,
                'lesson_type' => $lessonType,
            ];

            for ($n = 0; $n < $fullPairs; $n++) {
                $ok = $this->placeSlotInMemory(
                    $busyGroup, $busyTeacher, $busyRoom, $teacherLoad, $teacherMax, $groupLoad,
                    $days, $pairsByDay,
                    $base + ['week_kind' => self::WEEK_KIND_ALL],
                    $createdBy
                );
                if ($ok) {
                    $placed++;
                    $placedSlots++;
                    continue;
                }
                // Не влезло «каждую неделю» — кладём Ч + З (та же нагрузка в среднем)
                $numOk = $this->placeSlotInMemory(
                    $busyGroup, $busyTeacher, $busyRoom, $teacherLoad, $teacherMax, $groupLoad,
                    $days, $pairsByDay,
                    $base + ['week_kind' => self::WEEK_KIND_NUM],
                    $createdBy
                );
                $denOk = $this->placeSlotInMemory(
                    $busyGroup, $busyTeacher, $busyRoom, $teacherLoad, $teacherMax, $groupLoad,
                    $days, $pairsByDay,
                    $base + ['week_kind' => self::WEEK_KIND_DEN],
                    $createdBy
                );
                if ($numOk) {
                    $placed++;
                }
                if ($denOk) {
                    $placed++;
                }
                if ($numOk && $denOk) {
                    $placedSlots++;
                } elseif ($numOk || $denOk) {
                    $placedSlots++; // частичный успех
                } else {
                    break;
                }
            }

            if ($needHalf) {
                // 1 «лишний» акад.час → одна пара через неделю (числитель, иначе знаменатель)
                $halfOk = $this->placeSlotInMemory(
                    $busyGroup, $busyTeacher, $busyRoom, $teacherLoad, $teacherMax, $groupLoad,
                    $days, $pairsByDay,
                    $base + ['week_kind' => self::WEEK_KIND_NUM],
                    $createdBy
                );
                if (!$halfOk) {
                    $halfOk = $this->placeSlotInMemory(
                        $busyGroup, $busyTeacher, $busyRoom, $teacherLoad, $teacherMax, $groupLoad,
                        $days, $pairsByDay,
                        $base + ['week_kind' => self::WEEK_KIND_DEN],
                        $createdBy
                    );
                }
                if ($halfOk) {
                    $placed++;
                    $placedSlots++;
                }
            }

            if ($placedSlots < $targetPairs) {
                $failed[] = $gs['group_name'] . ' / ' . $gs['subject_name']
                    . ": размещено $placedSlots из $targetPairs пар"
                    . " ({$hoursAcademic} акад.ч/нед)";
            }
        }

        $slotCapacity = 0;
        foreach ($pairsByDay as $plist) {
            $slotCapacity += count($plist);
        }

        $hintParts = [
            'Кабинеты назначьте вручную в сетке (выбор «каб.» у каждой пары).',
            'Недельная нагрузка группы ≤ ' . self::MAX_WEEKLY_HOURS . ' акад.ч (кураторский не считается).',
        ];
        if ($slotCapacity > 0) {
            $hintParts[] = "В смене {$shift}: {$slotCapacity} слотов/нед.";
        }
        foreach ($groupLoad as $gid => $gh) {
            if ($gh > self::MAX_WEEKLY_HOURS + 0.01) {
                $failed[] = 'Группа #' . $gid . ': нагрузка ' . round($gh, 1) . ' ч/нед > ' . self::MAX_WEEKLY_HOURS;
            }
        }

        return [
            'success' => true,
            'placed' => $placed,
            'failed' => $failed,
            'capacity' => $slotCapacity,
            'group_hours' => $groupLoad,
            'hint' => implode(' ', $hintParts),
        ];
    }

    private function occKey($day, $pair, $weekKind)
    {
        return $day . '|' . $pair . '|' . self::normalizeWeekKind($weekKind);
    }

    private function markOccupancy(&$busyGroup, &$busyTeacher, &$busyRoom, &$teacherLoad, &$groupLoad, $groupId, $teacherId, $roomId, $day, $pair, $weekKind, $slot = null)
    {
        $weekKind = self::normalizeWeekKind($weekKind);
        $keys = [];
        if ($weekKind === self::WEEK_KIND_ALL) {
            $keys = [
                $this->occKey($day, $pair, self::WEEK_KIND_ALL),
                $this->occKey($day, $pair, self::WEEK_KIND_NUM),
                $this->occKey($day, $pair, self::WEEK_KIND_DEN),
            ];
        } else {
            $keys = [
                $this->occKey($day, $pair, self::WEEK_KIND_ALL),
                $this->occKey($day, $pair, $weekKind),
            ];
        }
        $hours = self::weeklyHoursForSlot($slot ?: [
            'week_kind' => $weekKind,
            'pair_number' => $pair,
            'lesson_type' => ($pair === 0) ? 'curator' : 'lecture',
        ]);
        $teacherLoad[$teacherId] = ($teacherLoad[$teacherId] ?? 0) + $hours;
        $groupLoad[$groupId] = ($groupLoad[$groupId] ?? 0) + $hours;
        foreach ($keys as $k) {
            $busyGroup[$groupId][$k] = true;
            $busyTeacher[$teacherId][$k] = true;
            if ((int)$roomId > 0) {
                $busyRoom[$roomId][$k] = true;
            }
        }
    }

    private function isOccupied($map, $id, $day, $pair, $weekKind)
    {
        $k = $this->occKey($day, $pair, $weekKind);
        return !empty($map[$id][$k]);
    }

    /**
     * Найти свободный слот в памяти и записать в БД (без кабинета — назначается вручную).
     */
    private function placeSlotInMemory(
        &$busyGroup,
        &$busyTeacher,
        &$busyRoom,
        &$teacherLoad,
        $teacherMax,
        &$groupLoad,
        array $days,
        array $pairsByDay,
        array $baseSlot,
        $createdBy = null
    ) {
        $groupId = (int)$baseSlot['group_id'];
        $teacherId = (int)$baseSlot['teacher_id'];
        $weekKind = self::normalizeWeekKind($baseSlot['week_kind'] ?? 'all');
        $addLoad = self::weeklyHoursForSlot($baseSlot + ['pair_number' => 1]);
        $max = (float)($teacherMax[$teacherId] ?? self::MAX_WEEKLY_HOURS);
        if ((($teacherLoad[$teacherId] ?? 0) + $addLoad) > $max + 0.01) {
            return false;
        }
        if ((($groupLoad[$groupId] ?? 0) + $addLoad) > self::MAX_WEEKLY_HOURS + 0.01) {
            return false;
        }

        foreach ($days as $day) {
            foreach ($pairsByDay[$day] as $pair) {
                if ($this->isOccupied($busyGroup, $groupId, $day, $pair, $weekKind)) {
                    continue;
                }
                if ($this->isOccupied($busyTeacher, $teacherId, $day, $pair, $weekKind)) {
                    continue;
                }
                $slot = $baseSlot;
                $slot['day_of_week'] = $day;
                $slot['pair_number'] = $pair;
                $slot['classroom_id'] = 0;
                $result = $this->addScheduleSlot($slot, $createdBy, true);
                if (!empty($result['success'])) {
                    $this->markOccupancy(
                        $busyGroup,
                        $busyTeacher,
                        $busyRoom,
                        $teacherLoad,
                        $groupLoad,
                        $groupId,
                        $teacherId,
                        0,
                        $day,
                        $pair,
                        $weekKind,
                        $slot
                    );
                    return true;
                }
            }
        }
        return false;
    }

    // --- Журнал ---

    public function saveJournalEntry($data, $createdBy = null)
    {
        $grade = isset($data['grade']) && $data['grade'] !== '' ? (float)$data['grade'] : null;
        $attendance = self::normalizeAttendance($data['attendance'] ?? self::ATTENDANCE_PRESENT);
        $note = (string)($data['note'] ?? '');
        $stmt = $this->db->prepare("INSERT INTO uchebni_journal (schedule_id, student_id, lesson_date, grade, attendance, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE grade = VALUES(grade), attendance = VALUES(attendance), note = VALUES(note)");
        $stmt->bind_param(
            'iisdssi',
            $data['schedule_id'],
            $data['student_id'],
            $data['lesson_date'],
            $grade,
            $attendance,
            $note,
            $createdBy
        );
        return $stmt->execute();
    }

    public function getJournalForSchedule($scheduleId, $date)
    {
        $sched = $this->db->prepare("SELECT group_id FROM uchebni_schedule WHERE id = ?");
        $sched->bind_param('i', $scheduleId);
        $sched->execute();
        $srow = $sched->get_result()->fetch_assoc();
        if (!$srow) {
            return [];
        }

        $not_graduated = sqlNotGraduatedCondition('s');
        $sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin,
                       j.grade, j.attendance, j.note
                FROM students s
                LEFT JOIN uchebni_journal j ON j.student_id = s.id AND j.schedule_id = ? AND j.lesson_date = ?
                WHERE s.group_id = ? AND $not_graduated
                ORDER BY s.last_name, s.first_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('isi', $scheduleId, $date, $srow['group_id']);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    // --- Аналитика ---

    public function getAnalyticsPerformanceByGroup($periodId = null)
    {
        $periodFilter = $periodId ? " AND sch.period_id = " . (int)$periodId : '';
        $sql = "SELECT g.id, g.name as group_name, g.code,
                       COUNT(j.id) as entries,
                       ROUND(AVG(j.grade), 2) as avg_grade,
                       SUM(CASE WHEN j.grade IS NOT NULL AND j.grade < 50 THEN 1 ELSE 0 END) as failing_count
                FROM `groups` g
                LEFT JOIN uchebni_schedule sch ON sch.group_id = g.id AND sch.is_active = 1 $periodFilter
                LEFT JOIN uchebni_journal j ON j.schedule_id = sch.id AND j.grade IS NOT NULL
                WHERE g.is_active = 1
                GROUP BY g.id, g.name, g.code
                ORDER BY avg_grade DESC";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getAnalyticsAttendance($periodId = null)
    {
        $periodFilter = $periodId ? " AND sch.period_id = " . (int)$periodId : '';
        $sql = "SELECT g.id, g.name as group_name,
                       COUNT(j.id) as total,
                       SUM(CASE WHEN j.attendance = 'present' THEN 1 ELSE 0 END) as present,
                       SUM(CASE WHEN j.attendance = 'absent' THEN 1 ELSE 0 END) as absent,
                       SUM(CASE WHEN j.attendance = 'late' THEN 1 ELSE 0 END) as late
                FROM `groups` g
                LEFT JOIN uchebni_schedule sch ON sch.group_id = g.id AND sch.is_active = 1 $periodFilter
                LEFT JOIN uchebni_journal j ON j.schedule_id = sch.id
                WHERE g.is_active = 1
                GROUP BY g.id, g.name
                HAVING total > 0
                ORDER BY (SUM(CASE WHEN j.attendance = 'present' THEN 1 ELSE 0 END) / COUNT(j.id)) DESC";
        $r = $this->db->query($sql);
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as &$row) {
            $row['attendance_pct'] = $row['total'] > 0 ? round(100 * $row['present'] / $row['total'], 1) : 0;
        }
        return $rows;
    }

    public function getAnalyticsTeacherRating($periodId = null)
    {
        $periodFilter = $periodId ? " AND sch.period_id = " . (int)$periodId : '';
        $sql = "SELECT t.id, CONCAT(t.last_name, ' ', t.first_name) as teacher_name,
                       COUNT(DISTINCT sch.id) as lessons,
                       ROUND(AVG(j.grade), 2) as avg_grade,
                       COUNT(j.id) as grades_count,
                       SUM(CASE WHEN j.attendance = 'present' THEN 1 ELSE 0 END) as present,
                       COUNT(CASE WHEN j.attendance IS NOT NULL THEN 1 END) as attendance_total
                FROM uchebni_teachers t
                LEFT JOIN uchebni_schedule sch ON sch.teacher_id = t.id AND sch.is_active = 1 $periodFilter
                LEFT JOIN uchebni_journal j ON j.schedule_id = sch.id
                WHERE t.is_active = 1
                GROUP BY t.id, t.last_name, t.first_name
                ORDER BY avg_grade DESC";
        $r = $this->db->query($sql);
        $rows = $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
        foreach ($rows as &$row) {
            $row['attendance_pct'] = $row['attendance_total'] > 0
                ? round(100 * $row['present'] / $row['attendance_total'], 1) : null;
            $workload = $periodId ? $this->getTeacherWorkload($row['id'], $periodId) : 0;
            $teacher = $this->getTeacherById($row['id']);
            $row['workload'] = $workload;
            $row['max_hours'] = $teacher ? (int)$teacher['max_hours_per_week'] : 0;
        }
        return $rows;
    }

    public function getAnalyticsClassroomLoad($periodId = null)
    {
        $periodJoin = $periodId
            ? " AND sch.period_id = " . (int)$periodId . " AND sch.is_active = 1"
            : " AND sch.is_active = 1";
        $sql = "SELECT c.id, c.number, c.building, c.capacity,
                       COUNT(sch.id) as hours_per_week,
                       COUNT(DISTINCT sch.group_id) as groups_count
                FROM uchebni_classrooms c
                LEFT JOIN uchebni_schedule sch ON sch.classroom_id = c.id $periodJoin
                WHERE c.is_active = 1
                GROUP BY c.id, c.number, c.building, c.capacity
                ORDER BY hours_per_week DESC";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getAnalyticsDebts($periodId = null)
    {
        $periodFilter = $periodId ? " AND sch.period_id = " . (int)$periodId : '';
        $not_graduated = sqlNotGraduatedCondition('s');
        $sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, g.name as group_name,
                       sub.name as subject_name,
                       COUNT(j.id) as graded_lessons,
                       ROUND(AVG(j.grade), 2) as avg_grade,
                       SUM(CASE WHEN j.grade < 50 THEN 1 ELSE 0 END) as fails
                FROM students s
                JOIN `groups` g ON s.group_id = g.id
                JOIN uchebni_schedule sch ON sch.group_id = g.id AND sch.is_active = 1 $periodFilter
                JOIN uchebni_subjects sub ON sch.subject_id = sub.id
                LEFT JOIN uchebni_journal j ON j.schedule_id = sch.id AND j.student_id = s.id AND j.grade IS NOT NULL
                WHERE $not_graduated
                GROUP BY s.id, s.last_name, s.first_name, s.middle_name, g.name, sub.name, sch.id
                HAVING avg_grade < 50 OR fails > 0 OR graded_lessons = 0
                ORDER BY g.name, s.last_name, sub.name
                LIMIT 200";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getPortalUsers()
    {
        $r = $this->db->query("SELECT u.id, u.first_name, u.last_name, u.middle_name, u.login, u.email, u.phone, r.name as role_name
            FROM users u LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.is_active = 1 ORDER BY u.last_name, u.first_name");
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getPortalUserById($id)
    {
        $stmt = $this->db->prepare("SELECT u.id, u.first_name, u.last_name, u.middle_name, u.login, u.email, u.phone, r.name as role_name
            FROM users u LEFT JOIN roles r ON u.role_id = r.id
            WHERE u.id = ? AND u.is_active = 1");
        $id = (int)$id;
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    /**
     * Поиск пользователей портала по ФИО / логину.
     * Уже привязанные к преподавателям возвращаются с already_teacher=true
     * (кроме текущей записи excludeTeacherId при редактировании).
     */
    public function searchPortalUsers($query, $limit = 15, $excludeTeacherId = 0)
    {
        $q = trim($query);
        if (mb_strlen($q) < 2) {
            return [];
        }

        $like = '%' . $q . '%';
        $limit = max(1, min(50, (int)$limit));
        $excludeTeacherId = (int)$excludeTeacherId;

        // Возвращаем и уже привязанных преподавателей — в UI помечаем «уже в базе».
        // excludeTeacherId: при редактировании текущая запись не считается занятой.
        $sql = "SELECT u.id, u.first_name, u.last_name, u.middle_name, u.login, u.email, u.phone,
                       r.name as role_name,
                       t.id as teacher_id,
                       t.is_active as teacher_is_active
            FROM users u
            LEFT JOIN roles r ON u.role_id = r.id
            LEFT JOIN uchebni_teachers t ON t.user_id = u.id
            WHERE u.is_active = 1
              AND (
                    u.last_name LIKE ?
                 OR u.first_name LIKE ?
                 OR u.middle_name LIKE ?
                 OR u.login LIKE ?
                 OR CONCAT_WS(' ', u.last_name, u.first_name, u.middle_name) LIKE ?
                 OR CONCAT_WS(' ', u.last_name, u.first_name) LIKE ?
              )
            ORDER BY
                CASE WHEN t.id IS NOT NULL AND t.id <> ? THEN 0 ELSE 1 END,
                u.last_name, u.first_name
            LIMIT {$limit}";

        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ssssssi', $like, $like, $like, $like, $like, $like, $excludeTeacherId);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        foreach ($rows as &$row) {
            $tid = !empty($row['teacher_id']) ? (int)$row['teacher_id'] : 0;
            $row['teacher_id'] = $tid ?: null;
            $row['already_teacher'] = $tid > 0 && $tid !== $excludeTeacherId;
            $row['teacher_is_active'] = isset($row['teacher_is_active']) ? (int)$row['teacher_is_active'] : null;
        }
        unset($row);

        return $rows;
    }

    // --- Статус урока (проведён / отменён) ---

    public function getLessonStatus($scheduleId, $lessonDate)
    {
        $stmt = $this->db->prepare("SELECT * FROM uchebni_lesson_status WHERE schedule_id = ? AND lesson_date = ?");
        $stmt->bind_param('is', $scheduleId, $lessonDate);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function setLessonStatus($scheduleId, $lessonDate, $status, $note = '', $markedBy = null)
    {
        $allowed = ['scheduled', 'held', 'cancelled'];
        if (!in_array($status, $allowed, true)) {
            return false;
        }

        $markedByVal = $markedBy !== null ? (int)$markedBy : null;
        $stmt = $this->db->prepare("INSERT INTO uchebni_lesson_status (schedule_id, lesson_date, status, note, marked_by)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE status = VALUES(status), note = VALUES(note), marked_by = VALUES(marked_by), marked_at = NOW()");
        $stmt->bind_param('isssi', $scheduleId, $lessonDate, $status, $note, $markedByVal);
        return $stmt->execute();
    }

    // --- Замены преподавателей ---

    public function getSubstitutions($filters = [])
    {
        $originalFio = self::sqlFio('ot');
        $substituteFio = self::sqlFio('st');
        $sql = "SELECT sub.*,
                       sch.day_of_week, sch.pair_number, sch.shift, sch.group_id, sch.subject_id,
                       sch.period_id, sch.start_time, sch.end_time, sch.classroom_id,
                       g.name as group_name, s.name as subject_name,
                       ss.name as substitute_subject_name,
                       $originalFio as original_teacher_name,
                       $substituteFio as substitute_teacher_name,
                       c.number as classroom_number
                FROM uchebni_substitutions sub
                JOIN uchebni_schedule sch ON sub.schedule_id = sch.id
                JOIN `groups` g ON sch.group_id = g.id
                JOIN uchebni_subjects s ON sch.subject_id = s.id
                LEFT JOIN uchebni_subjects ss ON sub.substitute_subject_id = ss.id
                JOIN uchebni_teachers ot ON sub.original_teacher_id = ot.id
                JOIN uchebni_teachers st ON sub.substitute_teacher_id = st.id
                LEFT JOIN uchebni_classrooms c ON sch.classroom_id = c.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['id'])) {
            $sql .= " AND sub.id = ?";
            $params[] = (int)$filters['id'];
            $types .= 'i';
        }
        if (!empty($filters['schedule_id'])) {
            $sql .= " AND sub.schedule_id = ?";
            $params[] = (int)$filters['schedule_id'];
            $types .= 'i';
        }
        if (!empty($filters['teacher_id'])) {
            $sql .= " AND (sub.original_teacher_id = ? OR sub.substitute_teacher_id = ?)";
            $tid = (int)$filters['teacher_id'];
            $params[] = $tid;
            $params[] = $tid;
            $types .= 'ii';
        }
        if (!empty($filters['lesson_date'])) {
            $sql .= " AND sub.lesson_date = ?";
            $params[] = $filters['lesson_date'];
            $types .= 's';
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND sub.lesson_date >= ?";
            $params[] = $filters['date_from'];
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND sub.lesson_date <= ?";
            $params[] = $filters['date_to'];
            $types .= 's';
        }
        if (!empty($filters['group_id'])) {
            $sql .= " AND sch.group_id = ?";
            $params[] = (int)$filters['group_id'];
            $types .= 'i';
        }

        $sql .= " ORDER BY sub.lesson_date DESC, sch.day_of_week, sch.pair_number";

        if ($types) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getSubstitutionById($id)
    {
        $rows = $this->getSubstitutions(['id' => (int)$id]);
        return $rows[0] ?? null;
    }

    public function addSubstitution($data, $createdBy = null)
    {
        $scheduleId = (int)($data['schedule_id'] ?? 0);
        $lessonDate = (string)($data['lesson_date'] ?? '');
        $originalId = (int)($data['original_teacher_id'] ?? 0);
        $substituteId = (int)($data['substitute_teacher_id'] ?? 0);
        $subSubjectId = !empty($data['substitute_subject_id']) ? (int)$data['substitute_subject_id'] : 0;
        $reason = (string)($data['reason'] ?? '');
        $createdByVal = $createdBy !== null ? (int)$createdBy : 0;

        if ($scheduleId <= 0 || $lessonDate === '' || $originalId <= 0 || $substituteId <= 0) {
            return false;
        }
        if ($originalId === $substituteId) {
            return false;
        }

        $stmt = $this->db->prepare("INSERT INTO uchebni_substitutions
            (schedule_id, lesson_date, original_teacher_id, substitute_teacher_id, substitute_subject_id, reason, created_by)
            VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, NULLIF(?, 0))
            ON DUPLICATE KEY UPDATE substitute_teacher_id = VALUES(substitute_teacher_id),
                original_teacher_id = VALUES(original_teacher_id),
                substitute_subject_id = VALUES(substitute_subject_id),
                reason = VALUES(reason), created_by = VALUES(created_by)");
        $stmt->bind_param(
            'isiiisi',
            $scheduleId,
            $lessonDate,
            $originalId,
            $substituteId,
            $subSubjectId,
            $reason,
            $createdByVal
        );
        return $stmt->execute();
    }

    public function updateSubstitution($id, $data, $createdBy = null)
    {
        $id = (int)$id;
        $existing = $this->getSubstitutionById($id);
        if (!$existing) {
            return false;
        }

        $scheduleId = !empty($data['schedule_id']) ? (int)$data['schedule_id'] : (int)$existing['schedule_id'];
        $lessonDate = !empty($data['lesson_date']) ? (string)$data['lesson_date'] : (string)$existing['lesson_date'];
        $originalId = !empty($data['original_teacher_id'])
            ? (int)$data['original_teacher_id']
            : (int)$existing['original_teacher_id'];
        $substituteId = !empty($data['substitute_teacher_id'])
            ? (int)$data['substitute_teacher_id']
            : (int)$existing['substitute_teacher_id'];
        $subSubjectId = array_key_exists('substitute_subject_id', $data)
            ? (int)$data['substitute_subject_id']
            : (int)($existing['substitute_subject_id'] ?? 0);
        $reason = array_key_exists('reason', $data)
            ? (string)$data['reason']
            : (string)($existing['reason'] ?? '');
        $createdByVal = $createdBy !== null ? (int)$createdBy : (int)($existing['created_by'] ?? 0);

        if ($scheduleId <= 0 || $lessonDate === '' || $substituteId <= 0 || $originalId === $substituteId) {
            return false;
        }

        // Если меняется пара/дата — уникальный ключ может конфликтовать с другой записью
        $dup = $this->db->prepare(
            "SELECT id FROM uchebni_substitutions
             WHERE schedule_id = ? AND lesson_date = ? AND id <> ? LIMIT 1"
        );
        $dup->bind_param('isi', $scheduleId, $lessonDate, $id);
        $dup->execute();
        if ($dup->get_result()->fetch_assoc()) {
            return false;
        }

        $stmt = $this->db->prepare(
            "UPDATE uchebni_substitutions SET
                schedule_id = ?,
                lesson_date = ?,
                original_teacher_id = ?,
                substitute_teacher_id = ?,
                substitute_subject_id = NULLIF(?, 0),
                reason = ?,
                created_by = NULLIF(?, 0)
             WHERE id = ?"
        );
        $stmt->bind_param(
            'isiiisii',
            $scheduleId,
            $lessonDate,
            $originalId,
            $substituteId,
            $subSubjectId,
            $reason,
            $createdByVal,
            $id
        );
        return $stmt->execute();
    }

    public function deleteSubstitution($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM uchebni_substitutions WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Удалить несколько замен по id.
     * @param list<int> $ids
     * @return int число удалённых
     */
    public function deleteSubstitutions(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (empty($ids)) {
            return 0;
        }
        $in = implode(',', $ids);
        $this->db->query("DELETE FROM uchebni_substitutions WHERE id IN ($in)");
        return (int)$this->db->getAffectedRows();
    }

    public function getScheduleWithMeta($filters = [], $lessonDate = null)
    {
        $rows = $this->getSchedule($filters);
        if (!$lessonDate || empty($rows)) {
            return $rows;
        }

        $scheduleIds = array_map(function ($r) {
            return (int)$r['id'];
        }, $rows);

        $statusMap = [];
        $subMap = [];

        if (!empty($scheduleIds)) {
            $ids = implode(',', $scheduleIds);

            $statusRes = $this->db->query("SELECT schedule_id, status, note FROM uchebni_lesson_status
                WHERE lesson_date = '" . $this->db->escape($lessonDate) . "' AND schedule_id IN ($ids)");
            if ($statusRes) {
                while ($s = $statusRes->fetch_assoc()) {
                    $statusMap[(int)$s['schedule_id']] = $s;
                }
            }

            $substituteFio = self::sqlFio('st');
            $subSql = "SELECT sub.*, $substituteFio as substitute_teacher_name,
                       ss.name as substitute_subject_name
                FROM uchebni_substitutions sub
                JOIN uchebni_teachers st ON sub.substitute_teacher_id = st.id
                LEFT JOIN uchebni_subjects ss ON sub.substitute_subject_id = ss.id
                WHERE sub.lesson_date = '" . $this->db->escape($lessonDate) . "' AND sub.schedule_id IN ($ids)";
            $subRes = $this->db->query($subSql);
            if ($subRes) {
                while ($sub = $subRes->fetch_assoc()) {
                    $subMap[(int)$sub['schedule_id']] = $sub;
                }
            }
        }

        foreach ($rows as &$row) {
            $sid = (int)$row['id'];
            if (isset($statusMap[$sid])) {
                $row['lesson_status'] = $statusMap[$sid]['status'];
                $row['lesson_status_note'] = $statusMap[$sid]['note'];
            } else {
                $row['lesson_status'] = 'scheduled';
            }
            if (isset($subMap[$sid])) {
                $row['substitute_teacher_id'] = $subMap[$sid]['substitute_teacher_id'];
                $row['substitute_teacher_name'] = $subMap[$sid]['substitute_teacher_name'];
                $row['substitute_subject_id'] = $subMap[$sid]['substitute_subject_id'] ?? null;
                $row['substitute_subject_name'] = $subMap[$sid]['substitute_subject_name'] ?? null;
                $row['substitution_reason'] = $subMap[$sid]['reason'];
            }
        }
        unset($row);

        return $rows;
    }

    public function getScheduleById($id)
    {
        $teacherFio = self::sqlFio('t');
        $stmt = $this->db->prepare("SELECT sch.*, g.name as group_name, g.code as group_code,
                       s.name as subject_name, s.code as subject_code,
                       $teacherFio as teacher_name,
                       c.number as classroom_number, c.building as classroom_building
                FROM uchebni_schedule sch
                JOIN `groups` g ON sch.group_id = g.id
                JOIN uchebni_subjects s ON sch.subject_id = s.id
                JOIN uchebni_teachers t ON sch.teacher_id = t.id
                LEFT JOIN uchebni_classrooms c ON sch.classroom_id = c.id
                WHERE sch.id = ? AND sch.is_active = 1");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    // --- Учёт часов преподавателей ---

    const HOUR_REPORT_MAX_DAYS = 120;

    /**
     * @return array{0:string,1:string}|null
     */
    private function normalizeHourReportDates($dateFrom, $dateTo)
    {
        $fromTs = strtotime((string)$dateFrom);
        $toTs = strtotime((string)$dateTo);
        if (!$fromTs || !$toTs || $toTs < $fromTs) {
            return null;
        }
        $maxSpan = self::HOUR_REPORT_MAX_DAYS * 86400;
        if (($toTs - $fromTs) > $maxSpan) {
            $toTs = $fromTs + $maxSpan;
        }
        return [date('Y-m-d', $fromTs), date('Y-m-d', $toTs)];
    }

    /**
     * Вхождения занятий в диапазоне с атрибуцией часов.
     *
     * @return list<array<string,mixed>>
     */
    private function collectTeacherHourOccurrences($dateFrom, $dateTo, $periodId = null)
    {
        $normalized = $this->normalizeHourReportDates($dateFrom, $dateTo);
        if (!$normalized) {
            return [];
        }
        list($dateFrom, $dateTo) = $normalized;

        $filters = [];
        if ($periodId) {
            $filters['period_id'] = (int)$periodId;
        }
        $slots = $this->getSchedule($filters);
        if (empty($slots)) {
            return [];
        }

        $byDay = [];
        $scheduleIds = [];
        foreach ($slots as $slot) {
            $dow = (int)$slot['day_of_week'];
            if ($dow < 1 || $dow > 5) {
                continue;
            }
            $byDay[$dow][] = $slot;
            $scheduleIds[] = (int)$slot['id'];
        }
        if (empty($scheduleIds)) {
            return [];
        }

        $idsSql = implode(',', array_unique($scheduleIds));
        $fromEsc = $this->db->escape($dateFrom);
        $toEsc = $this->db->escape($dateTo);

        $statusMap = [];
        $statusRes = $this->db->query(
            "SELECT schedule_id, lesson_date, status FROM uchebni_lesson_status
             WHERE lesson_date BETWEEN '$fromEsc' AND '$toEsc'
               AND schedule_id IN ($idsSql)"
        );
        if ($statusRes) {
            while ($row = $statusRes->fetch_assoc()) {
                $statusMap[(int)$row['schedule_id'] . '|' . $row['lesson_date']] = $row['status'];
            }
        }

        $subMap = [];
        $subRes = $this->db->query(
            "SELECT sub.schedule_id, sub.lesson_date, sub.original_teacher_id, sub.substitute_teacher_id,
                    sub.substitute_subject_id,
                    CONCAT(st.last_name, ' ', st.first_name) as substitute_teacher_name,
                    ss.name as substitute_subject_name
             FROM uchebni_substitutions sub
             JOIN uchebni_teachers st ON sub.substitute_teacher_id = st.id
             LEFT JOIN uchebni_subjects ss ON sub.substitute_subject_id = ss.id
             WHERE sub.lesson_date BETWEEN '$fromEsc' AND '$toEsc'
               AND sub.schedule_id IN ($idsSql)"
        );
        if ($subRes) {
            while ($row = $subRes->fetch_assoc()) {
                $subMap[(int)$row['schedule_id'] . '|' . $row['lesson_date']] = $row;
            }
        }

        $occurrences = [];
        $periodForKind = null;
        if ($periodId) {
            foreach ($this->getPeriods() as $p) {
                if ((int)$p['id'] === (int)$periodId) {
                    $periodForKind = $p;
                    break;
                }
            }
        }
        if (!$periodForKind) {
            $periodForKind = $this->getCurrentPeriod();
        }
        $cursor = strtotime($dateFrom);
        $end = strtotime($dateTo);
        while ($cursor <= $end) {
            $date = date('Y-m-d', $cursor);
            $dow = (int)date('N', $cursor);
            $cursor = strtotime('+1 day', $cursor);
            if ($dow < 1 || $dow > 5 || empty($byDay[$dow])) {
                continue;
            }

            foreach ($byDay[$dow] as $slot) {
                $sid = (int)$slot['id'];
                // Кураторский час в учёт часов не входит
                if (self::isCuratorSlot($slot)) {
                    continue;
                }
                $slotWeekKind = self::normalizeWeekKind($slot['week_kind'] ?? 'all');
                $dateWeekKind = $this->getWeekKindForDate($date, $periodForKind);
                if (!self::slotActiveOnWeekKind($slotWeekKind, $dateWeekKind)) {
                    continue;
                }
                $key = $sid . '|' . $date;
                $status = $statusMap[$key] ?? 'scheduled';
                $isCancelled = ($status === 'cancelled');
                $originalId = (int)$slot['teacher_id'];
                $taughtById = $originalId;
                $taughtByName = $slot['teacher_name'] ?? '';
                $role = 'own';
                $sub = $subMap[$key] ?? null;

                if ($sub) {
                    $subId = (int)$sub['substitute_teacher_id'];
                    if ($subId && $subId !== $originalId) {
                        $taughtById = $subId;
                        $taughtByName = trim($sub['substitute_teacher_name'] ?? '');
                        $role = 'substitute';
                    }
                }

                $subjectId = (int)$slot['subject_id'];
                $subjectName = $slot['subject_name'] ?? '';
                if ($role === 'substitute' && !empty($sub['substitute_subject_id'])) {
                    $subjectId = (int)$sub['substitute_subject_id'];
                    if (!empty($sub['substitute_subject_name'])) {
                        $subjectName = $sub['substitute_subject_name'];
                    }
                }

                $occurrences[] = [
                    'schedule_id' => $sid,
                    'lesson_date' => $date,
                    'day_of_week' => $dow,
                    'pair_number' => (int)$slot['pair_number'],
                    'shift' => (int)($slot['shift'] ?? 1),
                    'group_id' => (int)$slot['group_id'],
                    'group_name' => $slot['group_name'] ?? '',
                    'subject_id' => $subjectId,
                    'subject_name' => $subjectName,
                    'schedule_subject_id' => (int)$slot['subject_id'],
                    'schedule_subject_name' => $slot['subject_name'] ?? '',
                    'lesson_type' => $slot['lesson_type'] ?? 'lecture',
                    'is_curator' => false,
                    'hours' => self::hoursForSlot($slot),
                    'original_teacher_id' => $originalId,
                    'original_teacher_name' => $slot['teacher_name'] ?? '',
                    'taught_by_teacher_id' => $taughtById,
                    'taught_by_teacher_name' => $taughtByName,
                    'is_cancelled' => $isCancelled,
                    'role' => $isCancelled ? 'cancelled' : $role,
                    'start_time' => $slot['start_time'] ?? null,
                    'end_time' => $slot['end_time'] ?? null,
                ];
            }
        }

        return $occurrences;
    }

    /**
     * Сводка часов по преподавателям за период дат.
     *
     * @return list<array<string,mixed>>
     */
    public function getTeacherHourReport($dateFrom, $dateTo, $teacherId = null, $periodId = null)
    {
        $normalized = $this->normalizeHourReportDates($dateFrom, $dateTo);
        if (!$normalized) {
            return [];
        }

        $teachers = $this->getTeachers(false);
        $report = [];
        foreach ($teachers as $t) {
            $tid = (int)$t['id'];
            if ($teacherId && $tid !== (int)$teacherId) {
                continue;
            }
            $report[$tid] = [
                'id' => $tid,
                'last_name' => $t['last_name'],
                'first_name' => $t['first_name'],
                'middle_name' => $t['middle_name'] ?? '',
                'teacher_name' => self::formatFio($t),
                'is_active' => (int)$t['is_active'],
                'planned_weekly' => $periodId ? $this->getTeacherWorkload($tid, (int)$periodId) : 0,
                'own_hours' => 0,
                'substitute_in' => 0,
                'substitute_out' => 0,
                'cancelled' => 0,
                'total_fact' => 0,
            ];
        }

        if ($teacherId && empty($report)) {
            $t = $this->getTeacherById((int)$teacherId);
            if ($t) {
                $tid = (int)$t['id'];
                $report[$tid] = [
                    'id' => $tid,
                    'last_name' => $t['last_name'],
                    'first_name' => $t['first_name'],
                    'middle_name' => $t['middle_name'] ?? '',
                    'teacher_name' => self::formatFio($t),
                    'is_active' => (int)$t['is_active'],
                    'planned_weekly' => $periodId ? $this->getTeacherWorkload($tid, (int)$periodId) : 0,
                    'own_hours' => 0,
                    'substitute_in' => 0,
                    'substitute_out' => 0,
                    'cancelled' => 0,
                    'total_fact' => 0,
                ];
            }
        }

        $occurrences = $this->collectTeacherHourOccurrences($normalized[0], $normalized[1], $periodId);
        foreach ($occurrences as $occ) {
            $originalId = (int)$occ['original_teacher_id'];
            $taughtById = (int)$occ['taught_by_teacher_id'];
            $h = (int)($occ['hours'] ?? self::hoursForSlot($occ));

            if (!empty($occ['is_cancelled'])) {
                if (isset($report[$originalId])) {
                    $report[$originalId]['cancelled'] += $h;
                }
                continue;
            }

            if ($occ['role'] === 'substitute') {
                // Часы снимаются у исходного и добавляются заменяющему
                if (isset($report[$taughtById])) {
                    $report[$taughtById]['substitute_in'] += $h;
                }
                if (isset($report[$originalId])) {
                    $report[$originalId]['substitute_out'] += $h;
                }
            } elseif (isset($report[$originalId])) {
                $report[$originalId]['own_hours'] += $h;
            }
        }

        foreach ($report as &$row) {
            $row['total_fact'] = (int)$row['own_hours'] + (int)$row['substitute_in'];
        }
        unset($row);

        $rows = array_values($report);
        usort($rows, function ($a, $b) {
            return strcmp($a['teacher_name'], $b['teacher_name']);
        });
        return $rows;
    }

    /**
     * Детализация часов одного преподавателя.
     *
     * @return list<array<string,mixed>>
     */
    public function getTeacherHourDetails($teacherId, $dateFrom, $dateTo, $periodId = null)
    {
        $teacherId = (int)$teacherId;
        if ($teacherId <= 0) {
            return [];
        }

        $normalized = $this->normalizeHourReportDates($dateFrom, $dateTo);
        if (!$normalized) {
            return [];
        }

        $details = [];
        foreach ($this->collectTeacherHourOccurrences($normalized[0], $normalized[1], $periodId) as $occ) {
            $originalId = (int)$occ['original_teacher_id'];
            $taughtById = (int)$occ['taught_by_teacher_id'];
            $rowRole = null;

            if (!empty($occ['is_cancelled'])) {
                if ($originalId === $teacherId) {
                    $rowRole = 'cancelled';
                }
            } elseif ($occ['role'] === 'substitute') {
                if ($taughtById === $teacherId) {
                    $rowRole = 'substitute_in';
                } elseif ($originalId === $teacherId) {
                    $rowRole = 'substitute_out';
                }
            } elseif ($originalId === $teacherId) {
                $rowRole = 'own';
            }

            if ($rowRole === null) {
                continue;
            }

            $details[] = [
                'lesson_date' => $occ['lesson_date'],
                'day_of_week' => $occ['day_of_week'],
                'pair_number' => $occ['pair_number'],
                'shift' => $occ['shift'],
                'group_name' => $occ['group_name'],
                'subject_name' => $occ['subject_name'],
                'role' => $rowRole,
                'hours' => (int)($occ['hours'] ?? self::hoursForSlot($occ)),
                'original_teacher_name' => $occ['original_teacher_name'],
                'taught_by_teacher_name' => $occ['taught_by_teacher_name'],
                'start_time' => $occ['start_time'],
                'end_time' => $occ['end_time'],
                'counts_as_fact' => in_array($rowRole, ['own', 'substitute_in'], true) ? 1 : 0,
            ];
        }

        usort($details, function ($a, $b) {
            $cmp = strcmp($a['lesson_date'], $b['lesson_date']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return $a['pair_number'] <=> $b['pair_number'];
        });

        return $details;
    }

    // --- Секции (содержание) ---

    public function getSections($periodId, $subjectId = null)
    {
        $periodId = (int)$periodId;
        if ($periodId <= 0) {
            return [];
        }
        $sql = "SELECT sec.*, s.name as subject_name, s.category_code, s.category_name,
                       s.hours_sem1, s.hours_sem2, s.item_number
                FROM uchebni_sections sec
                JOIN uchebni_subjects s ON sec.subject_id = s.id
                WHERE sec.period_id = ?";
        $params = [$periodId];
        $types = 'i';
        if ($subjectId) {
            $sql .= " AND sec.subject_id = ?";
            $params[] = (int)$subjectId;
            $types .= 'i';
        }
        $sql .= " ORDER BY (s.category_code IS NULL OR s.category_code = ''),
                  s.category_code, s.item_number, s.name, sec.stream";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function upsertSection($data)
    {
        $periodId = (int)($data['period_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $stream = (int)($data['stream'] ?? 1);
        if ($stream !== 2) {
            $stream = 1;
        }
        $sectionCount = max(0, (int)($data['section_count'] ?? 1));
        $headcount = max(0, (int)($data['headcount'] ?? 0));
        $status = (($data['status'] ?? 'active') === 'disbanded') ? 'disbanded' : 'active';
        $note = trim((string)($data['note'] ?? ''));
        if ($periodId <= 0 || $subjectId <= 0) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO uchebni_sections (period_id, subject_id, stream, section_count, headcount, status, note)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                section_count = VALUES(section_count),
                headcount = VALUES(headcount),
                status = VALUES(status),
                note = VALUES(note)"
        );
        $stmt->bind_param('iiiiiss', $periodId, $subjectId, $stream, $sectionCount, $headcount, $status, $note);
        return $stmt->execute();
    }

    public function disbandSection($id, $newSectionCount = 2)
    {
        $id = (int)$id;
        $newSectionCount = max(0, (int)$newSectionCount);
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            "UPDATE uchebni_sections SET status = 'disbanded', section_count = ?, note = CONCAT(IFNULL(note,''), IF(IFNULL(note,'')='','',' | '), 'расформировано')
             WHERE id = ?"
        );
        $stmt->bind_param('ii', $newSectionCount, $id);
        return $stmt->execute();
    }

    public function deleteSection($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM uchebni_sections WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Сводка «Содержание»: дисциплины × сем. I/II с часами и секциями.
     *
     * @return list<array<string,mixed>>
     */
    public function getContentOverview($periodId)
    {
        $periodId = (int)$periodId;
        $period = null;
        foreach ($this->getPeriods() as $p) {
            if ((int)$p['id'] === $periodId) {
                $period = $p;
                break;
            }
        }
        if (!$period) {
            $period = $this->getCurrentPeriod();
            $periodId = $period ? (int)$period['id'] : 0;
        }

        $sections = $periodId ? $this->getSections($periodId) : [];
        $bySubject = [];
        foreach ($sections as $sec) {
            $sid = (int)$sec['subject_id'];
            $stream = (int)$sec['stream'];
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = [
                    'subject_id' => $sid,
                    'subject_name' => $sec['subject_name'],
                    'category_code' => $sec['category_code'],
                    'category_name' => $sec['category_name'],
                    'hours_sem1' => (int)$sec['hours_sem1'],
                    'hours_sem2' => (int)$sec['hours_sem2'],
                    'streams' => [],
                ];
            }
            $bySubject[$sid]['streams'][$stream] = $sec;
        }

        // Дисциплины с часами, даже без секций
        foreach ($this->getSubjects(true) as $s) {
            $sid = (int)$s['id'];
            $h1 = (int)($s['hours_sem1'] ?? 0);
            $h2 = (int)($s['hours_sem2'] ?? 0);
            if ($h1 <= 0 && $h2 <= 0) {
                continue;
            }
            if (!isset($bySubject[$sid])) {
                $bySubject[$sid] = [
                    'subject_id' => $sid,
                    'subject_name' => $s['name'],
                    'category_code' => $s['category_code'] ?? '',
                    'category_name' => $s['category_name'] ?? '',
                    'hours_sem1' => $h1,
                    'hours_sem2' => $h2,
                    'streams' => [],
                ];
            }
        }

        $rows = array_values($bySubject);
        usort($rows, function ($a, $b) {
            $ca = (string)($a['category_code'] ?? '');
            $cb = (string)($b['category_code'] ?? '');
            if ($ca !== $cb) {
                return strcmp($ca, $cb);
            }
            return strcmp((string)$a['subject_name'], (string)$b['subject_name']);
        });
        return $rows;
    }

    // --- Помесячная нагрузка ---

    /** Месяцы периода в формате YYYY-MM */
    public function getPeriodMonths($period)
    {
        if (!$period || empty($period['start_date']) || empty($period['end_date'])) {
            return [];
        }
        $months = [];
        $cursor = strtotime(date('Y-m-01', strtotime($period['start_date'])));
        $end = strtotime(date('Y-m-01', strtotime($period['end_date'])));
        while ($cursor !== false && $cursor <= $end) {
            $months[] = date('Y-m', $cursor);
            $cursor = strtotime('+1 month', $cursor);
        }
        return $months;
    }

    public function getSubjectSemesterHours($subject, $semester)
    {
        $sem = ((int)$semester === 2) ? 2 : 1;
        $hours = $sem === 2 ? (int)($subject['hours_sem2'] ?? 0) : (int)($subject['hours_sem1'] ?? 0);
        if ($hours <= 0) {
            $hours = (int)($subject['hours_per_semester'] ?? 0);
        }
        return max(0, $hours);
    }

    /**
     * Равномерно разложить часы по месяцам (остаток в последний месяц).
     *
     * @param list<string> $months
     * @return array<string,int>
     */
    public static function distributeHoursEvenly($totalHours, array $months)
    {
        $totalHours = max(0, (int)$totalHours);
        $n = count($months);
        if ($n === 0) {
            return [];
        }
        $base = intdiv($totalHours, $n);
        $rem = $totalHours % $n;
        $out = [];
        foreach ($months as $i => $ym) {
            $out[$ym] = $base + (($i === $n - 1) ? $rem : 0);
        }
        return $out;
    }

    public function seedTeacherWorkloadMonths($periodId, $teacherId)
    {
        $periodId = (int)$periodId;
        $teacherId = (int)$teacherId;
        $period = null;
        foreach ($this->getPeriods() as $p) {
            if ((int)$p['id'] === $periodId) {
                $period = $p;
                break;
            }
        }
        if (!$period || $teacherId <= 0) {
            return 0;
        }

        $months = $this->getPeriodMonths($period);
        if (empty($months)) {
            return 0;
        }
        $semester = $this->getPeriodSemesterNumber($period);
        $rows = $this->getGroupSubjects($periodId);
        $seeded = 0;

        foreach ($rows as $gs) {
            if ((int)($gs['teacher_id'] ?? 0) !== $teacherId) {
                continue;
            }
            $groupId = (int)$gs['group_id'];
            $subjectId = (int)$gs['subject_id'];
            $planTotal = $this->getSubjectSemesterHours($gs, $semester);

            $existing = $this->db->prepare(
                "SELECT plan_month FROM uchebni_workload_months
                 WHERE period_id = ? AND teacher_id = ? AND group_id = ? AND subject_id = ?"
            );
            $existing->bind_param('iiii', $periodId, $teacherId, $groupId, $subjectId);
            $existing->execute();
            $have = [];
            $res = $existing->get_result();
            while ($r = $res->fetch_assoc()) {
                $have[$r['plan_month']] = true;
            }
            if (!empty($have)) {
                continue;
            }

            $dist = self::distributeHoursEvenly($planTotal, $months);
            $ins = $this->db->prepare(
                "INSERT INTO uchebni_workload_months (period_id, teacher_id, group_id, subject_id, plan_month, hours_plan)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE hours_plan = hours_plan"
            );
            foreach ($dist as $ym => $hours) {
                $ins->bind_param('iiiisi', $periodId, $teacherId, $groupId, $subjectId, $ym, $hours);
                if ($ins->execute()) {
                    $seeded++;
                }
            }
        }
        return $seeded;
    }

    public function setWorkloadMonthHours($periodId, $teacherId, $groupId, $subjectId, $yearMonth, $hoursPlan)
    {
        $periodId = (int)$periodId;
        $teacherId = (int)$teacherId;
        $groupId = (int)$groupId;
        $subjectId = (int)$subjectId;
        $hoursPlan = max(0, (int)$hoursPlan);
        $yearMonth = preg_match('/^\d{4}-\d{2}$/', (string)$yearMonth) ? (string)$yearMonth : '';
        if ($periodId <= 0 || $teacherId <= 0 || $groupId <= 0 || $subjectId <= 0 || $yearMonth === '') {
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO uchebni_workload_months (period_id, teacher_id, group_id, subject_id, plan_month, hours_plan)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE hours_plan = VALUES(hours_plan)"
        );
        $stmt->bind_param('iiiisi', $periodId, $teacherId, $groupId, $subjectId, $yearMonth, $hoursPlan);
        return $stmt->execute();
    }

    /**
     * Нагрузка преподавателя: строки группа/предмет × месяцы (план + факт).
     *
     * @return array{months:list<string>,rows:list<array>,totals:array}
     */
    public function getTeacherWorkloadReport($periodId, $teacherId)
    {
        $periodId = (int)$periodId;
        $teacherId = (int)$teacherId;
        $empty = ['months' => [], 'rows' => [], 'totals' => ['plan' => 0, 'fact' => 0]];
        $period = null;
        foreach ($this->getPeriods() as $p) {
            if ((int)$p['id'] === $periodId) {
                $period = $p;
                break;
            }
        }
        if (!$period || $teacherId <= 0) {
            return $empty;
        }

        $this->seedTeacherWorkloadMonths($periodId, $teacherId);
        $months = $this->getPeriodMonths($period);
        $semester = $this->getPeriodSemesterNumber($period);

        $planMap = [];
        $stmt = $this->db->prepare(
            "SELECT group_id, subject_id, plan_month, hours_plan
             FROM uchebni_workload_months
             WHERE period_id = ? AND teacher_id = ?"
        );
        $stmt->bind_param('ii', $periodId, $teacherId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($r = $res->fetch_assoc()) {
            $key = (int)$r['group_id'] . ':' . (int)$r['subject_id'];
            $planMap[$key][$r['plan_month']] = (int)$r['hours_plan'];
        }

        $factMap = [];
        $dateFrom = $period['start_date'];
        $dateTo = $period['end_date'];
        foreach ($this->collectTeacherHourOccurrences($dateFrom, $dateTo, $periodId) as $occ) {
            if (!empty($occ['is_cancelled'])) {
                continue;
            }
            $taughtBy = (int)$occ['taught_by_teacher_id'];
            if ($taughtBy !== $teacherId) {
                continue;
            }
            $ym = substr($occ['lesson_date'], 0, 7);
            $key = (int)$occ['group_id'] . ':' . (int)$occ['subject_id'];
            if (!isset($factMap[$key][$ym])) {
                $factMap[$key][$ym] = 0;
            }
            $factMap[$key][$ym] += (int)($occ['hours'] ?? self::hoursForSlot($occ));
        }

        $rows = [];
        $totalPlan = 0;
        $totalFact = 0;
        $seenKeys = [];
        foreach ($this->getGroupSubjects($periodId) as $gs) {
            if ((int)($gs['teacher_id'] ?? 0) !== $teacherId) {
                continue;
            }
            $key = (int)$gs['group_id'] . ':' . (int)$gs['subject_id'];
            $seenKeys[$key] = true;
            $planByMonth = [];
            $factByMonth = [];
            $rowPlan = 0;
            $rowFact = 0;
            foreach ($months as $ym) {
                $p = (int)($planMap[$key][$ym] ?? 0);
                $f = (int)($factMap[$key][$ym] ?? 0);
                $planByMonth[$ym] = $p;
                $factByMonth[$ym] = $f;
                $rowPlan += $p;
                $rowFact += $f;
            }
            $totalPlan += $rowPlan;
            $totalFact += $rowFact;
            $rows[] = [
                'group_id' => (int)$gs['group_id'],
                'group_name' => $gs['group_name'] ?? ($gs['group_code'] ?? ''),
                'subject_id' => (int)$gs['subject_id'],
                'subject_name' => $gs['subject_name'] ?? '',
                'lesson_type' => $gs['lesson_type'] ?? 'lecture',
                'plan_total_subject' => $this->getSubjectSemesterHours($gs, $semester),
                'plan_by_month' => $planByMonth,
                'fact_by_month' => $factByMonth,
                'plan_sum' => $rowPlan,
                'fact_sum' => $rowFact,
            ];
        }

        // Кураторский час и прочий факт вне учебного плана — отдельными строками
        $groupsById = [];
        foreach ($this->getActiveGroups() as $g) {
            $groupsById[(int)$g['id']] = $g;
        }
        $subjectsById = [];
        foreach ($this->getSubjects(false) as $s) {
            $subjectsById[(int)$s['id']] = $s;
        }
        foreach ($factMap as $key => $byMonth) {
            if (isset($seenKeys[$key])) {
                continue;
            }
            $parts = explode(':', $key);
            $gid = (int)($parts[0] ?? 0);
            $sid = (int)($parts[1] ?? 0);
            $g = $groupsById[$gid] ?? null;
            $subj = $subjectsById[$sid] ?? null;
            $gName = $g ? ($g['name'] ?? ($g['code'] ?? '')) : '';
            $sName = $subj ? ($subj['name'] ?? 'Кураторский час') : 'Кураторский час';
            $isCuratorSubj = $subj && (($subj['code'] ?? '') === 'CURATOR');
            $factByMonth = [];
            $rowFact = 0;
            foreach ($months as $ym) {
                $f = (int)($byMonth[$ym] ?? 0);
                $factByMonth[$ym] = $f;
                $rowFact += $f;
            }
            if ($rowFact <= 0) {
                continue;
            }
            $totalFact += $rowFact;
            $rows[] = [
                'group_id' => $gid,
                'group_name' => $gName,
                'subject_id' => $sid,
                'subject_name' => $sName,
                'lesson_type' => $isCuratorSubj ? 'curator' : 'lecture',
                'plan_total_subject' => 0,
                'plan_by_month' => array_fill_keys($months, 0),
                'fact_by_month' => $factByMonth,
                'plan_sum' => 0,
                'fact_sum' => $rowFact,
            ];
        }

        return [
            'months' => $months,
            'rows' => $rows,
            'totals' => ['plan' => $totalPlan, 'fact' => $totalFact],
        ];
    }

    /**
     * Ведомость учёта учебного времени педагога за учебный год (сент–июнь).
     * Форма по приказу МОН РК от 06.04.2020 № 130 (как в Excel «uchet.xlsx»).
     *
     * @param int $teacherId
     * @param int|null $academicYearStart год сентября (напр. 2025 → 2025/2026)
     * @return array{teacher:array|null,year_label:string,date_from:string,date_to:string,month_keys:list,month_labels:list,columns:list,rows:list,totals:array}
     */
    public function getTeacherAnnualStatement($teacherId, $academicYearStart = null)
    {
        $teacherId = (int)$teacherId;
        $teacher = $teacherId > 0 ? $this->getTeacherById($teacherId) : null;
        $empty = [
            'teacher' => $teacher,
            'year_label' => '',
            'date_from' => '',
            'date_to' => '',
            'month_keys' => [],
            'month_labels' => [],
            'columns' => [],
            'matrix_plan' => [],
            'matrix_fact' => [],
            'exams' => [],
            'consultations' => [],
            'totals' => ['plan' => 0, 'fact' => 0, 'unfulfilled' => 0, 'overtime' => 0],
        ];
        if (!$teacher) {
            return $empty;
        }

        $period = $this->getCurrentPeriod();
        if ($academicYearStart === null || (int)$academicYearStart <= 0) {
            $y = $period && !empty($period['start_date'])
                ? (int)date('Y', strtotime($period['start_date']))
                : (int)date('Y');
            $m = $period && !empty($period['start_date'])
                ? (int)date('n', strtotime($period['start_date']))
                : (int)date('n');
            // если период весенний (янв–июль) — учебный год начался прошлым сентябрём
            $academicYearStart = ($m >= 1 && $m <= 7) ? ($y - 1) : $y;
        }
        $academicYearStart = (int)$academicYearStart;
        $dateFrom = sprintf('%04d-09-01', $academicYearStart);
        $dateTo = sprintf('%04d-06-30', $academicYearStart + 1);

        $monthKeys = [];
        $monthLabels = [];
        $order = [9, 10, 11, 12, 1, 2, 3, 4, 5, 6];
        $names = [
            9 => 'Сентябрь', 10 => 'Октябрь', 11 => 'Ноябрь', 12 => 'Декабрь',
            1 => 'Январь', 2 => 'Февраль', 3 => 'Март', 4 => 'Апрель', 5 => 'Май', 6 => 'Июнь',
        ];
        foreach ($order as $mo) {
            $yy = ($mo >= 9) ? $academicYearStart : ($academicYearStart + 1);
            $key = sprintf('%04d-%02d', $yy, $mo);
            $monthKeys[] = $key;
            $monthLabels[] = $names[$mo];
        }

        // Колонки: назначения педагога за периоды, пересекающиеся с учебным годом
        $columns = [];
        $colIndex = [];
        foreach ($this->getPeriods() as $p) {
            $ps = $p['start_date'] ?? '';
            $pe = $p['end_date'] ?? '';
            if ($ps === '' || $pe === '' || $pe < $dateFrom || $ps > $dateTo) {
                continue;
            }
            $semester = $this->getPeriodSemesterNumber($p);
            foreach ($this->getGroupSubjects((int)$p['id']) as $gs) {
                if ((int)($gs['teacher_id'] ?? 0) !== $teacherId) {
                    continue;
                }
                $gid = (int)$gs['group_id'];
                $sid = (int)$gs['subject_id'];
                $key = $gid . ':' . $sid;
                if (isset($colIndex[$key])) {
                    // суммируем плановые часы семестра, если предмет в обоих семестрах
                    $columns[$colIndex[$key]]['plan_year'] += $this->getSubjectSemesterHours($gs, $semester);
                    continue;
                }
                $colIndex[$key] = count($columns);
                $columns[] = [
                    'group_id' => $gid,
                    'group_name' => $gs['group_name'] ?? ($gs['group_code'] ?? ''),
                    'subject_id' => $sid,
                    'subject_code' => trim((string)($gs['subject_code'] ?? '')),
                    'subject_name' => $gs['subject_name'] ?? '',
                    'plan_year' => $this->getSubjectSemesterHours($gs, $semester),
                    'period_ids' => [(int)$p['id']],
                ];
            }
        }

        // План по месяцам из workload_months (все периоды года)
        $planMap = []; // colKey => ym => hours
        foreach ($this->getPeriods() as $p) {
            $pid = (int)$p['id'];
            $ps = $p['start_date'] ?? '';
            $pe = $p['end_date'] ?? '';
            if ($ps === '' || $pe === '' || $pe < $dateFrom || $ps > $dateTo) {
                continue;
            }
            $this->seedTeacherWorkloadMonths($pid, $teacherId);
            $stmt = $this->db->prepare(
                "SELECT group_id, subject_id, plan_month, hours_plan
                 FROM uchebni_workload_months
                 WHERE period_id = ? AND teacher_id = ?"
            );
            $stmt->bind_param('ii', $pid, $teacherId);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($r = $res->fetch_assoc()) {
                $ck = (int)$r['group_id'] . ':' . (int)$r['subject_id'];
                $ym = $r['plan_month'];
                if (!isset($planMap[$ck][$ym])) {
                    $planMap[$ck][$ym] = 0;
                }
                $planMap[$ck][$ym] += (int)$r['hours_plan'];
            }
        }

        // Если плана по месяцам нет — равномерно разложить plan_year по месяцам года
        foreach ($columns as $col) {
            $ck = $col['group_id'] . ':' . $col['subject_id'];
            $has = false;
            foreach ($monthKeys as $ym) {
                if (!empty($planMap[$ck][$ym])) {
                    $has = true;
                    break;
                }
            }
            if (!$has && $col['plan_year'] > 0) {
                $dist = self::distributeHoursEvenly((int)$col['plan_year'], $monthKeys);
                foreach ($dist as $ym => $h) {
                    $planMap[$ck][$ym] = $h;
                }
            }
        }

        // Факт из расписания (пары × 2 ч)
        $factMap = [];
        foreach ($this->collectTeacherHourOccurrences($dateFrom, $dateTo, null) as $occ) {
            if (!empty($occ['is_cancelled'])) {
                continue;
            }
            if ((int)$occ['taught_by_teacher_id'] !== $teacherId) {
                continue;
            }
            $ck = (int)$occ['group_id'] . ':' . (int)$occ['subject_id'];
            $ym = substr($occ['lesson_date'], 0, 7);
            if (!isset($factMap[$ck][$ym])) {
                $factMap[$ck][$ym] = 0;
            }
            $factMap[$ck][$ym] += (int)($occ['hours'] ?? self::hoursForSlot($occ));

            // колонка могла появиться только из факта (замены и т.п.)
            if (!isset($colIndex[$ck])) {
                $colIndex[$ck] = count($columns);
                $columns[] = [
                    'group_id' => (int)$occ['group_id'],
                    'group_name' => $occ['group_name'] ?? '',
                    'subject_id' => (int)$occ['subject_id'],
                    'subject_code' => trim((string)($occ['subject_code'] ?? '')),
                    'subject_name' => $occ['subject_name'] ?? '',
                    'plan_year' => 0,
                    'period_ids' => [],
                ];
            }
        }

        $matrixPlan = [];
        $matrixFact = [];
        $colPlan = [];
        $colFact = [];
        foreach ($columns as $i => $col) {
            $ck = $col['group_id'] . ':' . $col['subject_id'];
            $colPlan[$i] = 0;
            $colFact[$i] = 0;
            foreach ($monthKeys as $ym) {
                $p = (int)($planMap[$ck][$ym] ?? 0);
                $f = (int)($factMap[$ck][$ym] ?? 0);
                $matrixPlan[$ym][$i] = $p;
                $matrixFact[$ym][$i] = $f;
                $colPlan[$i] += $p;
                $colFact[$i] += $f;
            }
            // если помесячный план пуст, а plan_year задан — показываем plan_year в итоге колонки
            if ($colPlan[$i] <= 0 && $col['plan_year'] > 0) {
                $colPlan[$i] = (int)$col['plan_year'];
            }
            $columns[$i]['plan_sum'] = $colPlan[$i];
            $columns[$i]['fact_sum'] = $colFact[$i];
        }

        $totalPlan = array_sum($colPlan);
        $totalFact = array_sum($colFact);

        $monthRows = [];
        foreach ($monthKeys as $i => $ym) {
            $monthRows[] = [
                'key' => $ym,
                'label' => $monthLabels[$i],
                'plan' => $matrixPlan[$ym] ?? [],
                'fact' => $matrixFact[$ym] ?? [],
            ];
        }

        return [
            'teacher' => $teacher,
            'year_label' => $academicYearStart . '/' . ($academicYearStart + 1),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'month_keys' => $monthKeys,
            'month_labels' => $monthLabels,
            'month_rows' => $monthRows,
            'columns' => $columns,
            'col_plan' => $colPlan,
            'col_fact' => $colFact,
            'totals' => [
                'plan' => $totalPlan,
                'fact' => $totalFact,
                'unfulfilled' => max(0, $totalPlan - $totalFact),
                'overtime' => max(0, $totalFact - $totalPlan),
            ],
        ];
    }

    // --- Занятость преподавателей ---

    public function getTeacherBusySlots($teacherId = null)
    {
        $sql = "SELECT * FROM uchebni_teacher_busy";
        $params = [];
        $types = '';
        if ($teacherId) {
            $sql .= " WHERE teacher_id = ?";
            $params[] = (int)$teacherId;
            $types = 'i';
        }
        $sql .= " ORDER BY teacher_id, day_of_week, pair_number";
        if ($types !== '') {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $res = $this->db->query($sql);
        return $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function setTeacherBusy($teacherId, $dayOfWeek, $pairNumber, $reason = null)
    {
        $teacherId = (int)$teacherId;
        $dayOfWeek = (int)$dayOfWeek;
        $pairNumber = (int)$pairNumber;
        $reason = $reason !== null ? trim((string)$reason) : null;
        if ($teacherId <= 0 || $dayOfWeek < 1 || $dayOfWeek > 5 || $pairNumber < 0) {
            return false;
        }
        $stmt = $this->db->prepare(
            "INSERT INTO uchebni_teacher_busy (teacher_id, day_of_week, pair_number, reason)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason)"
        );
        $stmt->bind_param('iiis', $teacherId, $dayOfWeek, $pairNumber, $reason);
        return $stmt->execute();
    }

    public function clearTeacherBusy($teacherId, $dayOfWeek, $pairNumber)
    {
        $stmt = $this->db->prepare(
            "DELETE FROM uchebni_teacher_busy WHERE teacher_id = ? AND day_of_week = ? AND pair_number = ?"
        );
        $tid = (int)$teacherId;
        $dow = (int)$dayOfWeek;
        $pair = (int)$pairNumber;
        $stmt->bind_param('iii', $tid, $dow, $pair);
        return $stmt->execute();
    }

    /**
     * Матрица занятости: teacher × день × пары (busy + schedule).
     *
     * @return list<array<string,mixed>>
     */
    public function getOccupancyMatrix($periodId, $shift = null)
    {
        $periodId = (int)$periodId;
        $teachers = $this->getTeachers(true);
        $busy = $this->getTeacherBusySlots();
        $busyMap = [];
        foreach ($busy as $b) {
            $busyMap[(int)$b['teacher_id']][(int)$b['day_of_week']][(int)$b['pair_number']] = $b['reason'] ?? '';
        }

        $filters = ['period_id' => $periodId];
        if ($shift) {
            $filters['shift'] = (int)$shift;
        }
        $schedule = $periodId ? $this->getSchedule($filters) : [];
        $schedMap = [];
        $rooms = [];
        foreach ($schedule as $slot) {
            $tid = (int)$slot['teacher_id'];
            $dow = (int)$slot['day_of_week'];
            $pair = (int)$slot['pair_number'];
            $schedMap[$tid][$dow][$pair] = [
                'group_name' => $slot['group_name'] ?? '',
                'subject_name' => $slot['subject_name'] ?? '',
                'classroom' => $slot['classroom_number'] ?? ($slot['classroom_name'] ?? ''),
                'shift' => (int)($slot['shift'] ?? 1),
            ];
            $room = trim((string)($slot['classroom_number'] ?? ''));
            if ($room !== '') {
                $rooms[$room] = true;
            }
        }

        $matrix = [];
        foreach ($teachers as $t) {
            $tid = (int)$t['id'];
            $cells = [];
            for ($dow = 1; $dow <= 5; $dow++) {
                $dayCells = [];
                for ($pair = 1; $pair <= 4; $pair++) {
                    $isBusy = isset($busyMap[$tid][$dow][$pair]);
                    $lesson = $schedMap[$tid][$dow][$pair] ?? null;
                    $dayCells[$pair] = [
                        'busy' => $isBusy,
                        'busy_reason' => $isBusy ? ($busyMap[$tid][$dow][$pair] ?? '') : '',
                        'lesson' => $lesson,
                        'occupied' => $isBusy || $lesson !== null,
                    ];
                }
                $cells[$dow] = $dayCells;
            }
            $matrix[] = [
                'teacher_id' => $tid,
                'teacher_name' => self::formatFio($t),
                'cells' => $cells,
            ];
        }

        ksort($rooms);
        return [
            'teachers' => $matrix,
            'rooms' => array_keys($rooms),
        ];
    }

    /**
     * Факт часов по группе за месяц: строки преподавателей × дни.
     *
     * @return array{days:list<array>,rows:list<array>,totals:array}
     */
    public function getGroupDailyHourGrid($periodId, $groupId, $yearMonth)
    {
        $periodId = (int)$periodId;
        $groupId = (int)$groupId;
        $yearMonth = preg_match('/^\d{4}-\d{2}$/', (string)$yearMonth) ? (string)$yearMonth : date('Y-m');
        $empty = ['days' => [], 'rows' => [], 'totals' => ['hours' => 0]];

        $start = $yearMonth . '-01';
        $end = date('Y-m-t', strtotime($start));
        if ($periodId) {
            foreach ($this->getPeriods() as $p) {
                if ((int)$p['id'] === $periodId) {
                    if ($start < $p['start_date']) {
                        $start = $p['start_date'];
                    }
                    if ($end > $p['end_date']) {
                        $end = $p['end_date'];
                    }
                    break;
                }
            }
        }

        $days = [];
        $cursor = strtotime($start);
        $endTs = strtotime($end);
        while ($cursor !== false && $cursor <= $endTs) {
            $date = date('Y-m-d', $cursor);
            $dow = (int)date('N', $cursor);
            $dayNum = (int)date('j', $cursor);
            $days[] = [
                'date' => $date,
                'day' => $dayNum,
                'dow' => $dow,
                'is_weekend' => ($dow >= 6),
                'label' => $dow === 6 ? 'С' : ($dow === 7 ? 'В' : (string)$dayNum),
            ];
            $cursor = strtotime('+1 day', $cursor);
        }

        if ($groupId <= 0) {
            return ['days' => $days, 'rows' => [], 'totals' => ['hours' => 0]];
        }

        $byTeacher = [];
        foreach ($this->collectTeacherHourOccurrences($start, $end, $periodId ?: null) as $occ) {
            if ((int)$occ['group_id'] !== $groupId) {
                continue;
            }
            if (!empty($occ['is_cancelled'])) {
                continue;
            }
            $tid = (int)$occ['taught_by_teacher_id'];
            if ($tid <= 0) {
                continue;
            }
            if (!isset($byTeacher[$tid])) {
                $byTeacher[$tid] = [
                    'teacher_id' => $tid,
                    'teacher_name' => $occ['taught_by_teacher_name'] ?: $occ['original_teacher_name'],
                    'hours_by_date' => [],
                    'total' => 0,
                ];
            }
            $date = $occ['lesson_date'];
            if (!isset($byTeacher[$tid]['hours_by_date'][$date])) {
                $byTeacher[$tid]['hours_by_date'][$date] = 0;
            }
            $h = (int)($occ['hours'] ?? self::hoursForSlot($occ));
            $byTeacher[$tid]['hours_by_date'][$date] += $h;
            $byTeacher[$tid]['total'] += $h;
        }

        $rows = array_values($byTeacher);
        usort($rows, function ($a, $b) {
            return strcmp($a['teacher_name'], $b['teacher_name']);
        });
        $grand = 0;
        foreach ($rows as $r) {
            $grand += (int)$r['total'];
        }

        return [
            'days' => $days,
            'rows' => $rows,
            'totals' => ['hours' => $grand],
            'date_from' => $start,
            'date_to' => $end,
        ];
    }

    // --- Редактируемые часы преподавателя (сетка как в nagruzka) ---

    const HOUR_NOTE_MANUAL = 'manual';
    const HOUR_NOTE_SCHEDULE = 'from_schedule';

    const DAY_SHORT_RU = [
        1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс',
    ];

    /** Стабильный цвет дисциплины для UI сетки */
    public static function subjectColor($subjectId)
    {
        $hue = abs(((int)$subjectId * 47) % 360);
        return sprintf('hsl(%d, 62%%, 46%%)', $hue);
    }

    public static function weekPartLabel($weekKind)
    {
        $weekKind = self::normalizeWeekKind($weekKind);
        if ($weekKind === self::WEEK_KIND_DEN) {
            return 'Знаменатель';
        }
        if ($weekKind === self::WEEK_KIND_NUM) {
            return 'Числитель';
        }
        return 'Каждую';
    }

    /**
     * Даты практики в периоде: Y-m-d => title.
     * group_id NULL — для всех групп.
     */
    public function getPracticeDateSet($periodId, $groupId = null)
    {
        $periodId = (int)$periodId;
        if ($periodId <= 0) {
            return [];
        }
        $sql = "SELECT title, start_date, end_date, group_id
                FROM uchebni_practice_periods
                WHERE period_id = $periodId";
        if ($groupId !== null && (int)$groupId > 0) {
            $gid = (int)$groupId;
            $sql .= " AND (group_id IS NULL OR group_id = $gid)";
        }
        $sql .= " ORDER BY start_date";
        $r = $this->db->query($sql);
        $set = [];
        if (!$r) {
            return $set;
        }
        while ($p = $r->fetch_assoc()) {
            $cur = strtotime($p['start_date']);
            $end = strtotime($p['end_date']);
            if (!$cur || !$end) {
                continue;
            }
            while ($cur <= $end) {
                $set[date('Y-m-d', $cur)] = $p['title'] ?: 'практика';
                $cur = strtotime('+1 day', $cur);
            }
        }
        return $set;
    }

    /** Список периодов практики */
    public function getPracticePeriods($periodId, $groupId = null)
    {
        $periodId = (int)$periodId;
        if ($periodId <= 0) {
            return [];
        }
        $sql = "SELECT pp.*, g.name AS group_name, g.code AS group_code
                FROM uchebni_practice_periods pp
                LEFT JOIN `groups` g ON pp.group_id = g.id
                WHERE pp.period_id = $periodId";
        if ($groupId !== null && (int)$groupId > 0) {
            $gid = (int)$groupId;
            $sql .= " AND (pp.group_id IS NULL OR pp.group_id = $gid)";
        }
        $sql .= " ORDER BY pp.start_date, pp.id";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function createPracticePeriod(array $data)
    {
        $periodId = (int)($data['period_id'] ?? 0);
        $title = trim((string)($data['title'] ?? 'практика'));
        $start = (string)($data['start_date'] ?? '');
        $end = (string)($data['end_date'] ?? '');
        $groupId = isset($data['group_id']) && (int)$data['group_id'] > 0 ? (int)$data['group_id'] : null;

        if ($periodId <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            return ['ok' => false, 'error' => 'Укажите период и даты'];
        }
        if ($end < $start) {
            return ['ok' => false, 'error' => 'Дата окончания раньше начала'];
        }
        if ($title === '') {
            $title = 'практика';
        }

        if ($groupId === null) {
            $stmt = $this->db->prepare(
                "INSERT INTO uchebni_practice_periods (period_id, group_id, title, start_date, end_date)
                 VALUES (?, NULL, ?, ?, ?)"
            );
            $stmt->bind_param('isss', $periodId, $title, $start, $end);
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO uchebni_practice_periods (period_id, group_id, title, start_date, end_date)
                 VALUES (?, ?, ?, ?, ?)"
            );
            $stmt->bind_param('iisss', $periodId, $groupId, $title, $start, $end);
        }
        if (!$stmt->execute()) {
            return ['ok' => false, 'error' => 'Не удалось сохранить'];
        }
        return ['ok' => true, 'id' => (int)$this->db->getLastInsertId()];
    }

    public function deletePracticePeriod($id)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        $stmt = $this->db->prepare("DELETE FROM uchebni_practice_periods WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    /** Входящие замены (covers) преподавателя */
    public function getScheduleCovers($filters = [])
    {
        $sql = "SELECT c.*, g.name AS group_name, g.code AS group_code,
                       s.name AS subject_name,
                       " . self::sqlFio('t') . " AS cover_teacher_name
                FROM uchebni_schedule_covers c
                JOIN `groups` g ON c.group_id = g.id
                JOIN uchebni_subjects s ON c.subject_id = s.id
                JOIN uchebni_teachers t ON c.cover_teacher_id = t.id
                WHERE 1=1";
        if (!empty($filters['period_id'])) {
            $sql .= " AND c.period_id = " . (int)$filters['period_id'];
        }
        if (!empty($filters['teacher_id'])) {
            $sql .= " AND c.cover_teacher_id = " . (int)$filters['teacher_id'];
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND c.lesson_date >= '" . $this->db->escape($filters['date_from']) . "'";
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND c.lesson_date <= '" . $this->db->escape($filters['date_to']) . "'";
        }
        if (!empty($filters['lesson_date'])) {
            $sql .= " AND c.lesson_date = '" . $this->db->escape($filters['lesson_date']) . "'";
        }
        $sql .= " ORDER BY c.lesson_date DESC, c.shift, c.pair_number, g.name";
        $r = $this->db->query($sql);
        return $r ? $r->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function createScheduleCover(array $data)
    {
        $periodId = (int)($data['period_id'] ?? 0);
        $teacherId = (int)($data['cover_teacher_id'] ?? 0);
        $date = trim((string)($data['lesson_date'] ?? ''));
        $shift = ((int)($data['shift'] ?? 1) === 2) ? 2 : 1;
        $pair = (int)($data['pair_number'] ?? 0);
        $groupId = (int)($data['group_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $replacedSubject = trim((string)($data['replaced_subject_name'] ?? ''));
        $replacedTeacher = trim((string)($data['replaced_teacher_name'] ?? ''));
        $note = trim((string)($data['note'] ?? ''));
        $createdBy = !empty($data['created_by']) ? (int)$data['created_by'] : null;

        if ($periodId <= 0 || $teacherId <= 0) {
            return ['ok' => false, 'error' => 'Не указан период или преподаватель'];
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'error' => 'Некорректная дата'];
        }
        $dow = (int)date('N', strtotime($date));
        if ($dow >= 6) {
            return ['ok' => false, 'error' => 'Суббота и воскресенье — выходные'];
        }
        if ($pair < 1 || $pair > 8) {
            return ['ok' => false, 'error' => 'Укажите номер пары (1–8)'];
        }
        if ($groupId <= 0 || $subjectId <= 0) {
            return ['ok' => false, 'error' => 'Укажите группу и дисциплину'];
        }

        $stmt = $this->db->prepare(
            "INSERT INTO uchebni_schedule_covers
                (period_id, cover_teacher_id, lesson_date, shift, pair_number, group_id, subject_id,
                 replaced_teacher_name, replaced_subject_name, note, created_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)"
        );
        $repT = $replacedTeacher !== '' ? $replacedTeacher : '';
        $repS = $replacedSubject !== '' ? $replacedSubject : '';
        $noteVal = $note !== '' ? $note : '';
        $createdByVal = $createdBy !== null ? (int)$createdBy : 0;
        $stmt->bind_param(
            'iisiiiisssi',
            $periodId,
            $teacherId,
            $date,
            $shift,
            $pair,
            $groupId,
            $subjectId,
            $repT,
            $repS,
            $noteVal,
            $createdByVal
        );
        if (!$stmt->execute()) {
            return ['ok' => false, 'error' => 'Не удалось сохранить замену'];
        }
        $id = (int)$this->db->getLastInsertId();
        $rows = $this->getScheduleCovers(['period_id' => $periodId]);
        $cover = null;
        foreach ($rows as $r) {
            if ((int)$r['id'] === $id) {
                $cover = $r;
                break;
            }
        }
        return ['ok' => true, 'id' => $id, 'cover' => $cover];
    }

    public function deleteScheduleCover($id, $teacherId = null)
    {
        $id = (int)$id;
        if ($id <= 0) {
            return false;
        }
        if ($teacherId !== null && (int)$teacherId > 0) {
            $tid = (int)$teacherId;
            $stmt = $this->db->prepare(
                "DELETE FROM uchebni_schedule_covers WHERE id = ? AND cover_teacher_id = ?"
            );
            $stmt->bind_param('ii', $id, $tid);
        } else {
            $stmt = $this->db->prepare("DELETE FROM uchebni_schedule_covers WHERE id = ?");
            $stmt->bind_param('i', $id);
        }
        $stmt->execute();
        return $stmt->affected_rows > 0;
    }

    /**
     * Плановые часы дисциплины за семестр (для прогресса в сетке).
     */
    public function getSubjectPlannedHours($subject, $period = null)
    {
        if (!is_array($subject)) {
            return 0;
        }
        $sem1 = (int)($subject['hours_sem1'] ?? 0);
        $sem2 = (int)($subject['hours_sem2'] ?? 0);
        $per = (int)($subject['hours_per_semester'] ?? 0);
        if ($sem1 > 0 || $sem2 > 0) {
            // По номеру месяца начала периода: авг–янв ≈ 1 сем, иначе 2
            $month = 9;
            if ($period && !empty($period['start_date'])) {
                $month = (int)date('n', strtotime($period['start_date']));
            }
            $isSem2 = ($month >= 2 && $month <= 7);
            $h = $isSem2 ? $sem2 : $sem1;
            if ($h <= 0) {
                $h = $sem1 + $sem2;
            }
            return $h;
        }
        return $per;
    }

    /**
     * Карта и суммы записей часов преподавателя.
     * map[subject_id][group_id][ymd][week_part] = row
     */
    public function getHourEntriesMapAndTotals($teacherId, $periodId)
    {
        $teacherId = (int)$teacherId;
        $periodId = (int)$periodId;
        $map = [];
        $totals = [];
        if ($teacherId <= 0 || $periodId <= 0) {
            return [$map, $totals];
        }
        $stmt = $this->db->prepare(
            "SELECT * FROM uchebni_hour_entries
             WHERE teacher_id = ? AND period_id = ?
             ORDER BY entry_date, subject_id, group_id"
        );
        $stmt->bind_param('ii', $teacherId, $periodId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($e = $res->fetch_assoc()) {
            $sid = (int)$e['subject_id'];
            $gid = (int)$e['group_id'];
            $ymd = $e['entry_date'];
            $part = $e['week_part'] ?: 'both';
            $map[$sid][$gid][$ymd][$part] = $e;
            $totals[$sid] = ($totals[$sid] ?? 0) + (int)$e['hours'];
        }
        return [$map, $totals];
    }

    /**
     * Сохранить ручную ячейку часов (0 = удалить).
     */
    public function upsertHourEntry(array $data)
    {
        $periodId = (int)($data['period_id'] ?? 0);
        $teacherId = (int)($data['teacher_id'] ?? 0);
        $subjectId = (int)($data['subject_id'] ?? 0);
        $groupId = (int)($data['group_id'] ?? 0);
        $date = (string)($data['entry_date'] ?? '');
        $hours = (int)($data['hours'] ?? 0);
        $weekPart = (string)($data['week_part'] ?? 'both');
        $note = (string)($data['note'] ?? self::HOUR_NOTE_MANUAL);

        if (!in_array($weekPart, ['both', 'num', 'den'], true)) {
            $weekPart = 'both';
        }
        if ($note !== self::HOUR_NOTE_SCHEDULE) {
            $note = self::HOUR_NOTE_MANUAL;
        }
        if ($periodId <= 0 || $teacherId <= 0 || $subjectId <= 0 || $date === '') {
            return ['ok' => false, 'error' => 'Недостаточно данных'];
        }
        $ts = strtotime($date);
        if (!$ts) {
            return ['ok' => false, 'error' => 'Некорректная дата'];
        }
        $dow = (int)date('N', $ts);
        if ($dow >= 6) {
            return ['ok' => false, 'error' => 'Суббота и воскресенье — выходные'];
        }
        $today = strtotime('today');
        if ($ts > $today) {
            return ['ok' => false, 'error' => 'Нельзя ставить часы на будущие даты'];
        }

        if ($hours <= 0) {
            $stmt = $this->db->prepare(
                "DELETE FROM uchebni_hour_entries
                 WHERE period_id=? AND teacher_id=? AND subject_id=? AND group_id=?
                   AND entry_date=? AND week_part=?"
            );
            $stmt->bind_param('iiiiss', $periodId, $teacherId, $subjectId, $groupId, $date, $weekPart);
            $stmt->execute();
        } else {
            $stmt = $this->db->prepare(
                "INSERT INTO uchebni_hour_entries
                    (period_id, teacher_id, subject_id, group_id, entry_date, hours, week_part, note)
                 VALUES (?,?,?,?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE hours=VALUES(hours), note=VALUES(note)"
            );
            $stmt->bind_param(
                'iiiisiss',
                $periodId,
                $teacherId,
                $subjectId,
                $groupId,
                $date,
                $hours,
                $weekPart,
                $note
            );
            $stmt->execute();
        }

        [, $totals] = $this->getHourEntriesMapAndTotals($teacherId, $periodId);
        return [
            'ok' => true,
            'total' => (int)($totals[$subjectId] ?? 0),
        ];
    }

    /**
     * Подставить часы из расписания (и covers). Ручные ячейки не трогаем.
     * @return int число записанных ячеек
     */
    public function syncTeacherHoursFromSchedule($teacherId, $periodId)
    {
        $teacherId = (int)$teacherId;
        $periodId = (int)$periodId;
        $period = null;
        foreach ($this->getPeriods() as $p) {
            if ((int)$p['id'] === $periodId) {
                $period = $p;
                break;
            }
        }
        if (!$period || $teacherId <= 0) {
            return 0;
        }

        $start = $period['start_date'];
        $semesterEnd = $period['end_date'];
        $today = date('Y-m-d');
        $factEnd = ($today < $semesterEnd) ? $today : $semesterEnd;
        if ($factEnd < $start) {
            $factEnd = $start;
        }

        $practice = $this->getPracticeDateSet($periodId);

        // Удаляем только автозаписи в диапазоне
        $del = $this->db->prepare(
            "DELETE FROM uchebni_hour_entries
             WHERE period_id = ? AND teacher_id = ? AND note = ?
               AND entry_date BETWEEN ? AND ?"
        );
        $noteSched = self::HOUR_NOTE_SCHEDULE;
        $del->bind_param('iisss', $periodId, $teacherId, $noteSched, $start, $factEnd);
        $del->execute();

        // Ручные ключи — не перезаписываем
        $manualKeys = [];
        $man = $this->db->prepare(
            "SELECT subject_id, group_id, entry_date, week_part FROM uchebni_hour_entries
             WHERE period_id = ? AND teacher_id = ? AND note = ?
               AND entry_date BETWEEN ? AND ?"
        );
        $noteMan = self::HOUR_NOTE_MANUAL;
        $man->bind_param('iisss', $periodId, $teacherId, $noteMan, $start, $factEnd);
        $man->execute();
        $manRes = $man->get_result();
        while ($row = $manRes->fetch_assoc()) {
            $manualKeys[(int)$row['subject_id'] . '|' . (int)$row['group_id'] . '|' . $row['entry_date'] . '|' . $row['week_part']] = true;
        }

        $grid = $this->buildTeacherScheduleDayLessons($teacherId, $period, $start, $factEnd, $practice);
        $ins = $this->db->prepare(
            "INSERT INTO uchebni_hour_entries
                (period_id, teacher_id, subject_id, group_id, entry_date, hours, week_part, note)
             VALUES (?,?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE hours=VALUES(hours), note=VALUES(note)"
        );
        $weekPart = 'both';
        $written = 0;
        foreach ($grid as $ymd => $lessons) {
            if (isset($practice[$ymd])) {
                continue;
            }
            foreach ($lessons as $lesson) {
                if (!empty($lesson['is_substituted']) && empty($lesson['is_cover']) && empty($lesson['is_substitute_in'])) {
                    // Свою пару отдал — в факт не пишем
                    continue;
                }
                $sid = (int)$lesson['subject_id'];
                $gid = (int)$lesson['group_id'];
                $key = $sid . '|' . $gid . '|' . $ymd . '|both';
                if (isset($manualKeys[$key])) {
                    continue;
                }
                $hours = (int)$lesson['hours'];
                if ($hours <= 0) {
                    continue;
                }
                $ins->bind_param(
                    'iiiisiss',
                    $periodId,
                    $teacherId,
                    $sid,
                    $gid,
                    $ymd,
                    $hours,
                    $weekPart,
                    $noteSched
                );
                $ins->execute();
                $written++;
            }
        }
        return $written;
    }

    /**
     * Уроки по дням из расписания + covers + замены «взял».
     * @return array<string, list<array>>
     */
    public function buildTeacherScheduleDayLessons($teacherId, $period, $dateFrom, $dateTo, array $practice = [])
    {
        $teacherId = (int)$teacherId;
        $periodId = (int)($period['id'] ?? 0);
        $slots = $this->getSchedule([
            'period_id' => $periodId,
            'teacher_id' => $teacherId,
        ]);
        $byDay = [];
        $scheduleIds = [];
        foreach ($slots as $slot) {
            if (self::isCuratorSlot($slot)) {
                continue;
            }
            $dow = (int)$slot['day_of_week'];
            if ($dow < 1 || $dow > 5) {
                continue;
            }
            $byDay[$dow][] = $slot;
            $scheduleIds[] = (int)$slot['id'];
        }

        $fromEsc = $this->db->escape($dateFrom);
        $toEsc = $this->db->escape($dateTo);

        $subOutMap = []; // schedule_id|date => sub row (отдал)
        $subInByDate = []; // date => list of substitute slots for this teacher
        if (!empty($scheduleIds)) {
            $idsSql = implode(',', array_unique($scheduleIds));
            $subRes = $this->db->query(
                "SELECT sub.*, CONCAT(st.last_name, ' ', st.first_name) AS substitute_teacher_name,
                        ss.name AS substitute_subject_name
                 FROM uchebni_substitutions sub
                 JOIN uchebni_teachers st ON sub.substitute_teacher_id = st.id
                 LEFT JOIN uchebni_subjects ss ON sub.substitute_subject_id = ss.id
                 WHERE sub.lesson_date BETWEEN '$fromEsc' AND '$toEsc'
                   AND sub.schedule_id IN ($idsSql)"
            );
            if ($subRes) {
                while ($row = $subRes->fetch_assoc()) {
                    $subOutMap[(int)$row['schedule_id'] . '|' . $row['lesson_date']] = $row;
                }
            }
        }

        $subInRes = $this->db->query(
            "SELECT sch.*, g.name AS group_name, g.code AS group_code,
                    s.name AS subject_name, sub.lesson_date,
                    sub.substitute_subject_id,
                    ss.name AS substitute_subject_name,
                    " . self::sqlFio('ot') . " AS original_teacher_name
             FROM uchebni_substitutions sub
             JOIN uchebni_schedule sch ON sub.schedule_id = sch.id AND sch.is_active = 1
             JOIN `groups` g ON sch.group_id = g.id
             JOIN uchebni_subjects s ON sch.subject_id = s.id
             JOIN uchebni_teachers ot ON sch.teacher_id = ot.id
             LEFT JOIN uchebni_subjects ss ON sub.substitute_subject_id = ss.id
             WHERE sub.substitute_teacher_id = $teacherId
               AND sub.lesson_date BETWEEN '$fromEsc' AND '$toEsc'
               AND sch.period_id = $periodId"
        );
        if ($subInRes) {
            while ($row = $subInRes->fetch_assoc()) {
                $subInByDate[$row['lesson_date']][] = $row;
            }
        }

        $coversByDate = [];
        $coverRes = $this->db->query(
            "SELECT c.*, g.name AS group_name, g.code AS group_code, s.name AS subject_name
             FROM uchebni_schedule_covers c
             JOIN `groups` g ON c.group_id = g.id
             JOIN uchebni_subjects s ON c.subject_id = s.id
             WHERE c.cover_teacher_id = $teacherId
               AND c.period_id = $periodId
               AND c.lesson_date BETWEEN '$fromEsc' AND '$toEsc'"
        );
        if ($coverRes) {
            while ($row = $coverRes->fetch_assoc()) {
                $coversByDate[$row['lesson_date']][] = $row;
            }
        }

        $out = [];
        $cursor = strtotime($dateFrom);
        $end = strtotime($dateTo);
        while ($cursor <= $end) {
            $ymd = date('Y-m-d', $cursor);
            $dow = (int)date('N', $cursor);
            $cursor = strtotime('+1 day', $cursor);
            if ($dow < 1 || $dow > 5) {
                continue;
            }
            if (isset($practice[$ymd])) {
                $out[$ymd] = [];
                continue;
            }

            $dateWeekKind = $this->getWeekKindForDate($ymd, $period);
            $agg = [];

            foreach ($byDay[$dow] ?? [] as $slot) {
                $slotWeekKind = self::normalizeWeekKind($slot['week_kind'] ?? 'all');
                if (!self::slotActiveOnWeekKind($slotWeekKind, $dateWeekKind)) {
                    continue;
                }
                $sid = (int)$slot['subject_id'];
                $gid = (int)$slot['group_id'];
                $key = $sid . ':' . $gid;
                $sub = $subOutMap[(int)$slot['id'] . '|' . $ymd] ?? null;
                if (!isset($agg[$key])) {
                    $code = $slot['group_code'] ?: ($slot['group_name'] ?? '');
                    $agg[$key] = [
                        'subject_id' => $sid,
                        'group_id' => $gid,
                        'group_code' => $code,
                        'title' => $slot['subject_name'] ?? '',
                        'color' => self::subjectColor($sid),
                        'pairs' => 0,
                        'hours' => 0,
                        'groups' => [$code],
                        'is_substituted' => false,
                        'is_extra' => false,
                        'is_cover' => false,
                        'is_substitute_in' => false,
                        'substitute_name' => null,
                    ];
                }
                $agg[$key]['pairs']++;
                $agg[$key]['hours'] = $agg[$key]['pairs'] * self::HOURS_PER_PAIR;
                if ($sub) {
                    $agg[$key]['is_substituted'] = true;
                    $agg[$key]['substitute_name'] = trim($sub['substitute_teacher_name'] ?? '') ?: 'Замена';
                }
            }

            foreach ($subInByDate[$ymd] ?? [] as $row) {
                if (self::isCuratorSlot($row)) {
                    continue;
                }
                $sid = !empty($row['substitute_subject_id'])
                    ? (int)$row['substitute_subject_id']
                    : (int)$row['subject_id'];
                $title = !empty($row['substitute_subject_name'])
                    ? $row['substitute_subject_name']
                    : ($row['subject_name'] ?? '');
                $gid = (int)$row['group_id'];
                $key = $sid . ':' . $gid;
                $code = $row['group_code'] ?: ($row['group_name'] ?? '');
                $label = 'Замена';
                if (!empty($row['original_teacher_name'])) {
                    $label .= ' · ' . $row['original_teacher_name'];
                }
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'subject_id' => $sid,
                        'group_id' => $gid,
                        'group_code' => $code,
                        'title' => $title,
                        'color' => self::subjectColor($sid),
                        'pairs' => 0,
                        'hours' => 0,
                        'groups' => [$code],
                        'is_substituted' => true,
                        'is_extra' => true,
                        'is_cover' => false,
                        'is_substitute_in' => true,
                        'substitute_name' => $label,
                    ];
                }
                $agg[$key]['pairs']++;
                $agg[$key]['hours'] = $agg[$key]['pairs'] * self::HOURS_PER_PAIR;
                $agg[$key]['is_substituted'] = true;
                $agg[$key]['is_substitute_in'] = true;
                $agg[$key]['substitute_name'] = $label;
            }

            foreach ($coversByDate[$ymd] ?? [] as $cover) {
                $sid = (int)$cover['subject_id'];
                $gid = (int)$cover['group_id'];
                $key = $sid . ':' . $gid;
                $code = $cover['group_code'] ?: ($cover['group_name'] ?? '');
                $label = 'Замена';
                if (!empty($cover['replaced_teacher_name'])) {
                    $label .= ' · ' . $cover['replaced_teacher_name'];
                }
                if (!isset($agg[$key])) {
                    $agg[$key] = [
                        'subject_id' => $sid,
                        'group_id' => $gid,
                        'group_code' => $code,
                        'title' => $cover['subject_name'] ?? '',
                        'color' => self::subjectColor($sid),
                        'pairs' => 0,
                        'hours' => 0,
                        'groups' => [$code],
                        'is_substituted' => true,
                        'is_extra' => true,
                        'is_cover' => true,
                        'is_substitute_in' => false,
                        'substitute_name' => $label,
                        'cover_id' => (int)$cover['id'],
                    ];
                }
                $agg[$key]['pairs']++;
                $agg[$key]['hours'] = $agg[$key]['pairs'] * self::HOURS_PER_PAIR;
                $agg[$key]['is_cover'] = true;
                $agg[$key]['is_substituted'] = true;
                $agg[$key]['substitute_name'] = $label;
            }

            $rows = array_values($agg);
            usort($rows, static function ($a, $b) {
                $g = strnatcasecmp($a['group_code'], $b['group_code']);
                if ($g !== 0) {
                    return $g;
                }
                return strnatcasecmp($a['title'], $b['title']);
            });
            $out[$ymd] = $rows;
        }

        return $out;
    }

    /**
     * Данные для UI редактируемой сетки часов преподавателя.
     */
    public function getTeacherEditableHourGrid($teacherId, $periodId = null)
    {
        $teacherId = (int)$teacherId;
        $period = null;
        if ($periodId) {
            foreach ($this->getPeriods() as $p) {
                if ((int)$p['id'] === (int)$periodId) {
                    $period = $p;
                    break;
                }
            }
        }
        if (!$period) {
            $period = $this->getCurrentPeriod();
        }
        if (!$period || $teacherId <= 0) {
            return [
                'error' => !$period ? 'Нет активного периода' : 'Преподаватель не найден',
                'period' => $period,
                'days' => [],
                'cells' => new \stdClass(),
                'totals' => [],
                'fact_end' => null,
                'synced' => 0,
            ];
        }

        $periodId = (int)$period['id'];
        $start = $period['start_date'];
        $semesterEnd = $period['end_date'];
        $today = date('Y-m-d');
        $factEnd = ($today < $semesterEnd) ? $today : $semesterEnd;
        if ($factEnd < $start) {
            $factEnd = $start;
        }

        $practice = $this->getPracticeDateSet($periodId);
        $scheduleByDay = $this->buildTeacherScheduleDayLessons($teacherId, $period, $start, $factEnd, $practice);
        [$map, $totalsBySubject] = $this->getHourEntriesMapAndTotals($teacherId, $periodId);

        $subjectIds = [];
        foreach ($scheduleByDay as $lessons) {
            foreach ($lessons as $l) {
                $subjectIds[(int)$l['subject_id']] = true;
            }
        }
        foreach (array_keys($totalsBySubject) as $sid) {
            $subjectIds[(int)$sid] = true;
        }

        $subjectsById = [];
        if (!empty($subjectIds)) {
            $idsSql = implode(',', array_map('intval', array_keys($subjectIds)));
            $sr = $this->db->query("SELECT * FROM uchebni_subjects WHERE id IN ($idsSql)");
            if ($sr) {
                while ($s = $sr->fetch_assoc()) {
                    $subjectsById[(int)$s['id']] = $s;
                }
            }
        }

        $subjTotals = [];
        foreach ($subjectIds as $sid => $_) {
            $sid = (int)$sid;
            $subj = $subjectsById[$sid] ?? ['id' => $sid];
            $subjTotals[(string)$sid] = [
                'planned' => $this->getSubjectPlannedHours($subj, $period),
                'total' => (int)($totalsBySubject[$sid] ?? 0),
            ];
        }

        $daysJson = [];
        $cursor = strtotime($start);
        $endTs = strtotime($factEnd);
        $todayYmd = $today;
        while ($cursor <= $endTs) {
            $ymd = date('Y-m-d', $cursor);
            $dow = (int)date('N', $cursor);
            $cursor = strtotime('+1 day', $cursor);
            if ($dow < 1 || $dow > 5) {
                continue;
            }
            $lessons = $scheduleByDay[$ymd] ?? [];
            $isPractice = isset($practice[$ymd]);
            if (!$isPractice && empty($lessons)) {
                continue;
            }
            $weekKind = $this->getWeekKindForDate($ymd, $period);
            $daysJson[] = [
                'ymd' => $ymd,
                'day' => (int)date('j', strtotime($ymd)),
                'dow' => $dow,
                'dow_short' => self::DAY_SHORT_RU[$dow] ?? '',
                'week_part' => $weekKind === self::WEEK_KIND_DEN ? 'denominator' : 'numerator',
                'week_kind' => $weekKind,
                'week_label' => self::weekPartLabel($weekKind),
                'is_today' => $ymd === $todayYmd,
                'is_practice' => $isPractice,
                'label' => date('d.m', strtotime($ymd)),
                'lessons' => $isPractice ? [] : $lessons,
            ];
        }

        $cellsJson = [];
        foreach ($map as $sid => $byGroup) {
            foreach ($byGroup as $gid => $byDate) {
                foreach ($byDate as $ymd => $parts) {
                    $cellsJson[$sid . ':' . $gid][$ymd] = [
                        'both' => isset($parts['both']) ? (int)$parts['both']['hours'] : 0,
                        'numerator' => isset($parts['num']) ? (int)$parts['num']['hours'] : 0,
                        'denominator' => isset($parts['den']) ? (int)$parts['den']['hours'] : 0,
                    ];
                }
            }
        }

        $defaultDay = !empty($daysJson) ? $daysJson[count($daysJson) - 1]['ymd'] : null;
        foreach ($daysJson as $dj) {
            if (!empty($dj['is_today'])) {
                $defaultDay = $dj['ymd'];
                break;
            }
        }

        return [
            'error' => null,
            'period' => $period,
            'period_id' => $periodId,
            'teacher_id' => $teacherId,
            'days' => $daysJson,
            'cells' => empty($cellsJson) ? new \stdClass() : $cellsJson,
            'totals' => $subjTotals,
            'fact_end' => $factEnd,
            'default_day' => $defaultDay,
            'hours_per_pair' => self::HOURS_PER_PAIR,
            'practice' => $practice,
        ];
    }
}
