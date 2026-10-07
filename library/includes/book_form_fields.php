<?php
$fund_edu = Library::FUND_EDUCATIONAL;
$preselect_fund = $preselect_fund ?? '';
$fund_meta = [
    Library::FUND_PROFESSIONAL => ['icon' => 'bi-briefcase', 'hint' => 'Каталог: название, автор, ISBN, полка'],
    Library::FUND_FICTION => ['icon' => 'bi-book-half', 'hint' => 'Каталог: название, автор, ISBN, полка'],
    Library::FUND_EDUCATIONAL => ['icon' => 'bi-mortarboard', 'hint' => 'Учебный учёт: рег. номер, класс, направление'],
];
?>
<div class="book-form-fields" data-edu-fund="<?php echo htmlspecialchars($fund_edu); ?>">
    <div class="book-form-step">
        <div class="book-form-step-head">
            <span class="book-form-step-num">1</span>
            <div>
                <div class="book-form-step-title">Фонд</div>
                <div class="book-form-step-desc js-fund-hint">Выберите фонд — откроются нужные поля</div>
            </div>
        </div>
        <input type="hidden" name="fund" class="js-book-fund" value="<?php echo htmlspecialchars($preselect_fund); ?>" required>
        <div class="book-fund-picker" role="radiogroup" aria-label="Фонд">
            <?php foreach (Library::getFunds() as $value => $label):
                $meta = $fund_meta[$value] ?? ['icon' => 'bi-book', 'hint' => ''];
                $active = $preselect_fund === $value;
                ?>
                <button type="button"
                    class="book-fund-option <?php echo $active ? 'is-active' : ''; ?>"
                    data-fund="<?php echo htmlspecialchars($value); ?>"
                    data-hint="<?php echo htmlspecialchars($meta['hint']); ?>"
                    aria-pressed="<?php echo $active ? 'true' : 'false'; ?>">
                    <i class="bi <?php echo htmlspecialchars($meta['icon']); ?>"></i>
                    <span><?php echo htmlspecialchars($label); ?></span>
                </button>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="js-book-fields-standard book-form-panels" <?php echo ($preselect_fund !== '' && $preselect_fund !== $fund_edu) ? '' : 'hidden'; ?>>
        <div class="book-form-step">
            <div class="book-form-step-head">
                <span class="book-form-step-num">2</span>
                <div>
                    <div class="book-form-step-title">Основное</div>
                    <div class="book-form-step-desc">Название и автор</div>
                </div>
            </div>
            <div class="book-form-grid">
                <div class="book-field book-field--wide">
                    <label class="curator-form-label">Название <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="curator-form-control" data-required="1" placeholder="Полное название книги" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Автор <span class="text-danger">*</span></label>
                    <input type="text" name="author" class="curator-form-control" data-required="1" placeholder="ФИО автора" disabled>
                </div>
                <div class="book-field book-field--sm">
                    <label class="curator-form-label">Экземпляров</label>
                    <input type="number" name="copies_total" class="curator-form-control" value="1" min="1" disabled>
                </div>
            </div>
        </div>

        <div class="book-form-step">
            <div class="book-form-step-head">
                <span class="book-form-step-num">3</span>
                <div>
                    <div class="book-form-step-title">Издание</div>
                    <div class="book-form-step-desc">Год, язык, издательство</div>
                </div>
            </div>
            <div class="book-form-grid">
                <div class="book-field">
                    <label class="curator-form-label">Год издания</label>
                    <input type="number" name="publish_year" class="curator-form-control" min="1000" max="2100" placeholder="2024" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Язык</label>
                    <select name="language" class="curator-form-select" disabled>
                        <option value="">— не указан —</option>
                        <option value="казахский">Казахский</option>
                        <option value="русский">Русский</option>
                        <option value="английский">Английский</option>
                        <option value="немецкий">Немецкий</option>
                        <option value="французский">Французский</option>
                        <option value="турецкий">Турецкий</option>
                        <option value="украинский">Украинский</option>
                        <option value="польский">Польский</option>
                    </select>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Издательство</label>
                    <input type="text" name="publisher" class="curator-form-control" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">ISBN</label>
                    <input type="text" name="isbn" class="curator-form-control" placeholder="978-…" disabled>
                </div>
            </div>
        </div>

        <div class="book-form-step">
            <div class="book-form-step-head">
                <span class="book-form-step-num">4</span>
                <div>
                    <div class="book-form-step-title">Учёт</div>
                    <div class="book-form-step-desc">Инвентарь и место хранения</div>
                </div>
            </div>
            <div class="book-form-grid">
                <div class="book-field">
                    <label class="curator-form-label">Инвентарный №</label>
                    <input type="text" name="inventory_number" class="curator-form-control" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Место (полка)</label>
                    <input type="text" name="location" class="curator-form-control" placeholder="Зал · стеллаж · полка" disabled>
                </div>
                <div class="book-field book-field--wide">
                    <label class="curator-form-label">Примечание</label>
                    <textarea name="note" class="curator-form-control" rows="2" placeholder="Необязательно" disabled></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="js-book-fields-educational book-form-panels" <?php echo $preselect_fund === $fund_edu ? '' : 'hidden'; ?>>
        <div class="book-form-step">
            <div class="book-form-step-head">
                <span class="book-form-step-num">2</span>
                <div>
                    <div class="book-form-step-title">Учёт</div>
                    <div class="book-form-step-desc">Регистрация и класс</div>
                </div>
            </div>
            <div class="book-form-grid">
                <div class="book-field">
                    <label class="curator-form-label">Регистрационный номер</label>
                    <input type="text" name="inventory_number" class="curator-form-control" placeholder="Рег. №" disabled>
                </div>
                <div class="book-field book-field--sm">
                    <label class="curator-form-label">Класс</label>
                    <input type="text" name="grade_class" class="curator-form-control" placeholder="10каз, 11рус…" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Направление</label>
                    <input type="text" name="direction" class="curator-form-control" placeholder="ЕМ, ОГ…" disabled>
                </div>
            </div>
        </div>

        <div class="book-form-step">
            <div class="book-form-step-head">
                <span class="book-form-step-num">3</span>
                <div>
                    <div class="book-form-step-title">Издание</div>
                    <div class="book-form-step-desc">Наименование, автор, назначение</div>
                </div>
            </div>
            <div class="book-form-grid">
                <div class="book-field book-field--wide">
                    <label class="curator-form-label">Наименование издания <span class="text-danger">*</span></label>
                    <input type="text" name="title" class="curator-form-control" data-required="1" placeholder="Название учебника / пособия" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Автор <span class="text-danger">*</span></label>
                    <input type="text" name="author" class="curator-form-control" data-required="1" placeholder="ФИО автора" disabled>
                </div>
                <div class="book-field">
                    <label class="curator-form-label">Назначение</label>
                    <input type="text" name="purpose" class="curator-form-control" placeholder="учебник, тетрадь, методика…" disabled>
                </div>
                <div class="book-field book-field--sm">
                    <label class="curator-form-label">Год издания</label>
                    <input type="number" name="publish_year" class="curator-form-control" min="1000" max="2100" placeholder="2024" disabled>
                </div>
                <div class="book-field book-field--sm">
                    <label class="curator-form-label">Количество</label>
                    <input type="number" name="copies_total" class="curator-form-control" value="1" min="1" disabled>
                </div>
                <div class="book-field book-field--wide">
                    <label class="curator-form-label">Примечание</label>
                    <textarea name="note" class="curator-form-control" rows="2" placeholder="Необязательно" disabled></textarea>
                </div>
            </div>
        </div>
    </div>

    <div class="book-form-empty js-book-form-empty" <?php echo $preselect_fund !== '' ? 'hidden' : ''; ?>>
        <i class="bi bi-journal-plus"></i>
        <p>Сначала выберите фонд — форма подстроится под него</p>
    </div>
</div>
