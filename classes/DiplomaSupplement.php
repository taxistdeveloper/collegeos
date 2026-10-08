<?php
/**
 * Приложения к диплому: шаблоны дисциплин и ведомости выпускников.
 */
class DiplomaSupplement
{
    private $db;

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function defaultInstitution()
    {
        return defined('APP_LOGO_ALT') ? APP_LOGO_ALT : '';
    }

    /** Шкала балл → буква, GPA, традиционная оценка (как в приложении к диплому). */
    public static function gradeScale()
    {
        return [
            ['min' => 95, 'letter' => 'A', 'gpa' => 4.0, 'text' => 'отлично'],
            ['min' => 90, 'letter' => 'A-', 'gpa' => 3.67, 'text' => 'отлично'],
            ['min' => 85, 'letter' => 'B+', 'gpa' => 3.33, 'text' => 'хорошо'],
            ['min' => 80, 'letter' => 'B', 'gpa' => 3.0, 'text' => 'хорошо'],
            ['min' => 75, 'letter' => 'B-', 'gpa' => 2.67, 'text' => 'хорошо'],
            ['min' => 70, 'letter' => 'C+', 'gpa' => 2.33, 'text' => 'хорошо'],
            ['min' => 65, 'letter' => 'C', 'gpa' => 2.0, 'text' => 'удовл.'],
            ['min' => 60, 'letter' => 'C-', 'gpa' => 1.67, 'text' => 'удовл.'],
            ['min' => 55, 'letter' => 'D+', 'gpa' => 1.33, 'text' => 'удовл.'],
            ['min' => 50, 'letter' => 'D', 'gpa' => 1.0, 'text' => 'удовл.'],
            ['min' => 0, 'letter' => 'F', 'gpa' => 0.0, 'text' => 'неуд.'],
        ];
    }

    public static function gradeFromScore($score)
    {
        if ($score === null || $score === '') {
            return null;
        }
        $score = (float)$score;
        foreach (self::gradeScale() as $band) {
            if ($score >= $band['min']) {
                return $band;
            }
        }
        return ['min' => 0, 'letter' => 'F', 'gpa' => 0.0, 'text' => 'неуд.'];
    }

    /** 1 кредит = 24 академических часа. */
    public static function creditsFromHours($hours)
    {
        $hours = (int)$hours;
        if ($hours <= 0) {
            return null;
        }
        return round($hours / 24, 2);
    }

    public static function formatAmount($value, $forceDecimals = false)
    {
        if ($value === null || $value === '') {
            return '';
        }
        $number = (float)$value;
        if (!$forceDecimals && abs($number - round($number)) < 0.001) {
            return (string)(int)round($number);
        }
        return number_format($number, 2, '.', '');
    }

    public static function formatSpecialtyLine($code, $name)
    {
        $code = trim((string)$code);
        $name = trim((string)$name);
        if ($code !== '' && $name !== '') {
            return $code . ' «' . $name . '»';
        }
        return $code !== '' ? $code : $name;
    }

    public static function formatQualificationLine($code, $name)
    {
        $code = trim((string)$code);
        $name = trim((string)$name);
        if ($code !== '' && $name !== '') {
            $body = $code . ' – «' . $name . '»';
        } elseif ($name !== '') {
            $body = '«' . $name . '»';
        } else {
            $body = $code;
        }
        return 'Квалификация: ' . $body;
    }

    public static function splitCodeAndTitle($value)
    {
        $value = trim((string)$value);
        if ($value === '') {
            return ['', ''];
        }
        if (preg_match('/^(\d{4,8}(?:\s+\d+)?)\s*[—–\-:]*\s*[«"]?(.+?)[»"]?\s*$/u', $value, $m)) {
            return [trim($m[1]), trim($m[2])];
        }
        return ['', $value];
    }

