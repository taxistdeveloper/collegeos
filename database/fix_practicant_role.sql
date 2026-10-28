-- ========================================================================
-- Быстрое исправление роли «Практикант» (если она получила id = 0 на сервере)
-- Вставьте этот SQL в phpMyAdmin (вкладка SQL) и нажмите «Вперёд» (Go)
-- ========================================================================

-- 1. Гарантируем автоинкремент первичного ключа
ALTER TABLE `roles` MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT;

-- 2. Если роль practicant имела id = 0, исправляем её на id = 10
UPDATE `roles` SET `id` = 10 WHERE `name` = 'practicant' AND `id` = 0;

-- 3. Если в таблицах users или user_roles есть ссылки на id = 0, обновляем их на 10
UPDATE `users` SET `role_id` = 10 WHERE `role_id` = 0;
UPDATE `user_roles` SET `role_id` = 10 WHERE `role_id` = 0;

-- 4. Если роли practicant еще не было, вставляем её с id = 10
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
