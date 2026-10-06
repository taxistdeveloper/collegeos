<?php
/**
 * Класс для работы со справками (CRUD)
 */
class Spravka {
    private $db;

    public static function getTypes() {
        return [
            'obuchenie' => 'Справка об обучении',
            'mesto_ucheby' => 'Справка с места учёбы',
            'praktika' => 'Справка о прохождении практики',
            'status' => 'Справка о статусе студента',
        ];
    }

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Список справок с фильтрами
     */
    public function getList($filters = []) {
        $sql = "SELECT sp.*, 
                s.last_name, s.first_name, s.middle_name, s.iin,
                g.name as group_name, g.specialty, g.study_form,
                u.first_name as issuer_first, u.last_name as issuer_last
                FROM spravki sp
                JOIN students s ON sp.student_id = s.id
                LEFT JOIN `groups` g ON s.group_id = g.id
                LEFT JOIN users u ON sp.issued_by = u.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if (!empty($filters['search'])) {
            $sql .= " AND (s.last_name LIKE ? OR s.first_name LIKE ? OR s.middle_name LIKE ? OR s.iin LIKE ?)";
            $p = '%' . $filters['search'] . '%';
            $params = array_merge($params, [$p, $p, $p, $p]);
            $types .= 'ssss';
        }
        if (!empty($filters['type'])) {
            $sql .= " AND sp.type = ?";
            $params[] = $filters['type'];
            $types .= 's';
        }
        if (!empty($filters['date_from'])) {
            $sql .= " AND sp.issued_at >= ?";
            $params[] = $filters['date_from'];
            $types .= 's';
        }
        if (!empty($filters['date_to'])) {
            $sql .= " AND sp.issued_at <= ?";
            $params[] = $filters['date_to'];
            $types .= 's';
        }

        $sql .= " ORDER BY sp.issued_at DESC, sp.id DESC";

        if (!empty($params)) {
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $result = $stmt->get_result();
            return $result->fetch_all(MYSQLI_ASSOC);
        }
        $result = $this->db->query($sql);
        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    /**
     * Одна справка по ID
     */
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT sp.*, 
                s.last_name, s.first_name, s.middle_name, s.iin, s.birth_date, s.gender,
                g.name as group_name, g.specialty, g.qualification, g.study_form, g.language
                FROM spravki sp
                JOIN students s ON sp.student_id = s.id
                LEFT JOIN `groups` g ON s.group_id = g.id
                WHERE sp.id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Добавить справку
     */
    public function create($data) {
        $sql = "INSERT INTO spravki (student_id, type, template_id, issued_at, issued_by, note) VALUES (?, ?, ?, ?, ?, ?)";
        $stmt = $this->db->prepare($sql);
        $issued_by = !empty($data['issued_by']) ? (int)$data['issued_by'] : null;
        $note = !empty($data['note']) ? $data['note'] : null;
        $template_id = !empty($data['template_id']) ? (int)$data['template_id'] : null;
        $stmt->bind_param('isissi',
            $data['student_id'],
            $data['type'],
            $template_id,
            $data['issued_at'],
            $issued_by,
            $note
        );
        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    /**
     * Обновить справку
     */
    public function update($id, $data) {
        $sql = "UPDATE spravki SET student_id = ?, type = ?, template_id = ?, issued_at = ?, note = ? WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $note = !empty($data['note']) ? $data['note'] : null;
        $template_id = !empty($data['template_id']) ? (int)$data['template_id'] : null;
        $stmt->bind_param('isissi',
            $data['student_id'],
            $data['type'],
            $template_id,
            $data['issued_at'],
            $note,
            $id
        );
        return $stmt->execute();
    }

    /**
     * Удалить справку
     */
    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM spravki WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }
}
