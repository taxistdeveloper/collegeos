<?php
require_once '../config/config.php';
require_once '../classes/User.php';
require_once '../classes/Group.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';
require_once '../includes/student_status.php';

// Проверка авторизации
checkAuth();

// Проверка прав доступа
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('view_own_students');

$user = new User();
$group = new Group();
$current_user = getCurrentUser();

// Получение групп куратора
$curator_groups = $group->getGroupsByCurator($current_user['id']);
$total_groups = count($curator_groups);

$nakyl_sozder = [
	['kk' => 'Еңбек түбі — береке.', 'ru' => 'В основе труда — достаток.'],
	['kk' => 'Білімді мыңды жығады.', 'ru' => 'Знающий одолеет тысячу.'],
	['kk' => 'Отан отбасынан басталады.', 'ru' => 'Родина начинается с семьи.'],
	['kk' => 'Жақсы сөз — жарым ырыс.', 'ru' => 'Доброе слово — половина счастья.'],
	['kk' => 'Сабыр түбі — сары алтын.', 'ru' => 'Терпение в итоге — чистое золото.'],
	['kk' => 'Бірлік бар жерде — тірлік бар.', 'ru' => 'Где есть единство, там есть жизнь.'],
	['kk' => 'Ақыл — тозбас тон, білім — таусылмас кен.', 'ru' => 'Ум не износится, знание не иссякнет.'],
	['kk' => 'Ұяда не көрсе, ұшқанда соны іледі.', 'ru' => 'Что видит в гнезде, то и несёт в полёт.'],
	['kk' => 'Тәрбие — тал бесіктен.', 'ru' => 'Воспитание начинается с колыбели.'],
	['kk' => 'Жігітке жеті өнер де аз.', 'ru' => 'Джигиту и семи ремёсел мало.'],
	['kk' => 'Оқу — инемен құдық қазғандай.', 'ru' => 'Учёба похожа на колодец, выкопанный иглой.'],
	['kk' => 'Елдің ертеңі — жастар.', 'ru' => 'Будущее народа — молодёжь.'],
	['kk' => 'Адал еңбек абырой әкеледі.', 'ru' => 'Честный труд приносит честь.'],
	['kk' => 'Батыр бір рет өледі, қорқақ мың өледі.', 'ru' => 'Храбрец умирает один раз, трус — тысячу.'],
	['kk' => 'Көп түкірсе — көл.', 'ru' => 'Много малых усилий складываются в большое дело.'],
];
$nakyl_index = random_int(0, count($nakyl_sozder) - 1);

$db = getDB();
$student_stats = [
	'total' => 0,
	'active' => 0,
	'academic_leave' => 0,
	'graduated' => 0
];
$birthdays_today = [];
$birthdays_soon = [];

