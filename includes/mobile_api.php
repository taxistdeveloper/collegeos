<?php

/**
 * Общие функции для мобильного REST API
 */

if (!defined('MOBILE_API_SECRET')) {
    $mobileSecret = getenv('MOBILE_API_SECRET');
    if (!$mobileSecret && defined('MOBILE_API_SECRET_CONFIG')) {
        $mobileSecret = MOBILE_API_SECRET_CONFIG;
    }
    if (!$mobileSecret) {
        $mobileSecret = 'kvki_mobile_secret_change_in_production_2026';
    }
    define('MOBILE_API_SECRET', $mobileSecret);
}
define('MOBILE_TOKEN_TTL', 86400 * 30); // 30 дней

function mobileApiCors()
{
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept, X-Requested-With');
    header('Access-Control-Max-Age: 86400');
    header('Content-Type: application/json; charset=utf-8');

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(204);
        exit;
    }
}

function mobileJsonResponse($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function mobileJsonSuccess($data = null, $message = null)
{
    $payload = ['success' => true];
    if ($message !== null) {
        $payload['message'] = $message;
    }
    if ($data !== null) {
        $payload['data'] = $data;
    }
    mobileJsonResponse($payload);
}

function mobileJsonError($message, $code = 400, $extra = [])
{
    mobileJsonResponse(array_merge([
        'success' => false,
        'error' => $message,
    ], $extra), $code);
}

function mobileBase64UrlEncode($data)
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function mobileBase64UrlDecode($data)
{
    $remainder = strlen($data) % 4;
    if ($remainder) {
        $data .= str_repeat('=', 4 - $remainder);
    }
    return base64_decode(strtr($data, '-_', '+/'));
}

function mobileCreateToken(array $payload)
{
    $header = mobileBase64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
    $payload['iat'] = time();
    $payload['exp'] = time() + MOBILE_TOKEN_TTL;
    $body = mobileBase64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = mobileBase64UrlEncode(hash_hmac('sha256', "$header.$body", MOBILE_API_SECRET, true));
    return "$header.$body.$signature";
}

function mobileVerifyToken($token)
{
    if (!$token || substr_count($token, '.') !== 2) {
        return null;
    }

    [$header, $body, $signature] = explode('.', $token);
    $expected = mobileBase64UrlEncode(hash_hmac('sha256', "$header.$body", MOBILE_API_SECRET, true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }

    $payload = json_decode(mobileBase64UrlDecode($body), true);
    if (!$payload || empty($payload['exp']) || $payload['exp'] < time()) {
        return null;
    }

    return $payload;
}

function mobileGetBearerToken()
{
    $header = $_SERVER['HTTP_AUTHORIZATION']
        ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
        ?? $_SERVER['Authorization']
        ?? '';

    if (!$header && function_exists('getallheaders')) {
        $headers = getallheaders();
        if (is_array($headers)) {
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
    }

    if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
        return $matches[1];
    }

    // Запасной вариант для MAMP/Apache, если заголовок Authorization не доходит
    if (!empty($_GET['token']) && is_string($_GET['token'])) {
        return $_GET['token'];
    }

    $body = mobileGetJsonBody();
    if (!empty($body['token']) && is_string($body['token'])) {
        return $body['token'];
    }

    return null;
}

function mobileRequireAuth($allowedTypes = null)
{
    $token = mobileGetBearerToken();
    $payload = mobileVerifyToken($token);
    if (!$payload) {
        mobileJsonError('Требуется авторизация', 401);
    }

    if ($allowedTypes !== null) {
        $type = $payload['type'] ?? '';
        if (!in_array($type, (array)$allowedTypes, true)) {
            mobileJsonError('Недостаточно прав', 403);
        }
    }

    if (($payload['type'] ?? '') === 'student') {
        require_once __DIR__ . '/../classes/StudentMobileAuth.php';
        $studentId = (int)($payload['student_id'] ?? 0);
        if ($studentId <= 0) {
            mobileJsonError('Требуется авторизация', 401);
        }

        $mobileAuth = new StudentMobileAuth();
        $authRow = $mobileAuth->getAuthRow($studentId);
        if (!$authRow || empty($authRow['device_id'])) {
            mobileJsonError('Сессия сброшена. Получите новый код у куратора', 401);
        }

        $tokenDevice = (string)($payload['device_id'] ?? '');
        $tokenVer = (int)($payload['ver'] ?? -1);
        if (
            !hash_equals((string)$authRow['device_id'], $tokenDevice)
            || (int)$authRow['token_version'] !== $tokenVer
        ) {
            mobileJsonError('Сессия сброшена. Получите новый код у куратора', 401);
        }
    }

    return $payload;
}

function mobileGetJsonBody()
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        $cached = [];
        return $cached;
    }
    $data = json_decode($raw, true);
    $cached = is_array($data) ? $data : [];
    return $cached;
}

