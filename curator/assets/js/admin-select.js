(function () {
    function initAdminSelects(root) {
        (root || document).querySelectorAll('select.form-select').forEach(function (select) {
            if (select.closest('.admin-select')) {
                return;
            }
            enhanceAdminSelect(select);
        });
    }

    function enhanceAdminSelect(select) {
        const wrap = document.createElement('div');
        wrap.className = 'admin-select';
        select.parentNode.insertBefore(wrap, select);
        wrap.appendChild(select);

        const toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'admin-select-toggle';
        toggle.innerHTML = '<span class="admin-select-label"></span><i class="bi bi-chevron-down admin-select-caret"></i>';

        const menu = document.createElement('div');
        menu.className = 'admin-select-menu';

        wrap.appendChild(toggle);
        document.body.appendChild(menu);
        menu.style.position = 'fixed';
        menu.style.display = 'none';
        menu.style.zIndex = '3000';

        const labelEl = toggle.querySelector('.admin-select-label');
        let searchInput = null;
        let optionsWrap = null;
        let activeIndex = -1;

        function selectedOption() {
            return select.options[select.selectedIndex] || null;
        }

        function syncLabel() {
            const option = selectedOption();
            labelEl.textContent = option ? option.text : '';
            wrap.classList.toggle('is-placeholder', !select.value);
        }

        function closeMenu() {
            wrap.classList.remove('is-open');
            menu.style.display = 'none';
        }

        function positionMenu() {
            const rect = toggle.getBoundingClientRect();
            const spaceBelow = window.innerHeight - rect.bottom;
            const maxHeight = Math.min(280, Math.max(160, spaceBelow - 12));
            menu.style.left = Math.round(rect.left) + 'px';
            menu.style.width = Math.round(rect.width) + 'px';
            menu.style.maxHeight = maxHeight + 'px';
            if (spaceBelow < 180 && rect.top > spaceBelow) {
                menu.style.top = 'auto';
                menu.style.bottom = Math.round(window.innerHeight - rect.top + 6) + 'px';
            } else {
                menu.style.bottom = 'auto';
                menu.style.top = Math.round(rect.bottom + 6) + 'px';
            }
        }

        function visibleOptions() {
            return Array.from(menu.querySelectorAll('.admin-select-option'));
        }

        function setActive(index) {
            const items = visibleOptions();
            items.forEach(function (item) {
                item.classList.remove('is-active');
            });
            if (!items.length) {
                activeIndex = -1;
                return;
            }
            activeIndex = (index + items.length) % items.length;
            items[activeIndex].classList.add('is-active');
            items[activeIndex].scrollIntoView({ block: 'nearest' });
        }

        function onMenuKeydown(event) {
            const items = visibleOptions();
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                setActive(activeIndex + 1);
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                setActive(activeIndex - 1);
            } else if (event.key === 'Enter') {
                event.preventDefault();
                if (items[activeIndex]) {
                    items[activeIndex].click();
                }
            } else if (event.key === 'Escape') {
                event.preventDefault();
                closeMenu();
                toggle.focus();
            }
        }

        function ensureMenuChrome() {
            menu.innerHTML = '';
            searchInput = null;
            if (select.options.length > 8) {
                const searchWrap = document.createElement('div');
                searchWrap.className = 'admin-select-search';
                searchInput = document.createElement('input');
                searchInput.type = 'text';
                searchInput.placeholder = 'Поиск...';
                searchInput.addEventListener('input', renderOptions);
                searchInput.addEventListener('keydown', onMenuKeydown);
                searchWrap.appendChild(searchInput);
                menu.appendChild(searchWrap);
            }
            optionsWrap = document.createElement('div');
            menu.appendChild(optionsWrap);
        }

        function renderOptions() {
            const query = (searchInput ? searchInput.value : '').trim().toLowerCase();
            optionsWrap.innerHTML = '';
            let count = 0;
            Array.from(select.options).forEach(function (option) {
                const text = option.text || '';
                if (query && text.toLowerCase().indexOf(query) === -1) {
                    return;
                }
                const item = document.createElement('button');
                item.type = 'button';
                item.className = 'admin-select-option';
                if (!option.value) {
                    item.classList.add('is-placeholder');
                }
                if (option.value === select.value) {
                    item.classList.add('is-selected');
                }
                item.textContent = text;
                item.addEventListener('click', function () {
                    select.value = option.value;
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                    syncLabel();
                    closeMenu();
                });
                optionsWrap.appendChild(item);
                count += 1;
            });

            if (count === 0) {
                const empty = document.createElement('div');
                empty.className = 'admin-select-empty';
                empty.textContent = 'Ничего не найдено';
                optionsWrap.appendChild(empty);
            }

            const selectedItem = optionsWrap.querySelector('.admin-select-option.is-selected');
            const items = visibleOptions();
            activeIndex = selectedItem ? items.indexOf(selectedItem) : (items.length ? 0 : -1);
            if (activeIndex >= 0) {
                items[activeIndex].classList.add('is-active');
            }
        }

        function openMenu() {
            document.querySelectorAll('.admin-select.is-open').forEach(function (other) {
                if (other !== wrap && other._closeAdminSelect) {
                    other._closeAdminSelect();
                }
            });
            ensureMenuChrome();
            renderOptions();
            wrap.classList.add('is-open');
            menu.style.display = 'block';
            positionMenu();
            if (searchInput) {
                searchInput.focus();
            }
        }

        toggle.addEventListener('click', function (event) {
            event.preventDefault();
            if (wrap.classList.contains('is-open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        toggle.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                openMenu();
            }
        });

        select.addEventListener('change', syncLabel);
        select.addEventListener('invalid', function () {
            wrap.classList.add('is-invalid');
            toggle.style.borderColor = 'var(--danger)';
        });

        wrap._closeAdminSelect = closeMenu;
        wrap._syncAdminSelect = syncLabel;
        syncLabel();
    }

    function syncAllAdminSelects(root) {
        (root || document).querySelectorAll('.admin-select').forEach(function (wrap) {
            if (wrap._syncAdminSelect) {
                wrap._syncAdminSelect();
            }
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest('.admin-select') || event.target.closest('.admin-select-menu')) {
            return;
        }
        document.querySelectorAll('.admin-select.is-open').forEach(function (wrap) {
            if (wrap._closeAdminSelect) {
                wrap._closeAdminSelect();
            }
        });
    });

    window.addEventListener('resize', function () {
        document.querySelectorAll('.admin-select.is-open').forEach(function (wrap) {
            if (wrap._closeAdminSelect) {
                wrap._closeAdminSelect();
            }
        });
    });

    document.addEventListener('scroll', function (event) {
        if (event.target && event.target.closest && event.target.closest('.admin-select-menu')) {
            return;
        }
        document.querySelectorAll('.admin-select.is-open').forEach(function (wrap) {
            if (wrap._closeAdminSelect) {
                wrap._closeAdminSelect();
            }
        });
    }, true);

    window.initAdminSelects = initAdminSelects;
    window.syncAllAdminSelects = syncAllAdminSelects;

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initAdminSelects(document);
        });
    } else {
        initAdminSelects(document);
    }
})();
