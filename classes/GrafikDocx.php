<?php
/**
 * Генерация Word-документа grafik.docx для модуля практики.
 * Сохраняет 100% форматирование шаблона (таблицы, даты, часы, ФИО, группа).
 */

class GrafikDocx
{
    private const W_NS = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public function render(array $workload): string
    {
        $templatePath = dirname(__DIR__) . '/practics/grafik.docx';
        if (!is_file($templatePath)) {
            throw new RuntimeException('Файл grafik.docx не найден в папке practics.');
        }

        $tmp = tempnam(sys_get_temp_dir(), 'grafik_');
        if ($tmp === false || !copy($templatePath, $tmp)) {
            throw new RuntimeException('Не удалось скопировать временный файл шаблона.');
        }

        $zip = new ZipArchive();
        if ($zip->open($tmp) !== true) {
            @unlink($tmp);
            throw new RuntimeException('Не удалось открыть DOCX как zip-архив.');
        }

        $xml = $zip->getFromName('word/document.xml');
        if ($xml === false) {
            $zip->close();
            @unlink($tmp);
            throw new RuntimeException('Повреждённый файл word/document.xml.');
        }

        $filled = $this->fillDocumentXml($xml, $workload);
        $zip->deleteName('word/document.xml');
        $zip->addFromString('word/document.xml', $filled);
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        if ($bytes === false) {
            throw new RuntimeException('Не удалось прочитать сгенерированный DOCX.');
        }

        return $bytes;
    }

    public function fillDocumentXml(string $xml, array $workload): string
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new RuntimeException('Не удалось разобрать XML шаблона.');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::W_NS);

        $formData = json_decode($workload['form_data'] ?? '{}', true);
        $entries = $formData['entries'] ?? [];

        $practiceName = trim((string)($workload['module_name'] ?? ''));
        $group = trim((string)($workload['study_group'] ?? ''));
        $teacher = trim((string)($workload['teacher_name'] ?? ''));
        $deadline = trim((string)($workload['deadline'] ?? ''));

        // Замена метаданных
        $this->replaceGroup($xpath, $group);
        $this->replaceSupervisor($xpath, $teacher);
        $this->replacePracticeName($xpath, $practiceName);

        // Период практики
        $dates = [];
        foreach ($entries as $e) {
            if (!empty($e['date'])) $dates[] = $e['date'];
        }
        sort($dates);
        if (!empty($dates)) {
            $period = 'с ' . date('d.m.Y', strtotime($dates[0])) . ' по ' . date('d.m.Y', strtotime(end($dates)));
            $this->replaceInParagraphContaining($xpath, 'Сроки проведения', $period, false, true);
        }

        // Заполнение таблицы занятий
        if (!empty($entries)) {
            $this->fillScheduleTable($dom, $xpath, $entries);
        }

        return $dom->saveXML();
    }

    private function replaceGroup(DOMXPath $xpath, string $value): void
    {
        if ($value === '') return;
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (!str_contains($text, 'группы')) continue;
            foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                if (preg_match('/^_{3,}$/u', (string)$node->nodeValue)) {
                    $node->nodeValue = ' ' . $value . ' ';
                    return;
                }
            }
        }
    }

    private function replacePracticeName(DOMXPath $xpath, string $value): void
    {
        if ($value === '') return;
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (!str_contains($text, 'по') && !str_contains($text, 'практике')) continue;
            foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                if (preg_match('/^_{3,}$/u', (string)$node->nodeValue)) {
                    $node->nodeValue = ' ' . $value . ' ';
                    return;
                }
            }
        }
    }

    private function replaceSupervisor(DOMXPath $xpath, string $value): void
    {
        if ($value === '') return;
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (!str_contains($text, 'Руководитель') && !str_contains($text, 'преподаватель')) continue;
            foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                $nodeText = (string)$node->nodeValue;
                if (preg_match('/^_{3,}$/u', $nodeText)) {
                    $node->nodeValue = ' ' . $value . ' ';
                    return;
                }
            }
        }
    }

    private function replaceInParagraphContaining(DOMXPath $xpath, string $marker, string $value, bool $afterColon, bool $replaceAll): void
    {
        foreach ($xpath->query('//w:p') as $paragraph) {
            $text = $this->paragraphText($xpath, $paragraph);
            if (!str_contains($text, $marker)) continue;
            foreach ($xpath->query('.//w:t', $paragraph) as $node) {
                $node->nodeValue = $marker . ': ' . $value;
                return;
            }
        }
    }

    private function fillScheduleTable(DOMDocument $dom, DOMXPath $xpath, array $entries): void
    {
        $tables = $xpath->query('//w:tbl');
        if ($tables === false || $tables->length === 0) return;

        /** @var DOMElement $table */
        $table = $tables->item(0);
        $rows = $xpath->query('./w:tr', $table);
        if ($rows === false || $rows->length < 2) return;

        // Вторая строка — шаблон для строк данных
        /** @var DOMElement $templateRow */
        $templateRow = $rows->item(1);

        // Удаляем все строки данных кроме первой
        for ($i = $rows->length - 1; $i >= 1; $i--) {
            $table->removeChild($rows->item($i));
        }

        foreach ($entries as $idx => $entry) {
            /** @var DOMElement $newRow */
            $newRow = $templateRow->cloneNode(true);
            $cells = $xpath->query('./w:tc', $newRow);
            if ($cells === false || $cells->length < 7) continue;

            $num = $idx + 1;
            $module = $entry['module_name'] ?? '';
            $date = !empty($entry['date']) ? date('d.m.Y', strtotime($entry['date'])) : '';
            $timeStart = $entry['time_start'] ?? '';
            $timeEnd = $entry['time_end'] ?? '';
            $hours = number_format((float)($entry['hours'] ?? 0), 1);
            $place = $entry['place'] ?? '';
            $isDot = !empty($entry['is_dot']) ? 'ДОТ' : '';

            $this->setCellText($xpath, $cells->item(0), (string)$num);
            $this->setCellText($xpath, $cells->item(1), $module);
            $this->setCellText($xpath, $cells->item(2), $date);
            $this->setCellText($xpath, $cells->item(3), $timeStart);
            $this->setCellText($xpath, $cells->item(4), $timeEnd);
            $this->setCellText($xpath, $cells->item(5), $hours);
            $this->setCellText($xpath, $cells->item(6), $place . ($isDot ? " ($isDot)" : ''));

            $table->appendChild($newRow);
        }
    }

    private function setCellText(DOMXPath $xpath, ?DOMNode $cell, string $text): void
    {
        if (!$cell instanceof DOMElement) return;
        $tNodes = $xpath->query('.//w:t', $cell);
        if ($tNodes !== false && $tNodes->length > 0) {
            $tNodes->item(0)->nodeValue = $text;
            for ($i = 1; $i < $tNodes->length; $i++) {
                $tNodes->item($i)->nodeValue = '';
            }
        }
    }

    private function paragraphText(DOMXPath $xpath, DOMNode $p): string
    {
        $out = '';
        foreach ($xpath->query('.//w:t', $p) as $t) {
            $out .= (string)$t->nodeValue;
        }
        return $out;
    }
}

