<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Vouchers are not a separate operational resource anymore. They are the
// users/tickets imported from Mikhmon and are managed by the Stock workspace.
header('Location: operations.php?tab=inventory');
exit;
