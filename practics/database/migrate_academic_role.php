<?php

/**
 * Миграция: роль academic (учебный процесс).
 * Запуск: php database/migrate_academic_role.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

$db = Database::connection();
$cfg = require APP_PATH . '/config/database.php';

echo 'Database: ' . ($cfg['dbname'] ?? '') . PHP_EOL;

$db->exec(
    "ALTER TABLE users
     MODIFY COLUMN role ENUM('admin', 'academic', 'teacher') NOT NULL DEFAULT 'teacher'"
);
echo "Updated users.role ENUM\n";

$stmt = $db->prepare('SELECT COUNT(*) FROM users WHERE login = ?');
$stmt->execute(['uchebny']);
if ((int) $stmt->fetchColumn() === 0) {
    $db->prepare(
        "INSERT INTO users (fullname, login, password, role) VALUES (?, ?, ?, ?)"
    )->execute([
        'Отдел учебного процесса',
        'uchebny',
        '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
        'academic',
    ]);
    echo "Created demo user uchebny (password: password)\n";
} else {
    echo "Demo user uchebny already exists\n";
}

echo "Done.\n";
