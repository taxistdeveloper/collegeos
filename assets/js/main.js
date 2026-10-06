/**
 * Основной JavaScript файл для системы управления студентами
 */

// Глобальные переменные
let currentPage = 1;
let itemsPerPage = 10;
let totalItems = 0;

// Инициализация при загрузке страницы
document.addEventListener("DOMContentLoaded", function () {
  initializeApp();
  setupCuratorDashboardFilters();
});

/**
 * Инициализация приложения
 */
function initializeApp() {
  // Инициализация tooltips
  var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
  var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
    return new bootstrap.Tooltip(tooltipTriggerEl);
  });

  // Инициализация popovers
  var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
  var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
    return new bootstrap.Popover(popoverTriggerEl);
  });

  // Обработчики событий
  setupEventListeners();
}

/**
 * Настройка обработчиков событий
 */
function setupEventListeners() {
  // Поиск студентов
  const searchInput = document.getElementById("search-input");
  if (searchInput) {
    searchInput.addEventListener("input", debounce(handleSearch, 300));
  }

  // Фильтры
  const filterSelects = document.querySelectorAll(".filter-select");
  filterSelects.forEach((select) => {
    select.addEventListener("change", handleFilter);
  });

  // Пагинация
  const paginationLinks = document.querySelectorAll(".pagination .page-link");
  paginationLinks.forEach((link) => {
    link.addEventListener("click", handlePagination);
  });

  // Подтверждение удаления
  const deleteButtons = document.querySelectorAll(".btn-delete");
  deleteButtons.forEach((button) => {
    button.addEventListener("click", confirmDelete);
  });

  // Валидация форм
  const forms = document.querySelectorAll(".needs-validation");
  forms.forEach((form) => {
    form.addEventListener("submit", validateForm);
  });
}

/**
 * Фильтрация таблицы групп на дашборде куратора
 */
function setupCuratorDashboardFilters() {
  const searchInput = document.getElementById("cur-groups-search");
  const courseSelect = document.getElementById("cur-course-filter");
  const tbody = document.getElementById("cur-groups-tbody");

  if (!tbody) return;

  const applyFilter = () => {
    const query = (searchInput?.value || "").trim().toLowerCase();
    const course = (courseSelect?.value || "").toString();
    const rows = tbody.querySelectorAll("tr");

    rows.forEach((row) => {
      const name = (row.getAttribute("data-name") || "").toLowerCase();
      const code = (row.getAttribute("data-code") || "").toLowerCase();
      const specialty = (row.getAttribute("data-specialty") || "").toLowerCase();
      const rowCourse = (row.getAttribute("data-course") || "").toString();

      const textMatch = !query || name.includes(query) || code.includes(query) || specialty.includes(query);
      const courseMatch = !course || rowCourse === course;

      row.style.display = textMatch && courseMatch ? "" : "none";
    });
  };

  if (searchInput) searchInput.addEventListener("input", debounce(applyFilter, 200));
  if (courseSelect) courseSelect.addEventListener("change", applyFilter);
}

/**
 * Загрузка статистики
 */
async function loadStatistics() {
  try {
    const response = await fetch("api/statistics.php");
    const data = await response.json();

    if (data.success) {
      document.getElementById("total-students").textContent = data.total_students || 0;
      document.getElementById("active-students").textContent = data.active_students || 0;
      document.getElementById("academic-leave").textContent = data.academic_leave || 0;
      document.getElementById("graduated").textContent = data.graduated || 0;
    }
  } catch (error) {
    console.error("Ошибка загрузки статистики:", error);
  }
}

/**
 * Загрузка последних добавленных студентов
 */
async function loadRecentStudents() {
  try {
    const response = await fetch("api/recent_students.php");
    const data = await response.json();

    const container = document.getElementById("recent-students");

    if (data.success && data.students.length > 0) {
      container.innerHTML = generateStudentsTable(data.students);
    } else {
      container.innerHTML = `
                <div class="text-center py-4">
                    <i class="bi bi-inbox fs-1 text-muted"></i>
                    <p class="mt-2 text-muted">Нет данных о студентах</p>
                </div>
            `;
    }
  } catch (error) {
    console.error("Ошибка загрузки студентов:", error);
    document.getElementById("recent-students").innerHTML = `
            <div class="alert alert-danger" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                Ошибка загрузки данных
            </div>
        `;
  }
}

/**
 * Генерация таблицы студентов
 */
