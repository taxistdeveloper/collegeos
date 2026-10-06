/**
 * Универсальный обработчик ошибок для автоматического открытия секций
 */
class ErrorHandler {
    
    /**
     * Инициализирует обработчик ошибок
     */
    static init() {
        document.addEventListener('DOMContentLoaded', function() {
            // Проверяем, есть ли ID секции с ошибкой
            const errorSectionId = window.errorSectionId;
            
            if (errorSectionId) {
                ErrorHandler.openErrorSection(errorSectionId);
            }
        });
    }
    
    /**
     * Открывает секцию с ошибкой
     * @param {string} sectionId ID секции для открытия
     */
    static openErrorSection(sectionId) {
        const errorSection = document.getElementById(sectionId);
        
        if (errorSection) {
            // Закрываем все другие секции
            ErrorHandler.closeAllSections();
            
            // Открываем секцию с ошибкой
            const bsCollapse = new bootstrap.Collapse(errorSection, {
                show: true
            });
            
            // Прокручиваем к секции
            setTimeout(() => {
                errorSection.scrollIntoView({ 
                    behavior: 'smooth', 
                    block: 'start' 
                });
            }, 500);
        }
    }
    
    /**
     * Закрывает все секции аккордеона
     */
    static closeAllSections() {
        const allCollapses = document.querySelectorAll('.accordion-collapse');
        
        allCollapses.forEach(collapse => {
            const bsCollapse = bootstrap.Collapse.getInstance(collapse);
            if (bsCollapse) {
                bsCollapse.hide();
            } else {
                // Если экземпляр не существует, создаем новый и сразу скрываем
                const newCollapse = new bootstrap.Collapse(collapse, {toggle: false});
                newCollapse.hide();
            }
        });
    }
    
    /**
     * Показывает ошибку с автоматическим открытием секции
     * @param {string} message Сообщение об ошибке
     * @param {string} sectionId ID секции для открытия (опционально)
     */
    static showError(message, sectionId = null) {
        // Создаем элемент для отображения ошибки
        const errorDiv = document.createElement('div');
        errorDiv.className = 'alert alert-danger alert-dismissible fade show';
        errorDiv.setAttribute('role', 'alert');
        errorDiv.innerHTML = `
            <div class="d-flex justify-content-between align-items-start">
                <div>
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>
                    ${message}
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;
        
        // Вставляем ошибку в начало контейнера
        const container = document.querySelector('.container-fluid, .container, main, body');
        if (container) {
            container.insertBefore(errorDiv, container.firstChild);
        }
        
        // Если указан ID секции, открываем её
        if (sectionId) {
            ErrorHandler.openErrorSection(sectionId);
        }
    }
}

// Автоматическая инициализация
ErrorHandler.init();
