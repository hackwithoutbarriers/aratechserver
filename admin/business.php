<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/components/embedded-page.php';
$config = require __DIR__ . '/../config.php';
$pageTitle = 'Business — ARA Tech WiFi';
$tab = $_GET['tab'] ?? 'overview';
$tabs = ['overview'=>'Vue d’ensemble','finances'=>'Finances','reports'=>'Rapports','ads'=>'Annonces'];
if (!isset($tabs[$tab])) $tab='overview';
$periodKey = $_GET['period'] ?? 'thismonth';
if (!in_array($periodKey,['today','7days','thismonth','lastmonth'],true)) $periodKey='thismonth';
require __DIR__ . '/header.php';
?>
<div class="container-fluid px-3 px-md-4 py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div><h2 class="mb-1">Gestion Business</h2><p class="text-muted mb-0">Command Center pour le pilotage commercial et financier.</p></div>
    </div>
    <ul class="nav nav-tabs mb-4">
        <?php foreach ($tabs as $key=>$label): ?>
            <li class="nav-item"><a class="nav-link <?= $tab===$key?'active':'' ?>" href="business.php?tab=<?= urlencode($key) ?>"><?= htmlspecialchars($label,ENT_QUOTES,'UTF-8') ?></a></li>
        <?php endforeach; ?>
    </ul>
    <?php if ($tab==='overview'): ?>
        <?php require __DIR__ . '/partials/business/overview-v2.php'; ?>
    <?php elseif ($tab==='finances'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/finances.php'); ?>
    <?php elseif ($tab==='reports'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/reports.php'); ?>
    <?php elseif ($tab==='ads'): ?>
        <?php ara_render_embedded_page(__DIR__ . '/partials/business/ads.php'); ?>
    <?php endif; ?>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    const tab=<?= json_encode($tab) ?>;
    document.querySelectorAll('form').forEach(function(form){
        if(!form.querySelector('input[name="tab"]')){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='tab';hidden.value=tab;form.appendChild(hidden);}
    });
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
