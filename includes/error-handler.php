<?php
/**
 * Универсальный обработчик ошибок для всех форм
 * Подключается в файлах с формами для автоматического перевода ошибок
 */

// Подключаем ErrorTranslator если еще не подключен
if (!class_exists('ErrorTranslator')) {
    require_once __DIR__ . '/../classes/ErrorTranslator.php';
}

/**
 * Функция для отображения ошибки с автоматическим открытием секции
 * @param string $error Сообщение об ошибке
 * @param bool $show_close_button Показывать ли кнопку закрытия
 */
function displayError($error, $show_close_button = true) {
    if (empty($error)) return;
    
    $close_button = $show_close_button ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
    
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div>';
    echo '<i class="bi bi-exclamation-triangle-fill me-2"></i>';
    echo htmlspecialchars($error);
    echo '</div>';
    if ($show_close_button) {
        echo '<div class="ms-3">' . $close_button . '</div>';
    }
    echo '</div>';
    echo '</div>';
    
    // Добавляем JavaScript для автоматического открытия секции
    if (isset($GLOBALS['error_section_id']) && !empty($GLOBALS['error_section_id'])) {
        echo '<script>';
        echo 'window.errorSectionId = "' . htmlspecialchars($GLOBALS['error_section_id']) . '";';
        echo '</script>';
    }
}

/**
 * Функция для отображения сообщения об успехе
 * @param string $message Сообщение
 * @param bool $show_close_button Показывать ли кнопку закрытия
 */
function displaySuccess($message, $show_close_button = true) {
    if (empty($message)) return;
    
    $close_button = $show_close_button ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
    
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div>';
    echo '<i class="bi bi-check-circle-fill me-2"></i>';
    echo htmlspecialchars($message);
    echo '</div>';
    if ($show_close_button) {
        echo '<div class="ms-3">' . $close_button . '</div>';
    }
    echo '</div>';
    echo '</div>';
}

/**
 * Функция для отображения предупреждения
 * @param string $message Сообщение
 * @param bool $show_close_button Показывать ли кнопку закрытия
 */
function displayWarning($message, $show_close_button = true) {
    if (empty($message)) return;
    
    $close_button = $show_close_button ? '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>' : '';
    
    echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">';
    echo '<div class="d-flex justify-content-between align-items-start">';
    echo '<div>';
    echo '<i class="bi bi-exclamation-triangle-fill me-2"></i>';
    echo htmlspecialchars($message);
    echo '</div>';
    if ($show_close_button) {
        echo '<div class="ms-3">' . $close_button . '</div>';
    }
    echo '</div>';
    echo '</div>';
}
?>
