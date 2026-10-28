-- ========================================================================
-- Миграция базы данных для модуля «Практика» и роли «Практикант»
-- Платформа: Цифровой колледж (CollegeOS)
-- ========================================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. Исправление ID роли practicant, если она создалась с id = 0
UPDATE `roles` SET `id` = 10 WHERE `name` = 'practicant';
UPDATE `users` SET `role_id` = 10 WHERE `role_id` = 0;
UPDATE `user_roles` SET `role_id` = 10 WHERE `role_id` = 0;
DELETE FROM `roles` WHERE `id` = 0;

-- 2. Гарантия AUTO_INCREMENT на таблице roles
ALTER TABLE `roles` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `roles` AUTO_INCREMENT = 11;

-- 3. Добавление/обновление роли «Практикант»
INSERT INTO `roles` (`id`, `name`, `description`, `permissions`, `created_at`, `updated_at`)
VALUES (
  10,
  'practicant',
  'Практикант — управление практикой, нагрузки и графики',
  '{"view_groups": true, "view_reports": true, "view_practics": true, "view_teachers": true, "export_reports": true, "view_workloads": true, "manage_practics": true, "manage_workloads": true, "view_submissions": true, "manage_submissions": true}',
  NOW(),
  NOW()
)
ON DUPLICATE KEY UPDATE
  `description` = VALUES(`description`),
  `permissions` = VALUES(`permissions`),
  `updated_at` = NOW();

-- 4. Таблица преподавателей практики (связка с users)
CREATE TABLE IF NOT EXISTS `teachers` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `user_id` INT(11) NOT NULL UNIQUE,
  `department` VARCHAR(255) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_teacher_user` (`user_id`),
  CONSTRAINT `fk_teachers_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Таблица нагрузок практики (модули, группы, часы, дедлайн, статус, json графика)
CREATE TABLE IF NOT EXISTS `workloads` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `teacher_id` INT UNSIGNED NOT NULL,
  `module_name` VARCHAR(255) NOT NULL,
  `study_group` VARCHAR(100) DEFAULT NULL,
  `practice_hours` INT UNSIGNED NOT NULL DEFAULT 0,
  `deadline` DATE NOT NULL,
  `status` ENUM('assigned', 'in_progress', 'submitted', 'overdue') NOT NULL DEFAULT 'assigned',
  `submitted_file_path` VARCHAR(500) DEFAULT NULL,
  `submitted_filename` VARCHAR(255) DEFAULT NULL,
  `submitted_at` TIMESTAMP NULL DEFAULT NULL,
  `comment` TEXT DEFAULT NULL,
  `document_path` VARCHAR(500) DEFAULT NULL,
  `form_data` JSON DEFAULT NULL,
  `progress_percent` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `assigned_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_workload_teacher` (`teacher_id`),
  INDEX `idx_workload_status` (`status`),
  INDEX `idx_workload_deadline` (`deadline`),
  CONSTRAINT `fk_workload_assigned` FOREIGN KEY (`assigned_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_workload_teacher` FOREIGN KEY (`teacher_id`) REFERENCES `teachers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. Таблица версий графика (история изменений)
CREATE TABLE IF NOT EXISTS `workload_versions` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `workload_id` INT UNSIGNED NOT NULL,
  `form_data` JSON DEFAULT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `original_filename` VARCHAR(255) DEFAULT NULL,
  `version` INT UNSIGNED NOT NULL DEFAULT 1,
  `action` ENUM('upload', 'submit', 'resubmit') NOT NULL DEFAULT 'upload',
  `created_by` INT(11) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX `idx_wv_workload` (`workload_id`),
  CONSTRAINT `fk_wv_workload` FOREIGN KEY (`workload_id`) REFERENCES `workloads` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_wv_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. Заполнение таблицы teachers существующими преподавателями из uchebni_teachers
INSERT IGNORE INTO `teachers` (`user_id`, `department`)
SELECT `user_id`, COALESCE(`specialization`, 'Преподаватель колледжа')
FROM `uchebni_teachers`
WHERE `user_id` IS NOT NULL AND `user_id` > 0;
