<?php
require_once '../config/config.php';
require_once '../classes/Uchebni.php';
require_once '../classes/PermissionChecker.php';
require_once '../includes/auth.php';

checkRole(['methodist']);
$permissionChecker = new PermissionChecker();
$permissionChecker->requirePermission('manage_classrooms');

$uchebni = new Uchebni();
$message = '';
$buildings = array_keys(Uchebni::getDefaultBuildings());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $id = $uchebni->addClassroom([
            'number' => sanitize($_POST['number']),
            'building' => sanitize($_POST['building'] ?? ''),
            'capacity' => (int)($_POST['capacity'] ?? 30),
            'equipment' => sanitize($_POST['equipment'] ?? ''),
        ]);
        $message = $id ? 'Аудитория добавлена!' : 'Ошибка';
    }
    if ($action === 'edit') {
        $ok = $uchebni->updateClassroom((int)$_POST['classroom_id'], [
            'number' => sanitize($_POST['number']),
            'building' => sanitize($_POST['building'] ?? ''),
            'capacity' => (int)($_POST['capacity'] ?? 30),
            'equipment' => sanitize($_POST['equipment'] ?? ''),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ]);
        $message = $ok ? 'Обновлено!' : 'Ошибка';
    }
}

$classrooms = $uchebni->getClassrooms(false);
$byBuilding = [];
foreach ($classrooms as $c) {
    $key = $c['building'] !== '' && $c['building'] !== null ? $c['building'] : 'Без корпуса';
    $byBuilding[$key][] = $c;
}
foreach ($buildings as $b) {
    if (!isset($byBuilding[$b])) {
        $byBuilding[$b] = [];
    }
}
uksort($byBuilding, function ($a, $b) use ($buildings) {
    $ia = array_search($a, $buildings, true);
    $ib = array_search($b, $buildings, true);
    if ($ia === false && $ib === false) {
        return strcmp($a, $b);
    }
    if ($ia === false) {
        return 1;
    }
    if ($ib === false) {
        return -1;
    }
    return $ia <=> $ib;
});

$current_user = getCurrentUser();
$page_title = 'Аудитории';
$page_subtitle = count($classrooms) . ' кабинетов · ' . count($byBuilding) . ' корпуса';
require_once 'includes/header.php';
?>

<div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addRoomModal">
        <i class="bi bi-plus-lg me-1"></i>Добавить аудиторию
    </button>
</div>

<?php if ($message): ?>
<div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($message); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php foreach ($byBuilding as $buildingName => $rooms): ?>
<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <strong><i class="bi bi-building me-1"></i><?php echo htmlspecialchars($buildingName); ?></strong>
        <span class="badge bg-secondary"><?php echo count($rooms); ?></span>
    </div>
    <div class="card-body">
        <?php if (empty($rooms)): ?>
        <p class="text-muted mb-0">Аудиторий пока нет</p>
        <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rooms as $c): ?>
            <div class="col-md-4 col-lg-3">
                <div class="card h-100 <?php echo $c['is_active'] ? '' : 'border-secondary opacity-75'; ?>">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <h5 class="card-title mb-1">
                                <i class="bi bi-door-open text-primary me-1"></i>
                                <?php echo htmlspecialchars($c['number']); ?>
                            </h5>
                            <button class="btn btn-sm btn-outline-primary btn-edit-room"
                                data-room='<?php echo htmlspecialchars(json_encode($c, JSON_UNESCAPED_UNICODE), ENT_QUOTES); ?>'>
                                <i class="bi bi-pencil"></i>
                            </button>
                        </div>
                        <p class="mb-0"><i class="bi bi-people me-1"></i><?php echo (int)$c['capacity']; ?> мест</p>
                        <?php if ($c['equipment']): ?><p class="small text-muted mt-2 mb-0"><?php echo htmlspecialchars($c['equipment']); ?></p><?php endif; ?>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<div class="modal fade" id="addRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="add">
            <div class="modal-header"><h5 class="modal-title">Новая аудитория</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Номер *</label><input type="text" name="number" class="form-control" required placeholder="101"></div>
                <div class="mb-3">
                    <label class="form-label">Корпус</label>
                    <select name="building" class="form-select">
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Вместимость</label><input type="number" name="capacity" class="form-control" value="30" min="1"></div>
                <div class="mb-3"><label class="form-label">Оборудование</label><textarea name="equipment" class="form-control" rows="2" placeholder="Проектор, компьютеры..."></textarea></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div>
        </form>
    </div>
</div>

<div class="modal fade" id="editRoomModal" tabindex="-1">
    <div class="modal-dialog">
        <form method="post" class="modal-content">
            <input type="hidden" name="action" value="edit">
            <input type="hidden" name="classroom_id" id="edit_room_id">
            <div class="modal-header"><h5 class="modal-title">Редактирование</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <div class="mb-3"><label class="form-label">Номер *</label><input type="text" name="number" id="edit_room_number" class="form-control" required></div>
                <div class="mb-3">
                    <label class="form-label">Корпус</label>
                    <select name="building" id="edit_room_building" class="form-select">
                        <?php foreach ($buildings as $b): ?>
                        <option value="<?php echo htmlspecialchars($b); ?>"><?php echo htmlspecialchars($b); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3"><label class="form-label">Вместимость</label><input type="number" name="capacity" id="edit_room_capacity" class="form-control" min="1"></div>
                <div class="mb-3"><label class="form-label">Оборудование</label><textarea name="equipment" id="edit_room_equipment" class="form-control" rows="2"></textarea></div>
                <div class="form-check"><input type="checkbox" name="is_active" id="edit_room_active" class="form-check-input" value="1"><label class="form-check-label" for="edit_room_active">Активна</label></div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div>
        </form>
    </div>
</div>

<script>
document.querySelectorAll('.btn-edit-room').forEach(btn => {
    btn.addEventListener('click', function() {
        const r = JSON.parse(this.dataset.room);
        document.getElementById('edit_room_id').value = r.id;
        document.getElementById('edit_room_number').value = r.number;
        const buildingSelect = document.getElementById('edit_room_building');
        const building = r.building || '';
        if (building && ![...buildingSelect.options].some(o => o.value === building)) {
            const opt = document.createElement('option');
            opt.value = building;
            opt.textContent = building;
            buildingSelect.appendChild(opt);
        }
        buildingSelect.value = building;
        document.getElementById('edit_room_capacity').value = r.capacity;
        document.getElementById('edit_room_equipment').value = r.equipment || '';
        document.getElementById('edit_room_active').checked = r.is_active == 1;
        new bootstrap.Modal(document.getElementById('editRoomModal')).show();
    });
});
</script>

<?php require_once 'includes/footer.php'; ?>
