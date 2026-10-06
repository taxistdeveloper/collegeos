<?php
require_once '../config/config.php';

// Проверка авторизации
if (!isset($_SESSION['admin_logged_in'])) {
    header('Location: login.php');
    exit;
}

// Для header
$page_title = 'Уведомления';
$active_page = 'notifications';
include 'includes/admin_header.php';
?>

<style>
    .notification-item {
        transition: all 0.2s ease;
        border-left: 4px solid var(--danger);
        background: var(--card-bg);
        border-radius: var(--radius);
        margin-bottom: 0.75rem;
        padding: 1rem;
    }
    
    .notification-item.read {
        opacity: 0.7;
        border-left-color: var(--text-muted);
    }
    
    .notification-item:hover {
        box-shadow: var(--shadow-md);
    }
    
    .notification-meta {
        font-size: 0.8125rem;
        color: var(--text-secondary);
    }
    
    .notification-actions {
        opacity: 0;
        transition: opacity 0.2s ease;
    }
    
    .notification-item:hover .notification-actions {
        opacity: 1;
    }
    
    .badge-new {
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0%, 100% { transform: scale(1); }
        50% { transform: scale(1.05); }
    }
</style>

<!-- Page Header -->
<div class="page-header">
    <div>
        <h1 class="page-title">Уведомления</h1>
        <p class="page-subtitle">Системные уведомления и оповещения</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-outline" onclick="loadNotifications(false)" id="allBtn">
            <i class="bi bi-list-ul"></i>
            Все
        </button>
        <button class="btn btn-primary" onclick="loadNotifications(true)" id="unreadBtn">
            <i class="bi bi-bell-fill"></i>
            Непрочитанные
        </button>
        <button class="btn btn-success" onclick="markAllAsRead()">
            <i class="bi bi-check-all"></i>
            Прочитать все
        </button>
    </div>
</div>

<!-- Stats Grid -->
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-icon blue">
            <i class="bi bi-bell-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value" id="totalCount">—</div>
            <div class="stat-label">Всего</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon orange">
            <i class="bi bi-bell-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value" id="unreadCount">—</div>
            <div class="stat-label">Непрочитанные</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon cyan">
            <i class="bi bi-calendar-day"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value" id="todayCount">—</div>
            <div class="stat-label">За сегодня</div>
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon red">
            <i class="bi bi-person-x-fill"></i>
        </div>
        <div class="stat-content">
            <div class="stat-value" id="missingIinCount">—</div>
            <div class="stat-label">Ненайденные ИИН</div>
        </div>
    </div>
</div>

<!-- Notifications List -->
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="bi bi-list-ul"></i>
            Список уведомлений
        </h2>
    </div>
    <div class="card-body">
        <div id="notificationsContainer">
            <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                <div class="spinner-border" role="status" style="color: var(--primary);"></div>
                <p class="mt-2">Загрузка уведомлений...</p>
            </div>
        </div>
    </div>
</div>