if (!empty($curator_groups)) {
	$group_ids = array_column($curator_groups, 'id');
	$placeholders = str_repeat('?,', count($group_ids) - 1) . '?';

	$not_graduated = sqlNotGraduatedCondition('');
	$graduated = sqlGraduatedCondition('');
	$sql = "SELECT 
			COUNT(*) as total,
			SUM(CASE WHEN academic_leave = 0 AND $not_graduated THEN 1 ELSE 0 END) as active,
			SUM(CASE WHEN academic_leave = 1 THEN 1 ELSE 0 END) as academic_leave,
			SUM(CASE WHEN $graduated THEN 1 ELSE 0 END) as graduated
		FROM students 
		WHERE group_id IN ($placeholders)";

	$stmt = $db->prepare($sql);
	if ($stmt) {
		$stmt->bind_param(str_repeat('i', count($group_ids)), ...$group_ids);
		$stmt->execute();
		$result = $stmt->get_result();
		$student_stats = $result->fetch_assoc();
	}

	$not_graduated_s = sqlNotGraduatedCondition('s');
	$birthday_sql = "SELECT s.id, s.first_name, s.last_name, s.middle_name, s.birth_date, g.name as group_name
		FROM students s
		LEFT JOIN `groups` g ON s.group_id = g.id
		WHERE s.group_id IN ($placeholders)
		AND s.birth_date IS NOT NULL
		AND s.birth_date != '0000-00-00'
		AND $not_graduated_s";
	$birthday_stmt = $db->prepare($birthday_sql);
	if ($birthday_stmt) {
		$birthday_stmt->bind_param(str_repeat('i', count($group_ids)), ...$group_ids);
		$birthday_stmt->execute();
		$birthday_rows = $birthday_stmt->get_result()->fetch_all(MYSQLI_ASSOC);

		$today_md = date('m-d');
		$today = new DateTimeImmutable('today');
		foreach ($birthday_rows as $row) {
			$birth = DateTimeImmutable::createFromFormat('Y-m-d', substr((string)$row['birth_date'], 0, 10));
			if (!$birth) {
				continue;
			}
			$md = $birth->format('m-d');
			$full_name = trim(($row['last_name'] ?? '') . ' ' . ($row['first_name'] ?? '') . ' ' . ($row['middle_name'] ?? ''));
			$item = [
				'id' => (int)$row['id'],
				'name' => $full_name,
				'group' => $row['group_name'] ?? '',
				'birth_date' => $birth->format('d.m'),
				'age' => (int)$today->format('Y') - (int)$birth->format('Y'),
			];
			if ($md === $today_md) {
				$birthdays_today[] = $item;
				continue;
			}
			$next = $birth->setDate((int)$today->format('Y'), (int)$birth->format('m'), (int)$birth->format('d'));
			if ($next < $today) {
				$next = $next->modify('+1 year');
			}
			$days = (int)$today->diff($next)->format('%a');
			if ($days > 0 && $days <= 7) {
				$item['in_days'] = $days;
				$item['age'] = (int)$next->format('Y') - (int)$birth->format('Y');
				$birthdays_soon[] = $item;
			}
		}
		usort($birthdays_soon, static function ($a, $b) {
			return ($a['in_days'] ?? 0) <=> ($b['in_days'] ?? 0);
		});
	}
}

$hour = (int)date('G');
if ($hour < 12) {
	$day_greeting = 'Қайырлы таң! Доброе утро';
} elseif ($hour < 18) {
	$day_greeting = 'Қайырлы күн! Добрый день';
} else {
	$day_greeting = 'Қайырлы кеш! Добрый вечер';
}

$calendar_events = [
	['md' => '01-01', 'title' => 'Жаңа жыл', 'text' => 'С наступающим / с Новым годом!'],
	['md' => '03-08', 'title' => 'Халықаралық әйелдер күні', 'text' => 'Поздравляем с 8 Наурыз!'],
	['md' => '03-21', 'title' => 'Наурыз мейрамы', 'text' => 'Наурыз құтты болсын!'],
	['md' => '03-22', 'title' => 'Наурыз мейрамы', 'text' => 'Наурыз құтты болсын!'],
	['md' => '05-01', 'title' => 'Қазақстан халқының бірлігі күні', 'text' => 'С днём единства народа Казахстана!'],
	['md' => '05-07', 'title' => 'Отан қорғаушы күні', 'text' => 'С днём защитника Отечества!'],
	['md' => '05-09', 'title' => 'Жеңіс күні', 'text' => 'С днём Победы!'],
	['md' => '07-06', 'title' => 'Астана күні', 'text' => 'С днём столицы!'],
	['md' => '08-30', 'title' => 'Конституция күні', 'text' => 'С днём Конституции РК!'],
	['md' => '09-01', 'title' => 'Білім күні', 'text' => 'С днём знаний!'],
	['md' => '12-01', 'title' => 'Тұңғыш Президент күні', 'text' => 'С днём Первого Президента!'],
	['md' => '12-16', 'title' => 'Тәуелсіздік күні', 'text' => 'С днём Независимости Казахстана!'],
	['md' => '12-17', 'title' => 'Тәуелсіздік күні', 'text' => 'С днём Независимости Казахстана!'],
];

$teachers_day = new DateTimeImmutable(date('Y') . '-10-01');
while ((int)$teachers_day->format('w') !== 0) {
	$teachers_day = $teachers_day->modify('+1 day');
}
$calendar_events[] = [
	'md' => $teachers_day->format('m-d'),
	'title' => 'Ұстаздар күні',
	'text' => 'С днём учителя!',
];