function mobileFormatScheduleItem(array $row, $lessonDate = null)
{
    $pair = (int)($row['pair_number'] ?? 1);
    $day = (int)($row['day_of_week'] ?? 1);
    $shift = (int)($row['shift'] ?? 1) === 2 ? 2 : 1;

    $start = $row['start_time'] ?? null;
    $end = $row['end_time'] ?? null;
    if (!$start || !$end) {
        static $uchebniBell = null;
        if ($uchebniBell === null) {
            $uchebniBell = new Uchebni();
        }
        $times = $uchebniBell->getPairTimes($day, $shift, $pair);
        if ($times) {
            $start = $times[0];
            $end = $times[1];
        } else {
            $fallback = Uchebni::PAIR_TIMES[$pair] ?? ['08:30:00', '09:50:00'];
            $start = $fallback[0];
            $end = $fallback[1];
        }
    }

    $item = [
        'id' => (int)$row['id'],
        'day_of_week' => $day,
        'day_name' => Uchebni::DAY_NAMES[$day] ?? '',
        'shift' => $shift,
        'shift_name' => Uchebni::SHIFT_NAMES[$shift] ?? '',
        'pair_number' => $pair,
        'start_time' => substr((string)$start, 0, 5),
        'end_time' => substr((string)$end, 0, 5),
        'subject' => [
            'id' => (int)$row['subject_id'],
            'name' => $row['subject_name'] ?? '',
            'code' => $row['subject_code'] ?? '',
        ],
        'group' => [
            'id' => (int)$row['group_id'],
            'name' => $row['group_name'] ?? '',
            'code' => $row['group_code'] ?? '',
        ],
        'teacher' => [
            'id' => (int)$row['teacher_id'],
            'name' => $row['teacher_name'] ?? '',
        ],
        'classroom' => [
            'id' => (int)$row['classroom_id'],
            'number' => $row['classroom_number'] ?? '',
            'building' => $row['classroom_building'] ?? '',
        ],
        'lesson_type' => $row['lesson_type'] ?? 'lecture',
        'week_kind' => Uchebni::normalizeWeekKind($row['week_kind'] ?? 'all'),
        'week_kind_label' => Uchebni::WEEK_KIND_NAMES[Uchebni::normalizeWeekKind($row['week_kind'] ?? 'all')] ?? '',
        'week_kind_short' => Uchebni::WEEK_KIND_SHORT[Uchebni::normalizeWeekKind($row['week_kind'] ?? 'all')] ?? '',
    ];

    if (!empty($row['substitute_teacher_id'])) {
        $originalName = $row['teacher_name'] ?? '';
        $originalSubjectName = $row['subject_name'] ?? '';
        $item['substitution'] = [
            'original_teacher_id' => (int)$row['teacher_id'],
            'original_teacher_name' => $originalName,
            'original_subject_id' => (int)($row['subject_id'] ?? 0),
            'original_subject_name' => $originalSubjectName,
            'substitute_teacher_id' => (int)$row['substitute_teacher_id'],
            'substitute_teacher_name' => $row['substitute_teacher_name'] ?? '',
            'substitute_subject_id' => !empty($row['substitute_subject_id']) ? (int)$row['substitute_subject_id'] : null,
            'substitute_subject_name' => $row['substitute_subject_name'] ?? null,
            'reason' => $row['substitution_reason'] ?? '',
        ];
        $item['teacher']['name'] = $row['substitute_teacher_name'] ?? $item['teacher']['name'];
        $item['teacher']['id'] = (int)$row['substitute_teacher_id'];
        $item['teacher']['is_substitute'] = true;
        $item['teacher']['original_name'] = $originalName;
        if (!empty($row['substitute_subject_name'])) {
            $item['subject']['name'] = $row['substitute_subject_name'];
            if (!empty($row['substitute_subject_id'])) {
                $item['subject']['id'] = (int)$row['substitute_subject_id'];
            }
            $item['subject']['is_substitute'] = true;
            $item['subject']['original_name'] = $originalSubjectName;
        }
    }

    if ($lessonDate !== null) {
        $item['lesson_date'] = $lessonDate;
        if (isset($row['lesson_status'])) {
            $item['lesson_status'] = $row['lesson_status'];
        }
        if (isset($row['lesson_status_note'])) {
            $item['lesson_status_note'] = $row['lesson_status_note'];
        }
    }

    return $item;
}