function generateStudentsTable(students) {
  if (!students || students.length === 0) {
    return '<p class="text-muted">Нет данных</p>';
  }

  let html = `
        <div class="table-responsive">
            <table class="table table-hover">
                <thead>
                    <tr>
                        <th>ИИН</th>
                        <th>ФИО</th>
                        <th>Группа</th>
                        <th>Курс</th>
                        <th>Статус</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
    `;

  students.forEach((student) => {
    const statusClass = getStatusClass(student);
    const statusText = getStatusText(student);

    html += `
            <tr>
                <td>${student.iin}</td>
                <td>${student.first_name} ${student.middle_name}</td>
                <td>${student.course}</td>
                <td><span class="badge ${statusClass}">${statusText}</span></td>
                <td>
                    <div class="btn-group btn-group-sm">
                        <a href="view_student.php?id=${student.id}" class="btn btn-outline-primary" title="Просмотр">
                            <i class="bi bi-eye"></i>
                        </a>
                        <a href="edit_student_new.php?id=${student.id}" class="btn btn-outline-warning" title="Редактировать">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button class="btn btn-outline-danger btn-delete" data-id="${student.id}" title="Удалить">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
  });

  html += `
                </tbody>
            </table>
        </div>
    `;

  return html;
}

/**
 * Дата автовыпуска: max(course_end_date, 1 июля того же года)
 */
function getGraduationCutoffDate(courseEndDate) {
  if (!courseEndDate) return null;
  const end = new Date(courseEndDate);
  if (Number.isNaN(end.getTime())) return null;
  const july1 = new Date(end.getFullYear(), 6, 1);
  return end > july1 ? end : july1;
}

function isStudentGraduatedJs(student) {
  if (student.graduation_date) return true;
  const cutoff = getGraduationCutoffDate(student.course_end_date);
  if (!cutoff) return false;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return today >= cutoff;
}

function isStudentGraduatingJs(student) {
  if (student.graduation_date || !student.course_end_date || isStudentGraduatedJs(student)) {
    return false;
  }
  const end = new Date(student.course_end_date);
  if (Number.isNaN(end.getTime())) return false;
  const graduatingStart = new Date(end.getFullYear() - 1, 8, 1);
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  return today >= graduatingStart;
}

/**
 * Получение класса для статуса
 */
function getStatusClass(student) {
  if (student.academic_leave) {
    return "bg-warning text-dark";
  } else if (isStudentGraduatedJs(student)) {
    return "bg-success";
  } else if (isStudentGraduatingJs(student)) {
    return "bg-warning text-dark";
  } else {
    return "bg-primary";
  }
}

/**
 * Получение текста статуса
 */
function getStatusText(student) {
  if (student.academic_leave) {
    return "Академ. отпуск";
  } else if (isStudentGraduatedJs(student)) {
    return "Выпускник";
  } else if (isStudentGraduatingJs(student)) {
    return "Выпускная группа";
  } else {
    return "Активный";
  }
}

/**
 * Обработка поиска
 */
function handleSearch(event) {
  const query = event.target.value;
  // Здесь будет логика поиска
  console.log("Поиск:", query);
}

/**
 * Обработка фильтров
 */
function handleFilter(event) {
  const filterType = event.target.dataset.filter;
  const filterValue = event.target.value;
  // Здесь будет логика фильтрации
  console.log("Фильтр:", filterType, filterValue);
}

/**
 * Обработка пагинации
 */
function handlePagination(event) {
  event.preventDefault();
  const page = event.target.dataset.page;
  if (page) {
    currentPage = parseInt(page);
    loadStudents();
  }
}

/**
 * Подтверждение удаления
 */
function confirmDelete(event) {
  event.preventDefault();
  const studentId = event.target.closest(".btn-delete").dataset.id;

  if (confirm("Вы уверены, что хотите удалить этого студента?")) {
    deleteStudent(studentId);
  }
}

/**
 * Удаление студента
 */
async function deleteStudent(studentId) {
  try {
    // Определяем базовый путь (если мы в подпапке вроде curator/)
    const base = window.location.pathname.includes("/curator/") || window.location.pathname.includes("/admin/") || window.location.pathname.includes("/manager/") || window.location.pathname.includes("/director/") ? "../" : "";

    let response = await fetch(`${base}api/delete_student.php?id=${studentId}`, {
      method: "DELETE",
    });

    // Fallback на POST, если сервер/браузер блокирует DELETE
    if (!response.ok) {
      const formData = new FormData();
      formData.append("id", studentId);
      response = await fetch(`${base}api/delete_student.php`, { method: "POST", body: formData });
    }

    const data = await response.json();

    if (data.success) {
      showAlert("Студент успешно удален", "success");
      // Удаляем строку из таблицы, если кнопка была внутри строки
      const btn = document.querySelector(`.btn-delete[data-id="${studentId}"]`);
      if (btn) {
        const row = btn.closest("tr");
        if (row) row.remove();
      }
      // Обновляем виджеты/списки, если есть
      if (typeof loadRecentStudents === "function") {
        loadRecentStudents();
      }
      if (typeof loadStatistics === "function") {
        loadStatistics();
      }
    } else {
      showAlert(data.error || "Ошибка при удалении студента", "danger");
    }
  } catch (error) {
    console.error("Ошибка удаления:", error);
    showAlert("Ошибка при удалении студента", "danger");
  }
}

/**
 * Валидация формы
 */
function validateForm(event) {
  const form = event.target;

  if (!form.checkValidity()) {
    event.preventDefault();
    event.stopPropagation();
  }

  form.classList.add("was-validated");
}

/**
 * Показать уведомление
 */
function showAlert(message, type = "info") {
  const alertContainer = document.getElementById("alert-container") || createAlertContainer();

  const alertId = "alert-" + Date.now();
  const alertHtml = `
        <div id="${alertId}" class="alert alert-${type} alert-dismissible fade show" role="alert">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    `;

  alertContainer.insertAdjacentHTML("beforeend", alertHtml);

  // Автоматическое скрытие через 5 секунд
  setTimeout(() => {
    const alert = document.getElementById(alertId);
    if (alert) {
      const bsAlert = new bootstrap.Alert(alert);
      bsAlert.close();
    }
  }, 5000);
}

/**
 * Создание контейнера для уведомлений
 */
function createAlertContainer() {
  const container = document.createElement("div");
  container.id = "alert-container";
  container.className = "position-fixed top-0 end-0 p-3";
  container.style.zIndex = "9999";
  document.body.appendChild(container);
  return container;
}

/**
 * Функция debounce для оптимизации поиска
 */
function debounce(func, wait) {
  let timeout;
  return function executedFunction(...args) {
    const later = () => {
      clearTimeout(timeout);
      func(...args);
    };
    clearTimeout(timeout);
    timeout = setTimeout(later, wait);
  };
}

/**
 * Форматирование даты
 */
function formatDate(dateString) {
  const date = new Date(dateString);
  return date.toLocaleDateString("ru-RU");
}

/**
 * Форматирование телефона
 */
function formatPhone(phone) {
  return phone.replace(/(\d{3})(\d{3})(\d{2})(\d{2})/, "+7 ($1) $2-$3-$4");
}

/**
 * Экспорт данных
 */
function exportData(format = "excel") {
  const params = new URLSearchParams(window.location.search);
  params.set("export", format);
  window.location.href = "export.php?" + params.toString();
}

/**
 * Печать данных
 */
function printData() {
  window.print();
}

/**
 * Копирование в буфер обмена
 */
async function copyToClipboard(text) {
  try {
    await navigator.clipboard.writeText(text);
    showAlert("Скопировано в буфер обмена", "success");
  } catch (error) {
    console.error("Ошибка копирования:", error);
    showAlert("Ошибка копирования", "danger");
  }
}

/**
 * Загрузка файла
 */
function uploadFile(input) {
  const file = input.files[0];
  if (file) {
    const formData = new FormData();
    formData.append("file", file);

    fetch("api/upload.php", {
      method: "POST",
      body: formData,
    })
      .then((response) => response.json())
      .then((data) => {
        if (data.success) {
          showAlert("Файл успешно загружен", "success");
        } else {
          showAlert("Ошибка загрузки файла", "danger");
        }
      })
      .catch((error) => {
        console.error("Ошибка:", error);
        showAlert("Ошибка загрузки файла", "danger");
      });
  }
}

/**
 * Валидация ИИН
 */
function validateIIN(iin) {
  const iinRegex = /^\d{12}$/;
  return iinRegex.test(iin);
}

/**
 * Валидация email
 */
function validateEmail(email) {
  const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
  return emailRegex.test(email);
}

/**
 * Валидация телефона
 */
function validatePhone(phone) {
  const phoneRegex = /^\+?[1-9]\d{1,14}$/;
  return phoneRegex.test(phone.replace(/\D/g, ""));
}

/**
 * Генерация случайного пароля
 */
function generatePassword(length = 8) {
  const chars = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*";
  let password = "";
  for (let i = 0; i < length; i++) {
    password += chars.charAt(Math.floor(Math.random() * chars.length));
  }
  return password;
}

/**
 * Очистка формы
 */
function clearForm(formId) {
  const form = document.getElementById(formId);
  if (form) {
    form.reset();
    form.classList.remove("was-validated");
  }
}

/**
 * Предварительный просмотр изображения
 */
function previewImage(input, previewId) {
  const file = input.files[0];
  const preview = document.getElementById(previewId);

  if (file && preview) {
    const reader = new FileReader();
    reader.onload = function (e) {
      preview.src = e.target.result;
      preview.style.display = "block";
    };
    reader.readAsDataURL(file);
  }
}
