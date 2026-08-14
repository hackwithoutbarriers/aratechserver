<?php
declare(strict_types=1);
?>
        <button id="backToTop" title="Retour en haut" aria-label="Retour en haut"><i class="bi bi-arrow-up"></i></button>
    </div><!-- /.main-content -->
</div><!-- /.app-shell -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    const toggle = document.getElementById('sidebarToggle');
    const backToTopBtn = document.getElementById('backToTop');
    toggle?.addEventListener('click', function () {
        sidebar?.classList.toggle('open');
        backdrop?.classList.toggle('open');
    });
    backdrop?.addEventListener('click', function () {
        sidebar?.classList.remove('open');
        backdrop?.classList.remove('open');
    });
    window.addEventListener('scroll', function () {
        if (backToTopBtn) backToTopBtn.style.display = window.scrollY > 300 ? 'block' : 'none';
    });
    backToTopBtn?.addEventListener('click', function () {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>
</body>
</html>