$today_md = date('m-d');
$today_dt = new DateTimeImmutable('today');
$events_today = [];
$events_soon = [];
foreach ($calendar_events as $event) {
	if ($event['md'] === $today_md) {
		$events_today[] = $event;
		continue;
	}
	[$m, $d] = array_map('intval', explode('-', $event['md']));
	$event_date = $today_dt->setDate((int)$today_dt->format('Y'), $m, $d);
	if ($event_date < $today_dt) {
		$event_date = $event_date->modify('+1 year');
	}
	$days = (int)$today_dt->diff($event_date)->format('%a');
	if ($days > 0 && $days <= 14) {
		$event['date'] = $event_date->format('d.m');
		$event['in_days'] = $days;
		$events_soon[] = $event;
	}
}
usort($events_soon, static function ($a, $b) {
	return ($a['in_days'] ?? 0) <=> ($b['in_days'] ?? 0);
});
?>
<!DOCTYPE html>
<html lang="ru">

<head>
	<meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <?php appThemeInitScript(); ?>
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Дашборд - <?php echo APP_NAME; ?></title>
	<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
	<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
	<link href="../assets/css/style.css" rel="stylesheet">
	<link href="assets/css/curator-ui.css" rel="stylesheet">
    <?php appThemeStylesheet(); ?>
</head>

