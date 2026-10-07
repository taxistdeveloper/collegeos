<?php
/**
 * Класс для работы с цифровой библиотекой
 */
require_once __DIR__ . '/../includes/student_status.php';

class Library
{
    private $db;
    const DEFAULT_LOAN_DAYS = 14;
    const EXTEND_DAYS = 7;
    const RESERVATION_DAYS = 7;

    /** Книжные фонды */
    const FUND_PROFESSIONAL = 'профессиональный';
    const FUND_FICTION = 'художественный';
    const FUND_EDUCATIONAL = 'учебный';

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function getFunds()
    {
        return [
            self::FUND_PROFESSIONAL => 'Профессиональный',
            self::FUND_FICTION => 'Художественный',
            self::FUND_EDUCATIONAL => 'Учебный',
        ];
    }

    public static function normalizeFund($raw)
    {
        $raw = trim(mb_strtolower((string)$raw));
        if ($raw === '') {
            return null;
        }

        $map = [
            'профессиональный' => self::FUND_PROFESSIONAL,
            'профессион' => self::FUND_PROFESSIONAL,
            'проф' => self::FUND_PROFESSIONAL,
            'professional' => self::FUND_PROFESSIONAL,
            'художественный' => self::FUND_FICTION,
            'художествен' => self::FUND_FICTION,
            'худ' => self::FUND_FICTION,
            'худ. лит' => self::FUND_FICTION,
            'худ лит' => self::FUND_FICTION,
            'fiction' => self::FUND_FICTION,
            'учебный' => self::FUND_EDUCATIONAL,
            'учебн' => self::FUND_EDUCATIONAL,
            'учебник' => self::FUND_EDUCATIONAL,
            'educational' => self::FUND_EDUCATIONAL,
        ];

        if (isset($map[$raw])) {
            return $map[$raw];
        }

        foreach ($map as $needle => $fund) {
            if (mb_strpos($raw, $needle) !== false) {
                return $fund;
            }
        }

        return null;
    }

    public static function fundLabel($fund)
    {
        $funds = self::getFunds();
        return $funds[$fund] ?? ($fund !== null && $fund !== '' ? (string)$fund : '—');
    }

    public static function formatStudentFio($student)
    {
        return trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
    }

    public static function formatBorrowerFio($row)
    {
        return self::formatStudentFio($row);
    }

    public static function borrowerTypeLabel($type)
    {
        return $type === 'teacher' ? 'Преподаватель' : 'Студент';
    }