    public static function ensureTablesExist()
    {
        static $done = false;
        if ($done) {
            return;
        }
        $done = true;
        $db = getDB();

        $db->query("CREATE TABLE IF NOT EXISTS diploma_templates (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            institution VARCHAR(255) NOT NULL DEFAULT '',
            specialty_code VARCHAR(64) NOT NULL DEFAULT '',
            specialty_name VARCHAR(500) NOT NULL DEFAULT '',
            qualification_code VARCHAR(64) NOT NULL DEFAULT '',
            qualification_name VARCHAR(500) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS diploma_template_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            template_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            row_kind VARCHAR(16) NOT NULL DEFAULT 'grade',
            name VARCHAR(500) NOT NULL,
            hours INT NULL,
            credits DECIMAL(8,2) NULL,
            KEY idx_diploma_template_rows (template_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS diploma_supplements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            template_id INT NULL,
            record_number VARCHAR(32) NOT NULL DEFAULT '',
            display_name VARCHAR(255) NOT NULL,
            year_from SMALLINT NULL,
            year_to SMALLINT NULL,
            institution VARCHAR(255) NOT NULL DEFAULT '',
            specialty_code VARCHAR(64) NOT NULL DEFAULT '',
            specialty_name VARCHAR(500) NOT NULL DEFAULT '',
            qualification_code VARCHAR(64) NOT NULL DEFAULT '',
            qualification_name VARCHAR(500) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_diploma_student (student_id),
            KEY idx_diploma_template (template_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->query("CREATE TABLE IF NOT EXISTS diploma_supplement_rows (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplement_id INT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            row_kind VARCHAR(16) NOT NULL DEFAULT 'grade',
            name VARCHAR(500) NOT NULL,
            hours INT NULL,
            credits DECIMAL(8,2) NULL,
            score DECIMAL(5,2) NULL,
            letter_grade VARCHAR(8) NOT NULL DEFAULT '',
            gpa DECIMAL(4,2) NULL,
            grade_text VARCHAR(32) NOT NULL DEFAULT '',
            KEY idx_diploma_supplement_rows (supplement_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function listTemplates()
    {
        $sql = "SELECT t.*,
                       (SELECT COUNT(*) FROM diploma_template_rows r WHERE r.template_id = t.id) AS rows_count
                FROM diploma_templates t
                ORDER BY t.title";
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getTemplate($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT * FROM diploma_templates WHERE id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $template = $stmt->get_result()->fetch_assoc();
        if (!$template) {
            return null;
        }
        $template['rows'] = $this->rowsFor('diploma_template_rows', 'template_id', $id);
        return $template;
    }

    public function saveTemplate($id, array $data, array $rows)
    {
        $title = self::cleanText($data['title'] ?? '', 255);
        if ($title === '') {
            return ['ok' => false, 'error' => 'Укажите название шаблона'];
        }
        $fields = $this->programFields($data);
        $id = (int)$id;

        $this->db->begin_transaction();
        if ($id > 0) {
            $sql = "UPDATE diploma_templates
                    SET title = ?, institution = ?, specialty_code = ?, specialty_name = ?,
                        qualification_code = ?, qualification_name = ?
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить шаблон'];
            }
            $stmt->bind_param(
                'ssssssi',
                $title,
                $fields['institution'],
                $fields['specialty_code'],
                $fields['specialty_name'],
                $fields['qualification_code'],
                $fields['qualification_name'],
                $id
            );
            if (!$stmt->execute()) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить шаблон'];
            }
        } else {
            $sql = "INSERT INTO diploma_templates
                    (title, institution, specialty_code, specialty_name, qualification_code, qualification_name)
                    VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить шаблон'];
            }
            $stmt->bind_param(
                'ssssss',
                $title,
                $fields['institution'],
                $fields['specialty_code'],
                $fields['specialty_name'],
                $fields['qualification_code'],
                $fields['qualification_name']
            );
            if (!$stmt->execute()) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить шаблон'];
            }
            $id = (int)$this->db->getLastInsertId();
        }

        if (!$this->replaceTemplateRows($id, $rows)) {
            $this->db->rollback();
            return ['ok' => false, 'error' => 'Не удалось сохранить дисциплины шаблона'];
        }
        $this->db->commit();
        return ['ok' => true, 'id' => $id];
    }

    public function deleteTemplate($id)
    {
        $id = (int)$id;
        $this->db->begin_transaction();
        $rows = $this->db->prepare("DELETE FROM diploma_template_rows WHERE template_id = ?");
        $tpl = $this->db->prepare("DELETE FROM diploma_templates WHERE id = ?");
        if (!$rows || !$tpl) {
            $this->db->rollback();
            return false;
        }
        $rows->bind_param('i', $id);
        $tpl->bind_param('i', $id);
        $ok = $rows->execute() && $tpl->execute();
        if ($ok) {
            $this->db->commit();
        } else {
            $this->db->rollback();
        }
        return $ok;
    }

    public function listSupplements($search = '')
    {
        $sql = "SELECT d.*,
                       s.last_name, s.first_name, s.middle_name, s.iin,
                       g.name AS group_name,
                       (SELECT COUNT(*) FROM diploma_supplement_rows r WHERE r.supplement_id = d.id AND r.row_kind <> 'section') AS subjects_count
                FROM diploma_supplements d
                JOIN students s ON s.id = d.student_id
                LEFT JOIN `groups` g ON g.id = s.group_id
                WHERE 1=1";
        $params = [];
        $types = '';
        $search = trim((string)$search);
        if ($search !== '') {
            $sql .= " AND (d.display_name LIKE ? OR d.record_number LIKE ? OR s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ? OR g.name LIKE ?)";
            $like = '%' . $search . '%';
            $params = [$like, $like, $like, $like, $like, $like, $like];
            $types = 'sssssss';
        }
        $sql .= " ORDER BY d.updated_at DESC, d.id DESC";
        if ($types === '') {
            $result = $this->db->query($sql);
            return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
        }
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function getSupplement($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT d.*, s.last_name, s.first_name, s.middle_name, s.iin, g.name AS group_name
                                    FROM diploma_supplements d
                                    JOIN students s ON s.id = d.student_id
                                    LEFT JOIN `groups` g ON g.id = s.group_id
                                    WHERE d.id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            return null;
        }
        $row['rows'] = $this->rowsFor('diploma_supplement_rows', 'supplement_id', $id);
        return $row;
    }

    public function saveSupplement($id, array $data, array $rows)
    {
        $studentId = (int)($data['student_id'] ?? 0);
        if ($studentId <= 0 || !$this->studentExists($studentId)) {
            return ['ok' => false, 'error' => 'Выберите студента'];
        }
        $displayName = self::cleanText($data['display_name'] ?? '', 255);
        if ($displayName === '') {
            return ['ok' => false, 'error' => 'Укажите имя для бланка'];
        }
        $fields = $this->programFields($data);
        $recordNumber = self::cleanText($data['record_number'] ?? '', 32);
        $yearFrom = self::nullableYear($data['year_from'] ?? null);
        $yearTo = self::nullableYear($data['year_to'] ?? null);
        $templateId = (int)($data['template_id'] ?? 0);
        $templateId = $templateId > 0 ? $templateId : null;
        $id = (int)$id;

        $this->db->begin_transaction();
        if ($id > 0) {
            $sql = "UPDATE diploma_supplements
                    SET student_id = ?, template_id = ?, record_number = ?, display_name = ?,
                        year_from = ?, year_to = ?, institution = ?, specialty_code = ?, specialty_name = ?,
                        qualification_code = ?, qualification_name = ?
                    WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить приложение'];
            }
            $stmt->bind_param(
                'issssssssssi',
                $studentId,
                $templateId,
                $recordNumber,
                $displayName,
                $yearFrom,
                $yearTo,
                $fields['institution'],
                $fields['specialty_code'],
                $fields['specialty_name'],
                $fields['qualification_code'],
                $fields['qualification_name'],
                $id
            );
            if (!$stmt->execute()) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить приложение'];
            }
        } else {
            $sql = "INSERT INTO diploma_supplements
                    (student_id, template_id, record_number, display_name, year_from, year_to,
                     institution, specialty_code, specialty_name, qualification_code, qualification_name)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить приложение'];
            }
            $stmt->bind_param(
                'issssssssss',
                $studentId,
                $templateId,
                $recordNumber,
                $displayName,
                $yearFrom,
                $yearTo,
                $fields['institution'],
                $fields['specialty_code'],
                $fields['specialty_name'],
                $fields['qualification_code'],
                $fields['qualification_name']
            );
            if (!$stmt->execute()) {
                $this->db->rollback();
                return ['ok' => false, 'error' => 'Не удалось сохранить приложение'];
            }
            $id = (int)$this->db->getLastInsertId();
        }

        if (!$this->replaceSupplementRows($id, $rows)) {
            $this->db->rollback();
            return ['ok' => false, 'error' => 'Не удалось сохранить дисциплины'];
        }
        $this->db->commit();
        return ['ok' => true, 'id' => $id];
    }

