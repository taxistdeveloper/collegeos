<?php
// Sidebar для менеджера
// Этот файл должен быть подключен в каждом файле manager после получения $current_user

if (!isset($current_user)) {
    throw new Exception('$current_user не определен. Убедитесь, что файл подключен после getCurrentUser()');
}
?>

<aside class="sb-sidebar card me-3 mb-3 curator-animate-slideInLeft">
    <div class="card-body d-flex flex-column h-100">
        <!-- Информация о пользователе -->
        <div class="curator-user-info text-center">
            <div class="user-icon mx-auto">
                <i class="bi bi-person-gear fs-3"></i>
            </div>
            <div class="user-name"><?php echo htmlspecialchars($current_user['name']); ?></div>
            <div class="user-role mb-3">Менеджер</div>
            <!-- Кнопка выхода -->
            <div class="curator-logout">
                <div class="d-grid">
                    <a href="../logout.php" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-box-arrow-right me-2"></i>Выход
                    </a>
                </div>
            </div>
        </div>

        <!-- Быстрые действия -->
        <div class="curator-quick-actions">
            <div class="d-grid gap-2">
                <a href="dashboard.php" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-speedometer2 me-2"></i>Дашборд
                </a>
                <a href="students.php" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-people-fill me-2"></i>Все студенты
                </a>
                <a href="groups.php" class="btn btn-outline-info btn-sm">
                    <i class="bi bi-collection-fill me-2"></i>Группы
                </a>
                <a href="reports.php" class="btn btn-outline-success btn-sm">
                    <i class="bi bi-graph-up me-2"></i>Отчеты
                </a>
            </div>
        </div>



    </div>
</aside>