    public static function ensureTablesExist()
    {
        $db = getDB();

        $db->query("CREATE TABLE IF NOT EXISTS library_books (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(500) NOT NULL,
            author VARCHAR(300) NOT NULL,
            isbn VARCHAR(50) NULL,
            publisher VARCHAR(200) NULL,
            publish_year SMALLINT NULL,
            category VARCHAR(100) NULL,
            fund VARCHAR(50) NULL,
            language VARCHAR(50) NULL,
            location VARCHAR(100) NULL,
            inventory_number VARCHAR(50) NULL,
            copies_total INT NOT NULL DEFAULT 1,
            copies_available INT NOT NULL DEFAULT 1,
            status ENUM('active', 'written_off') NOT NULL DEFAULT 'active',
            note TEXT NULL,
            added_by INT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_books_status (status),
            INDEX idx_books_title (title(100)),
            INDEX idx_books_fund (fund)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $langCol = $db->query("SHOW COLUMNS FROM library_books LIKE 'language'");
        if ($langCol && $langCol->num_rows === 0) {
            $db->query("ALTER TABLE library_books ADD COLUMN language VARCHAR(50) NULL AFTER category");
        }

        $fundCol = $db->query("SHOW COLUMNS FROM library_books LIKE 'fund'");
        if ($fundCol && $fundCol->num_rows === 0) {
            $db->query("ALTER TABLE library_books ADD COLUMN fund VARCHAR(50) NULL AFTER category");
            $db->query("ALTER TABLE library_books ADD INDEX idx_books_fund (fund)");
            // Перенос известных значений из category в fund
            foreach (self::getFunds() as $key => $label) {
                $escaped = $db->escape($key);
                $db->query("UPDATE library_books SET fund = '{$escaped}'
                    WHERE (fund IS NULL OR fund = '')
                    AND category IS NOT NULL
                    AND (
                        LOWER(category) = '{$escaped}'
                        OR LOWER(category) LIKE '%{$escaped}%'
                    )");
            }
        }

        // Поля учебного фонда: класс, направление, назначение
        $eduCols = [
            'grade_class' => "ALTER TABLE library_books ADD COLUMN grade_class VARCHAR(50) NULL AFTER inventory_number",
            'direction' => "ALTER TABLE library_books ADD COLUMN direction VARCHAR(200) NULL AFTER grade_class",
            'purpose' => "ALTER TABLE library_books ADD COLUMN purpose VARCHAR(200) NULL AFTER direction",
        ];
        foreach ($eduCols as $col => $alterSql) {
            $check = $db->query("SHOW COLUMNS FROM library_books LIKE '{$col}'");
            if ($check && $check->num_rows === 0) {
                $db->query($alterSql);
            }
        }

        $db->query("CREATE TABLE IF NOT EXISTS library_loans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            borrower_type ENUM('student', 'teacher') NOT NULL DEFAULT 'student',
            student_id INT NULL,
            teacher_id INT NULL,
            issued_at DATE NOT NULL,
            due_date DATE NOT NULL,
            returned_at DATE NULL,
            extended_count INT NOT NULL DEFAULT 0,
            status ENUM('active', 'returned', 'overdue') NOT NULL DEFAULT 'active',
            issued_by INT NULL,
            returned_by INT NULL,
            note TEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_loans_status (status),
            INDEX idx_loans_due (due_date),
            INDEX idx_loans_book (book_id),
            INDEX idx_loans_student (student_id),
            INDEX idx_loans_teacher (teacher_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Миграция: поддержка выдачи преподавателям
        $borrowerTypeCol = $db->query("SHOW COLUMNS FROM library_loans LIKE 'borrower_type'");
        if ($borrowerTypeCol && $borrowerTypeCol->num_rows === 0) {
            $db->query("ALTER TABLE library_loans
                ADD COLUMN borrower_type ENUM('student', 'teacher') NOT NULL DEFAULT 'student' AFTER book_id");
        }
        $teacherIdCol = $db->query("SHOW COLUMNS FROM library_loans LIKE 'teacher_id'");
        if ($teacherIdCol && $teacherIdCol->num_rows === 0) {
            $db->query("ALTER TABLE library_loans ADD COLUMN teacher_id INT NULL AFTER student_id");
            $db->query("ALTER TABLE library_loans ADD INDEX idx_loans_teacher (teacher_id)");
        }
        $studentIdCol = $db->query("SHOW COLUMNS FROM library_loans LIKE 'student_id'");
        if ($studentIdCol && $row = $studentIdCol->fetch_assoc()) {
            if (strtoupper((string)($row['Null'] ?? '')) === 'NO') {
                $db->query("ALTER TABLE library_loans MODIFY student_id INT NULL");
            }
        }

        $db->query("CREATE TABLE IF NOT EXISTS library_reservations (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            student_id INT NOT NULL,
            reserved_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATE NOT NULL,
            status ENUM('pending', 'fulfilled', 'cancelled', 'expired') NOT NULL DEFAULT 'pending',
            note TEXT NULL,
            created_by INT NULL,
            INDEX idx_res_status (status),
            INDEX idx_res_book (book_id),
            INDEX idx_res_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function getStats()
    {
        $stats = [
            'books_total' => 0,
            'books_available' => 0,
            'active_loans' => 0,
            'overdue_loans' => 0,
            'pending_reservations' => 0,
            'by_fund' => [],
        ];

        $r = $this->db->query("SELECT COALESCE(SUM(copies_total), 0) as total, COALESCE(SUM(copies_available), 0) as available
            FROM library_books WHERE status = 'active'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['books_total'] = (int)$row['total'];
            $stats['books_available'] = (int)$row['available'];
        }

        foreach (self::getFunds() as $key => $label) {
            $stats['by_fund'][$key] = ['label' => $label, 'total' => 0, 'titles' => 0];
        }

        $r = $this->db->query("SELECT fund,
                COALESCE(SUM(copies_total), 0) AS total,
                COUNT(*) AS titles
            FROM library_books
            WHERE status = 'active' AND fund IS NOT NULL AND fund != ''
            GROUP BY fund");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $fund = $row['fund'];
                if (!isset($stats['by_fund'][$fund])) {
                    $stats['by_fund'][$fund] = [
                        'label' => self::fundLabel($fund),
                        'total' => 0,
                        'titles' => 0,
                    ];
                }
                $stats['by_fund'][$fund]['total'] = (int)$row['total'];
                $stats['by_fund'][$fund]['titles'] = (int)$row['titles'];
            }
        }

        $r = $this->db->query("SELECT COUNT(*) as cnt FROM library_loans WHERE status = 'active'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['active_loans'] = (int)$row['cnt'];
        }

        $today = date('Y-m-d');
        $r = $this->db->query("SELECT COUNT(*) as cnt FROM library_loans WHERE status = 'active' AND due_date < '$today'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['overdue_loans'] = (int)$row['cnt'];
        }

        $r = $this->db->query("SELECT COUNT(*) as cnt FROM library_reservations WHERE status = 'pending'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['pending_reservations'] = (int)$row['cnt'];
        }

        return $stats;
    }

    public function searchStudents($query, $limit = 20)
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $not_graduated = sqlNotGraduatedCondition('s');
        $sql = "SELECT s.id, s.last_name, s.first_name, s.middle_name, s.iin, g.name as group_name
                FROM students s
                LEFT JOIN `groups` g ON s.group_id = g.id
                WHERE $not_graduated
                AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ?)
                ORDER BY s.last_name, s.first_name, s.middle_name
                LIMIT ?";
        $p = '%' . $query . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('ssssi', $p, $p, $p, $p, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function searchTeachers($query, $limit = 20)
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $tables = $this->db->query("SHOW TABLES LIKE 'uchebni_teachers'");
        if (!$tables || $tables->num_rows === 0) {
            return [];
        }

        $limit = max(1, min(50, (int)$limit));
        $sql = "SELECT t.id, t.last_name, t.first_name, t.middle_name
                FROM uchebni_teachers t
                WHERE t.is_active = 1
                  AND (
                        t.last_name LIKE ?
                     OR t.first_name LIKE ?
                     OR t.middle_name LIKE ?
                     OR CONCAT_WS(' ', t.last_name, t.first_name, t.middle_name) LIKE ?
                     OR CONCAT_WS(' ', t.last_name, t.first_name) LIKE ?
                  )
                ORDER BY t.last_name, t.first_name, t.middle_name
                LIMIT ?";
        $p = '%' . $query . '%';
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('sssssi', $p, $p, $p, $p, $p, $limit);
        $stmt->execute();
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    private function buildBooksFilterSql($filters = [])
    {
        $where = ' WHERE 1=1';
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $where .= ' AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.inventory_number LIKE ?
                OR b.grade_class LIKE ? OR b.direction LIKE ? OR b.purpose LIKE ?)';
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p, $p, $p, $p]);
            $types .= 'sssssss';
        }
        if (!empty($filters['status'])) {
            $where .= ' AND b.status = ?';
            $params[] = $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['category'])) {
            $where .= ' AND b.category = ?';
            $params[] = $filters['category'];
            $types .= 's';
        }
        if (!empty($filters['fund'])) {
            $where .= ' AND b.fund = ?';
            $params[] = $filters['fund'];
            $types .= 's';
        }
        if (!empty($filters['available_only'])) {
            $where .= " AND b.copies_available > 0 AND b.status = 'active'";
        }

        return ['where' => $where, 'params' => $params, 'types' => $types];
    }

    public function countBooks($filters = [])
    {
        $f = $this->buildBooksFilterSql($filters);
        $sql = 'SELECT COUNT(*) AS cnt FROM library_books b' . $f['where'];

        if (!empty($f['params'])) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($f['types'], ...$f['params']);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            return (int)($row['cnt'] ?? 0);
        }

        $result = $this->db->query($sql);
        if (!$result) {
            return 0;
        }
        $row = $result->fetch_assoc();
        return (int)($row['cnt'] ?? 0);
    }

