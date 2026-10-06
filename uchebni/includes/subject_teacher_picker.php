<?php
$prefix = $teacher_picker_prefix ?? '';
?>
<div class="mb-1">
    <label class="form-label">Преподаватели</label>
    <div class="position-relative">
        <input type="text"
               id="<?php echo $prefix; ?>teacher_search"
               class="form-control"
               placeholder="Поиск преподавателя по ФИО..."
               autocomplete="off">
        <div id="<?php echo $prefix; ?>teacher_results"
             class="list-group position-absolute w-100 shadow-sm d-none"
             style="z-index: 1060; max-height: 240px; overflow: auto;"></div>
    </div>
    <div class="form-text">Сначала добавьте преподавателя в разделе «Преподаватели», затем найдите его здесь</div>
    <div id="<?php echo $prefix; ?>teacher_selected" class="mt-2"></div>
    <div id="<?php echo $prefix; ?>teacher_empty" class="text-muted small">Пока никто не выбран</div>
</div>
