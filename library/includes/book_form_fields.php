<div class="row g-3">
    <div class="col-md-8">
        <label class="curator-form-label">Название *</label>
        <input type="text" name="title" class="curator-form-control" required>
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Экземпляров</label>
        <input type="number" name="copies_total" class="curator-form-control" value="1" min="1">
    </div>
    <div class="col-md-6">
        <label class="curator-form-label">Автор *</label>
        <input type="text" name="author" class="curator-form-control" required>
    </div>
    <div class="col-md-6">
        <label class="curator-form-label">ISBN</label>
        <input type="text" name="isbn" class="curator-form-control">
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Издательство</label>
        <input type="text" name="publisher" class="curator-form-control">
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Год издания</label>
        <input type="number" name="publish_year" class="curator-form-control" min="1000" max="2100">
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Категория</label>
        <input type="text" name="category" class="curator-form-control" placeholder="Учебник, худ. лит...">
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Инвентарный №</label>
        <input type="text" name="inventory_number" class="curator-form-control">
    </div>
    <div class="col-md-4">
        <label class="curator-form-label">Место (полка)</label>
        <input type="text" name="location" class="curator-form-control">
    </div>
    <div class="col-12">
        <label class="curator-form-label">Примечание</label>
        <textarea name="note" class="curator-form-control" rows="2"></textarea>
    </div>
</div>
