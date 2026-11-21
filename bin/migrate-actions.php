<?php
/** Operator-only additive schema upgrade. No seed, delete, update of business rows, or grant changes. */
declare(strict_types=1);
if (PHP_SAPI!=='cli' || ($argv[1]??'')!=='--apply' || count($argv)!==2) {
    fwrite(STDERR,"Usage: php bin/migrate-actions.php --apply (explicit APP_DSN required)\n"); exit(1);
}
require __DIR__.'/../app/bootstrap.php';
if (!getenv('APP_DSN')) { fwrite(STDERR,"Explicit APP_DSN required; no default database selected.\n"); exit(1); }
try {
    $db=database();
    $db->query('SELECT active FROM clients LIMIT 0');
    Schema::migrateRowActions($db);
    echo "Additive row-action schema verified. No business rows or grants changed.\n";
} catch (Throwable $e) {
    fwrite(STDERR,"Migration stopped; review schema locally. No reset attempted. MySQL ALTER statements commit individually; rerun after review.\n"); exit(1);
}
