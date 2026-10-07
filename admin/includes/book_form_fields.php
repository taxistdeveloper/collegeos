<?php
$fund_edu = Library::FUND_EDUCATIONAL;
$preselect_fund = $preselect_fund ?? '';
?>
<div class="row g-3 book-form-fields" data-edu-fund="<?php echo htmlspecialchars($fund_edu); ?>">
    <div class="col-12">
        <label class="form-label">Фонд <span class="text-danger">*</span></label>
        <select name="fund" class="form-select js-book-fund" required>
            <option value="">— выберите фонд —</option>
            <?php foreach (Library::getFunds() as $value => $label): ?>
                <option value="<?php echo htmlspecialchars($value); ?>" <?php echo $preselect_fund === $value ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="js-book-fields-standard contents-row">
        <div class="col-md-8">
            <label class="form-label">Название <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" data-required="1">
        </div>
        <div class="col-md-4">
            <label class="form-label">Экземпляров</label>
            <input type="number" name="copies_total" class="form-control" value="1" min="1">
        </div>
        <div class="col-md-6">
            <label class="form-label">Автор <span class="text-danger">*</span></label>
            <input type="text" name="author" class="form-control" data-required="1">
        </div>
        <div class="col-md-6">
            <label class="form-label">ISBN</label>
            <input type="text" name="isbn" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">Издательство</label>
            <input type="text" name="publisher" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">Год издания</label>
            <input type="number" name="publish_year" class="form-control" min="1000" max="2100">
        </div>
        <div class="col-md-4">
            <label class="form-label">Язык</label>
            <select name="language" class="form-select">
                <option value="">— не указан —</option>
                <option value="казахский">Казахский</option>
                <option value="русский">Русский</option>
                <option value="английский">Английский</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Инвентарный №</label>
            <input type="text" name="inventory_number" class="form-control">
        </div>
        <div class="col-md-4">
            <label class="form-label">Место (полка)</label>
            <input type="text" name="location" class="form-control">
        </div>
        <div class="col-12">
            <label class="form-label">Примечание</label>
            <textarea name="note" class="form-control" rows="2"></textarea>
        </div>
    </div>

    <div class="js-book-fields-educational contents-row" hidden>
        <div class="col-md-4">
            <label class="form-label">Регистрационный номер</label>
            <input type="text" name="inventory_number" class="form-control" disabled>
        </div>
        <div class="col-md-4">
            <label class="form-label">Класс</label>
            <input type="text" name="grade_class" class="form-control" disabled placeholder="например 10, 11">
        </div>
        <div class="col-md-4">
            <label class="form-label">Направление</label>
            <input type="text" name="direction" class="form-control" disabled>
        </div>
        <div class="col-md-8">
            <label class="form-label">Наименование издания <span class="text-danger">*</span></label>
            <input type="text" name="title" class="form-control" data-required="1" disabled>
        </div>
        <div class="col-md-4">
            <label class="form-label">Количество</label>
            <input type="number" name="copies_total" class="form-control" value="1" min="1" disabled>
        </div>
        <div class="col-md-6">
            <label class="form-label">Автор <span class="text-danger">*</span></label>
            <input type="text" name="author" class="form-control" data-required="1" disabled>
        </div>
        <div class="col-md-6">
            <label class="form-label">Назначение</label>
            <input type="text" name="purpose" class="form-control" disabled placeholder="учебник, пособие...">
        </div>
        <div class="col-md-4">
            <label class="form-label">Год издания</label>
            <input type="number" name="publish_year" class="form-control" min="1000" max="2100" disabled>
        </div>
        <div class="col-md-8">
            <label class="form-label">Примечание</label>
            <textarea name="note" class="form-control" rows="2" disabled></textarea>
        </div>
    </div>
</div>
