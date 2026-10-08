(function () {
    function creditsFromHours(hours) {
        var value = parseFloat(String(hours).replace(',', '.'));
        if (!value || value <= 0) {
            return '';
        }
        return formatAmount(value / 24, false);
    }

    function formatAmount(value, forceDecimals) {
        if (value === null || value === undefined || value === '') {
            return '';
        }
        var number = parseFloat(String(value).replace(',', '.'));
        if (isNaN(number)) {
            return '';
        }
        if (!forceDecimals && Math.abs(number - Math.round(number)) < 0.001) {
            return String(Math.round(number));
        }
        return number.toFixed(2);
    }

    function gradeFromScore(score, scale) {
        var number = parseFloat(String(score).replace(',', '.'));
        if (isNaN(number)) {
            return null;
        }
        for (var i = 0; i < scale.length; i++) {
            if (number >= scale[i].min) {
                return scale[i];
            }
        }
        return scale.length ? scale[scale.length - 1] : null;
    }

    function emptyRow(kind) {
        return {
            kind: kind || 'grade',
            name: '',
            hours: '',
            credits: '',
            score: '',
            letter: '',
            gpa: '',
            grade_text: kind === 'pass' ? 'зачет' : '',
            creditsManual: false
        };
    }

    function init(options) {
        var tbody = options.tableBody;
        var jsonInput = options.jsonInput;
        var mode = options.mode === 'template' ? 'template' : 'supplement';
        var scale = options.scale || [];
        var rows = (options.rows || []).map(function (row) {
            var copy = emptyRow(row.kind || 'grade');
            copy.name = row.name || '';
            copy.hours = row.hours || '';
            copy.credits = row.credits || '';
            copy.score = row.score || '';
            copy.letter = row.letter || '';
            copy.gpa = row.gpa || '';
            copy.grade_text = row.grade_text || (copy.kind === 'pass' ? 'зачет' : '');
            var autoCredits = creditsFromHours(copy.hours);
            copy.creditsManual = copy.credits !== '' && autoCredits !== '' && copy.credits !== autoCredits;
            return copy;
        });

        function sync() {
            jsonInput.value = JSON.stringify(rows.map(function (row) {
                return {
                    kind: row.kind,
                    name: row.name,
                    hours: row.kind === 'section' ? '' : row.hours,
                    credits: row.kind === 'section' ? '' : row.credits,
                    score: mode === 'supplement' && row.kind === 'grade' ? row.score : '',
                    letter: mode === 'supplement' && row.kind === 'grade' ? row.letter : '',
                    gpa: mode === 'supplement' && row.kind === 'grade' ? row.gpa : '',
                    grade_text: row.kind === 'pass' ? 'зачет' : (mode === 'supplement' && row.kind === 'grade' ? row.grade_text : '')
                };
            }));
        }

        function input(value, className, onInput) {
            var field = document.createElement('input');
            field.type = 'text';
            field.className = 'form-control form-control-sm ' + className;
            field.value = value || '';
            field.addEventListener('input', onInput);
            return field;
        }

        function render() {
            tbody.innerHTML = '';
            if (!rows.length) {
                var empty = document.createElement('tr');
                var cell = document.createElement('td');
                cell.colSpan = mode === 'supplement' ? 10 : 6;
                cell.className = 'diploma-empty';
                cell.textContent = 'Дисциплин пока нет. Добавьте строку или подставьте шаблон.';
                empty.appendChild(cell);
                tbody.appendChild(empty);
                sync();
                return;
            }

            rows.forEach(function (row, index) {
                var tr = document.createElement('tr');
                tr.className = 'diploma-row-' + row.kind;

                var order = document.createElement('td');
                order.className = 'diploma-order';
                order.textContent = String(index + 1);
                tr.appendChild(order);

                var kindCell = document.createElement('td');
                var kind = document.createElement('select');
                kind.className = 'form-select form-select-sm';
                [
                    ['grade', 'Оценка'],
                    ['pass', 'Зачёт'],
                    ['section', 'Раздел']
                ].forEach(function (option) {
                    var node = document.createElement('option');
                    node.value = option[0];
                    node.textContent = option[1];
                    if (row.kind === option[0]) {
                        node.selected = true;
                    }
                    kind.appendChild(node);
                });
                kind.addEventListener('change', function () {
                    row.kind = kind.value;
                    if (row.kind === 'pass') {
                        row.grade_text = 'зачет';
                        row.score = '';
                        row.letter = '';
                        row.gpa = '';
                    }
                    if (row.kind === 'section') {
                        row.hours = '';
                        row.credits = '';
                        row.score = '';
                        row.letter = '';
                        row.gpa = '';
                        row.grade_text = '';
                    }
                    render();
                });
                kindCell.appendChild(kind);
                tr.appendChild(kindCell);

                var nameCell = document.createElement('td');
                var name = input(row.name, 'diploma-name', function () {
                    row.name = name.value;
                    sync();
                });
                name.placeholder = row.kind === 'section' ? 'Название раздела' : 'Дисциплина или модуль';
                nameCell.appendChild(name);
                tr.appendChild(nameCell);

                var hoursCell = document.createElement('td');
                var hours = input(row.hours, 'diploma-num', function () {
                    row.hours = hours.value;
                    if (!row.creditsManual) {
                        row.credits = creditsFromHours(row.hours);
                        var creditsField = tr.querySelector('.diploma-credits');
                        if (creditsField) {
                            creditsField.value = row.credits;
                        }
                    }
                    sync();
                });
                hours.disabled = row.kind === 'section';
                hoursCell.appendChild(hours);
                tr.appendChild(hoursCell);

                var creditsCell = document.createElement('td');
                var credits = input(row.credits, 'diploma-num diploma-credits', function () {
                    row.credits = credits.value;
                    row.creditsManual = true;
                    sync();
                });
                credits.disabled = row.kind === 'section';
                creditsCell.appendChild(credits);
                tr.appendChild(creditsCell);

                if (mode === 'supplement') {
                    var scoreCell = document.createElement('td');
                    var score = input(row.score, 'diploma-num', function () {
                        row.score = score.value;
                        var band = gradeFromScore(row.score, scale);
                        if (band) {
                            row.letter = band.letter;
                            row.gpa = Number(band.gpa).toFixed(2);
                            row.grade_text = band.text;
                            tr.querySelector('.diploma-letter').value = row.letter;
                            tr.querySelector('.diploma-gpa').value = row.gpa;
                            tr.querySelector('.diploma-text').value = row.grade_text;
                        }
                        sync();
                    });
                    score.disabled = row.kind !== 'grade';
                    scoreCell.appendChild(score);
                    tr.appendChild(scoreCell);

                    var letterCell = document.createElement('td');
                    var letter = input(row.letter, 'diploma-num diploma-letter', function () {
                        row.letter = letter.value;
                        sync();
                    });
                    letter.disabled = row.kind !== 'grade';
                    letterCell.appendChild(letter);
                    tr.appendChild(letterCell);

                    var gpaCell = document.createElement('td');
                    var gpa = input(row.gpa, 'diploma-num diploma-gpa', function () {
                        row.gpa = gpa.value;
                        sync();
                    });
                    gpa.disabled = row.kind !== 'grade';
                    gpaCell.appendChild(gpa);
                    tr.appendChild(gpaCell);

                    var textCell = document.createElement('td');
                    var text = input(row.kind === 'pass' ? 'зачет' : row.grade_text, 'diploma-text', function () {
                        row.grade_text = text.value;
                        sync();
                    });
                    text.disabled = row.kind !== 'grade';
                    textCell.appendChild(text);
                    tr.appendChild(textCell);
                }

                var actions = document.createElement('td');
                actions.className = 'diploma-actions';
                actions.appendChild(iconButton('bi-arrow-up', 'Выше', index === 0, function () {
                    if (index === 0) return;
                    var current = rows[index];
                    rows[index] = rows[index - 1];
                    rows[index - 1] = current;
                    render();
                }));
                actions.appendChild(iconButton('bi-arrow-down', 'Ниже', index === rows.length - 1, function () {
                    if (index === rows.length - 1) return;
                    var current = rows[index];
                    rows[index] = rows[index + 1];
                    rows[index + 1] = current;
                    render();
                }));
                actions.appendChild(iconButton('bi-trash', 'Удалить', false, function () {
                    rows.splice(index, 1);
                    render();
                }));
                tr.appendChild(actions);
                tbody.appendChild(tr);
            });
            sync();
        }

        function iconButton(icon, title, disabled, onClick) {
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-icon btn-sm btn-outline';
            button.title = title;
            button.disabled = disabled;
            button.innerHTML = '<i class="bi ' + icon + '"></i>';
            button.addEventListener('click', onClick);
            return button;
        }

        render();

        return {
            add: function (kind) {
                rows.push(emptyRow(kind));
                render();
                var fields = tbody.querySelectorAll('.diploma-name');
                if (fields.length) {
                    fields[fields.length - 1].focus();
                }
            },
            replace: function (nextRows) {
                rows = (nextRows || []).map(function (row) {
                    var copy = emptyRow(row.kind || 'grade');
                    copy.name = row.name || '';
                    copy.hours = row.hours || '';
                    copy.credits = row.credits || '';
                    copy.grade_text = row.kind === 'pass' ? 'зачет' : '';
                    return copy;
                });
                render();
            },
            count: function () {
                return rows.length;
            }
        };
    }

    window.DiplomaRows = { init: init };
})();
