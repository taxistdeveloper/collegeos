        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php appThemeScript(); ?>
<script src="../assets/js/main.js"></script>
<script src="../curator/assets/js/curator-ui.js"></script>
<script>
function initPersonSearch(options) {
    const input = document.getElementById(options.inputId);
    const hidden = document.getElementById(options.hiddenId);
    const results = document.getElementById(options.resultsId);
    if (!input || !hidden || !results) return;

    const endpoint = options.endpoint;
    const listKey = options.listKey;
    const metaFn = options.metaFn || function() { return ''; };

    let timer = null;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        const q = input.value.trim();
        hidden.value = '';
        if (q.length < 2) {
            results.innerHTML = '';
            results.classList.add('d-none');
            return;
        }
        timer = setTimeout(function() {
            fetch(endpoint + '?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    const items = (data.success && data[listKey]) ? data[listKey] : [];
                    if (!items.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = items.map(s => {
                        const fio = [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');
                        const meta = metaFn(s);
                        return '<button type="button" class="list-group-item list-group-item-action text-start" data-id="' + s.id + '" data-fio="' + fio.replace(/"/g, '&quot;') + '">' +
                            '<strong>' + fio + '</strong>' +
                            (meta ? '<br><small class="text-muted">' + meta + '</small>' : '') +
                            '</button>';
                    }).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('.list-group-item-action').forEach(btn => {
                        btn.addEventListener('click', function() {
                            hidden.value = this.dataset.id;
                            input.value = this.dataset.fio;
                            results.classList.add('d-none');
                        });
                    });
                });
        }, 300);
    });

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target)) {
            results.classList.add('d-none');
        }
    });
}

function initStudentSearch(inputId, hiddenId, resultsId) {
    initPersonSearch({
        inputId: inputId,
        hiddenId: hiddenId,
        resultsId: resultsId,
        endpoint: 'api/search_students.php',
        listKey: 'students',
        metaFn: function(s) {
            return [s.group_name, s.iin].filter(Boolean).join(' · ');
        }
    });
}

function initTeacherSearch(inputId, hiddenId, resultsId) {
    initPersonSearch({
        inputId: inputId,
        hiddenId: hiddenId,
        resultsId: resultsId,
        endpoint: 'api/search_teachers.php',
        listKey: 'teachers',
        metaFn: function() {
            return 'Преподаватель';
        }
    });
}

