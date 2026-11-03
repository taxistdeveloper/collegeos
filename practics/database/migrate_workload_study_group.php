<?php

/**
 * Однократная миграция: study_group в workloads (не в teachers).
 * Запуск из корня проекта:
 *   php database/migrate_workload_study_group.php
 * или через MAMP:
 *   /Applications/MAMP/bin/php/php8.2.0/bin/php database/migrate_workload_study_group.php
 */

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database;

function columnExists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$db = Database::connection();
$cfg = require APP_PATH . '/config/database.php';

echo 'Database: ' . ($cfg['dbname'] ?? '') . PHP_EOL;

if (!columnExists($db, 'workloads', 'study_group')) {
    $db->exec(
        "ALTER TABLE workloads
         ADD COLUMN study_group VARCHAR(100) DEFAULT NULL COMMENT 'Учебная группа' AFTER module_name"
    );
    echo "Added workloads.study_group\n";
} else {
    echo "workloads.study_group already exists — nothing to add (#1060 in phpMyAdmin is normal)\n";
}

if (columnExists($db, 'teachers', 'study_group')) {
    $db->exec(
        "UPDATE workloads w
         INNER JOIN teachers t ON t.id = w.teacher_id
         SET w.study_group = t.study_group
         WHERE (w.study_group IS NULL OR TRIM(w.study_group) = '')
           AND t.study_group IS NOT NULL AND TRIM(t.study_group) != ''"
    );
    $db->exec('ALTER TABLE teachers DROP COLUMN study_group');
    echo "Copied study_group from teachers and dropped teachers.study_group\n";
} else {
    echo "teachers.study_group not present — skip copy/drop\n";
}

echo "Done.\n";
