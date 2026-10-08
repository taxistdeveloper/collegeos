<?php
require_once '../config/config.php';
require_once '../classes/DiplomaSupplement.php';

if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$diploma = new DiplomaSupplement();
$doc = $id > 0 ? $diploma->getSupplement($id) : null;
if (!$doc) {
    header('HTTP/1.1 404 Not Found');
    echo 'Приложение не найдено';
    exit;
}

$numbered = [];
$number = 0;
foreach ($doc['rows'] as $row) {
    if (($row['row_kind'] ?? '') === 'section') {
        $row['num'] = '';
    } else {
        $number++;
        $row['num'] = $number;
    }
    $numbered[] = $row;
}

$firstCapacity = 11;
$continueCapacity = 24;
$lastCapacity = 16;

$pages = [];
$count = count($numbered);
$firstSlice = array_slice($numbered, 0, $firstCapacity);
$rest = array_slice($numbered, $firstCapacity);
$pages[] = ['sign' => false, 'fullHead' => true, 'rows' => $firstSlice, 'slots' => $firstCapacity];

if ($rest === []) {
    $pages[] = ['sign' => true, 'fullHead' => false, 'rows' => [], 'slots' => $lastCapacity];
} else {
    while (count($rest) > $lastCapacity) {
        $take = count($rest) - $lastCapacity;
        if ($take > $continueCapacity) {
            $take = $continueCapacity;
        }
        $pages[] = [
            'sign' => false,
            'fullHead' => false,
            'rows' => array_slice($rest, 0, $take),
            'slots' => $continueCapacity,
        ];
        $rest = array_slice($rest, $take);
    }
    $pages[] = ['sign' => true, 'fullHead' => false, 'rows' => $rest, 'slots' => $lastCapacity];
}

function diplomaPrintAmount($value, $forceDecimals = false)
{
    $text = DiplomaSupplement::formatAmount($value, $forceDecimals);
    return $text === '' ? '' : htmlspecialchars($text);
}