function escapeHtml(str) {
    return String(str == null ? '' : str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function initBookSearch(options) {
    const input = document.getElementById(options.inputId);
    const hidden = document.getElementById(options.hiddenId);
    const results = document.getElementById(options.resultsId);
    const selected = options.selectedId ? document.getElementById(options.selectedId) : null;
    const clearBtn = options.clearId ? document.getElementById(options.clearId) : null;
    const select = options.selectId ? document.getElementById(options.selectId) : null;
    if (!input || !hidden || !results) return;

    function renderSelected(book) {
        if (!selected) return;
        if (!book) {
            selected.classList.add('d-none');
            selected.innerHTML = '';
            return;
        }
        const meta = [
            book.author ? 'Автор: ' + book.author : '',
            book.inventory_number ? 'Инв. № ' + book.inventory_number : '',
            book.fund_label && book.fund_label !== '—' ? book.fund_label : '',
            book.publish_year ? book.publish_year + ' г.' : '',
            'доступно: ' + (book.copies_available != null ? book.copies_available : '—')
        ].filter(Boolean).join(' · ');

        selected.innerHTML =
            '<div class="library-book-selected-title">' + escapeHtml(book.title) + '</div>' +
            '<div class="library-book-selected-meta">' + escapeHtml(meta) + '</div>';
        selected.classList.remove('d-none');
    }

    function syncClearVisibility() {
        const field = input.closest('.library-book-search-field');
        const hasValue = !!(input.value.trim() || hidden.value);
        if (clearBtn) {
            clearBtn.classList.toggle('is-visible', hasValue);
        }
        if (field) {
            field.classList.toggle('has-value', hasValue);
        }
    }

    function applyBook(book, syncSelect) {
        if (!book || !book.id) {
            hidden.value = '';
            input.value = '';
            if (select && syncSelect !== false) select.value = '';
            renderSelected(null);
            syncClearVisibility();
            return;
        }
        hidden.value = String(book.id);
        input.value = book.title || '';
        if (select && syncSelect !== false) {
            select.value = String(book.id);
            if (select.value !== String(book.id)) {
                // книги нет в select (например, только что стала доступна) — оставляем поиск
                select.value = '';
            }
        }
        renderSelected(book);
        results.classList.add('d-none');
        syncClearVisibility();
    }

    function clearSelection() {
        applyBook(null);
        results.innerHTML = '';
        results.classList.add('d-none');
        input.focus();
    }

    let timer = null;
    input.addEventListener('input', function() {
        clearTimeout(timer);
        const q = input.value.trim();
        hidden.value = '';
        if (select) select.value = '';
        renderSelected(null);
        syncClearVisibility();
        if (q.length < 2) {
            results.innerHTML = '';
            results.classList.add('d-none');
            return;
        }
        timer = setTimeout(function() {
            fetch('api/search_books.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    const books = (data.success && data.books) ? data.books : [];
                    if (!books.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено среди доступных книг</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = books.map(b => {
                        const meta = [
                            b.author || '',
                            b.inventory_number ? 'Инв. № ' + b.inventory_number : '',
                            'доступно: ' + b.copies_available
                        ].filter(Boolean).join(' · ');
                        return '<button type="button" class="list-group-item list-group-item-action text-start"' +
                            ' data-id="' + b.id + '"' +
                            ' data-title="' + escapeHtml(b.title) + '"' +
                            ' data-author="' + escapeHtml(b.author || '') + '"' +
                            ' data-inventory="' + escapeHtml(b.inventory_number || '') + '"' +
                            ' data-fund-label="' + escapeHtml(b.fund_label || '') + '"' +
                            ' data-year="' + escapeHtml(b.publish_year || '') + '"' +
                            ' data-copies="' + b.copies_available + '">' +
                            '<strong>' + escapeHtml(b.title) + '</strong>' +
                            '<br><small class="text-muted">' + escapeHtml(meta) + '</small></button>';
                    }).join('');
                    results.classList.remove('d-none');
                    results.querySelectorAll('.list-group-item-action').forEach(btn => {
                        btn.addEventListener('click', function() {
                            applyBook({
                                id: this.dataset.id,
                                title: this.dataset.title,
                                author: this.dataset.author,
                                inventory_number: this.dataset.inventory,
                                fund_label: this.dataset.fundLabel,
                                publish_year: this.dataset.year,
                                copies_available: this.dataset.copies
                            });
                        });
                    });
                });
        }, 300);
    });

    if (select) {
        select.addEventListener('change', function() {
            const opt = select.options[select.selectedIndex];
            if (!opt || !opt.value) {
                clearSelection();
                return;
            }
            applyBook({
                id: opt.value,
                title: opt.dataset.title || opt.textContent.trim(),
                author: opt.dataset.author || '',
                inventory_number: opt.dataset.inventory || '',
                fund_label: opt.dataset.fundLabel || '',
                publish_year: opt.dataset.year || '',
                copies_available: opt.dataset.copies || ''
            }, false);
            select.value = opt.value;
        });
    }

    if (clearBtn) {
        clearBtn.addEventListener('click', function(e) {
            e.preventDefault();
            clearSelection();
        });
    }

    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !results.contains(e.target) && !(clearBtn && clearBtn.contains(e.target))) {
            results.classList.add('d-none');
        }
    });

    syncClearVisibility();
}

document.addEventListener('DOMContentLoaded', function() {
    const globalSearch = document.getElementById('curatorGlobalSearch');
    const pageSearch = document.getElementById('librarySearchInput');
    if (globalSearch && pageSearch) {
        if (pageSearch.value) globalSearch.value = pageSearch.value;
        globalSearch.addEventListener('input', function() {
            pageSearch.value = this.value;
            if (pageSearch.form) pageSearch.form.submit();
        });
    }
});
</script>
</body>

</html>
