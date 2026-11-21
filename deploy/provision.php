<?php
/** Root CLI only. Never print credentials, SQL or exception messages. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) exit(1);
umask(0077);
require '/srv/gimnasio/app/bootstrap.php';
const STATE = '/etc/gimnasio/state.json';
function saveState(array $state): void {
    // A truncated state after a power failure fails closed; do not regenerate secrets.
    if (file_put_contents(STATE, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new RuntimeException();
    chmod(STATE, 0600);
}
function runtime(array $s): PDO {
    return new PDO('mysql:host=127.0.0.1;dbname=gimnasio;charset=utf8mb4', 'gimnasio_app', $s['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
}
try {
    if (count($argv)>2 || !in_array($argv[1]??'', ['', '--check', '--migrate-actions'],true)) throw new RuntimeException();
    $check = ($argv[1] ?? '') === '--check';
    $migrate = ($argv[1] ?? '') === '--migrate-actions';
    $root = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    if (!preg_match('/^8\./', (string)$root->query('SELECT VERSION()')->fetchColumn())) throw new RuntimeException();
    $exists = (bool)$root->query("SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='gimnasio'")->fetchColumn();
    $accounts = $root->query("SELECT Host FROM mysql.user WHERE User='gimnasio_app'")->fetchAll(PDO::FETCH_COLUMN);
    if (!file_exists(STATE)) {
        if ($check || $migrate || $exists || $accounts) throw new RuntimeException();
        $s = ['phase'=>'allocated', 'db_password'=>bin2hex(random_bytes(32)),
            'admin_email'=>'admin@sintetico.invalid', 'admin_password'=>bin2hex(random_bytes(24))];
        saveState($s);
    } else {
        if (is_link(STATE) || (fileperms(STATE) & 0077) !== 0 || fileowner(STATE) !== 0) throw new RuntimeException();
        $s = json_decode(file_get_contents(STATE), true, 512, JSON_THROW_ON_ERROR);
        if (!in_array($s['phase'] ?? '', ['allocated','ready'], true)
            || !preg_match('/^[a-f0-9]{64}$/D', $s['db_password'] ?? '')
            || !preg_match('/^[a-f0-9]{48}$/D', $s['admin_password'] ?? '')
            || ($s['admin_email'] ?? '') !== 'admin@sintetico.invalid') throw new RuntimeException();
    }
    $grants = [
        'users'=>['SELECT','INSERT','UPDATE'], 'clients'=>['SELECT','INSERT','UPDATE'],
        'consents'=>['SELECT','INSERT','UPDATE'], 'requests'=>['SELECT','INSERT','UPDATE'],
        'plans'=>['SELECT','INSERT','UPDATE'], 'memberships'=>['SELECT','INSERT','UPDATE'],
        'measurements'=>['SELECT','INSERT'], 'audit'=>['SELECT','INSERT'],
    ];
    if ($s['phase'] === 'allocated') {
        if ($check || $migrate) throw new RuntimeException();
        if (!$exists) $root->exec('CREATE DATABASE gimnasio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $root->exec('USE gimnasio');
        // Setup is NOT a migration. Partial DDL must be reviewed by the operator.
        if ($root->query('SHOW TABLES')->fetchAll()) throw new RuntimeException();
        if ($accounts && $accounts !== ['127.0.0.1']) throw new RuntimeException();
        if (!$accounts) $root->exec("CREATE USER 'gimnasio_app'@'127.0.0.1' IDENTIFIED BY ".$root->quote($s['db_password']));
        // No database privilege exists yet; authenticate without selecting a schema.
        new PDO('mysql:host=127.0.0.1;charset=utf8mb4', 'gimnasio_app', $s['db_password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
        Schema::create($root);
        $m = new Model($root);
        $m->transaction(function() use ($m, $s) {
            $id = $m->insert('users', ['email'=>$s['admin_email'], 'password_hash'=>password_hash($s['admin_password'], PASSWORD_DEFAULT), 'role'=>'admin']);
            $m->insert('plans', ['name'=>'Mensual sintético','days'=>30,'amount'=>50000]);
            $m->audit($id, 'alta', 'users', $id);
        });
        foreach ($grants as $table=>$permissions) {
            $root->exec('GRANT '.implode(',', $permissions)." ON gimnasio.`$table` TO 'gimnasio_app'@'127.0.0.1'");
        }
        $s['phase'] = 'ready';
        saveState($s);
    }
    if (!$exists && $check) throw new RuntimeException();
    // Detect privilege drift, including global/schema/routine/column grants and extra hosts.
    if ($root->query("SELECT Host FROM mysql.user WHERE User='gimnasio_app'")->fetchAll(PDO::FETCH_COLUMN) !== ['127.0.0.1']) throw new RuntimeException();
    $expected = ["GRANT USAGE ON *.* TO `gimnasio_app`@`127.0.0.1`"];
    foreach ($grants as $table=>$permissions) $expected[] = 'GRANT '.implode(', ', $permissions)." ON `gimnasio`.`$table` TO `gimnasio_app`@`127.0.0.1`";
    $actual = $root->query("SHOW GRANTS FOR 'gimnasio_app'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN);
    // Only the two explicitly reviewed table grants may change during migration.
    // Accept old or new grants independently so an interrupted GRANT is safely resumable.
    if ($migrate) {
        foreach (['plans','memberships'] as $table) {
            $legacy="GRANT SELECT, INSERT ON `gimnasio`.`$table` TO `gimnasio_app`@`127.0.0.1`";
            $upgraded="GRANT SELECT, INSERT, UPDATE ON `gimnasio`.`$table` TO `gimnasio_app`@`127.0.0.1`";
            $actual=array_map(static fn($grant)=>$grant===$legacy?$upgraded:$grant,$actual);
        }
    }
    sort($expected); sort($actual);
    if ($expected !== $actual) throw new RuntimeException();
    if ($migrate) {
        $root->exec('USE gimnasio');
        // Validate prerequisites before additive DDL (MySQL commits each ALTER implicitly).
        $root->query('SELECT active FROM clients LIMIT 0');
        $root->query('SELECT id,name,days,amount FROM plans LIMIT 0');
        $root->query('SELECT id,client_id,plan_id,start_date,end_date,amount FROM memberships LIMIT 0');
        Schema::migrateRowActions($root);
        foreach (['plans','memberships'] as $table) $root->exec("GRANT SELECT, INSERT, UPDATE ON gimnasio.`$table` TO 'gimnasio_app'@'127.0.0.1'");
        $verified=$root->query("SHOW GRANTS FOR 'gimnasio_app'@'127.0.0.1'")->fetchAll(PDO::FETCH_COLUMN);
        sort($verified);
        if ($expected!==$verified) throw new RuntimeException();
    }
    $db = runtime($s);
    $db->query('SELECT archived FROM plans LIMIT 0');
    $db->query('SELECT archived FROM memberships LIMIT 0');
    foreach ($grants as $table=>$permissions) $db->query("SELECT * FROM `$table` LIMIT 0");
    if (!$db->query("SELECT COUNT(*) FROM users WHERE role='admin' AND active=1")->fetchColumn()) throw new RuntimeException();
    if (!$check && !$migrate) {
        $config = "SetEnv APP_DSN mysql:host=127.0.0.1;dbname=gimnasio;charset=utf8mb4\nSetEnv APP_DB_USER gimnasio_app\nSetEnv APP_DB_PASSWORD ".$s['db_password']."\nSetEnv APP_SESSION_PATH /var/lib/gimnasio/sessions\n";
        if (file_put_contents('/etc/gimnasio/apache-env.conf', $config, LOCK_EX) === false) throw new RuntimeException();
        chmod('/etc/gimnasio/apache-env.conf', 0600);
    }
    echo "Database and least-privilege account verified. Credentials remain root-only in /etc/gimnasio/state.json.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Provisioning stopped. Inspect state/ownership/schema locally as root; no reset or credential rotation was attempted. Do not delete state or rerun bin/setup.php on partial tables.\n");
    exit(1);
}