    public function deleteSupplement($id)
    {
        $id = (int)$id;
        $this->db->begin_transaction();
        $rows = $this->db->prepare("DELETE FROM diploma_supplement_rows WHERE supplement_id = ?");
        $doc = $this->db->prepare("DELETE FROM diploma_supplements WHERE id = ?");
        if (!$rows || !$doc) {
            $this->db->rollback();
            return false;
        }
        $rows->bind_param('i', $id);
        $doc->bind_param('i', $id);
        $ok = $rows->execute() && $doc->execute();
        if ($ok) {
            $this->db->commit();
        } else {
            $this->db->rollback();
        }
        return $ok;
    }

    public function searchStudents($query, $limit = 15)
    {
        $query = trim((string)$query);
        if (mb_strlen($query) < 2) {
            return [];
        }
        $limit = max(1, min(30, (int)$limit));
        $like = '%' . $query . '%';
        $sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin,
                       g.name AS group_name, g.specialty, g.qualification,
                       g.start_date, g.end_date, g.study_duration
                FROM students s
                LEFT JOIN `groups` g ON g.id = s.group_id
                WHERE s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?
                   OR s.iin LIKE ? OR g.name LIKE ?
                ORDER BY s.last_name, s.first_name, s.middle_name
                LIMIT ?";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('sssssi', $like, $like, $like, $like, $like, $limit);
        $stmt->execute();
        $result = $stmt->get_result();
        $students = [];
        while ($row = $result->fetch_assoc()) {
            $students[] = $this->studentPayload($row);
        }
        return $students;
    }

