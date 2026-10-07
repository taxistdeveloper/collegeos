        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php appThemeScript(); ?>
<script src="../assets/js/main.js"></script>
<script src="../curator/assets/js/curator-ui.js"></script>
<script>
function initStudentSearch(inputId, hiddenId, resultsId) {
    const input = document.getElementById(inputId);
    const hidden = document.getElementById(hiddenId);
    const results = document.getElementById(resultsId);
    if (!input || !hidden || !results) return;

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
            fetch('api/search_students.php?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !data.students.length) {
                        results.innerHTML = '<div class="list-group-item text-muted small">Не найдено</div>';
                        results.classList.remove('d-none');
                        return;
                    }
                    results.innerHTML = data.students.map(s => {
                        const fio = [s.last_name, s.first_name, s.middle_name].filter(Boolean).join(' ');
                        const meta = [s.group_name, s.iin].filter(Boolean).join(' · ');
                        return '<button type="button" class="list-group-item list-group-item-action text-start" data-id="' + s.id + '" data-fio="' + fio.replace(/"/g, '&quot;') + '">' +
                            '<strong>' + fio + '</strong><br><small class="text-muted">' + meta + '</small></button>';
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
