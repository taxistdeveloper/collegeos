/**
 * Edit student page — tabs + form helpers
 */
(function () {
    function switchTab(btn) {
        if (!btn) return false;
        var targetId = btn.getAttribute('data-tab-target');
        if (!targetId) return false;

        var tabs = document.querySelectorAll('#editStudentTabs .es-tab');
        for (var i = 0; i < tabs.length; i++) {
            if (tabs[i] === btn) {
                tabs[i].classList.add('is-active');
            } else {
                tabs[i].classList.remove('is-active');
            }
        }

        var panes = document.querySelectorAll('.es-pane');
        for (var j = 0; j < panes.length; j++) {
            var open = panes[j].id === targetId;
            if (open) {
                panes[j].classList.add('is-open');
                panes[j].style.setProperty('display', 'block', 'important');
            } else {
                panes[j].classList.remove('is-open');
                panes[j].style.setProperty('display', 'none', 'important');
            }
        }
        return false;
    }

    window.esSwitchTab = switchTab;

    function onReady(fn) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', fn);
        } else {
            fn();
        }
    }

    onReady(function () {
        var tabbar = document.getElementById('editStudentTabs');
        if (tabbar) {
            tabbar.addEventListener('click', function (e) {
                var btn = e.target.closest('.es-tab');
                if (!btn || !tabbar.contains(btn)) return;
                e.preventDefault();
                e.stopPropagation();
                switchTab(btn);
            });
        }

        var form = document.getElementById('edit-student-form');
        if (!form) return;

        var sticky = document.getElementById('editStickyBar');
        var inputs = form.querySelectorAll('input, select, textarea');
        var formChanged = false;
        var viewUrl = form.getAttribute('data-view-url') || 'my_students.php';

        function markDirty() {
            formChanged = true;
            if (sticky) sticky.classList.add('is-dirty');
        }

        function validateField(field) {
            var value = (field.value || '').trim();
            var isRequired = field.hasAttribute('required');
            field.classList.remove('is-valid', 'is-invalid');
            if (isRequired && !value) {
                field.classList.add('is-invalid');
                return false;
            }
            if (!value) return true;
            var ok = true;
            if (field.type === 'email') ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
            else if (field.name === 'iin') ok = /^\d{12}$/.test(value);
            else if (field.type === 'tel') ok = /^[\d\s+\-()]+$/.test(value);
            field.classList.add(ok ? 'is-valid' : 'is-invalid');
            return ok;
        }

        function openPaneForField(field) {
            var pane = field.closest('.es-pane');
            if (!pane || !pane.id) return;
            var btn = document.querySelector('#editStudentTabs [data-tab-target="' + pane.id + '"]');
            if (btn) switchTab(btn);
        }

        for (var i = 0; i < inputs.length; i++) {
            (function (input) {
                input.addEventListener('input', function () { markDirty(); validateField(input); });
                input.addEventListener('change', markDirty);
                input.addEventListener('blur', function () { validateField(input); });
            })(inputs[i]);
        }

        var resetBtn = document.getElementById('btnResetForm');
        if (resetBtn) {
            resetBtn.addEventListener('click', function () {
                if (!confirm('Сбросить все несохранённые изменения?')) return;
                form.reset();
                formChanged = false;
                if (sticky) sticky.classList.remove('is-dirty');
                for (var r = 0; r < inputs.length; r++) {
                    inputs[r].classList.remove('is-valid', 'is-invalid');
                }
                toggleAcademicLeaveFields();
            });
        }

        document.addEventListener('keydown', function (e) {
            if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
                e.preventDefault();
                if (form.requestSubmit) form.requestSubmit();
                else form.submit();
            }
            if (e.key === 'Escape') {
                window.location.href = viewUrl;
            }
        });

        window.addEventListener('beforeunload', function (e) {
            if (formChanged) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        form.addEventListener('submit', function (e) {
            var firstInvalid = null;
            var required = form.querySelectorAll('[required]');
            for (var q = 0; q < required.length; q++) {
                if (!validateField(required[q]) && !firstInvalid) {
                    firstInvalid = required[q];
                }
            }
            if (firstInvalid) {
                e.preventDefault();
                openPaneForField(firstInvalid);
                setTimeout(function () { firstInvalid.focus(); }, 50);
                return;
            }
            formChanged = false;
        });

        function toggleAcademicLeaveFields() {
            var yes = document.getElementById('academic_leave_yes');
            var show = yes && yes.checked;
            var reasonBlock = document.getElementById('academic_leave_reason_block');
            var dateBlock = document.getElementById('academic_leave_order_date_block');
            if (reasonBlock) reasonBlock.style.display = show ? 'block' : 'none';
            if (dateBlock) dateBlock.style.display = show ? 'block' : 'none';
        }

        var yesEl = document.getElementById('academic_leave_yes');
        var noEl = document.getElementById('academic_leave_no');
        if (yesEl) yesEl.addEventListener('change', toggleAcademicLeaveFields);
        if (noEl) noEl.addEventListener('change', toggleAcademicLeaveFields);
        toggleAcademicLeaveFields();
    });
})();
