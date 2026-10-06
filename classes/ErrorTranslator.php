<?php

class ErrorTranslator {
    
    // Словарь переводов названий полей с указанием секции и ID
    private static $field_translations = [
        // Основная информация
        'iin' => ['label' => 'ИИН', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'first_name' => ['label' => 'Имя', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'middle_name' => ['label' => 'Отчество', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'birth_date' => ['label' => 'Дата рождения', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'gender' => ['label' => 'Пол', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'nationality' => ['label' => 'Национальность', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'phone' => ['label' => 'Телефон', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        'email' => ['label' => 'Email', 'section' => 'Основная информация', 'section_id' => 'collapseMain'],
        
        // Адреса
        'permanent_address_ru' => ['label' => 'Постоянный адрес (русский)', 'section' => 'Адреса', 'section_id' => 'collapseAddress'],
        'permanent_address_kz' => ['label' => 'Постоянный адрес (казахский)', 'section' => 'Адреса', 'section_id' => 'collapseAddress'],
        'temporary_address_ru' => ['label' => 'Временный адрес (русский)', 'section' => 'Адреса', 'section_id' => 'collapseAddress'],
        'temporary_address_kz' => ['label' => 'Временный адрес (казахский)', 'section' => 'Адреса', 'section_id' => 'collapseAddress'],
        
        // Информация о зачислении
        'arrival_date' => ['label' => 'Дата прибытия', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'enrollment_order_number' => ['label' => 'Номер приказа', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'enrollment_type' => ['label' => 'Тип зачисления', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'arrival_from' => ['label' => 'Прибыл из', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'education_type' => ['label' => 'Тип образования', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'residence_type' => ['label' => 'Тип проживания', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'study_duration' => ['label' => 'Продолжительность обучения', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'graduation_date' => ['label' => 'Дата окончания', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'course' => ['label' => 'Курс', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'course_start_date' => ['label' => 'Дата начала курса', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'course_end_date' => ['label' => 'Дата окончания курса', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'language' => ['label' => 'Язык обучения', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'study_form' => ['label' => 'Форма обучения', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'specialty' => ['label' => 'Специальность', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        'group_code' => ['label' => 'Код группы', 'section' => 'Информация о зачислении', 'section_id' => 'collapseEnrollment'],
        
        // Социальные категории
        'quota_type' => ['label' => 'Тип квоты', 'section' => 'Социальные категории', 'section_id' => 'collapseQuota'],
        'social_category' => ['label' => 'Социальная категория', 'section' => 'Социальные категории', 'section_id' => 'collapseQuota'],
        
        // Питание
        'meal_type' => ['label' => 'Тип питания', 'section' => 'Питание', 'section_id' => 'collapseMeals'],
        'meal_status' => ['label' => 'Статус питания', 'section' => 'Питание', 'section_id' => 'collapseMeals'],
        
        // Внеучебная деятельность
        'activity_type' => ['label' => 'Тип деятельности', 'section' => 'Внеучебная деятельность', 'section_id' => 'collapseActivity'],
        'activity_status' => ['label' => 'Статус деятельности', 'section' => 'Внеучебная деятельность', 'section_id' => 'collapseActivity'],
        
        // Академический отпуск
        'academic_leave' => ['label' => 'Академический отпуск', 'section' => 'Академический отпуск', 'section_id' => 'collapseAcademic'],
        'leave_reason' => ['label' => 'Причина отпуска', 'section' => 'Академический отпуск', 'section_id' => 'collapseAcademic'],
        
        // Нарушения
        'disability' => ['label' => 'Нарушения', 'section' => 'Нарушения', 'section_id' => 'collapseDisability'],
        'disability_type' => ['label' => 'Тип нарушения', 'section' => 'Нарушения', 'section_id' => 'collapseDisability'],
        
        // Дополнительная информация
        'additional_info' => ['label' => 'Дополнительная информация', 'section' => 'Дополнительная информация', 'section_id' => 'collapseDynamic'],
        'notes' => ['label' => 'Примечания', 'section' => 'Дополнительная информация', 'section_id' => 'collapseDynamic']
    ];
    
    /**
     * Переводит ошибку базы данных на русский язык
     * @param string $error_message Оригинальное сообщение об ошибке
     * @return string Переведенное сообщение об ошибке
     */
    public static function translate($error_message) {
        // Обработка ошибки "Column cannot be null"
        if (preg_match("/Column '([^']+)' cannot be null/i", $error_message, $matches)) {
            $field_name = $matches[1];
            $field_info = self::$field_translations[$field_name] ?? ['label' => $field_name, 'section' => 'форме', 'section_id' => ''];
            $field_label = $field_info['label'];
            $field_section = $field_info['section'];
            $section_id = $field_info['section_id'];
            
            // Сохраняем ID секции для JavaScript
            if (!empty($section_id)) {
                $GLOBALS['error_section_id'] = $section_id;
            }
            
            return "Ошибка базы данных: столбец «{$field_label}» в секции «{$field_section}» не может быть нулевым.";
        }
        
        // Обработка ошибки "Data truncated"
        if (preg_match("/Data truncated for column '([^']+)' at row \d+/i", $error_message, $matches)) {
            $field_name = $matches[1];
            $field_info = self::$field_translations[$field_name] ?? ['label' => $field_name, 'section' => 'форме', 'section_id' => ''];
            $field_label = $field_info['label'];
            $field_section = $field_info['section'];
            $section_id = $field_info['section_id'];
            
            // Сохраняем ID секции для JavaScript
            if (!empty($section_id)) {
                $GLOBALS['error_section_id'] = $section_id;
            }
            
            return "Ошибка базы данных: поле «{$field_label}» в секции «{$field_section}» не заполнено, заполните.";
        }
        
        // Обработка других ошибок
        if (strpos($error_message, 'Incorrect date value') !== false) {
            return 'Ошибка в дате: некорректное значение даты.';
        } elseif (strpos($error_message, 'Duplicate entry') !== false) {
            return 'Дублирование данных: запись с такими данными уже существует.';
        } elseif (strpos($error_message, 'Data too long') !== false) {
            return 'Слишком длинные данные: превышена максимальная длина поля.';
        } elseif (strpos($error_message, 'Cannot add or update a child row') !== false) {
            return 'Ошибка связи с базой данных: нарушена целостность данных.';
        } elseif (strpos($error_message, 'Out of range value') !== false) {
            return 'Некорректное значение: значение выходит за допустимые пределы.';
        }
        
        return 'Ошибка базы данных: ' . $error_message;
    }
    
    /**
     * Добавляет новые переводы полей
     * @param array $translations Массив переводов
     */
    public static function addTranslations($translations) {
        self::$field_translations = array_merge(self::$field_translations, $translations);
    }
    
    /**
     * Получает информацию о поле
     * @param string $field_name Название поля
     * @return array Информация о поле
     */
    public static function getFieldInfo($field_name) {
        return self::$field_translations[$field_name] ?? ['label' => $field_name, 'section' => 'форме', 'section_id' => ''];
    }
}
