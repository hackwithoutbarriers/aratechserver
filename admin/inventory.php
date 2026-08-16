<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

// Legacy URL: the canonical stock/import workflow is now /admin/stock.php
// and is exposed through Operations > Stock & import.
header('Location: operations.php?tab=inventory');
exit;
