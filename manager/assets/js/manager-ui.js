/**
 * manager UI/UX Enhancements
 * Современные анимации и интерактивность для интерфейса куратора
 */

document.addEventListener('DOMContentLoaded', function() {
    initSidebar();
    initGlobalSearch();
});

function initSidebar() {
    const sidebar = document.getElementById('managerSidebar');
    const overlay = document.getElementById('managerSidebarOverlay');
    const toggle = document.getElementById('managerSidebarToggle');

    if (!sidebar || !toggle) return;

    function closeSidebar() {
        sidebar.classList.remove('open');
        overlay?.classList.remove('show');
    }

    function openSidebar() {
        sidebar.classList.add('open');
        overlay?.classList.add('show');
    }

    toggle.addEventListener('click', function() {
        if (sidebar.classList.contains('open')) {
            closeSidebar();
        } else {
            openSidebar();
        }
    });

    overlay?.addEventListener('click', closeSidebar);

    sidebar.querySelectorAll('.nav-link').forEach(function(link) {
        link.addEventListener('click', function() {
            if (window.innerWidth <= 992) {
                closeSidebar();
            }
        });
    });
}

function initGlobalSearch() {
    const globalSearch = document.getElementById('managerGlobalSearch');
    const pageSearch = document.getElementById('searchInput');

    if (!globalSearch) return;

    if (pageSearch) {
        globalSearch.value = pageSearch.value;
        globalSearch.addEventListener('input', function() {
            pageSearch.value = this.value;
            pageSearch.dispatchEvent(new Event('input'));
        });
        pageSearch.addEventListener('input', function() {
            globalSearch.value = this.value;
        });
        return;
    }

    globalSearch.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter' || !this.value.trim()) return;
        window.location.href = 'students.php?search=' + encodeURIComponent(this.value.trim());
    });
}

/**
 * Инициализация анимаций
 */
function initAnimations() {
    // Анимация появления элементов при скролле
    const observerOptions = {
        threshold: 0.1,
        rootMargin: '0px 0px -50px 0px'
    };

    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
                const clearTransform = (e) => {
                    if (e && e.propertyName && e.propertyName !== 'transform') return;
                    entry.target.style.transform = '';
                    entry.target.removeEventListener('transitionend', clearTransform);
                };
                entry.target.addEventListener('transitionend', clearTransform);
                // fallback if transitionend does not fire
                setTimeout(clearTransform, 700);
                observer.unobserve(entry.target);
            }
        });
    }, observerOptions);

    // Наблюдаем за всеми анимируемыми элементами
    document.querySelectorAll('.manager-animate-fadeInUp, .manager-animate-slideInLeft').forEach(el => {
        el.style.opacity = '0';
        el.style.transform = 'translateY(30px)';
        el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
        observer.observe(el);
    });

    // Анимация при наведении на карточки статистики
    document.querySelectorAll('.manager-stat-card').forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px) scale(1.02)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0) scale(1)';
        });
    });

    // Анимация кнопок
    document.querySelectorAll('.btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
        });
    });
}

/**
 * Инициализация всплывающих подсказок (общий модуль PortalTooltips)
 */
function initTooltips() {
    if (window.PortalTooltips && typeof window.PortalTooltips.init === 'function') {
        window.PortalTooltips.init();
    }
}

/**
 * Улучшенная валидация форм
 */
function initFormValidation() {
    document.querySelectorAll('.manager-form-control').forEach(input => {
        // Валидация в реальном времени
        input.addEventListener('blur', function() {
            validateField(this);
        });
        
        input.addEventListener('input', function() {
            if (this.classList.contains('is-invalid')) {
                validateField(this);
            }
        });
    });
}

function validateField(field) {
    const value = field.value.trim();
    let isValid = true;
    let message = '';
    
    // Проверка обязательных полей
    if (field.hasAttribute('required') && !value) {
        isValid = false;
        message = 'Это поле обязательно для заполнения';
    }
    
    // Проверка email
    if (field.type === 'email' && value) {
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailRegex.test(value)) {
            isValid = false;
            message = 'Введите корректный email адрес';
        }
    }
    
    // Проверка телефона
    if (field.name === 'phone' && value) {
        const phoneRegex = /^[\+]?[0-9\s\-\(\)]{10,}$/;
        if (!phoneRegex.test(value)) {
            isValid = false;
            message = 'Введите корректный номер телефона';
        }
    }
    
    // Проверка ИИН
    if (field.name === 'iin' && value) {
        if (value.length !== 12 || !/^\d+$/.test(value)) {
            isValid = false;
            message = 'ИИН должен содержать 12 цифр';
        }
    }
    
    // Обновление стилей
    field.classList.toggle('is-invalid', !isValid);
    field.classList.toggle('is-valid', isValid && value);
    
    // Удаление предыдущих сообщений
    const existingFeedback = field.parentNode.querySelector('.invalid-feedback');
    if (existingFeedback) {
        existingFeedback.remove();
    }
    
    // Добавление нового сообщения
    if (!isValid) {
        const feedback = document.createElement('div');
        feedback.className = 'invalid-feedback';
        feedback.textContent = message;
        field.parentNode.appendChild(feedback);
    }
}