    public function getBooks($filters = [])
    {
        $f = $this->buildBooksFilterSql($filters);
        $sql = "SELECT b.*, u.last_name as added_by_last, u.first_name as added_by_first
                FROM library_books b
                LEFT JOIN users u ON b.added_by = u.id"
            . $f['where']
            . ' ORDER BY b.title, b.author';

        $params = $f['params'];
        $types = $f['types'];

        $limit = isset($filters['limit']) ? (int)$filters['limit'] : 0;
        $offset = isset($filters['offset']) ? max(0, (int)$filters['offset']) : 0;
        // LIMIT/OFFSET вшиваем числом — надёжнее, чем bind_param на MySQL 5.7
        if ($limit > 0) {
            $sql .= ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        }

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            if (!$stmt) {
                return [];
            }
            $stmt->bind_param($types, ...$params);
            if (!$stmt->execute()) {
                return [];
            }
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }

        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function getBookById($id)
    {
        $stmt = $this->db->prepare("SELECT * FROM library_books WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        return $stmt->get_result()->fetch_assoc();
    }

    public function addBook($data)
    {
        $copies = max(1, (int)($data['copies_total'] ?? 1));
        $sql = "INSERT INTO library_books (title, author, isbn, publisher, publish_year, category, fund, language, location,
                inventory_number, grade_class, direction, purpose, copies_total, copies_available, note, added_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        $isbn = !empty($data['isbn']) ? $data['isbn'] : null;
        $publisher = !empty($data['publisher']) ? $data['publisher'] : null;
        $year = !empty($data['publish_year']) ? (int)$data['publish_year'] : null;
        $category = !empty($data['category']) ? $data['category'] : null;
        $fund = self::normalizeFund($data['fund'] ?? '');
        $language = !empty($data['language']) ? $data['language'] : null;
        $location = !empty($data['location']) ? $data['location'] : null;
        $inv = !empty($data['inventory_number']) ? $data['inventory_number'] : null;
        $grade = !empty($data['grade_class']) ? $data['grade_class'] : null;
        $direction = !empty($data['direction']) ? $data['direction'] : null;
        $purpose = !empty($data['purpose']) ? $data['purpose'] : null;
        $note = !empty($data['note']) ? $data['note'] : null;
        $added_by = !empty($data['added_by']) ? (int)$data['added_by'] : null;

        // Для учебного фонда очищаем поля общей формы
        if ($fund === self::FUND_EDUCATIONAL) {
            $isbn = null;
            $publisher = null;
            $language = null;
            $location = null;
            $category = null;
        } else {
            $grade = null;
            $direction = null;
            $purpose = null;
        }

        $stmt->bind_param(
            'ssssissssssssiisi',
            $data['title'],
            $data['author'],
            $isbn,
            $publisher,
            $year,
            $category,
            $fund,
            $language,
            $location,
            $inv,
            $grade,
            $direction,
            $purpose,
            $copies,
            $copies,
            $note,
            $added_by
        );

        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    public function updateBook($id, $data)
    {
        $book = $this->getBookById($id);
        if (!$book) {
            return false;
        }

        $copies_total = max(1, (int)($data['copies_total'] ?? $book['copies_total']));
        $on_loan = (int)$book['copies_total'] - (int)$book['copies_available'];
        $copies_available = max(0, $copies_total - $on_loan);

        $sql = "UPDATE library_books SET title = ?, author = ?, isbn = ?, publisher = ?, publish_year = ?,
                category = ?, fund = ?, language = ?, location = ?, inventory_number = ?,
                grade_class = ?, direction = ?, purpose = ?, copies_total = ?, copies_available = ?, note = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);

        $isbn = !empty($data['isbn']) ? $data['isbn'] : null;
        $publisher = !empty($data['publisher']) ? $data['publisher'] : null;
        $year = !empty($data['publish_year']) ? (int)$data['publish_year'] : null;
        $category = !empty($data['category']) ? $data['category'] : null;
        $fund = self::normalizeFund($data['fund'] ?? '');
        $language = !empty($data['language']) ? $data['language'] : null;
        $location = !empty($data['location']) ? $data['location'] : null;
        $inv = !empty($data['inventory_number']) ? $data['inventory_number'] : null;
        $grade = !empty($data['grade_class']) ? $data['grade_class'] : null;
        $direction = !empty($data['direction']) ? $data['direction'] : null;
        $purpose = !empty($data['purpose']) ? $data['purpose'] : null;
        $note = !empty($data['note']) ? $data['note'] : null;

        if ($fund === self::FUND_EDUCATIONAL) {
            $isbn = null;
            $publisher = null;
            $language = null;
            $location = null;
            $category = null;
        } else {
            $grade = null;
            $direction = null;
            $purpose = null;
        }

        $stmt->bind_param(
            'ssssissssssssiisi',
            $data['title'],
            $data['author'],
            $isbn,
            $publisher,
            $year,
            $category,
            $fund,
            $language,
            $location,
            $inv,
            $grade,
            $direction,
            $purpose,
            $copies_total,
            $copies_available,
            $note,
            $id
        );

        return $stmt->execute();
    }

    public function writeOffBook($id, $copies = 1, $note = null)
    {
        $book = $this->getBookById($id);
        if (!$book || $book['status'] !== 'active') {
            return ['success' => false, 'error' => 'Книга не найдена или уже списана'];
        }

        $copies = max(1, (int)$copies);
        $available = (int)$book['copies_available'];
        if ($copies > $available) {
            return ['success' => false, 'error' => 'Нельзя списать больше экземпляров, чем доступно (не на руках у читателей)'];
        }

        $new_total = (int)$book['copies_total'] - $copies;
        $new_available = $available - $copies;
        $status = $new_total <= 0 ? 'written_off' : 'active';

        $write_note = $book['note'] ?? '';
        if ($note) {
            $write_note .= ($write_note ? "\n" : '') . date('d.m.Y') . ' — списано ' . $copies . ' экз.: ' . $note;
        }

        $stmt = $this->db->prepare("UPDATE library_books SET copies_total = ?, copies_available = ?, status = ?, note = ? WHERE id = ?");
        $stmt->bind_param('iissi', $new_total, $new_available, $status, $write_note, $id);

        if (!$stmt->execute()) {
            return ['success' => false, 'error' => 'Ошибка при списании'];
        }

        return ['success' => true, 'written_off' => $copies, 'status' => $status];
    }

    /**
     * Полное удаление книги из БД (только для админки).
     * Нельзя удалить, пока есть активные выдачи.
     */
    public function deleteBook($id)
    {
        $id = (int)$id;
        $book = $this->getBookById($id);
        if (!$book) {
            return ['success' => false, 'error' => 'Книга не найдена'];
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) AS cnt FROM library_loans WHERE book_id = ? AND status IN ('active', 'overdue')");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        if ((int)($row['cnt'] ?? 0) > 0) {
            return ['success' => false, 'error' => 'Нельзя удалить: книга выдана читателям. Сначала примите все экземпляры'];
        }

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare('DELETE FROM library_reservations WHERE book_id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                throw new Exception('Ошибка удаления броней');
            }

            $stmt = $this->db->prepare('DELETE FROM library_loans WHERE book_id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute()) {
                throw new Exception('Ошибка удаления истории выдач');
            }

            $stmt = $this->db->prepare('DELETE FROM library_books WHERE id = ?');
            $stmt->bind_param('i', $id);
            if (!$stmt->execute() || $stmt->affected_rows === 0) {
                throw new Exception('Ошибка удаления книги');
            }

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function getLoans($filters = [])
    {
        $sql = "SELECT l.*, b.title, b.author, b.inventory_number,
                COALESCE(s.last_name, t.last_name) AS last_name,
                COALESCE(s.first_name, t.first_name) AS first_name,
                COALESCE(s.middle_name, t.middle_name) AS middle_name,
                s.iin,
                g.name as group_name,
                iu.last_name as issuer_last, iu.first_name as issuer_first
                FROM library_loans l
                JOIN library_books b ON l.book_id = b.id
                LEFT JOIN students s ON l.student_id = s.id
                LEFT JOIN `groups` g ON s.group_id = g.id
                LEFT JOIN uchebni_teachers t ON l.teacher_id = t.id
                LEFT JOIN users iu ON l.issued_by = iu.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['status'])) {
            $sql .= " AND l.status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (
                s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ?
                OR t.last_name LIKE ? OR t.first_name LIKE ? OR t.middle_name LIKE ?
                OR b.title LIKE ? OR b.author LIKE ?
            )";
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p, $p, $p, $p, $p]);
            $types .= 'ssssssss';
        }
        if (!empty($filters['overdue_only'])) {
            $sql .= " AND l.status = 'active' AND l.due_date < CURDATE()";
        }

        $sql .= " ORDER BY l.status ASC, l.due_date ASC, l.id DESC";

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function issueBook($book_id, $borrower_id, $issued_by, $due_date = null, $note = null, $borrower_type = 'student')
    {
        $book = $this->getBookById($book_id);
        if (!$book || $book['status'] !== 'active' || (int)$book['copies_available'] < 1) {
            return ['success' => false, 'error' => 'Книга недоступна для выдачи'];
        }

        $borrower_type = $borrower_type === 'teacher' ? 'teacher' : 'student';
        $student_id = null;
        $teacher_id = null;

        if ($borrower_type === 'teacher') {
            $teacher_id = (int)$borrower_id;
            $tables = $this->db->query("SHOW TABLES LIKE 'uchebni_teachers'");
            if (!$tables || $tables->num_rows === 0) {
                return ['success' => false, 'error' => 'Справочник преподавателей недоступен'];
            }
            $stmt = $this->db->prepare("SELECT id FROM uchebni_teachers WHERE id = ? AND is_active = 1");
            $stmt->bind_param('i', $teacher_id);
            $stmt->execute();
            if (!$stmt->get_result()->fetch_assoc()) {
                return ['success' => false, 'error' => 'Преподаватель не найден'];
            }
        } else {
            $student_id = (int)$borrower_id;
            $stmt = $this->db->prepare("SELECT id, graduation_date, course_end_date FROM students WHERE id = ?");
            $stmt->bind_param('i', $student_id);
            $stmt->execute();
            $student = $stmt->get_result()->fetch_assoc();
            if (!$student) {
                return ['success' => false, 'error' => 'Студент не найден'];
            }
            if (isStudentGraduated($student)) {
                return ['success' => false, 'error' => 'Студент уже выпустился — выдача недоступна'];
            }
        }

        $issued_at = date('Y-m-d');
        $due_date = $due_date ?: date('Y-m-d', strtotime('+' . self::DEFAULT_LOAN_DAYS . ' days'));

        $this->db->begin_transaction();
        try {
            if ($borrower_type === 'teacher') {
                $stmt = $this->db->prepare("INSERT INTO library_loans (book_id, borrower_type, student_id, teacher_id, issued_at, due_date, issued_by, note)
                    VALUES (?, 'teacher', NULL, ?, ?, ?, ?, ?)");
                $stmt->bind_param('iissis', $book_id, $teacher_id, $issued_at, $due_date, $issued_by, $note);
            } else {
                $stmt = $this->db->prepare("INSERT INTO library_loans (book_id, borrower_type, student_id, teacher_id, issued_at, due_date, issued_by, note)
                    VALUES (?, 'student', ?, NULL, ?, ?, ?, ?)");
                $stmt->bind_param('iissis', $book_id, $student_id, $issued_at, $due_date, $issued_by, $note);
            }
            if (!$stmt->execute()) {
                throw new Exception('Ошибка создания выдачи');
            }
            $loan_id = $this->db->getLastInsertId();

            $stmt = $this->db->prepare("UPDATE library_books SET copies_available = copies_available - 1 WHERE id = ? AND copies_available > 0");
            $stmt->bind_param('i', $book_id);
            $stmt->execute();
            if ($stmt->affected_rows === 0) {
                throw new Exception('Нет доступных экземпляров');
            }

            if ($borrower_type === 'student' && $student_id) {
                $this->fulfillReservationForStudent($book_id, $student_id);
            }

            $this->db->commit();
            return ['success' => true, 'loan_id' => $loan_id];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function returnBook($loan_id, $returned_by, $note = null)
    {
        $stmt = $this->db->prepare("SELECT * FROM library_loans WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $loan_id);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        if (!$loan) {
            return ['success' => false, 'error' => 'Активная выдача не найдена'];
        }

        $returned_at = date('Y-m-d');
        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("UPDATE library_loans SET status = 'returned', returned_at = ?, returned_by = ?,
                note = CONCAT(COALESCE(note, ''), ?) WHERE id = ?");
            $note_append = $note ? "\n" . date('d.m.Y') . ' — возврат: ' . $note : '';
            $stmt->bind_param('sisi', $returned_at, $returned_by, $note_append, $loan_id);
            if (!$stmt->execute()) {
                throw new Exception('Ошибка при приёме книги');
            }

            $stmt = $this->db->prepare("UPDATE library_books SET copies_available = copies_available + 1
                WHERE id = ? AND status = 'active'");
            $stmt->bind_param('i', $loan['book_id']);
            $stmt->execute();

            $this->db->commit();
            return ['success' => true];
        } catch (Exception $e) {
            $this->db->rollback();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public function extendLoan($loan_id, $days = null)
    {
        $days = $days ?: self::EXTEND_DAYS;

        $stmt = $this->db->prepare("SELECT * FROM library_loans WHERE id = ? AND status = 'active'");
        $stmt->bind_param('i', $loan_id);
        $stmt->execute();
        $loan = $stmt->get_result()->fetch_assoc();
        if (!$loan) {
            return ['success' => false, 'error' => 'Активная выдача не найдена'];
        }

        $new_due = date('Y-m-d', strtotime($loan['due_date'] . ' +' . (int)$days . ' days'));
        $stmt = $this->db->prepare("UPDATE library_loans SET due_date = ?, extended_count = extended_count + 1 WHERE id = ?");
        $stmt->bind_param('si', $new_due, $loan_id);

        if ($stmt->execute()) {
            return ['success' => true, 'new_due_date' => $new_due];
        }
        return ['success' => false, 'error' => 'Ошибка продления'];
    }

    public function getReservations($filters = [])
    {
        $sql = "SELECT r.*, b.title, b.author, b.copies_available,
                s.last_name, s.first_name, s.middle_name, s.iin,
                g.name as group_name
                FROM library_reservations r
                JOIN library_books b ON r.book_id = b.id
                JOIN students s ON r.student_id = s.id
                LEFT JOIN `groups` g ON s.group_id = g.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['status'])) {
            $sql .= " AND r.status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['search'])) {
            $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR b.title LIKE ?)";
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p]);
            $types .= 'ssss';
        }

        $sql .= " ORDER BY r.status ASC, r.reserved_at DESC";

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        }
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function createReservation($book_id, $student_id, $created_by, $expires_at = null, $note = null)
    {
        $book = $this->getBookById($book_id);
        if (!$book || $book['status'] !== 'active') {
            return ['success' => false, 'error' => 'Книга не найдена'];
        }

        $stmt = $this->db->prepare("SELECT id, graduation_date, course_end_date FROM students WHERE id = ?");
        $stmt->bind_param('i', $student_id);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();
        if (!$student) {
            return ['success' => false, 'error' => 'Студент не найден'];
        }
        if (isStudentGraduated($student)) {
            return ['success' => false, 'error' => 'Студент уже выпустился — бронирование недоступно'];
        }

        $stmt = $this->db->prepare("SELECT id FROM library_reservations WHERE book_id = ? AND student_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $book_id, $student_id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) {
            return ['success' => false, 'error' => 'У студента уже есть активная бронь на эту книгу'];
        }

        $expires_at = $expires_at ?: date('Y-m-d', strtotime('+' . self::RESERVATION_DAYS . ' days'));

        $stmt = $this->db->prepare("INSERT INTO library_reservations (book_id, student_id, expires_at, note, created_by)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->bind_param('iissi', $book_id, $student_id, $expires_at, $note, $created_by);

        if ($stmt->execute()) {
            return ['success' => true, 'reservation_id' => $this->db->getLastInsertId()];
        }
        return ['success' => false, 'error' => 'Ошибка создания брони'];
    }

    public function cancelReservation($id)
    {
        $stmt = $this->db->prepare("UPDATE library_reservations SET status = 'cancelled' WHERE id = ? AND status = 'pending'");
        $stmt->bind_param('i', $id);
        return $stmt->execute() && $stmt->affected_rows > 0;
    }

    private function fulfillReservationForStudent($book_id, $student_id)
    {
        $stmt = $this->db->prepare("UPDATE library_reservations SET status = 'fulfilled'
            WHERE book_id = ? AND student_id = ? AND status = 'pending'");
        $stmt->bind_param('ii', $book_id, $student_id);
        $stmt->execute();
    }

    public function expireOldReservations()
    {
        $this->db->query("UPDATE library_reservations SET status = 'expired'
            WHERE status = 'pending' AND expires_at < CURDATE()");
    }

    public function markOverdueLoans()
    {
        $this->db->query("UPDATE library_loans SET status = 'overdue'
            WHERE status = 'active' AND due_date < CURDATE()");
    }

    public function getCategories()
    {
        $result = $this->db->query("SELECT DISTINCT category FROM library_books WHERE category IS NOT NULL AND category != '' ORDER BY category");
        if (!$result) {
            return [];
        }
        return array_column($result->fetch_all(MYSQLI_ASSOC), 'category');
    }

    /**
     * Импорт книг из Excel (.xlsx).
     * Общий шаблон: Дата поступления, Инвентарный №, Экземпляров, Язык, Автор, Название, Фонд/Категория, Год издания
     * Учебный фонд: Регистрационный номер, Класс, Направление, Наименование издания, Автор, Назначение, Год издания, Количество, Примечание
     *
     * @param string|null $defaultFund принудительный фонд (например «учебный»)
     * @return array{success:bool,error?:string,imported?:int,skipped?:int,errors?:int}
     */
    public function importBooksFromXlsx($filePath, $addedBy = null, $defaultFund = null)
    {
        if (!class_exists('ZipArchive') || !class_exists('XMLReader')) {
            return ['success' => false, 'error' => 'На сервере нет ZipArchive/XMLReader'];
        }
        if (!is_readable($filePath)) {
            return ['success' => false, 'error' => 'Не удалось прочитать файл'];
        }

        @set_time_limit(600);
        @ini_set('memory_limit', '512M');

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            return ['success' => false, 'error' => 'Файл не является корректным .xlsx'];
        }

        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXml === false || $sheetXml === '') {
            // первая sheet из workbook
            $wb = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            $sheetPath = self::resolveFirstSheetPath($wb, $rels);
            if ($sheetPath) {
                $sheetXml = $zip->getFromName($sheetPath);
            }
        }
        $zip->close();

        if ($sheetXml === false || $sheetXml === '') {
            return ['success' => false, 'error' => 'В файле не найден лист с данными'];
        }

        $shared = self::parseXlsxSharedStrings($sharedXml !== false ? $sharedXml : '');
        $rows = self::parseXlsxSheetRows($sheetXml, $shared);
        if (empty($rows)) {
            return ['success' => false, 'error' => 'Файл пуст'];
        }

        $headerRow = array_shift($rows);
        $colMap = self::mapBookImportColumns($headerRow);
        if (!isset($colMap['title'])) {
            return ['success' => false, 'error' => 'Не найдена колонка «Название» / «Наименование издания». Проверьте шаблон файла.'];
        }

        $isEduTemplate = isset($colMap['grade_class']) || isset($colMap['direction']) || isset($colMap['purpose'])
            || (isset($colMap['inventory_number']) && !isset($colMap['language']));
        $forcedFund = self::normalizeFund($defaultFund ?? '');
        if ($forcedFund === null && $isEduTemplate) {
            $forcedFund = self::FUND_EDUCATIONAL;
        }

        $existingInv = [];
        $r = $this->db->query("SELECT inventory_number FROM library_books WHERE inventory_number IS NOT NULL AND inventory_number != ''");
        if ($r) {
            while ($row = $r->fetch_assoc()) {
                $existingInv[(string)$row['inventory_number']] = true;
            }
        }

        $imported = 0;
        $skipped = 0;
        $errors = 0;
        $batch = [];
        $batchSize = 200;
        $addedBy = $addedBy !== null ? (int)$addedBy : null;

        foreach ($rows as $row) {
            $title = trim((string)($row[$colMap['title']] ?? ''));
            if ($title === '') {
                $skipped++;
                continue;
            }

            $author = trim((string)($row[$colMap['author'] ?? -1] ?? ''));
            if ($author === '') {
                $author = 'Без автора';
            }

            $inv = isset($colMap['inventory_number'])
                ? trim((string)($row[$colMap['inventory_number']] ?? ''))
                : '';
            if ($inv !== '' && isset($existingInv[$inv])) {
                $skipped++;
                continue;
            }

            $copies = isset($colMap['copies']) ? (int)($row[$colMap['copies']] ?? 1) : 1;
            if ($copies < 1) {
                $copies = 1;
            }

            $languageRaw = isset($colMap['language']) ? trim((string)($row[$colMap['language']] ?? '')) : '';
            $language = self::normalizeBookLanguage($languageRaw);

            $category = isset($colMap['category']) ? trim((string)($row[$colMap['category']] ?? '')) : '';
            $fundRaw = isset($colMap['fund']) ? trim((string)($row[$colMap['fund']] ?? '')) : '';
            if ($fundRaw === '' && $category !== '') {
                $fundRaw = $category;
            }
            $fund = $forcedFund ?: self::normalizeFund($fundRaw);
            // Если колонка «Категория» — это фонд, не дублируем как свободную категорию
            if ($fund !== null && self::normalizeFund($category) === $fund) {
                $category = '';
            }
            $yearRaw = isset($colMap['publish_year']) ? trim((string)($row[$colMap['publish_year']] ?? '')) : '';
            $year = ($yearRaw !== '' && is_numeric($yearRaw)) ? (int)$yearRaw : null;
            if ($year !== null && ($year < 1000 || $year > 2100)) {
                $year = null;
            }

            $grade = isset($colMap['grade_class']) ? trim((string)($row[$colMap['grade_class']] ?? '')) : '';
            $direction = isset($colMap['direction']) ? trim((string)($row[$colMap['direction']] ?? '')) : '';
            $purpose = isset($colMap['purpose']) ? trim((string)($row[$colMap['purpose']] ?? '')) : '';

            $note = isset($colMap['note']) ? trim((string)($row[$colMap['note']] ?? '')) : '';
            if ($note === '' && isset($colMap['arrival_date'])) {
                $arrival = self::excelSerialToDate($row[$colMap['arrival_date']] ?? '');
                if ($arrival) {
                    $note = 'Поступление: ' . $arrival;
                }
            }

            if ($fund === self::FUND_EDUCATIONAL) {
                $language = null;
                $category = '';
            }

            $batch[] = [
                'title' => $title,
                'author' => $author,
                'language' => $language,
                'category' => $category !== '' ? $category : null,
                'fund' => $fund,
                'publish_year' => $year,
                'inventory_number' => $inv !== '' ? $inv : null,
                'grade_class' => $grade !== '' ? $grade : null,
                'direction' => $direction !== '' ? $direction : null,
                'purpose' => $purpose !== '' ? $purpose : null,
                'copies' => $copies,
                'note' => $note !== '' ? $note : null,
            ];

            if ($inv !== '') {
                $existingInv[$inv] = true;
            }

            if (count($batch) >= $batchSize) {
                $ok = $this->insertBookImportBatch($batch, $addedBy);
                if ($ok === false) {
                    $errors += count($batch);
                } else {
                    $imported += $ok;
                }
                $batch = [];
            }
        }

        if (!empty($batch)) {
            $ok = $this->insertBookImportBatch($batch, $addedBy);
            if ($ok === false) {
                $errors += count($batch);
            } else {
                $imported += $ok;
            }
        }

        return [
            'success' => true,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function insertBookImportBatch(array $batch, $addedBy)
    {
        if (empty($batch)) {
            return 0;
        }

        $parts = [];
        foreach ($batch as $item) {
            $title = "'" . $this->db->escape($item['title']) . "'";
            $author = "'" . $this->db->escape($item['author']) . "'";
            $year = $item['publish_year'] !== null ? (int)$item['publish_year'] : 'NULL';
            $category = $item['category'] !== null ? "'" . $this->db->escape($item['category']) . "'" : 'NULL';
            $fund = $item['fund'] !== null ? "'" . $this->db->escape($item['fund']) . "'" : 'NULL';
            $language = $item['language'] !== null ? "'" . $this->db->escape($item['language']) . "'" : 'NULL';
            $inv = $item['inventory_number'] !== null ? "'" . $this->db->escape($item['inventory_number']) . "'" : 'NULL';
            $grade = !empty($item['grade_class']) ? "'" . $this->db->escape($item['grade_class']) . "'" : 'NULL';
            $direction = !empty($item['direction']) ? "'" . $this->db->escape($item['direction']) . "'" : 'NULL';
            $purpose = !empty($item['purpose']) ? "'" . $this->db->escape($item['purpose']) . "'" : 'NULL';
            $copies = (int)$item['copies'];
            $note = $item['note'] !== null ? "'" . $this->db->escape($item['note']) . "'" : 'NULL';
            $by = $addedBy !== null ? (int)$addedBy : 'NULL';
            $parts[] = "($title, $author, $year, $category, $fund, $language, $inv, $grade, $direction, $purpose, $copies, $copies, $note, $by)";
        }

        $sql = "INSERT INTO library_books
            (title, author, publish_year, category, fund, language, inventory_number, grade_class, direction, purpose, copies_total, copies_available, note, added_by)
            VALUES " . implode(",\n", $parts);

        $result = $this->db->query($sql);
        return $result ? count($batch) : false;
    }

    public static function normalizeBookLanguage($raw)
    {
        $raw = trim(mb_strtolower((string)$raw));
        if ($raw === '') {
            return null;
        }

        $map = [
            'рус' => 'русский',
            'русский' => 'русский',
            'ru' => 'русский',
            'қаз' => 'казахский',
            'каз' => 'казахский',
            'казахский' => 'казахский',
            'kk' => 'казахский',
            'анг' => 'английский',
            'англ' => 'английский',
            'английский' => 'английский',
            'en' => 'английский',
            'нем' => 'немецкий',
            'немецкий' => 'немецкий',
            'de' => 'немецкий',
            'фр' => 'французский',
            'французский' => 'французский',
            'fr' => 'французский',
            'тур' => 'турецкий',
            'турецкий' => 'турецкий',
            'укр' => 'украинский',
            'украинский' => 'украинский',
            'польс' => 'польский',
            'польский' => 'польский',
        ];

        return $map[$raw] ?? $raw;
    }

    public static function excelSerialToDate($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
            return substr($value, 0, 10);
        }
        if (!is_numeric($value)) {
            return null;
        }
        $serial = (float)$value;
        if ($serial < 1) {
            return null;
        }
        $unix = (int)(($serial - 25569) * 86400);
        return gmdate('Y-m-d', $unix);
    }

    private static function resolveFirstSheetPath($workbookXml, $relsXml)
    {
        if (!$workbookXml || !$relsXml) {
            return 'xl/worksheets/sheet1.xml';
        }
        if (!preg_match('/<sheet[^>]*r:id="([^"]+)"/u', $workbookXml, $m)) {
            return 'xl/worksheets/sheet1.xml';
        }
        $rid = $m[1];
        if (!preg_match('/Id="' . preg_quote($rid, '/') . '"[^>]*Target="([^"]+)"/u', $relsXml, $tm)
            && !preg_match('/Target="([^"]+)"[^>]*Id="' . preg_quote($rid, '/') . '"/u', $relsXml, $tm)) {
            return 'xl/worksheets/sheet1.xml';
        }
        $target = str_replace('\\', '/', $tm[1]);
        if (strpos($target, 'xl/') === 0) {
            return $target;
        }
        return 'xl/' . ltrim($target, '/');
    }

    private static function parseXlsxSharedStrings($xml)
    {
        if ($xml === '') {
            return [];
        }

        $strings = [];
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            return [];
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $depth = $reader->depth;
            $text = '';
            while ($reader->read() && $reader->depth > $depth) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 't') {
                    $text .= $reader->readString();
                }
            }
            $strings[] = trim(preg_replace('/\s+/u', ' ', $text));
        }
        $reader->close();
        return $strings;
    }

    private static function parseXlsxSheetRows($xml, array $shared)
    {
        $rows = [];
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET)) {
            return [];
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                continue;
            }

            $rowDepth = $reader->depth;
            $cells = [];

            while ($reader->read() && $reader->depth > $rowDepth) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'c') {
                    continue;
                }

                $ref = (string)$reader->getAttribute('r');
                $type = (string)$reader->getAttribute('t');
                $col = preg_replace('/\d+/', '', $ref);
                $cellDepth = $reader->depth;
                $value = '';
                $rawV = null;
                $inline = '';

                while ($reader->read() && $reader->depth > $cellDepth) {
                    if ($reader->nodeType !== XMLReader::ELEMENT) {
                        continue;
                    }
                    if ($reader->localName === 'v') {
                        $rawV = $reader->readString();
                    } elseif ($reader->localName === 't') {
                        $inline .= $reader->readString();
                    }
                }

                if ($type === 'inlineStr') {
                    $value = $inline;
                } elseif ($type === 's' && $rawV !== null && $rawV !== '') {
                    $value = $shared[(int)$rawV] ?? '';
                } elseif ($rawV !== null) {
                    $value = $rawV;
                }

                if ($col !== '') {
                    $cells[$col] = trim(preg_replace('/\s+/u', ' ', (string)$value));
                }
            }

            $rows[] = $cells;
        }

        $reader->close();
        return $rows;
    }

    private static function mapBookImportColumns(array $headerRow)
    {
        $map = [];
        foreach ($headerRow as $col => $label) {
            $key = mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string)$label)));
            if ($key === '') {
                continue;
            }
            if (mb_strpos($key, 'наименован') !== false || mb_strpos($key, 'назван') !== false) {
                $map['title'] = $col;
            } elseif (mb_strpos($key, 'автор') !== false) {
                $map['author'] = $col;
            } elseif (mb_strpos($key, 'регистрац') !== false || mb_strpos($key, 'инвентар') !== false) {
                $map['inventory_number'] = $col;
            } elseif (mb_strpos($key, 'экземпляр') !== false || mb_strpos($key, 'количеств') !== false) {
                $map['copies'] = $col;
            } elseif (mb_strpos($key, 'язык') !== false) {
                $map['language'] = $col;
            } elseif (mb_strpos($key, 'фонд') !== false) {
                $map['fund'] = $col;
            } elseif ($key === 'класс' || mb_strpos($key, 'класс') === 0) {
                $map['grade_class'] = $col;
            } elseif (mb_strpos($key, 'направлен') !== false) {
                $map['direction'] = $col;
            } elseif (mb_strpos($key, 'назначен') !== false) {
                $map['purpose'] = $col;
            } elseif (mb_strpos($key, 'категор') !== false) {
                $map['category'] = $col;
            } elseif (mb_strpos($key, 'год') !== false) {
                $map['publish_year'] = $col;
            } elseif (mb_strpos($key, 'примечан') !== false) {
                $map['note'] = $col;
            } elseif (mb_strpos($key, 'поступлен') !== false || mb_strpos($key, 'дата') !== false) {
                $map['arrival_date'] = $col;
            }
        }
        return $map;
    }

    /**
     * Путь к локальному файлу учебного фонда, если есть.
     */
    public static function findEducationalFundXlsx($dir)
    {
        $exact = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'Учебный фонд.xlsx';
        if (is_readable($exact)) {
            return $exact;
        }
        foreach (glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*.xlsx') ?: [] as $path) {
            $base = mb_strtolower(pathinfo($path, PATHINFO_FILENAME));
            if (mb_strpos($base, 'учебн') !== false) {
                return $path;
            }
        }
        return null;
    }
}
