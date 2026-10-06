<?php

/**
 * Общие функции определения статуса студента
 *
 * Автовыпуск по course_end_date срабатывает не раньше
 * GREATEST(course_end_date, 1 июля того же года) — и только если группа
 * уже неактивна (в архиве). Пока группа активна и нет graduation_date,
 * студент остаётся «Выпускная группа» (ещё учится).
 * Явная graduation_date переводит в выпускники сразу.
 */

/** Дата автовыпуска: max(дата окончания курса, 1 июля того же года) */
function getGraduationCutoffDate(string $courseEndDate): ?string
{
    $endTs = strtotime($courseEndDate);
    if ($endTs === false) {
        return null;
    }

    $year = (int)date('Y', $endTs);
    $july1Ts = strtotime(sprintf('%04d-07-01', $year));
    $cutoffTs = max($endTs, $july1Ts);

    return date('Y-m-d', $cutoffTs);
}

/**
 * Группа ещё активна?
 * Если поля нет — считаем активной (не помечать выпускником ошибочно).
 */
function studentGroupIsActive(array $student): bool
{
    foreach (['group_is_active', 'group_active', 'g_is_active'] as $key) {
        if (array_key_exists($key, $student)) {
            return (int)$student[$key] === 1;
        }
    }

    // JOIN groups g ... g.is_active иногда приходит просто как is_active вместе с group_name
    if (array_key_exists('group_name', $student) && array_key_exists('is_active', $student)) {
        return (int)$student['is_active'] === 1;
    }

    return true;
}

function isStudentGraduated(array $student): bool
{
    if (!empty($student['graduation_date'])) {
        return true;
    }

    if (empty($student['course_end_date'])) {
        return false;
    }

    $cutoff = getGraduationCutoffDate((string)$student['course_end_date']);
    if ($cutoff === null || strtotime('today') < strtotime($cutoff)) {
        return false;
    }

    // После 1 июля, но группа ещё активна — ещё учится (выпускная группа)
    if (studentGroupIsActive($student)) {
        return false;
    }

    return true;
}

/**
 * Студент в выпускном цикле → жёлтый статус «Выпускная группа».
 */
function isStudentGraduating(array $student): bool
{
    if (!empty($student['graduation_date']) || isStudentGraduated($student)) {
        return false;
    }

    if (empty($student['course_end_date'])) {
        return false;
    }

    $endTs = strtotime((string)$student['course_end_date']);
    if ($endTs === false) {
        return false;
    }

    $today = strtotime('today');
    $endYear = (int)date('Y', $endTs);
    $graduatingStart = strtotime(sprintf('%04d-09-01', $endYear - 1));

    if ($today >= $graduatingStart) {
        return true;
    }

    // Срок/cutoff уже прошли, группа активна
    $cutoff = getGraduationCutoffDate((string)$student['course_end_date']);
    return $cutoff !== null
        && $today >= strtotime($cutoff)
        && studentGroupIsActive($student);
}

function getStudentStatus(array $student): string
{
    if ((int)($student['academic_leave'] ?? 0) === 1) {
        return 'academic_leave';
    }

    if (isStudentGraduated($student)) {
        return 'graduated';
    }

    if (isStudentGraduating($student)) {
        return 'graduating';
    }

    return 'active';
}

function getStudentStatusLabel(string $status): string
{
    switch ($status) {
        case 'academic_leave':
            return 'Академ. отпуск';
        case 'graduated':
            return 'Выпускник';
        case 'graduating':
            return 'Выпускная группа';
        default:
            return 'Активный';
    }
}

/** CSS-класс бейджа статуса (жёлтый для выпускной группы) */
function getStudentStatusBadgeClass(string $status, string $style = 'bootstrap'): string
{
    if ($style === 'curator') {
        switch ($status) {
            case 'academic_leave':
            case 'graduating':
                return 'curator-badge badge-warning';
            case 'graduated':
                return 'curator-badge badge-success';
            default:
                return 'curator-badge badge-primary';
        }
    }

    if ($style === 'admin') {
        switch ($status) {
            case 'academic_leave':
            case 'graduating':
                return 'badge badge-warning';
            case 'graduated':
                return 'badge badge-success';
            default:
                return 'badge badge-primary';
        }
    }

    switch ($status) {
        case 'academic_leave':
        case 'graduating':
            return 'badge bg-warning text-dark';
        case 'graduated':
            return 'badge bg-success';
        default:
            return 'badge bg-primary';
    }
}

/** Активная группа в выпускном цикле */
function isGraduatingGroup(array $group): bool
{
    if (isset($group['is_active']) && (int)$group['is_active'] === 0) {
        return false;
    }

    $endDate = $group['end_date'] ?? null;
    if (empty($endDate) || $endDate === '0000-00-00') {
        return false;
    }

    $endTs = strtotime((string)$endDate);
    if ($endTs === false) {
        return false;
    }

    $today = strtotime('today');
    $endYear = (int)date('Y', $endTs);
    $graduatingStart = strtotime(sprintf('%04d-09-01', $endYear - 1));

    return $today >= $graduatingStart;
}

/** SQL-фрагмент: дата автовыпуска по course_end_date */
function sqlGraduationCutoffExpr(string $alias = 's'): string
{
    $p = $alias ? $alias . '.' : '';
    return "GREATEST({$p}course_end_date, STR_TO_DATE(CONCAT(YEAR({$p}course_end_date), '-07-01'), '%Y-%m-%d'))";
}

/** SQL: группа студента активна (подзапрос, JOIN не нужен) */
function sqlStudentGroupActiveExpr(string $alias = 's'): string
{
    $p = $alias ? $alias . '.' : '';
    return "COALESCE((SELECT gx.is_active FROM `groups` gx WHERE gx.id = {$p}group_id LIMIT 1), 1)";
}

/**
 * SQL: студент в выпускном цикле (жёлтый статус).
 */
function sqlGraduatingStudentCondition(string $alias = 's'): string
{
    $p = $alias ? $alias . '.' : '';
    $cutoff = sqlGraduationCutoffExpr($alias);
    $groupActive = sqlStudentGroupActiveExpr($alias);

    return "({$p}graduation_date IS NULL
            AND {$p}course_end_date IS NOT NULL
            AND {$groupActive} = 1
            AND (
                CURDATE() >= STR_TO_DATE(CONCAT(YEAR({$p}course_end_date) - 1, '-09-01'), '%Y-%m-%d')
                OR CURDATE() >= {$cutoff}
            ))";
}

/**
 * SQL: студент — выпускник.
 * graduation_date ИЛИ (после cutoff И группа неактивна).
 */
function sqlGraduatedCondition(string $alias = 's'): string
{
    $p = $alias ? $alias . '.' : '';
    $cutoff = sqlGraduationCutoffExpr($alias);
    $groupActive = sqlStudentGroupActiveExpr($alias);

    return "({$p}graduation_date IS NOT NULL
            OR (
                {$p}course_end_date IS NOT NULL
                AND CURDATE() >= {$cutoff}
                AND {$groupActive} = 0
            ))";
}

/** SQL: студент НЕ выпускник */
function sqlNotGraduatedCondition(string $alias = 's'): string
{
    return 'NOT ' . sqlGraduatedCondition($alias);
}
