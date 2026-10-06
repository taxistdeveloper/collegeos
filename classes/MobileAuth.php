<?php

require_once __DIR__ . '/User.php';
require_once __DIR__ . '/Uchebni.php';
require_once __DIR__ . '/StudentMobileAuth.php';
require_once __DIR__ . '/../includes/mobile_api.php';
require_once __DIR__ . '/../includes/student_status.php';

class MobileAuth
{
    private $db;

    public function __construct()
    {
        $this->db = getDB();
    }

    public function loginTeacher($login, $password)
    {
        $login = trim($login);
        if ($login === '' || $password === '') {
            return ['error' => 'Введите логин и пароль'];
        }

        $userModel = new User();
        $user = $userModel->getUserByLogin($login);

        if (!$user || !password_verify($password, $user['password'])) {
            // Проверяем, не заблокирован ли пользователь
            $stmt = $this->db->prepare("SELECT u.is_active, r.name as role_name FROM users u
                LEFT JOIN roles r ON u.role_id = r.id
                WHERE u.login = ? OR u.email = ? LIMIT 1");
            $stmt->bind_param('ss', $login, $login);
            $stmt->execute();
            $check = $stmt->get_result()->fetch_assoc();
            if ($check && !(int)$check['is_active']) {
                return ['error' => 'Аккаунт заблокирован. Обратитесь к администратору'];
            }
            return ['error' => 'Неверный логин или пароль'];
        }

        $roleNames = $user['role_names'] ?? [];
        if (empty($roleNames) && !empty($user['role_name'])) {
            $roleNames = [$user['role_name']];
        }
        $role = $user['role_name'] ?? '';
        $canTeacherApp = in_array('teacher', $roleNames, true) || in_array('methodist', $roleNames, true);
        if (!$canTeacherApp) {
            return ['error' => 'Мобильное приложение доступно только преподавателям. Ваша роль: ' . ($role ?: 'не назначена')];
        }
        if (in_array('teacher', $roleNames, true)) {
            $role = 'teacher';
        } elseif (in_array('methodist', $roleNames, true)) {
            $role = 'methodist';
        }

        $uchebni = new Uchebni();
        $teacher = $this->resolveTeacherProfile($user, $uchebni);

        if (!$teacher && $role === 'teacher') {
            return ['error' => 'Не удалось создать профиль преподавателя. Обратитесь в учебную часть'];
        }

        $permissions = json_decode($user['permissions'] ?? '{}', true) ?: [];

        $token = mobileCreateToken([
            'type' => 'teacher',
            'user_id' => (int)$user['id'],
            'teacher_id' => $teacher ? (int)$teacher['id'] : null,
            'role' => $role,
            'name' => trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . ($user['middle_name'] ?? '')),
        ]);

        return [
            'token' => $token,
            'user' => [
                'type' => 'teacher',
                'id' => (int)$user['id'],
                'teacher_id' => $teacher ? (int)$teacher['id'] : null,
                'name' => trim($user['last_name'] . ' ' . $user['first_name'] . ' ' . ($user['middle_name'] ?? '')),
                'role' => $role,
                'login' => $user['login'],
                'permissions' => [
                    'view_journal' => !empty($permissions['edit_journal']) || !empty($permissions['view_journal']) || !empty($permissions['all']),
                    'edit_journal' => !empty($permissions['edit_journal']) || !empty($permissions['all']),
                ],
            ],
        ];
    }