function diplomaPadRows(array $rows, $slots)
{
    $slots = max(count($rows), (int)$slots);
    while (count($rows) < $slots) {
        $rows[] = ['pad' => true];
    }
    return $rows;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <?php appFaviconTags(); ?>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Приложение к диплому — <?php echo htmlspecialchars($doc['display_name']); ?></title>
    <style>
        @page { size: A4 portrait; margin: 7mm; }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: #d9d9d9;
            color: #111;
            font-family: "Times New Roman", Times, serif;
        }
        .no-print {
            max-width: 210mm;
            margin: 12px auto 0;
            display: flex;
            gap: 8px;
            align-items: center;
        }
        .no-print a, .no-print button {
            font-family: Arial, sans-serif;
            font-size: 14px;
            padding: 8px 14px;
            border-radius: 8px;
            border: 1px solid #ccc;
            background: #fff;
            text-decoration: none;
            color: #111;
            cursor: pointer;
        }
        .no-print button { background: #2c5af2; color: #fff; border-color: #2c5af2; }
        .no-print span { font-family: Arial, sans-serif; font-size: 13px; color: #333; }
        .sheet {
            width: 210mm;
            min-height: 297mm;
            margin: 12px auto;
            background: #fff;
            padding: 6mm 6mm 7mm;
            page-break-after: always;
        }
        .sheet:last-child { page-break-after: auto; }
        .frame { border: 1.5px solid #000; min-height: 283mm; padding: 3mm 3.5mm 4mm; }
        .doc-title { text-align: center; font-weight: 700; font-size: 13pt; line-height: 1.15; margin: 0; }
        .doc-invalid { text-align: center; font-size: 10pt; margin: 1mm 0 2mm; }
        .doc-invalid .blank {
            display: inline-block;
            min-width: 38mm;
            border-bottom: 1px solid #000;
            text-align: center;
            padding: 0 2mm;
        }
        .field { margin: 1.2mm 0 0; }
        .field-value {
            min-height: 5.2mm;
            border-bottom: 1px solid #000;
            text-align: center;
            font-size: 11pt;
            line-height: 1.15;
            padding: 0 2mm;
        }
        .field-hint { text-align: center; font-size: 7.5pt; line-height: 1.1; margin-top: 0.3mm; }
        .study-line { font-size: 11pt; text-align: center; margin-top: 1.5mm; }
        .study-line .blank {
            display: inline-block;
            min-width: 16mm;
            border-bottom: 1px solid #000;
            text-align: center;
            padding: 0 1mm;
        }
        .showed { font-size: 10.5pt; text-align: center; margin: 1.6mm 0 1.2mm; line-height: 1.25; }
        table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
        table.grid th, table.grid td { border: 1px solid #000; }
        table.grid th {
            font-size: 6.4pt;
            font-weight: 700;
            text-align: center;
            vertical-align: middle;
            line-height: 1.05;
            padding: 1px 1px;
        }
        table.grid td {
            font-size: 8pt;
            vertical-align: middle;
            padding: 0.4mm 0.8mm;
            height: 6.4mm;
            line-height: 1.1;
        }
        td.num, td.hrs, td.cr, td.score, td.letter, td.gpa, td.grade { text-align: center; }
        td.name { text-align: left; }
        tr.section td.name { font-weight: 700; }
        .signs { margin-top: 4mm; font-size: 11pt; }
        .sign-row { display: flex; align-items: flex-end; gap: 3mm; margin-top: 3.2mm; }
        .sign-row span { white-space: nowrap; }
        .sign-line { flex: 1; border-bottom: 1px solid #000; height: 4.5mm; }
        .mp { margin: 4mm 0 3mm; font-weight: 700; }
        .note { font-size: 8pt; line-height: 1.2; margin: 2mm 0 0; }
        @media print {
            body { background: #fff; }
            .no-print { display: none !important; }
            .sheet { margin: 0; width: auto; min-height: auto; padding: 0; }
            .frame { min-height: 0; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button type="button" onclick="window.print()">Печать</button>
        <a href="diploma_edit.php?id=<?php echo (int)$doc['id']; ?>">Назад</a>
        <span>Бланк приложения: шапка, таблица оценок, на последнем листе подписи. Печать на А4, книжная.</span>
    </div>

    <?php foreach ($pages as $page): ?>
        <section class="sheet">
            <div class="frame">
                <?php if ($page['fullHead']): ?>
                    <h1 class="doc-title">Приложение к диплому<br>о техническом и профессиональном образовании</h1>
                    <div class="doc-invalid">
                        (без диплома ТКБ № <span class="blank"><?php echo htmlspecialchars($doc['record_number']); ?></span> недействительно)
                    </div>
                    <div class="field">
                        <div class="field-value"><?php echo htmlspecialchars($doc['display_name']); ?></div>
                        <div class="field-hint">(фамилия, имя, отчество (при его наличии))</div>
                    </div>
                    <div class="study-line">
                        за время обучения с <span class="blank"><?php echo htmlspecialchars((string)$doc['year_from']); ?></span>
                        год по <span class="blank"><?php echo htmlspecialchars((string)$doc['year_to']); ?></span> год в
                    </div>
                    <div class="field">
                        <div class="field-value"><?php echo htmlspecialchars($doc['institution']); ?></div>
                        <div class="field-hint">(полное наименование организации образования)</div>
                    </div>
                    <div class="field">
                        <div class="field-value">по специальности <?php echo htmlspecialchars(DiplomaSupplement::formatSpecialtyLine($doc['specialty_code'], $doc['specialty_name'])); ?></div>
                        <div class="field-hint">(код и наименование специальности)</div>
                    </div>
                    <div class="field">
                        <div class="field-value"><?php echo htmlspecialchars(trim($doc['qualification_code'] . (($doc['qualification_code'] !== '' && $doc['qualification_name'] !== '') ? ' – ' : '') . ($doc['qualification_name'] !== '' ? '«' . $doc['qualification_name'] . '»' : ''))); ?></div>
                        <div class="field-hint">(код и наименование квалификации)</div>
                    </div>
                    <div class="showed">показал (-а) <span style="display:inline-block;min-width:28mm;border-bottom:1px solid #000;">&nbsp;</span> соответствующие знания и навыки по следующим<br>дисциплинам и (или) модулям:</div>
                <?php endif; ?>

                <table class="grid">
                    <colgroup>
                        <col style="width:7%">
                        <col style="width:33%">
                        <col style="width:8%">
                        <col style="width:9%">
                        <col style="width:8%">
                        <col style="width:10%">
                        <col style="width:9%">
                        <col style="width:16%">
                    </colgroup>
                    <thead>
                    <?php if ($page['fullHead']): ?>
                        <tr>
                            <th rowspan="3">№<br>п/п</th>
                            <th rowspan="3">Наименование<br>дисциплины и (или)<br>модулей</th>
                            <th colspan="2">Количество</th>
                            <th colspan="4">Итоговая оценка</th>
                        </tr>
                        <tr>
                            <th rowspan="2">часов</th>
                            <th rowspan="2">кредитов</th>
                            <th colspan="3">по балльно-рейтинго-<br>вой буквенной системе<br>оценивания</th>
                            <th rowspan="2">по<br>цифровой<br>пятибалль-<br>ной системе<br>оценивания</th>
                        </tr>
                        <tr>
                            <th>в %</th>
                            <th>буквен-<br>ная</th>
                            <th>в<br>баллах</th>
                        </tr>
                    <?php else: ?>
                        <tr>
                            <th>№<br>п/п</th>
                            <th>Наименование дисциплины и (или) модулей</th>
                            <th>часов</th>
                            <th>кредитов</th>
                            <th>в %</th>
                            <th>буквенная</th>
                            <th>в баллах</th>
                            <th>пятибалльная</th>
                        </tr>
                    <?php endif; ?>
                    </thead>
                    <tbody>
                    <?php foreach (diplomaPadRows($page['rows'], $page['slots']) as $row): ?>
                        <?php if (!empty($row['pad'])): ?>
                            <tr class="pad"><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
                        <?php elseif ($row['row_kind'] === 'section'): ?>
                            <tr class="section">
                                <td class="num"></td>
                                <td class="name"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td></td><td></td><td></td><td></td><td></td><td></td>
                            </tr>
                        <?php else: ?>
                            <tr>
                                <td class="num"><?php echo (int)$row['num']; ?></td>
                                <td class="name"><?php echo htmlspecialchars($row['name']); ?></td>
                                <td class="hrs"><?php echo diplomaPrintAmount($row['hours']); ?></td>
                                <td class="cr"><?php echo diplomaPrintAmount($row['credits']); ?></td>
                                <td class="score"><?php echo diplomaPrintAmount($row['score']); ?></td>
                                <td class="letter"><?php echo htmlspecialchars($row['letter_grade']); ?></td>
                                <td class="gpa"><?php echo ($row['gpa'] === null || $row['gpa'] === '') ? '' : diplomaPrintAmount($row['gpa'], true); ?></td>
                                <td class="grade"><?php echo htmlspecialchars($row['grade_text']); ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if ($page['sign']): ?>
                    <div class="signs">
                        <div class="sign-row"><span>Заместитель руководителя по учебной работе</span><span class="sign-line"></span></div>
                        <div class="sign-row"><span>Руководитель учебной группы</span><span class="sign-line"></span></div>
                        <div class="mp">М.П.</div>
                        <p class="note">* Примечание: графы заполняются с учетом применяемой в организации образования технологии обучения и системы оценивания.</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    <?php endforeach; ?>
</body>
</html>
