<?php
declare(strict_types=1);
require_once __DIR__ . '/../lib/RouterosAPI.php';
require_once __DIR__ . '/../lib/voucher.php';
$config = require __DIR__ . '/../config.php';

$voucher = new Voucher($config['mikrotik']);
if (!$voucher->isConnected()) {
    echo '<div class="alert alert-danger">Connexion au routeur impossible.</div>';
    return;
}

$comment = $_GET['comment'] ?? '';
$action  = $_GET['act'] ?? 'list';
$users = [];
if ($comment !== '') {
    $users = $voucher->getUsersByComment($comment);
}

if ($action === 'print' && !empty($users)) {
    $profile = $voucher->getProfileForUser($users[0]);
    ?>
    <!DOCTYPE html>
    <html lang="fr">
    <head>
        <meta charset="UTF-8">
        <title>Impression des vouchers</title>
        <script src="https://cdn.jsdelivr.net/npm/qrious@4.0.2/dist/qrious.min.js"></script>
        <style>body { font-family: 'Helvetica', Arial, sans-serif; margin: 0; } @media print { table { page-break-after: auto; } tr { page-break-inside: avoid; } } table.voucher { display: inline-block; border: 2px solid black; margin: 2px; } .qrcode { height:80px; width:80px; }</style>
    </head>
    <body onload="window.print()">
        <?php foreach ($users as $i => $user):
            $data = $voucher->prepareVoucherData($user, $profile);
            $num = $i + 1;
            include __DIR__ . '/../vouchers/template.php';
        endforeach; ?>
    </body>
    </html>
    <?php
    exit;
}

$voucher->disconnect();
?>

<div class="card card-custom">
    <div class="card-header bg-dark text-white"><h3><i class="bi bi-ticket-perforated"></i> Vouchers</h3></div>
    <div class="card-body">
        <form method="get" class="row g-2 align-items-end">
            <input type="hidden" name="tab" value="vouchers">
            <div class="col-md-4">
                <label class="form-label">Code commentaire (ex: vc-xxxx)</label>
                <input type="text" class="form-control" name="comment" value="<?= htmlspecialchars($comment) ?>" required>
            </div>
            <div class="col-md-2"><button type="submit" class="btn btn-orange w-100"><i class="bi bi-search"></i> Afficher</button></div>
            <?php if (!empty($users)): ?>
            <div class="col-md-3"><a href="hotspot.php?tab=vouchers&comment=<?= urlencode($comment) ?>&act=print" class="btn btn-primary w-100" target="_blank"><i class="bi bi-printer"></i> Imprimer tout</a></div>
            <?php endif; ?>
        </form>

        <?php if ($comment !== '' && empty($users)): ?>
            <div class="alert alert-info mt-3">Aucun voucher trouvé avec ce commentaire (ou déjà utilisés).</div>
        <?php elseif (!empty($users)): ?>
            <div class="mt-3">
                <p><?= count($users) ?> voucher(s) trouvé(s).</p>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead><tr><th>Utilisateur</th><th>Profil</th><th>Mot de passe</th><th>Commentaire</th></tr></thead>
                        <tbody>
                        <?php foreach ($users as $u):
                            $password = (string)($u['password'] ?? '');
                            $masked = $password === '' ? '' : str_repeat('•', min(12, max(6, strlen($password))));
                        ?>
                            <tr>
                                <td><?= htmlspecialchars($u['name'] ?? '') ?></td>
                                <td><?= htmlspecialchars($u['profile'] ?? '') ?></td>
                                <td>
                                    <span class="voucher-password" data-password="<?= htmlspecialchars($password, ENT_QUOTES) ?>"><?= htmlspecialchars($masked) ?></span>
                                    <?php if ($password !== ''): ?><button type="button" class="btn btn-sm btn-outline-secondary ms-1 toggle-password" aria-label="Afficher le mot de passe">Afficher</button><?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($u['comment'] ?? '') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
document.querySelectorAll('.toggle-password').forEach(function (button) {
    button.addEventListener('click', function () {
        const target = button.previousElementSibling;
        const visible = target.dataset.visible === '1';
        target.textContent = visible ? '••••••' : target.dataset.password;
        target.dataset.visible = visible ? '0' : '1';
        button.textContent = visible ? 'Afficher' : 'Masquer';
    });
});
</script>