    /**
     * Найти или создать профиль преподавателя для пользователя портала
     */
    private function resolveTeacherProfile(array $user, Uchebni $uchebni)
    {
        $userId = (int)$user['id'];
        $teacher = $uchebni->getTeacherByUserId($userId);
        if ($teacher) {
            return $teacher;
        }

        // Привязать существующую карточку преподавателя по ФИО
        $stmt = $this->db->prepare("SELECT * FROM uchebni_teachers
            WHERE is_active = 1 AND (user_id IS NULL OR user_id = 0)
            AND last_name = ? AND first_name = ?
            LIMIT 2");
        $stmt->bind_param('ss', $user['last_name'], $user['first_name']);
        $stmt->execute();
        $candidates = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

        if (count($candidates) === 1) {
            $teacherId = (int)$candidates[0]['id'];
            $upd = $this->db->prepare("UPDATE uchebni_teachers SET user_id = ? WHERE id = ?");
            $upd->bind_param('ii', $userId, $teacherId);
            $upd->execute();
            return $uchebni->getTeacherById($teacherId);
        }

        // Создать профиль автоматически из данных пользователя
        $teacherId = $uchebni->addTeacher([
            'user_id' => $userId,
            'last_name' => $user['last_name'],
            'first_name' => $user['first_name'],
            'middle_name' => $user['middle_name'] ?? '',
            'phone' => $user['phone'] ?? '',
            'email' => $user['email'] ?? '',
            'max_hours_per_week' => 18,
            'specialization' => '',
            'subject_ids' => [],
        ]);

        return $teacherId ? $uchebni->getTeacherById($teacherId) : null;
    }

    /**
     * Вход студента по одноразовому коду куратора + привязка устройства
     */
    public function loginStudentByInvite($code, $deviceId)
    {
        $mobileAuth = new StudentMobileAuth();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $codeNorm = strtoupper(preg_replace('/[^A-Z0-9]/i', '', (string)$code));
        $deviceId = trim((string)$deviceId);

        if ($mobileAuth->isRateLimited($ip, $codeNorm)) {
            return ['error' => 'Слишком много попыток. Попробуйте позже'];
        }

        if ($deviceId === '' || strlen($deviceId) < 8 || strlen($deviceId) > 64) {
            $mobileAuth->recordAttempt($ip, $codeNorm);
            return ['error' => 'Неверный или просроченный код'];
        }

        if (strlen($codeNorm) !== StudentMobileAuth::CODE_LENGTH) {
            $mobileAuth->recordAttempt($ip, $codeNorm);
            return ['error' => 'Неверный или просроченный код'];
        }

        $invite = $mobileAuth->findValidInviteByCode($codeNorm);
        if (!$invite) {
            $mobileAuth->recordAttempt($ip, $codeNorm);
            return ['error' => 'Неверный или просроченный код'];
        }

        $studentId = (int)$invite['student_id'];
        $notGraduated = sqlNotGraduatedCondition('s');
        $sql = "SELECT s.*, g.name as group_name, g.code as group_code
                FROM students s
                LEFT JOIN `groups` g ON s.group_id = g.id
                WHERE s.id = ? AND $notGraduated
                LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bind_param('i', $studentId);
        $stmt->execute();
        $student = $stmt->get_result()->fetch_assoc();

        if (!$student || empty($student['group_id'])) {
            $mobileAuth->recordAttempt($ip, $codeNorm);
            return ['error' => 'Неверный или просроченный код'];
        }

        $authRow = $mobileAuth->ensureAuthRow($studentId);
        $boundDevice = $authRow['device_id'] ?? null;

        if (!empty($boundDevice) && !hash_equals((string)$boundDevice, $deviceId)) {
            $mobileAuth->recordAttempt($ip, $codeNorm);
            return [
                'error' => 'Вход разрешён только с привязанного устройства. Обратитесь к куратору для сброса',
            ];
        }

        if (empty($boundDevice)) {
            $authRow = $mobileAuth->bindDevice($studentId, $deviceId);
        }

        $mobileAuth->markInviteUsed((int)$invite['id']);

        $name = trim($student['last_name'] . ' ' . $student['first_name'] . ' ' . ($student['middle_name'] ?? ''));
        $tokenVersion = (int)($authRow['token_version'] ?? 0);
        $finalDeviceId = !empty($authRow['device_id']) ? $authRow['device_id'] : $deviceId;

        $token = mobileCreateToken([
            'type' => 'student',
            'student_id' => $studentId,
            'group_id' => (int)$student['group_id'],
            'name' => $name,
            'device_id' => $finalDeviceId,
            'ver' => $tokenVersion,
        ]);

        return [
            'token' => $token,
            'user' => [
                'type' => 'student',
                'id' => $studentId,
                'group_id' => (int)$student['group_id'],
                'group_name' => $student['group_name'] ?? '',
                'group_code' => $student['group_code'] ?? '',
                'name' => $name,
            ],
        ];
    }
}
