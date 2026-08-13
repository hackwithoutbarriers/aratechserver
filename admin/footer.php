        </main>

        <div class="app-footer">© <?= date('Y') ?> ARA Tech WiFi</div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<?php if (!empty($footerExtra ?? '')): ?>
    <?= $footerExtra ?>
<?php endif; ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggle = document.getElementById('sidebarToggle');
    if (!sidebar || !backdrop || !toggle) return;

    const setMenu = (open) => {
        sidebar.classList.toggle('open', open);
        backdrop.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    };

    toggle.addEventListener('click', () => setMenu(!sidebar.classList.contains('open')));
    backdrop.addEventListener('click', () => setMenu(false));
});
</script>
</body>
</html>