<body class="curator-app">
<div class="curator-shell">
    <?php include 'includes/sidebar.php'; ?>
    <div class="curator-main">
        <?php
        $page_title = 'Дашборд';
        $page_subtitle = '';
        include 'includes/header.php';
        ?>
        <div class="curator-content">

				<div class="curator-stats-grid">
					<a href="my_groups.php" class="curator-stat-card stat-primary">
						<div class="stat-label"><span class="stat-dot"></span>Мои группы</div>
						<div class="stat-number"><?php echo (int)$total_groups; ?></div>
						<div class="stat-icon"><i class="bi bi-collection-fill"></i></div>
					</a>
					<a href="my_students.php" class="curator-stat-card stat-success">
						<div class="stat-label"><span class="stat-dot"></span>Активные</div>
						<div class="stat-number"><?php echo (int)$student_stats['active']; ?></div>
						<div class="stat-icon"><i class="bi bi-person-check-fill"></i></div>
					</a>
					<a href="my_students.php" class="curator-stat-card stat-warning">
						<div class="stat-label"><span class="stat-dot"></span>Академ. отпуск</div>
						<div class="stat-number"><?php echo (int)$student_stats['academic_leave']; ?></div>
						<div class="stat-icon"><i class="bi bi-pause-circle-fill"></i></div>
					</a>
					<a href="graduates.php" class="curator-stat-card stat-info">
						<div class="stat-label"><span class="stat-dot"></span>Выпускники</div>
						<div class="stat-number"><?php echo (int)$student_stats['graduated']; ?></div>
						<div class="stat-icon"><i class="bi bi-mortarboard"></i></div>
					</a>
				</div>

				<section class="curator-quote" aria-labelledby="nakylTitle">
					<div class="curator-quote-head">
						<h2 class="curator-section-title" id="nakylTitle">Нақыл сөздер</h2>
						<span class="curator-quote-count" id="nakylCount"></span>
					</div>
					<blockquote class="curator-quote-text" id="nakylText"></blockquote>
					<p class="curator-quote-meaning" id="nakylMeaning"></p>
					<button type="button" class="btn btn-outline-primary btn-sm" id="nakylNext">
						Келесі
					</button>
				</section>

				<div class="curator-feed-grid">
					<section class="curator-feed-card" aria-labelledby="greetTitle">
						<h2 class="curator-section-title" id="greetTitle">Поздравления</h2>
						<p class="curator-feed-greeting"><?php echo htmlspecialchars($day_greeting); ?>, <?php echo htmlspecialchars($current_user['name'] ?? 'куратор'); ?>!</p>

						<?php if (!empty($birthdays_today)): ?>
							<div class="curator-feed-list">
								<?php foreach ($birthdays_today as $person): ?>
									<a class="curator-feed-item curator-feed-item-birthday" href="view_student.php?id=<?php echo (int)$person['id']; ?>">
										<div class="curator-feed-icon"><i class="bi bi-gift"></i></div>
										<div>
											<div class="curator-feed-item-title">С днём рождения!</div>
											<div class="curator-feed-item-text">
												<strong><?php echo htmlspecialchars($person['name']); ?></strong>
												<?php if ($person['group'] !== ''): ?>
													· <?php echo htmlspecialchars($person['group']); ?>
												<?php endif; ?>
												· сегодня исполняется <?php echo (int)$person['age']; ?>
											</div>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						<?php else: ?>
							<p class="curator-feed-empty">Сегодня дней рождения среди ваших студентов нет.</p>
						<?php endif; ?>

						<?php if (!empty($birthdays_soon)): ?>
							<div class="curator-feed-subtitle">Скоро</div>
							<div class="curator-feed-list">
								<?php foreach (array_slice($birthdays_soon, 0, 5) as $person): ?>
									<a class="curator-feed-item" href="view_student.php?id=<?php echo (int)$person['id']; ?>">
										<div class="curator-feed-icon curator-feed-icon-muted"><i class="bi bi-gift"></i></div>
										<div>
											<div class="curator-feed-item-title"><?php echo htmlspecialchars($person['name']); ?></div>
											<div class="curator-feed-item-text">
												День рождения <?php echo htmlspecialchars($person['birth_date']); ?>
												· через <?php echo (int)$person['in_days']; ?> дн.
											</div>
										</div>
									</a>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>

					<section class="curator-feed-card" aria-labelledby="eventsTitle">
						<h2 class="curator-section-title" id="eventsTitle">Мероприятия</h2>

						<?php if (!empty($events_today)): ?>
							<div class="curator-feed-list">
								<?php foreach ($events_today as $event): ?>
									<div class="curator-feed-item curator-feed-item-event">
										<div class="curator-feed-icon"><i class="bi bi-calendar-heart"></i></div>
										<div>
											<div class="curator-feed-item-title"><?php echo htmlspecialchars($event['title']); ?></div>
											<div class="curator-feed-item-text"><?php echo htmlspecialchars($event['text']); ?></div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php else: ?>
							<p class="curator-feed-empty">Сегодня праздничных дат нет.</p>
						<?php endif; ?>

						<?php if (!empty($events_soon)): ?>
							<div class="curator-feed-subtitle">Ближайшие</div>
							<div class="curator-feed-list">
								<?php foreach (array_slice($events_soon, 0, 5) as $event): ?>
									<div class="curator-feed-item">
										<div class="curator-feed-icon curator-feed-icon-muted"><i class="bi bi-calendar-event"></i></div>
										<div>
											<div class="curator-feed-item-title"><?php echo htmlspecialchars($event['title']); ?></div>
											<div class="curator-feed-item-text">
												<?php echo htmlspecialchars($event['date']); ?>
												· через <?php echo (int)$event['in_days']; ?> дн.
												· <?php echo htmlspecialchars($event['text']); ?>
											</div>
										</div>
									</div>
								<?php endforeach; ?>
							</div>
						<?php endif; ?>
					</section>
				</div>
        </div>
    </div>
</div>

	<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
	<script src="../assets/js/main.js"></script>
	<script src="assets/js/curator-ui.js"></script>
	<script>
		const nakylSozder = <?php echo json_encode($nakyl_sozder, JSON_UNESCAPED_UNICODE); ?>;
		let nakylIndex = <?php echo (int)$nakyl_index; ?>;
		let lastNakylIndex = nakylIndex;

		function randomNakylIndex() {
			if (nakylSozder.length < 2) return 0;
			let next = Math.floor(Math.random() * nakylSozder.length);
			while (next === lastNakylIndex) {
				next = Math.floor(Math.random() * nakylSozder.length);
			}
			return next;
		}

		function showNakyl() {
			const item = nakylSozder[nakylIndex];
			lastNakylIndex = nakylIndex;
			document.getElementById('nakylText').textContent = item.kk;
			document.getElementById('nakylMeaning').textContent = item.ru;
			document.getElementById('nakylCount').textContent = 'кездейсоқ';
		}

		document.getElementById('nakylNext').addEventListener('click', function () {
			nakylIndex = randomNakylIndex();
			showNakyl();
		});

		showNakyl();
	</script>
</body>

</html>