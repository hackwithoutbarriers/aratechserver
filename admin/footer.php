<?php
declare(strict_types=1);
/**
 * admin/includes/footer.php
 * -----------------------------------------------------------------------
 * Referme exactement ce que includes/header.php a ouvert :
 *   <div class="container-fluid ..."> (contenu page)
 *   <div class="main-content">
 *   <div class="app-shell">
 * puis charge les scripts communs à toutes les pages admin.
 *
 * Une page qui a besoin de JS supplémentaire (graphiques, formulaires...)
 * place son propre <script> juste AVANT d'inclure footer.php (donc à
 * l'intérieur de <body>). footer.php ferme </body></html> : un script
 * placé après l'include se retrouverait hors structure HTML. L'ordre des
 * scripts n'est pas un problème : Bootstrap bundle est chargé en fin de
 * <body> par ce fichier, mais tous les scripts de page ici ne font que de
 * la manipulation DOM différée (addEventListener('submit'/'click', ...)),
 * jamais un appel direct à `bootstrap.*` au chargement.
 * -----------------------------------------------------------------------
 */
?>
        </div><!-- /.container-fluid -->
    </div><!-- /.main-content -->
</div><!-- /.app-shell -->

<button id="backToTop" title="Retour en haut"><i class="bi bi-arrow-up"></i></button>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    // --- Sidebar responsive (mobile) ---
    const sidebar = document.getElementById('sidebar');
    const backdrop = document.getElementById('sidebarBackdrop');
    document.getElementById('sidebarToggle')?.addEventListener('click', function () {
        sidebar.classList.toggle('open');
        backdrop.classList.toggle('open');
    });
    backdrop?.addEventListener('click', function () {
        sidebar.classList.remove('open');
        backdrop.classList.remove('open');
    });

    // --- Bouton retour en haut ---
    const backToTopBtn = document.getElementById('backToTop');
    window.addEventListener('scroll', () => {
        backToTopBtn.style.display = window.scrollY > 300 ? 'block' : 'none';
    });
    backToTopBtn.addEventListener('click', () => {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    });
});
</script>
</body>
</html>
