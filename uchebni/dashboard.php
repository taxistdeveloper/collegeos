<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist', 'teacher']);

$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_uchebni');

$uchebni = new Uchebni();
$period = $uchebni->getCurrentPeriod();
$current_user = getCurrentUser();
$role = $current_user['role'] ?? '';
$isTeacher = ($role === 'teacher');

$page_title = 'Главная';
$page_subtitle = ($period ? $period['name'] . ' · ' : '') . date('d.m.Y');

$teacher = null;
$hoursWeek = 0;
$maxHours = 0;
$disciplines = [];

if ($isTeacher) {
    $teacher = $uchebni->getTeacherByUserId((int)$current_user['id']);
    if ($teacher) {
        $teacherId = (int)$teacher['id'];
        $periodId = $period ? (int)$period['id'] : 0;
        $hoursWeek = $periodId ? $uchebni->getTeacherWorkload($teacherId, $periodId) : 0;
        $maxHours = Uchebni::effectiveTeacherMaxHours($teacher['max_hours_per_week'] ?? Uchebni::MAX_WEEKLY_HOURS);
        $disciplines = $uchebni->getTeacherSubjectsList($teacherId);
    }
} else {
    $stats = $uchebni->getStats();
}

require_once 'includes/header.php';
?>

<?php if ($isTeacher): ?>
<div class="curator-stats-grid">
    <div class="curator-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Часов в неделю</div>
        <div class="stat-number"><?php echo (int)$hoursWeek; ?><?php if ($maxHours): ?><span class="fs-6 fw-normal opacity-75"> / <?php echo (int)$maxHours; ?></span><?php endif; ?></div>
        <div class="stat-icon"><i class="bi bi-clock-history"></i></div>
    </div>
    <div class="curator-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Мои дисциплины</div>
        <div class="stat-number"><?php echo count($disciplines); ?></div>
        <div class="stat-icon"><i class="bi bi-journal-text"></i></div>
    </div>
    <div class="curator-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Период</div>
        <div class="stat-number" style="font-size:1.15rem;line-height:1.3;"><?php echo $period ? htmlspecialchars($period['name']) : '—'; ?></div>
        <div class="stat-icon"><i class="bi bi-calendar3"></i></div>
    </div>
</div>

<div class="card uchebni-welcome-card mb-0">
    <div class="card-body p-4 p-md-5">
        <h2 class="h4 mb-2"><i class="bi bi-person-badge me-2"></i>Преподаватель</h2>
        <p class="mb-4 opacity-75">
            <?php if ($teacher): ?>
                <?php echo htmlspecialchars(trim(($teacher['last_name'] ?? '') . ' ' . ($teacher['first_name'] ?? '') . ' ' . ($teacher['middle_name'] ?? ''))); ?> —
                ваше расписание, нагрузка, ведомость и учёт часов.
            <?php else: ?>
                Ваше расписание, нагрузка, ведомость и учёт часов.
                Профиль преподавателя ещё не привязан — обратитесь в учебную часть.
            <?php endif; ?>
        </p>
        <div class="d-flex flex-wrap gap-2">
            <a href="grades.php" class="btn btn-light"><i class="bi bi-journal-check me-1"></i>Оценки</a>
            <a href="schedule.php" class="btn btn-light"><i class="bi bi-calendar-week me-1"></i>Расписание</a>
            <a href="workload.php" class="btn btn-light"><i class="bi bi-calendar3 me-1"></i>Нагрузка</a>
            <a href="hours.php" class="btn btn-light"><i class="bi bi-clock-history me-1"></i>Часы</a>
            <a href="vedomost.php" class="btn btn-outline-light"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Ведомость</a>
        </div>
    </div>
</div>

<?php else: ?>
<div class="curator-stats-grid">
    <div class="curator-stat-card stat-primary">
        <div class="stat-label"><span class="stat-dot"></span>Преподавателей</div>
        <div class="stat-number"><?php echo $stats['teachers']; ?></div>
        <div class="stat-icon"><i class="bi bi-person-workspace"></i></div>
    </div>
    <div class="curator-stat-card stat-success">
        <div class="stat-label"><span class="stat-dot"></span>Дисциплин</div>
        <div class="stat-number"><?php echo $stats['subjects']; ?></div>
        <div class="stat-icon"><i class="bi bi-journal-text"></i></div>
    </div>
    <div class="curator-stat-card stat-info">
        <div class="stat-label"><span class="stat-dot"></span>Аудиторий</div>
        <div class="stat-number"><?php echo $stats['classrooms']; ?></div>
        <div class="stat-icon"><i class="bi bi-door-open"></i></div>
    </div>
</div>

<div class="card uchebni-welcome-card mb-0">
    <div class="card-body p-4 p-md-5">
        <h2 class="h4 mb-2"><i class="bi bi-mortarboard-fill me-2"></i>Учебная часть</h2>
        <p class="mb-4 opacity-75">
            Преподаватели, содержание/секции, нагрузка, занятость, учёт часов, сводная ведомость и аналитика.
        </p>
        <div class="d-flex flex-wrap gap-2">
            <?php if (hasPermission('manage_teachers')): ?>
                <a href="teachers.php" class="btn btn-light"><i class="bi bi-person-plus me-1"></i>Преподаватели</a>
            <?php endif; ?>
            <?php if (hasPermission('manage_subjects')): ?>
                <a href="content.php" class="btn btn-light"><i class="bi bi-list-columns me-1"></i>Содержание</a>
            <?php endif; ?>
            <?php if (hasPermission('manage_schedule') || hasPermission('view_schedule') || hasPermission('view_workload')): ?>
                <a href="workload.php" class="btn btn-light"><i class="bi bi-calendar3 me-1"></i>Нагрузка</a>
                <a href="hours.php" class="btn btn-light"><i class="bi bi-clock-history me-1"></i>Часы</a>
            <?php endif; ?>
            <?php if (hasPermission('manage_schedule')): ?>
                <a href="occupancy.php" class="btn btn-light"><i class="bi bi-grid-3x3-gap me-1"></i>Занятость</a>
            <?php endif; ?>
            <?php if (hasPermission('view_journal')): ?>
                <a href="journal.php" class="btn btn-light"><i class="bi bi-table me-1"></i>Сводная ведомость</a>
            <?php endif; ?>
            <?php if (hasPermission('view_uchebni_analytics')): ?>
                <a href="analytics.php" class="btn btn-outline-light"><i class="bi bi-bar-chart me-1"></i>Аналитика</a>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
