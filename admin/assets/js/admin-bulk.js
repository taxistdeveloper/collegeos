(function () {
    function initBulkDelete(form) {
        const root = document.getElementById(form.dataset.bulkRoot) || document;
        const selectAll = root.querySelector('.bulk-select-all');
        const button = document.querySelector('button.bulk-delete-btn[form="' + form.id + '"]');

        function rows() {
            return Array.from(root.querySelectorAll('.bulk-row-check'));
        }

        function sync() {
            const list = rows();
            const checked = list.filter(function (box) { return box.checked; });
            if (selectAll) {
                selectAll.checked = list.length > 0 && checked.length === list.length;
                selectAll.indeterminate = checked.length > 0 && checked.length < list.length;
            }
            if (button) {
                button.disabled = checked.length === 0;
            }
        }

        if (selectAll) {
            selectAll.addEventListener('change', function () {
                rows().forEach(function (box) {
                    box.checked = selectAll.checked;
                });
                sync();
            });
        }

        root.addEventListener('change', function (event) {
            if (event.target.classList && event.target.classList.contains('bulk-row-check')) {
                sync();
            }
        });

        form.addEventListener('submit', function (event) {
            const count = rows().filter(function (box) { return box.checked; }).length;
            if (!count) {
                event.preventDefault();
                alert('Выберите записи для удаления');
                return;
            }
            const label = form.dataset.confirm || 'Удалить выбранные записи';
            if (!confirm(label + ' (' + count + ')?')) {
                event.preventDefault();
            }
        });

        sync();
    }

    function boot() {
        document.querySelectorAll('form.bulk-delete-form').forEach(initBulkDelete);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