    public function studentPayloadById($id)
    {
        $id = (int)$id;
        $stmt = $this->db->prepare("SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin,
                                            g.name AS group_name, g.specialty, g.qualification,
                                            g.start_date, g.end_date, g.study_duration
                                     FROM students s
                                     LEFT JOIN `groups` g ON g.id = s.group_id
                                     WHERE s.id = ?");
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        return $row ? $this->studentPayload($row) : null;
    }

    public static function rowsToEditor(array $rows, $withGrades)
    {
        $editor = [];
        foreach ($rows as $row) {
            $kind = $row['row_kind'] ?? 'grade';
            $item = [
                'kind' => $kind,
                'name' => $row['name'] ?? '',
                'hours' => self::formatAmount($row['hours'] ?? null),
                'credits' => self::formatAmount($row['credits'] ?? null),
            ];
            if ($withGrades) {
                $item['score'] = self::formatAmount($row['score'] ?? null);
                $item['letter'] = $row['letter_grade'] ?? '';
                $item['gpa'] = ($row['gpa'] ?? null) === null || $row['gpa'] === ''
                    ? ''
                    : self::formatAmount($row['gpa'], true);
                $item['grade_text'] = $row['grade_text'] ?? '';
            }
            $editor[] = $item;
        }
        return $editor;
    }

    public static function parseRowsJson($json, $withGrades)
    {
        $data = json_decode((string)$json, true);
        if (!is_array($data)) {
            return [];
        }
        $rows = [];
        foreach ($data as $item) {
            if (!is_array($item)) {
                continue;
            }
            $kind = $item['kind'] ?? 'grade';
            if (!in_array($kind, ['section', 'grade', 'pass'], true)) {
                $kind = 'grade';
            }
            $name = self::cleanText($item['name'] ?? '', 500);
            if ($name === '') {
                continue;
            }
            $hours = $kind === 'section' ? null : self::nullableInt($item['hours'] ?? null);
            $credits = $kind === 'section' ? null : self::nullableDecimal($item['credits'] ?? null, 0, 9999);
            if ($hours !== null && $credits === null) {
                $credits = self::creditsFromHours($hours);
            }
            $row = [
                'row_kind' => $kind,
                'name' => $name,
                'hours' => $hours,
                'credits' => $credits,
                'score' => null,
                'letter_grade' => '',
                'gpa' => null,
                'grade_text' => '',
            ];
            if ($kind === 'pass') {
                $row['grade_text'] = 'зачет';
            } elseif ($kind === 'grade' && $withGrades) {
                $score = self::nullableDecimal($item['score'] ?? null, 0, 100);
                $computed = self::gradeFromScore($score);
                $letter = self::cleanText($item['letter'] ?? '', 8);
                $gpa = self::nullableDecimal($item['gpa'] ?? null, 0, 4);
                $text = self::cleanText($item['grade_text'] ?? '', 32);
                if ($computed) {
                    if ($letter === '') {
                        $letter = $computed['letter'];
                    }
                    if ($gpa === null) {
                        $gpa = $computed['gpa'];
                    }
                    if ($text === '') {
                        $text = $computed['text'];
                    }
                }
                $row['score'] = $score;
                $row['letter_grade'] = $letter;
                $row['gpa'] = $gpa;
                $row['grade_text'] = $text;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    private function studentPayload(array $row)
    {
        $years = self::yearsFromGroup($row);
        [$specialtyCode, $specialtyName] = self::splitCodeAndTitle($row['specialty'] ?? '');
        [$qualificationCode, $qualificationName] = self::splitCodeAndTitle($row['qualification'] ?? '');
        $last = trim((string)($row['last_name'] ?? ''));
        $first = trim((string)($row['first_name'] ?? ''));
        $middle = trim((string)($row['middle_name'] ?? ''));
        return [
            'id' => (int)$row['id'],
            'label' => trim($last . ' ' . $first . ' ' . $middle),
            'iin' => $row['iin'] ?? '',
            'group_name' => $row['group_name'] ?? '',
            'display_name' => trim($last . ' ' . $first),
            'year_from' => $years[0],
            'year_to' => $years[1],
            'institution' => self::defaultInstitution(),
            'specialty_code' => $specialtyCode,
            'specialty_name' => $specialtyName,
            'qualification_code' => $qualificationCode,
            'qualification_name' => $qualificationName,
        ];
    }

    private static function yearsFromGroup(array $group)
    {
        $from = self::yearFromDate($group['start_date'] ?? '');
        $to = self::yearFromDate($group['end_date'] ?? '');
        if ($from !== '' && $to === '' && !empty($group['study_duration']) && preg_match('/(\d+)/', (string)$group['study_duration'], $m)) {
            $to = (string)((int)$from + (int)$m[1]);
        }
        return [$from, $to];
    }

    private static function yearFromDate($value)
    {
        $value = trim((string)$value);
        if ($value === '' || strpos($value, '0000') === 0) {
            return '';
        }
        $ts = strtotime($value);
        if (!$ts) {
            return '';
        }
        return date('Y', $ts);
    }

    private function studentExists($id)
    {
        $stmt = $this->db->prepare("SELECT id FROM students WHERE id = ?");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return (bool)$stmt->get_result()->fetch_assoc();
    }

    private function programFields(array $data)
    {
        return [
            'institution' => self::cleanText($data['institution'] ?? '', 255),
            'specialty_code' => self::cleanText($data['specialty_code'] ?? '', 64),
            'specialty_name' => self::cleanText($data['specialty_name'] ?? '', 500),
            'qualification_code' => self::cleanText($data['qualification_code'] ?? '', 64),
            'qualification_name' => self::cleanText($data['qualification_name'] ?? '', 500),
        ];
    }

    private function rowsFor($table, $column, $id)
    {
        $allowed = [
            'diploma_template_rows' => 'template_id',
            'diploma_supplement_rows' => 'supplement_id',
        ];
        if (!isset($allowed[$table]) || $allowed[$table] !== $column) {
            return [];
        }
        $sql = "SELECT * FROM {$table} WHERE {$column} = ? ORDER BY sort_order, id";
        $stmt = $this->db->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function replaceTemplateRows($templateId, array $rows)
    {
        $delete = $this->db->prepare("DELETE FROM diploma_template_rows WHERE template_id = ?");
        if (!$delete) {
            return false;
        }
        $delete->bind_param('i', $templateId);
        if (!$delete->execute()) {
            return false;
        }
        if (empty($rows)) {
            return true;
        }
        $insert = $this->db->prepare("INSERT INTO diploma_template_rows
            (template_id, sort_order, row_kind, name, hours, credits) VALUES (?, ?, ?, ?, ?, ?)");
        if (!$insert) {
            return false;
        }
        foreach ($rows as $index => $row) {
            $order = $index + 1;
            $kind = $row['row_kind'];
            $name = $row['name'];
            $hours = $row['hours'];
            $credits = $row['credits'] === null ? null : (string)$row['credits'];
            $insert->bind_param('iissss', $templateId, $order, $kind, $name, $hours, $credits);
            if (!$insert->execute()) {
                return false;
            }
        }
        return true;
    }

    private function replaceSupplementRows($supplementId, array $rows)
    {
        $delete = $this->db->prepare("DELETE FROM diploma_supplement_rows WHERE supplement_id = ?");
        if (!$delete) {
            return false;
        }
        $delete->bind_param('i', $supplementId);
        if (!$delete->execute()) {
            return false;
        }
        if (empty($rows)) {
            return true;
        }
        $insert = $this->db->prepare("INSERT INTO diploma_supplement_rows
            (supplement_id, sort_order, row_kind, name, hours, credits, score, letter_grade, gpa, grade_text)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if (!$insert) {
            return false;
        }
        foreach ($rows as $index => $row) {
            $order = $index + 1;
            $kind = $row['row_kind'];
            $name = $row['name'];
            $hours = $row['hours'];
            $credits = $row['credits'] === null ? null : (string)$row['credits'];
            $score = $row['score'] === null ? null : (string)$row['score'];
            $letter = $row['letter_grade'];
            $gpa = $row['gpa'] === null ? null : (string)$row['gpa'];
            $text = $row['grade_text'];
            $insert->bind_param(
                'iissssssss',
                $supplementId,
                $order,
                $kind,
                $name,
                $hours,
                $credits,
                $score,
                $letter,
                $gpa,
                $text
            );
            if (!$insert->execute()) {
                return false;
            }
        }
        return true;
    }

    private static function cleanText($value, $max)
    {
        $value = trim(strip_tags((string)$value));
        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }
        return $value;
    }

    private static function nullableInt($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, (int)$value);
    }

    private static function nullableDecimal($value, $min, $max)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $number = (float)str_replace(',', '.', (string)$value);
        if ($number < $min) {
            $number = $min;
        }
        if ($number > $max) {
            $number = $max;
        }
        return round($number, 2);
    }

    private static function nullableYear($value)
    {
        $value = trim((string)$value);
        if ($value === '' || !preg_match('/^\d{4}$/', $value)) {
            return null;
        }
        $year = (int)$value;
        if ($year < 1990 || $year > 2100) {
            return null;
        }
        return $year;
    }
}
