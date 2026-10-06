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

    public function __construct()
    {
        $this->db = getDB();
        self::ensureTablesExist();
    }

    public static function formatStudentFio($student)
    {
        return trim(($student['last_name'] ?? '') . ' ' . ($student['first_name'] ?? '') . ' ' . ($student['middle_name'] ?? ''));
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
            INDEX idx_books_title (title(100))
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $db->query("CREATE TABLE IF NOT EXISTS library_loans (
            id INT AUTO_INCREMENT PRIMARY KEY,
            book_id INT NOT NULL,
            student_id INT NOT NULL,
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
            INDEX idx_loans_student (student_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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
        ];

        $r = $this->db->query("SELECT COALESCE(SUM(copies_total), 0) as total, COALESCE(SUM(copies_available), 0) as available
            FROM library_books WHERE status = 'active'");
        if ($r && $row = $r->fetch_assoc()) {
            $stats['books_total'] = (int)$row['total'];
            $stats['books_available'] = (int)$row['available'];
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

    public function getBooks($filters = [])
    {
        $sql = "SELECT b.*, u.last_name as added_by_last, u.first_name as added_by_first
                FROM library_books b
                LEFT JOIN users u ON b.added_by = u.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $sql .= " AND (b.title LIKE ? OR b.author LIKE ? OR b.isbn LIKE ? OR b.inventory_number LIKE ?)";
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p]);
            $types .= 'ssss';
        }
        if (!empty($filters['status'])) {
            $sql .= " AND b.status = ?";
            $params[] = $filters['status'];
            $types .= 's';
        }
        if (!empty($filters['category'])) {
            $sql .= " AND b.category = ?";
            $params[] = $filters['category'];
            $types .= 's';
        }
        if (!empty($filters['available_only'])) {
            $sql .= " AND b.copies_available > 0 AND b.status = 'active'";
        }

        $sql .= " ORDER BY b.title, b.author";

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
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
        $sql = "INSERT INTO library_books (title, author, isbn, publisher, publish_year, category, location,
                inventory_number, copies_total, copies_available, note, added_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);

        $isbn = !empty($data['isbn']) ? $data['isbn'] : null;
        $publisher = !empty($data['publisher']) ? $data['publisher'] : null;
        $year = !empty($data['publish_year']) ? (int)$data['publish_year'] : null;
        $category = !empty($data['category']) ? $data['category'] : null;
        $location = !empty($data['location']) ? $data['location'] : null;
        $inv = !empty($data['inventory_number']) ? $data['inventory_number'] : null;
        $note = !empty($data['note']) ? $data['note'] : null;
        $added_by = !empty($data['added_by']) ? (int)$data['added_by'] : null;

        $stmt->bind_param(
            'ssssisssiisi',
            $data['title'],
            $data['author'],
            $isbn,
            $publisher,
            $year,
            $category,
            $location,
            $inv,
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
                category = ?, location = ?, inventory_number = ?, copies_total = ?, copies_available = ?, note = ?
                WHERE id = ?";
        $stmt = $this->db->prepare($sql);

        $isbn = !empty($data['isbn']) ? $data['isbn'] : null;
        $publisher = !empty($data['publisher']) ? $data['publisher'] : null;
        $year = !empty($data['publish_year']) ? (int)$data['publish_year'] : null;
        $category = !empty($data['category']) ? $data['category'] : null;
        $location = !empty($data['location']) ? $data['location'] : null;
        $inv = !empty($data['inventory_number']) ? $data['inventory_number'] : null;
        $note = !empty($data['note']) ? $data['note'] : null;

        $stmt->bind_param(
            'ssssisssiisi',
            $data['title'],
            $data['author'],
            $isbn,
            $publisher,
            $year,
            $category,
            $location,
            $inv,
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

    public function getLoans($filters = [])
    {
        $sql = "SELECT l.*, b.title, b.author, b.inventory_number,
                s.last_name, s.first_name, s.middle_name, s.iin,
                g.name as group_name,
                iu.last_name as issuer_last, iu.first_name as issuer_first
                FROM library_loans l
                JOIN library_books b ON l.book_id = b.id
                JOIN students s ON l.student_id = s.id
                LEFT JOIN `groups` g ON s.group_id = g.id
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
            $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR b.title LIKE ? OR b.author LIKE ?)";
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p, $p]);
            $types .= 'sssss';
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

    public function issueBook($book_id, $student_id, $issued_by, $due_date = null, $note = null)
    {
        $book = $this->getBookById($book_id);
        if (!$book || $book['status'] !== 'active' || (int)$book['copies_available'] < 1) {
            return ['success' => false, 'error' => 'Книга недоступна для выдачи'];
        }

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

        $issued_at = date('Y-m-d');
        $due_date = $due_date ?: date('Y-m-d', strtotime('+' . self::DEFAULT_LOAN_DAYS . ' days'));

        $this->db->begin_transaction();
        try {
            $stmt = $this->db->prepare("INSERT INTO library_loans (book_id, student_id, issued_at, due_date, issued_by, note)
                VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('iissis', $book_id, $student_id, $issued_at, $due_date, $issued_by, $note);
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

            $this->fulfillReservationForStudent($book_id, $student_id);

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
}
