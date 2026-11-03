-- ========================================================================
-- Быстрое исправление роли «Практикант», таблицы users и AUTO_INCREMENT
-- Вставьте этот SQL в phpMyAdmin (вкладка SQL) и нажмите «Вперёд» (Go)
-- ========================================================================

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';

-- 1. Сначала меняем ID роли practicant на 10
UPDATE `roles` SET `id` = 10 WHERE `name` = 'practicant';

-- 2. Если роли practicant еще не было, вставляем её с id = 10
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
  `id` = 10,
  `description` = VALUES(`description`),
  `permissions` = VALUES(`permissions`),
  `updated_at` = NOW();

-- 3. Если были пользователи или роли с role_id = 0, переводим их на 10
UPDATE `users` SET `role_id` = 10 WHERE `role_id` = 0;
UPDATE `user_roles` SET `role_id` = 10 WHERE `role_id` = 0;

-- 4. Удаляем мусорные записи в roles с id = 0 (если остались)
DELETE FROM `roles` WHERE `id` = 0;

-- 5. Включаем AUTO_INCREMENT на таблице roles
ALTER TABLE `roles` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;
ALTER TABLE `roles` AUTO_INCREMENT = 11;

-- 6. Исправляем таблицу users: переносим запись с id = 0 на новый свободный ID
SET @max_user_id = (SELECT COALESCE(MAX(id), 0) + 1 FROM `users` WHERE `id` > 0);
UPDATE `users` SET `id` = @max_user_id WHERE `id` = 0;
UPDATE `user_roles` SET `user_id` = @max_user_id WHERE `user_id` = 0;
UPDATE `teachers` SET `user_id` = @max_user_id WHERE `user_id` = 0;

-- 7. Включаем AUTO_INCREMENT на таблице users, чтобы новые пользователи получали ID автоматически
ALTER TABLE `users` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;

SET FOREIGN_KEY_CHECKS = 1;