/**
 * Улучшения таблиц
 */
function initTableEnhancements() {
    // Анимация строк таблицы при наведении
    document.querySelectorAll('.manager-table tbody tr').forEach(row => {
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = 'rgba(102, 126, 234, 0.05)';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    });
    
    // Анимация кнопок действий
    document.querySelectorAll('.manager-table-actions .btn').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.transform = 'scale(1.1)';
        });
        
        btn.addEventListener('mouseleave', function() {
            this.style.transform = 'scale(1)';
        });
    });
}

/**
 * Состояния загрузки
 */
function initLoadingStates() {
    // Показ спиннера при отправке форм
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', function() {
            const submitBtn = this.querySelector('button[type="submit"]');
            if (submitBtn) {
                const originalText = submitBtn.innerHTML;
                submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Загрузка...';
                submitBtn.disabled = true;
                
                // Восстанавливаем кнопку через 3 секунды (на случай ошибки)
                setTimeout(() => {
                    submitBtn.innerHTML = originalText;
                    submitBtn.disabled = false;
                }, 3000);
            }
        });
    });
}

/**
 * Система уведомлений
 */
function initNotifications() {
    // Создаем контейнер для уведомлений
    const notificationContainer = document.createElement('div');
    notificationContainer.id = 'notification-container';
    notificationContainer.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        max-width: 400px;
    `;
    document.body.appendChild(notificationContainer);
}

/**
 * Показать уведомление
 */
function showNotification(message, type = 'info', duration = 5000) {
    const notification = document.createElement('div');
    notification.className = `alert alert-${type} alert-dismissible fade show manager-notification`;
    notification.style.cssText = `
        margin-bottom: 10px;
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15);
        border: none;
        border-radius: 10px;
    `;
    
    const colors = {
        success: 'rgba(5, 150, 105, 0.1)',
        danger: 'rgba(220, 53, 69, 0.1)',
        warning: 'rgba(217, 119, 6, 0.1)',
        info: 'rgba(8, 145, 178, 0.1)'
    };
    
    notification.style.backgroundColor = colors[type] || colors.info;
    
    notification.innerHTML = `
        <i class="bi bi-${getNotificationIcon(type)} me-2"></i>
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    `;
    
    const container = document.getElementById('notification-container');
    container.appendChild(notification);
    
    // Автоматическое удаление
    setTimeout(() => {
        if (notification.parentNode) {
            notification.remove();
        }
    }, duration);
}

function getNotificationIcon(type) {
    const icons = {
        success: 'check-circle-fill',
        danger: 'exclamation-triangle-fill',
        warning: 'exclamation-triangle-fill',
        info: 'info-circle-fill'
    };
    return icons[type] || icons.info;
}

/**
 * Улучшенная фильтрация таблиц
 */
function initAdvancedTableFilter() {
    const searchInput = document.getElementById('searchInput');
    if (!searchInput) return;
    
    // Debounce для поиска
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            filterTable();
        }, 300);
    });
    
    // Анимация при фильтрации
    function filterTable() {
        const rows = document.querySelectorAll('#studentsTable tbody tr');
        let visibleCount = 0;
        
        rows.forEach((row, index) => {
            const isVisible = row.style.display !== 'none';
            if (isVisible) {
                visibleCount++;
                row.style.animationDelay = `${index * 0.05}s`;
                row.classList.add('manager-animate-fadeInUp');
            }
        });
        
        // Показываем количество найденных результатов
        showNotification(`Найдено результатов: ${visibleCount}`, 'info', 2000);
    }
}

/**
 * Улучшенные прогресс-бары
 */
function initProgressBars() {
    document.querySelectorAll('.manager-progress-bar').forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        
        setTimeout(() => {
            bar.style.width = width;
        }, 500);
    });
}

/**
 * Инициализация всех компонентов после загрузки
 */
function initAllComponents() {
    initAnimations();
    initTooltips();
    initFormValidation();
    initTableEnhancements();
    initLoadingStates();
    initNotifications();
    initAdvancedTableFilter();
    initProgressBars();
}

// Запускаем инициализацию
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAllComponents);
} else {
    initAllComponents();
}

// Экспорт функций для использования в других скриптах
window.managerUI = {
    showNotification,
    validateField,
    initAnimations,
    initTooltips,
    initFormValidation
};

// Кастомные select как в админке
(function loadAdminSelect() {
    if (window.initAdminSelects || document.querySelector('script[data-admin-select]')) {
        return;
    }
    var current = document.currentScript;
    var src = current && current.src
        ? current.src.replace(/manager-ui\.js(\?.*)?$/, 'admin-select.js$1')
        : 'assets/js/admin-select.js';
    var script = document.createElement('script');
    script.src = src;
    script.setAttribute('data-admin-select', '1');
    (current && current.parentNode ? current.parentNode : document.body).appendChild(script);
})();
