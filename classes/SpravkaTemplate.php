<?php
/**
 * Класс для работы с шаблонами справок
 */
class SpravkaTemplate {
    private $db;

    public function __construct() {
        $this->db = getDB();
    }

    /**
     * Получить все шаблоны
     */
    public function getAll($type = null, $active_only = false) {
        $sql = "SELECT st.*, u.first_name as creator_first, u.last_name as creator_last
                FROM spravka_templates st
                LEFT JOIN users u ON st.created_by = u.id
                WHERE 1=1";
        $params = [];
        $types = '';

        if ($type) {
            $sql .= " AND st.type = ?";
            $params[] = $type;
            $types .= 's';
        }
        if ($active_only) {
            $sql .= " AND st.is_active = 1";
        }

        $sql .= " ORDER BY st.type, st.name";

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
     * Получить шаблон по ID
     */
    public function getById($id) {
        $stmt = $this->db->prepare("SELECT * FROM spravka_templates WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $result = $stmt->get_result();
        return $result->fetch_assoc();
    }

    /**
     * Создать шаблон
     */
    public function create($data) {
        $stmt = $this->db->prepare("INSERT INTO spravka_templates (name, type, content, description, is_active, created_by) VALUES (?, ?, ?, ?, ?, ?)");
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $created_by = !empty($data['created_by']) ? (int)$data['created_by'] : null;
        $description = !empty($data['description']) ? $data['description'] : null;
        $stmt->bind_param('ssssii',
            $data['name'],
            $data['type'],
            $data['content'],
            $description,
            $is_active,
            $created_by
        );
        return $stmt->execute() ? $this->db->getLastInsertId() : false;
    }

    /**
     * Обновить шаблон
     */
    public function update($id, $data) {
        $stmt = $this->db->prepare("UPDATE spravka_templates SET name = ?, type = ?, content = ?, description = ?, is_active = ? WHERE id = ?");
        $is_active = isset($data['is_active']) ? (int)$data['is_active'] : 1;
        $description = !empty($data['description']) ? $data['description'] : null;
        $stmt->bind_param('ssssii',
            $data['name'],
            $data['type'],
            $data['content'],
            $description,
            $is_active,
            $id
        );
        return $stmt->execute();
    }

    /**
     * Удалить шаблон
     */
    public function delete($id) {
        $stmt = $this->db->prepare("DELETE FROM spravka_templates WHERE id = ?");
        $stmt->bind_param('i', $id);
        return $stmt->execute();
    }

    /**
     * Рендер шаблона с данными студента
     */
    public function render($template_id, $student_data, $spravka_data = []) {
        $template = $this->getById($template_id);
        if (!$template || !$template['is_active']) {
            return false;
        }

        $content = $template['content'];
        
        // Плейсхолдеры для замены
        $placeholders = [
            '{FIO}' => trim(($student_data['last_name'] ?? '') . ' ' . ($student_data['first_name'] ?? '') . ' ' . ($student_data['middle_name'] ?? '')),
            '{LAST_NAME}' => $student_data['last_name'] ?? '',
            '{FIRST_NAME}' => $student_data['first_name'] ?? '',
            '{MIDDLE_NAME}' => $student_data['middle_name'] ?? '',
            '{IIN}' => $student_data['iin'] ?? '',
            '{BIRTH_DATE}' => !empty($student_data['birth_date']) ? date('d.m.Y', strtotime($student_data['birth_date'])) : '—',
            '{GENDER}' => $student_data['gender'] ?? '',
            '{GENDER_ROD}' => ($student_data['gender'] ?? '') === 'мужской' ? 'родившемуся' : 'родившейся',
            '{GENDER_ON}' => ($student_data['gender'] ?? '') === 'мужской' ? 'он' : 'она',
            '{GROUP_NAME}' => $student_data['group_name'] ?? '—',
            '{SPECIALTY}' => $student_data['specialty'] ?? '—',
            '{QUALIFICATION}' => $student_data['qualification'] ?? '—',
            '{STUDY_FORM}' => $student_data['study_form'] ?? '—',
            '{LANGUAGE}' => $student_data['language'] ?? '—',
            '{COURSE}' => $student_data['course'] ?? '—',
            '{ISSUED_AT}' => !empty($spravka_data['issued_at']) ? date('d.m.Y', strtotime($spravka_data['issued_at'])) : date('d.m.Y'),
            '{ISSUED_DATE}' => !empty($spravka_data['issued_at']) ? date('d.m.Y', strtotime($spravka_data['issued_at'])) : date('d.m.Y'),
            '{NOTE}' => $spravka_data['note'] ?? '',
            '{APP_NAME}' => APP_NAME,
        ];

        // Замена плейсхолдеров
        foreach ($placeholders as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
    }

    /**
     * Получить типы шаблонов
     */
    public static function getTypes() {
        return [
            'obuchenie' => 'Справка об обучении',
            'mesto_ucheby' => 'Справка с места учёбы',
            'praktika' => 'Справка о прохождении практики',
            'status' => 'Справка о статусе студента',
            'custom' => 'Произвольная справка',
        ];
    }
}