<script>
    let currentFilter = true;

    document.addEventListener('DOMContentLoaded', function() {
        loadNotifications(true);
        setInterval(() => loadNotifications(currentFilter), 30000);
    });

    function loadNotifications(unreadOnly = false) {
        currentFilter = unreadOnly;

        document.getElementById('allBtn').className = unreadOnly ? 'btn btn-outline' : 'btn btn-primary';
        document.getElementById('unreadBtn').className = unreadOnly ? 'btn btn-primary' : 'btn btn-outline';

        const formData = new FormData();
        formData.append('action', 'get_notifications');
        formData.append('limit', '100');
        if (unreadOnly) {
            formData.append('unread_only', 'true');
        }

        fetch('../api/send_notification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayNotifications(data.data);
                updateStatistics(data.data);
            } else {
                showError('Ошибка загрузки: ' + data.error);
            }
        })
        .catch(error => {
            showError('Ошибка соединения');
        });
    }

    function displayNotifications(notifications) {
        const container = document.getElementById('notificationsContainer');

        if (notifications.length === 0) {
            container.innerHTML = `
                <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                    <i class="bi bi-bell-slash fs-1 d-block mb-2"></i>
                    <p>Нет уведомлений</p>
                </div>
            `;
            return;
        }

        let html = '';
        notifications.forEach(notification => {
            const isRead = notification.is_read === '1';
            const createdAt = new Date(notification.created_at);
            const timeAgo = getTimeAgo(createdAt);

            html += `
                <div class="notification-item ${isRead ? 'read' : ''}" data-id="${notification.id}">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="flex: 1;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <strong>${escapeHtml(notification.title)}</strong>
                                ${!isRead ? '<span class="badge badge-danger badge-new">Новое</span>' : ''}
                            </div>
                            <p style="margin: 0 0 0.5rem 0; color: var(--text-primary); white-space: pre-wrap;">${escapeHtml(notification.message)}</p>
                            <div class="notification-meta">
                                <i class="bi bi-clock me-1"></i>${timeAgo}
                                ${notification.curator_name ? `<span class="ms-3"><i class="bi bi-person me-1"></i>${escapeHtml(notification.curator_name)}</span>` : ''}
                                ${notification.iin ? `<span class="ms-3"><i class="bi bi-card-text me-1"></i>ИИН: ${notification.iin}</span>` : ''}
                            </div>
                        </div>
                        <div class="notification-actions d-flex gap-1">
                            ${!isRead ? `<button class="btn btn-icon btn-sm btn-outline" onclick="markAsRead(${notification.id})" title="Прочитано">
                                <i class="bi bi-check"></i>
                            </button>` : ''}
                            <button class="btn btn-icon btn-sm btn-outline" style="color: var(--danger);" onclick="deleteNotification(${notification.id})" title="Удалить">
                                <i class="bi bi-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });

        container.innerHTML = html;
    }

    function updateStatistics(notifications) {
        const total = notifications.length;
        const unread = notifications.filter(n => n.is_read === '0').length;
        const today = notifications.filter(n => {
            const notificationDate = new Date(n.created_at);
            const todayDate = new Date();
            return notificationDate.toDateString() === todayDate.toDateString();
        }).length;
        const missingIin = notifications.filter(n => n.type === 'missing_student_data').length;

        document.getElementById('totalCount').textContent = total;
        document.getElementById('unreadCount').textContent = unread;
        document.getElementById('todayCount').textContent = today;
        document.getElementById('missingIinCount').textContent = missingIin;
    }

    function markAsRead(notificationId) {
        const formData = new FormData();
        formData.append('action', 'mark_as_read');
        formData.append('notification_id', notificationId);

        fetch('../api/send_notification.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const notificationElement = document.querySelector(`[data-id="${notificationId}"]`);
                if (notificationElement) {
                    notificationElement.classList.add('read');
                    const badge = notificationElement.querySelector('.badge-new');
                    if (badge) badge.remove();
                    const markButton = notificationElement.querySelector('.btn-outline-success, .btn-outline:first-child');
                    if (markButton && markButton.querySelector('.bi-check')) markButton.remove();
                }
                
                if (currentFilter) {
                    setTimeout(() => loadNotifications(true), 500);
                }
            }
        });
    }

    function markAllAsRead() {
        if (!confirm('Отметить все как прочитанные?')) return;

        const unreadNotifications = document.querySelectorAll('.notification-item:not(.read)');
        unreadNotifications.forEach(notification => {
            const notificationId = notification.getAttribute('data-id');
            markAsRead(notificationId);
        });
    }

    function deleteNotification(notificationId) {
        if (!confirm('Удалить уведомление?')) return;
        alert('Функция удаления будет реализована позже');
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function getTimeAgo(date) {
        const now = new Date();
        const diffInSeconds = Math.floor((now - date) / 1000);

        if (diffInSeconds < 60) return 'только что';
        if (diffInSeconds < 3600) return `${Math.floor(diffInSeconds / 60)} мин. назад`;
        if (diffInSeconds < 86400) return `${Math.floor(diffInSeconds / 3600)} ч. назад`;
        return `${Math.floor(diffInSeconds / 86400)} дн. назад`;
    }

    function showError(message) {
        document.getElementById('notificationsContainer').innerHTML = `
            <div style="background: rgba(239, 68, 68, 0.1); color: var(--danger); padding: 1rem; border-radius: var(--radius);">
                <i class="bi bi-exclamation-triangle me-2"></i>${message}
            </div>
        `;
    }
</script>

<?php include 'includes/admin_footer.php'; ?>
