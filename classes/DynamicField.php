<?php
require_once __DIR__ . '/../config/config.php';

class DynamicField {
    private $db;
    
    public function __construct() {
        $this->db = getDB();
    }
    
    /**
     * Получить все активные поля
     */
    public function getActiveFields() {
        $sql = "SELECT * FROM dynamic_fields WHERE is_active = 1 ORDER BY field_order ASC";
        $result = $this->db->query($sql);
        
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['field_options']) {
                $row['field_options'] = json_decode($row['field_options'], true);
            }
            $fields[] = $row;
        }
        
        return $fields;
    }
    
    /**
     * Получить все поля (включая неактивные)
     */
    public function getAllFields() {
        $sql = "SELECT * FROM dynamic_fields ORDER BY field_order ASC";
        $result = $this->db->query($sql);
        
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            if ($row['field_options']) {
                $row['field_options'] = json_decode($row['field_options'], true);
            }
            $fields[] = $row;
        }
        
        return $fields;
    }
    
    /**
     * Получить поле по ID
     */
    public function getFieldById($id) {
        $sql = "SELECT * FROM dynamic_fields WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($row = $result->fetch_assoc()) {
            if ($row['field_options']) {
                $row['field_options'] = json_decode($row['field_options'], true);
            }
            return $row;
        }
        
        return null;
    }
    
    /**
     * Создать новое поле
     */
    public function createField($data) {
        $sql = "INSERT INTO dynamic_fields (field_name, field_label, field_type, field_options, is_required, field_order, is_active) 
                VALUES (?, ?, ?, ?, ?, ?, ?)";
        
        $stmt = $this->db->prepare($sql);
        
        $field_options = null;
        if (!empty($data['field_options'])) {
            $field_options = json_encode($data['field_options']);
        }
        
        $is_required = isset($data['is_required']) ? 1 : 0;
        $is_active = isset($data['is_active']) ? 1 : 0;
        
        $stmt->bind_param("ssssiii", 
            $data['field_name'], 
            $data['field_label'], 
            $data['field_type'], 
            $field_options,
            $is_required,
            $data['field_order'],
            $is_active
        );
        
        return $stmt->execute();
    }
    
    /**
     * Обновить поле
     */
    public function updateField($id, $data) {
        $sql = "UPDATE dynamic_fields SET 
                field_name = ?, 
                field_label = ?, 
                field_type = ?, 
                field_options = ?, 
                is_required = ?, 
                field_order = ?, 
                is_active = ? 
                WHERE id = ?";
        
        $stmt = $this->db->prepare($sql);
        
        $field_options = null;
        if (!empty($data['field_options'])) {
            $field_options = json_encode($data['field_options']);
        }
        
        $is_required = isset($data['is_required']) ? 1 : 0;
        $is_active = isset($data['is_active']) ? 1 : 0;
        
        $stmt->bind_param("ssssiiii", 
            $data['field_name'], 
            $data['field_label'], 
            $data['field_type'], 
            $field_options,
            $is_required,
            $data['field_order'],
            $is_active,
            $id
        );
        
        return $stmt->execute();
    }
    
    /**
     * Удалить поле
     */
    public function deleteField($id) {
        // Сначала удаляем все значения этого поля у студентов
        $field = $this->getFieldById($id);
        if ($field) {
            $sql = "DELETE FROM student_dynamic_fields WHERE field_name = ?";
            $stmt = $this->db->prepare($sql);
            $stmt->bind_param("s", $field['field_name']);
            $stmt->execute();
        }
        
        // Затем удаляем само поле
        $sql = "DELETE FROM dynamic_fields WHERE id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $id);
        
        return $stmt->execute();
    }
    
    /**
     * Проверить уникальность имени поля
     */
    public function isFieldNameUnique($field_name, $exclude_id = null) {
        $sql = "SELECT COUNT(*) as count FROM dynamic_fields WHERE field_name = ?";
        $params = [$field_name];
        $types = "s";
        
        if ($exclude_id) {
            $sql .= " AND id != ?";
            $params[] = $exclude_id;
            $types .= "i";
        }
        
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        return $row['count'] == 0;
    }
    
    /**
     * Получить значения динамических полей для студента
     */
    public function getStudentDynamicFields($student_id) {
        $sql = "SELECT field_name, field_value FROM student_dynamic_fields WHERE student_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $fields = [];
        while ($row = $result->fetch_assoc()) {
            $fields[$row['field_name']] = $row['field_value'];
        }
        
        return $fields;
    }
    
    /**
     * Сохранить значения динамических полей для студента
     */
    public function saveStudentDynamicFields($student_id, $fields_data) {
        // Проверяем корректность student_id
        if (!$student_id || $student_id <= 0) {
            error_log("DynamicField::saveStudentDynamicFields - Некорректный student_id: " . var_export($student_id, true));
            error_log("DynamicField::saveStudentDynamicFields - Тип student_id: " . gettype($student_id));
            throw new Exception("Некорректный ID студента для сохранения динамических полей: " . var_export($student_id, true));
        }
        
        // Проверяем, что студент существует в базе данных
        $check_sql = "SELECT id FROM students WHERE id = ?";
        $check_stmt = $this->db->prepare($check_sql);
        $check_stmt->bind_param("i", $student_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows === 0) {
            error_log("DynamicField::saveStudentDynamicFields - Студент с ID $student_id не найден в базе данных");
            throw new Exception("Студент с ID $student_id не найден в базе данных");
        }
        
        // Удаляем старые значения
        $sql = "DELETE FROM student_dynamic_fields WHERE student_id = ?";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param("i", $student_id);
        $stmt->execute();
        
        // Добавляем новые значения
        if (!empty($fields_data)) {
            $sql = "INSERT INTO student_dynamic_fields (student_id, field_name, field_value) VALUES (?, ?, ?)";
            $stmt = $this->db->prepare($sql);
            
            foreach ($fields_data as $field_name => $field_value) {
                if (!empty($field_value)) {
                    $stmt->bind_param("iss", $student_id, $field_name, $field_value);
                    if (!$stmt->execute()) {
                        error_log("Ошибка сохранения динамического поля '$field_name': " . $stmt->error);
                        throw new Exception("Ошибка сохранения динамического поля: " . $stmt->error);
                    }
                }
            }
        }
        
        return true;
    }
    
    /**
     * Получить все поля формы (стандартные + динамические)
     */
    public function getAllFormFields() {
        // Стандартные поля формы
        $standard_fields = [
            ['field_name' => 'iin', 'field_label' => 'ИИН', 'field_type' => 'text', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 1],
            ['field_name' => 'first_name', 'field_label' => 'Имя', 'field_type' => 'text', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 2],
            ['field_name' => 'middle_name', 'field_label' => 'Фамилия', 'field_type' => 'text', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 3],
            ['field_name' => 'birth_date', 'field_label' => 'Дата рождения', 'field_type' => 'date', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 4],
            ['field_name' => 'gender', 'field_label' => 'Пол', 'field_type' => 'select', 'field_options' => ['мужской', 'женский'], 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 5],
            ['field_name' => 'nationality', 'field_label' => 'Национальность', 'field_type' => 'text', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 6],
            ['field_name' => 'permanent_address_ru', 'field_label' => 'Постоянный адрес (рус)', 'field_type' => 'textarea', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 7],
            ['field_name' => 'permanent_address_kz', 'field_label' => 'Постоянный адрес (каз)', 'field_type' => 'textarea', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 8],
            ['field_name' => 'temporary_address_ru', 'field_label' => 'Временный адрес (рус)', 'field_type' => 'textarea', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 9],
            ['field_name' => 'temporary_address_kz', 'field_label' => 'Временный адрес (каз)', 'field_type' => 'textarea', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 10],
            ['field_name' => 'arrival_date', 'field_label' => 'Дата прибытия', 'field_type' => 'date', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 11],
            ['field_name' => 'enrollment_order_number', 'field_label' => 'Номер приказа', 'field_type' => 'text', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 12],
            ['field_name' => 'arrival_from', 'field_label' => 'Прибыл из', 'field_type' => 'select', 'field_options' => ['данного района (города, села) данной области', 'другого района (города) данной области', 'другой области', 'другого государства СНГ', 'дальнего зарубежья'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 13],
            ['field_name' => 'phone', 'field_label' => 'Телефон', 'field_type' => 'tel', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 14],
            ['field_name' => 'email', 'field_label' => 'Email', 'field_type' => 'email', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 15],
            ['field_name' => 'education_type', 'field_label' => 'Тип образования', 'field_type' => 'select', 'field_options' => ['основная школа', 'средняя школа', 'организация ТиПО', 'ВУЗ'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 16],
            ['field_name' => 'residence_type', 'field_label' => 'Тип местности', 'field_type' => 'select', 'field_options' => ['городская местность', 'сельская местность'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 17],
            ['field_name' => 'study_duration', 'field_label' => 'Срок обучения', 'field_type' => 'select', 'field_options' => ['1 год', '2 года', '3 года', '4 года', '5 лет'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 18],
            ['field_name' => 'graduation_date', 'field_label' => 'Дата окончания обучения', 'field_type' => 'date', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 19],
            ['field_name' => 'course', 'field_label' => 'Курс', 'field_type' => 'select', 'field_options' => ['1 курс', '2 курс', '3 курс', '4 курс', '5 курс'], 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 20],
            ['field_name' => 'course_start_date', 'field_label' => 'Дата начала курса', 'field_type' => 'date', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 21],
            ['field_name' => 'course_end_date', 'field_label' => 'Дата окончания курса', 'field_type' => 'date', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 22],
            ['field_name' => 'language', 'field_label' => 'Язык обучения', 'field_type' => 'select', 'field_options' => ['казахский', 'русский', 'английский'], 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 23],
            ['field_name' => 'study_form', 'field_label' => 'Форма обучения', 'field_type' => 'select', 'field_options' => ['очная', 'заочная', 'вечерняя'], 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 24],
            ['field_name' => 'specialty', 'field_label' => 'Специальность', 'field_type' => 'text', 'is_required' => true, 'is_active' => true, 'is_standard' => true, 'field_order' => 25],
            ['field_name' => 'academic_leave', 'field_label' => 'Академический отпуск', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 26],
            ['field_name' => 'academic_leave_reason', 'field_label' => 'Причина академического отпуска', 'field_type' => 'select', 'field_options' => ['по состоянию здоровья', 'по беременности и родам', 'призван в ряды Вооруженных Сил РК'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 27],
            ['field_name' => 'academic_leave_order_date', 'field_label' => 'Дата приказа об академическом отпуске', 'field_type' => 'date', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 28],
            ['field_name' => 'quota_category', 'field_label' => 'Категория квоты', 'field_type' => 'select', 'field_options' => ['граждан из числа инвалидов I, II групп', 'инвалидов с детства', 'детей-инвалидов', 'лиц, приравненных по льготам и гарантиям к участникам и инвалидам Великой Отечественной войны', 'граждан из числа сельской молодежи на специальности, определяющие социально-экономическое развитие села', 'лиц казахской национальности, не являющихся гражданами Республики Казахстан', 'детей-сирот и детей, оставшихся без попечения родителей', 'граждан Республики Казахстан из числа молодежи, потерявших или оставшихся без попечения родителей до совершеннолетия', 'граждан Республики Казахстан из числа сельской молодежи, переселяющихся в регионы, определенные Правительством Республики Казахстан', 'детей из семей, в которых воспитывается четыре и более несовершеннолетних детей', 'детей из числа неполных семей, имеющих данный статус не менее трех лет', 'детей из семей, воспитывающих детей-инвалидов с детства, инвалидов первой и второй групп', 'не относится ни к одной из указанных категорий'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 29],
            ['field_name' => 'hot_meal', 'field_label' => 'Горячее питание', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 30],
            ['field_name' => 'free_hot_meal', 'field_label' => 'Бесплатное горячее питание', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 31],
            ['field_name' => 'buffet_meal', 'field_label' => 'Буфетное питание', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 32],
            ['field_name' => 'free_buffet_meal', 'field_label' => 'Бесплатное буфетное питание', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 33],
            ['field_name' => 'youth_committee', 'field_label' => 'Молодежный комитет', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 34],
            ['field_name' => 'student_parliament', 'field_label' => 'Студенческий парламент', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 35],
            ['field_name' => 'practice_type', 'field_label' => 'Тип практики', 'field_type' => 'select', 'field_options' => ['производственная', 'учебная', 'преддипломная', 'не проходит'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 36],
            ['field_name' => 'paid_practice', 'field_label' => 'Оплачиваемая практика', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 37],
            ['field_name' => 'jas_sarbaz', 'field_label' => 'Жас сарбаз', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 38],
            ['field_name' => 'competitions', 'field_label' => 'Участие в конкурсах', 'field_type' => 'textarea', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 39],
            ['field_name' => 'orphan', 'field_label' => 'Сирота', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 40],
            ['field_name' => 'without_parental_care', 'field_label' => 'Без попечения родителей', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 41],
            ['field_name' => 'disability', 'field_label' => 'Инвалидность', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 42],
            ['field_name' => 'primary_violation', 'field_label' => 'Первичное нарушение', 'field_type' => 'select', 'field_options' => ['НОДА', 'ЗПР', 'нарушения речи', 'нарушения общения', 'нарушения поведения', 'сложные нарушения', 'кохлеарный имплант'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 43],
            ['field_name' => 'hearing_impairment', 'field_label' => 'Нарушения слуха', 'field_type' => 'select', 'field_options' => ['слабослышащие', 'неслышащие'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 44],
            ['field_name' => 'vision_impairment', 'field_label' => 'Нарушения зрения', 'field_type' => 'select', 'field_options' => ['слабовидящий', 'незрячий'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 45],
            ['field_name' => 'intellectual_impairment', 'field_label' => 'Нарушения интеллекта', 'field_type' => 'select', 'field_options' => ['легкие', 'умеренные', 'тяжелые', 'глубокие'], 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 46],
            ['field_name' => 'social_assistance', 'field_label' => 'Социальная помощь', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 47],
            ['field_name' => 'large_family', 'field_label' => 'Многодетная семья', 'field_type' => 'checkbox', 'is_required' => false, 'is_active' => true, 'is_standard' => true, 'field_order' => 48]
        ];
        
        // Получаем динамические поля
        $dynamic_fields = $this->getAllFields();
        
        // Добавляем флаг is_standard для динамических полей
        foreach ($dynamic_fields as &$field) {
            $field['is_standard'] = false;
        }
        
        // Объединяем и сортируем по field_order
        $all_fields = array_merge($standard_fields, $dynamic_fields);
        usort($all_fields, function($a, $b) {
            return $a['field_order'] - $b['field_order'];
        });
        
        return $all_fields;
    }
    
    /**
     * Генерировать HTML для поля
     */
    public function generateFieldHTML($field) {
        $required = $field['is_required'] ? 'required' : '';
        $required_class = $field['is_required'] ? 'required-field' : '';
        
        $html = '<div class="col-md-6 mb-3">';
        $html .= '<label for="' . $field['field_name'] . '" class="form-label ' . $required_class . '">' . htmlspecialchars($field['field_label']) . '</label>';
        
        switch ($field['field_type']) {
            case 'text':
            case 'email':
            case 'tel':
            case 'number':
                $html .= '<input type="' . $field['field_type'] . '" class="form-control" id="' . $field['field_name'] . '" name="dynamic_fields[' . $field['field_name'] . ']" ' . $required . '>';
                break;
                
            case 'date':
                $html .= '<input type="date" class="form-control" id="' . $field['field_name'] . '" name="dynamic_fields[' . $field['field_name'] . ']" ' . $required . '>';
                break;
                
            case 'textarea':
                $html .= '<textarea class="form-control" id="' . $field['field_name'] . '" name="dynamic_fields[' . $field['field_name'] . ']" rows="3" ' . $required . '></textarea>';
                break;
                
            case 'select':
                $html .= '<select class="form-select" id="' . $field['field_name'] . '" name="dynamic_fields[' . $field['field_name'] . ']" ' . $required . '>';
                $html .= '<option value="">Выберите...</option>';
                if (!empty($field['field_options'])) {
                    foreach ($field['field_options'] as $option) {
                        $html .= '<option value="' . htmlspecialchars($option) . '">' . htmlspecialchars($option) . '</option>';
                    }
                }
                $html .= '</select>';
                break;
        }
        
        $html .= '</div>';
        
        return $html;
    }
}
?>
