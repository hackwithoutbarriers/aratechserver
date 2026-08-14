<?php
declare(strict_types=1);
?>
        <button id="backToTop" title="Retour en haut" aria-label="Retour en haut"><i class="bi bi-arrow-up"></i></button>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
 const sidebar=document.getElementById('sidebar'),backdrop=document.getElementById('sidebarBackdrop'),toggle=document.getElementById('sidebarToggle'),btn=document.getElementById('backToTop');
 toggle?.addEventListener('click',()=>{sidebar?.classList.toggle('open');backdrop?.classList.toggle('open');});
 backdrop?.addEventListener('click',()=>{sidebar?.classList.remove('open');backdrop?.classList.remove('open');});
 window.addEventListener('scroll',()=>{if(btn)btn.style.display=window.scrollY>300?'block':'none';});
 btn?.addEventListener('click',()=>window.scrollTo({top:0,behavior:'smooth'}));
});
</script></body></html>
