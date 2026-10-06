(function () {
    const app = document.getElementById('hoursDayApp');
    if (!app) return;

    const bootEl = document.getElementById('hoursBootstrap');
    if (!bootEl) return;

    const boot = JSON.parse(bootEl.textContent);
    const days = boot.days || [];
    const totals = boot.totals || {};
    const cells = boot.cells && typeof boot.cells === 'object' && !Array.isArray(boot.cells)
        ? boot.cells
        : {};

    const periodId = app.dataset.period;
    const teacherId = app.dataset.teacher;
    const saveUrl = app.dataset.saveUrl;
    let selectedDay = app.dataset.defaultDay || (days.length ? days[days.length - 1].ymd : null);

    const dayPicker = document.getElementById('dayPicker');
    const listEl = document.getElementById('subjectList');
    const emptyEl = document.getElementById('emptyDay');
    const titleEl = document.getElementById('selectedDayTitle');
    const metaEl = document.getElementById('selectedDayMeta');

    function dayMeta(ymd) {
        return days.find((d) => d.ymd === ymd) || null;
    }

    function cellKey(sid, gid) {
        return String(sid) + ':' + String(gid || 0);
    }

    function getCell(sid, ymd, gid) {
        const keys = [cellKey(sid, gid), cellKey(sid, 0), String(sid)];
        for (let i = 0; i < keys.length; i++) {
            const row = cells[keys[i]];
            if (row && row[ymd]) return row[ymd];
        }
        return { both: 0, numerator: 0, denominator: 0 };
    }

    function setCell(sid, ymd, patch, gid) {
        const key = cellKey(sid, gid);
        if (!cells[key]) cells[key] = {};
        cells[key][ymd] = Object.assign(
            { both: 0, numerator: 0, denominator: 0 },
            cells[key][ymd] || {},
            patch
        );
    }

    function shownHours(sid, ymd, gid) {
        const c = getCell(sid, ymd, gid);
        return (c.both || 0) + (c.numerator || 0) + (c.denominator || 0);
    }

    async function save(payload) {
        const res = await fetch(saveUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                Accept: 'application/json',
            },
            body: new URLSearchParams(payload),
            credentials: 'same-origin',
        });
        if (res.status === 401) {
            window.location.href = '../login.php';
            throw new Error('Нужна авторизация');
        }
        return res.json();
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function renderDays() {
        dayPicker.innerHTML = '';
        days.forEach((d) => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className =
                'day-chip' +
                (d.ymd === selectedDay ? ' active' : '') +
                (d.is_today ? ' today' : '') +
                (d.is_practice ? ' practice' : '') +
                (d.week_part === 'numerator' ? ' num' : ' den');
            const mark = d.is_practice ? 'П' : (d.lessons || []).length + 'п';
            btn.innerHTML =
                '<span class="dc-dow">' +
                escapeHtml(d.dow_short) +
                '</span>' +
                '<span class="dc-day">' +
                d.day +
                '</span>' +
                '<span class="dc-mark">' +
                mark +
                '</span>';
            btn.title =
                d.label +
                ' · ' +
                d.week_label +
                (d.is_practice ? ' · практика' : ' · пар: ' + (d.lessons || []).length);
            btn.addEventListener('click', () => {
                selectedDay = d.ymd;
                renderDays();
                renderList();
            });
            dayPicker.appendChild(btn);
        });

        const active = dayPicker.querySelector('.day-chip.active');
        if (active) {
            active.scrollIntoView({ inline: 'center', block: 'nearest', behavior: 'smooth' });
        }
    }

    function renderList() {
        const d = dayMeta(selectedDay);
        listEl.innerHTML = '';

        if (!d) {
            titleEl.textContent = 'Нет дня';
            metaEl.textContent = '';
            emptyEl.classList.remove('d-none');
            emptyEl.textContent = 'Нет дней с парами';
            return;
        }

        titleEl.textContent = d.label + ' · ' + d.dow_short;
        metaEl.textContent =
            d.week_label +
            (d.is_today ? ' · сегодня' : '') +
            (d.is_practice ? ' · практика' : '');

        if (d.is_practice) {
            emptyEl.classList.remove('d-none');
            emptyEl.textContent = 'Практика — пары не ставятся';
            return;
        }

        const lessons = d.lessons || [];
        if (!lessons.length) {
            emptyEl.classList.remove('d-none');
            emptyEl.textContent = 'В этот день пар по расписанию нет';
            return;
        }
        emptyEl.classList.add('d-none');

        lessons.forEach((lesson) => {
            const sid = lesson.subject_id;
            const gid = lesson.group_id || 0;
            const groupCode = lesson.group_code || ((lesson.groups || [])[0] || '');
            const t = totals[String(sid)] || { planned: 0, total: 0 };
            const hours = shownHours(sid, selectedDay, gid);
            const pairWord = lesson.pairs === 1 ? 'пара' : 'пар';
            const subLine = lesson.is_substituted
                ? '<div class="sdc-sub">' +
                  escapeHtml(
                      lesson.is_cover || lesson.is_substitute_in
                          ? lesson.substitute_name || 'Замена'
                          : 'Замена' +
                            (lesson.substitute_name ? ' · ' + lesson.substitute_name : '')
                  ) +
                  '</div>'
                : '';
            const card = document.createElement('div');
            card.className =
                'subject-day-card' +
                (hours > 0 ? ' has-h' : '') +
                (lesson.is_substituted ? ' is-sub' : '');
            card.innerHTML =
                '<span class="entity-color" style="background:' +
                escapeHtml(lesson.color || '#64748b') +
                '"></span>' +
                '<div class="sdc-left">' +
                '<div>' +
                '<div class="sdc-title">' +
                escapeHtml(groupCode) +
                '</div>' +
                '<div class="sdc-subj">' +
                escapeHtml(lesson.title) +
                '</div>' +
                subLine +
                '<div class="sdc-meta">' +
                lesson.pairs +
                ' ' +
                pairWord +
                ' (' +
                lesson.hours +
                ' ч)' +
                ' · факт <span data-total="' +
                sid +
                '">' +
                t.total +
                '</span>' +
                (t.planned ? ' / ' + t.planned : '') +
                '</div>' +
                '</div></div>' +
                '<div class="sdc-right">' +
                '<div class="sdc-now">' +
                (hours > 0 ? hours + ' ч' : '—') +
                '</div>' +
                '<div class="sdc-actions">' +
                '<button type="button" class="btn btn-sm btn-primary" data-act="set" data-h="2" data-sid="' +
                sid +
                '" data-gid="' +
                gid +
                '">2</button>' +
                '<button type="button" class="btn btn-sm btn-outline-primary" data-act="set" data-h="4" data-sid="' +
                sid +
                '" data-gid="' +
                gid +
                '">4</button>' +
                '<button type="button" class="btn btn-sm btn-outline-secondary" data-act="clear" data-sid="' +
                sid +
                '" data-gid="' +
                gid +
                '" title="Снять">×</button>' +
                '</div></div>';
            listEl.appendChild(card);
        });
    }

    listEl.addEventListener('click', async (e) => {
        const btn = e.target.closest('button[data-act]');
        if (!btn || !selectedDay) return;
        const sid = parseInt(btn.dataset.sid, 10);
        const gid = parseInt(btn.dataset.gid || '0', 10);
        const act = btn.dataset.act;
        const d = dayMeta(selectedDay);
        if (!d || d.is_practice) return;

        let hours = 0;
        if (act === 'set') {
            hours = parseInt(btn.dataset.h, 10);
            const cur = getCell(sid, selectedDay, gid).both || 0;
            if (cur === hours) hours = 0;
        }

        btn.disabled = true;
        try {
            const json = await save({
                action: 'save_hour',
                period_id: periodId,
                teacher_id: teacherId,
                subject_id: sid,
                group_id: gid,
                entry_date: selectedDay,
                week_part: 'both',
                hours: hours,
            });
            if (!json.ok) {
                alert(json.error || 'Ошибка');
                return;
            }
            setCell(sid, selectedDay, { both: hours, numerator: 0, denominator: 0 }, gid);
            if (!totals[String(sid)]) totals[String(sid)] = { planned: 0, total: 0 };
            totals[String(sid)].total = json.total;
            renderList();
        } catch (err) {
            alert('Нет связи с сервером');
        } finally {
            btn.disabled = false;
        }
    });

    if (!dayMeta(selectedDay) && days.length) {
        selectedDay = days[days.length - 1].ymd;
    }

    renderDays();
    renderList();
})();
