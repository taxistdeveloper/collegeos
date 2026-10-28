-- ========================================================================
-- Быстрое исправление роли «Практикант» и AUTO_INCREMENT
-- Вставьте этот SQL в phpMyAdmin (вкладка SQL) и нажмите «Вперёд» (Go)
-- ========================================================================

SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. Сначала обновляем ID роли practicant с 0 на 10
UPDATE `roles` SET `id` = 10 WHERE `name` = 'practicant';

-- 2. Если в таблицах пользователей были записи с role_id = 0, привязываем их к роли 10
UPDATE `users` SET `role_id` = 10 WHERE `role_id` = 0;
UPDATE `user_roles` SET `role_id` = 10 WHERE `role_id` = 0;

-- 3. Если вдруг осталась пустая запись с id = 0, удаляем её
DELETE FROM `roles` WHERE `id` = 0;

-- 4. Теперь, когда записей с id = 0 нет, безопасно включаем AUTO_INCREMENT
ALTER TABLE `roles` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `roles` AUTO_INCREMENT = 11;

-- 5. Проверяем, что роль создана (если её вообще не было)
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
