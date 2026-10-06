<?php
$prefix = !empty($is_edit) ? 'edit_' : '';
?>
<div class="row g-3">
    <div class="col-12">
        <label class="form-label">Преподаватель (поиск по ФИО) *</label>
        <div class="position-relative">
            <input type="text"
                   id="<?php echo $prefix; ?>user_search"
                   class="form-control"
                   placeholder="Начните вводить фамилию или имя..."
                   autocomplete="off">
            <input type="hidden" name="user_id" id="<?php echo $prefix; ?>user_id" value="">
            <div id="<?php echo $prefix; ?>user_results"
                 class="list-group position-absolute w-100 shadow-sm d-none"
                 style="z-index: 1060; max-height: 260px; overflow: auto;"></div>
        </div>
        <div id="<?php echo $prefix; ?>user_hint" class="form-text text-muted mt-1">Любой пользователь портала: куратор, преподаватель и др. — роль менять не нужно</div>
    </div>

    <?php if (!empty($is_edit)): ?>
    <div class="col-12">
        <div class="form-check">
            <input type="checkbox" name="is_active" id="edit_is_active" class="form-check-input" value="1" checked>
            <label class="form-check-label" for="edit_is_active">Активен</label>
        </div>
    </div>
    <div class="col-12">
        <label class="form-label">Дисциплины</label>
        <div id="edit_subjects_readonly" class="small text-muted">Назначаются в разделе «Дисциплины»</div>
    </div>
    <?php else: ?>
    <div class="col-12">
        <div class="form-text">Дисциплины и часы назначаются в разделе «Дисциплины» — после привязки они появятся здесь автоматически</div>
    </div>
    <?php endif; ?>
</div>
