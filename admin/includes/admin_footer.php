        </div>
    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?php appThemeScript(); ?>
    <script src="../assets/js/tooltips.js"></script>
    <script src="assets/js/admin-select.js"></script>
    <script>
        // Sidebar Toggle
        function toggleSidebar() {
            document.querySelector('.admin-sidebar').classList.toggle('open');
            document.querySelector('.sidebar-overlay').classList.toggle('show');
        }
        
        // Dropdown Toggle
        function toggleDropdown(element) {
            const menu = element.nextElementSibling;
            document.querySelectorAll('.dropdown-menu').forEach(m => {
                if (m !== menu) m.classList.remove('show');
            });
            menu.classList.toggle('show');
        }
        
        // Close dropdown on click outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown')) {
                document.querySelectorAll('.dropdown-menu').forEach(m => m.classList.remove('show'));
            }
        });
        
        // Close sidebar on escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                document.querySelector('.admin-sidebar').classList.remove('open');
                document.querySelector('.sidebar-overlay').classList.remove('show');
            }
        });
    </script>
</body>
</html>
