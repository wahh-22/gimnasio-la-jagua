<?php
declare(strict_types=1);
require __DIR__.'/../app/Schema.php';
date_default_timezone_set('America/Bogota');
$root = dirname(__DIR__);
$run = $root.'/var/test-seed-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run, 0700, true);
$checks = 0;
function check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) throw new RuntimeException('FAIL '.$label);
    ++$checks; echo 'OK '.$label.PHP_EOL;
}
function fixture(string $name): array {
    global $run;
    $dsn = 'sqlite:'.$run.'/'.$name.'.sqlite';
    $db = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON'); Schema::create($db);
    // Existing unrelated data; deliberately not a usable login/password hash.
    $db->exec("INSERT INTO users(email,password_hash,role) VALUES ('fixture@example.invalid','NOT-A-LOGIN-HASH','admin')");
    $db->exec("INSERT INTO clients(code,name,contact) VALUES ('REAL-FIXTURE','Unrelated fixture','unchanged')");
    $db->exec("INSERT INTO plans(name,days,amount) VALUES ('Unrelated plan',30,100)");
    $db->exec("INSERT INTO memberships(client_id,plan_id,start_date,end_date,amount) VALUES (1,1,'2020-01-01','2020-01-30',100)");
    $db->exec("INSERT INTO consents(client_id,kind,version,purpose,evidence,granted_at) VALUES (1,'administrativa','fixture','unchanged','unchanged','2020-01-01 00:00:00')");
    $db->exec("INSERT INTO requests(client_id,kind,received_at) VALUES (1,'consulta','2020-01-01 00:00:00')");
    $db->exec("INSERT INTO measurements(client_id,consent_id,user_id,measured_on,kind,value,unit) VALUES (1,1,1,'2020-01-01','peso',70,'kg')");
    $db->exec("INSERT INTO audit(user_id,happened_at,action,entity,result) VALUES (1,'2020-01-01 00:00:00','fixture','clients','ok')");
    return [$db, $dsn];
}
function snapshot(PDO $db): array {
    $rows = [];
    foreach (['users','clients','plans','memberships','consents','requests','measurements','audit'] as $table) $rows[$table] = $db->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll();
    return $rows;
}
function seed(string $dsn): array {
    global $root;
    $p = proc_open([PHP_BINARY, $root.'/bin/seed-demo.php'], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, $root, ['APP_DSN'=>$dsn]);
    if (!is_resource($p)) throw new RuntimeException('Cannot run seed');
    fclose($pipes[0]); $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]); return [proc_close($p), $out, $err];
}
[$db,$dsn] = fixture('initial'); $before = snapshot($db);
[$code,$out,$err] = seed($dsn);
check($code === 0 && str_contains($out, 'DEMO insertado') && $err === '', 'initial CLI run');
$after = snapshot($db);
foreach (['clients'=>8,'plans'=>3,'memberships'=>8,'consents'=>16,'requests'=>3,'users'=>0,'measurements'=>0,'audit'=>0] as $table=>$added) {
    check(count($after[$table])-count($before[$table]) === $added, 'exact added count '.$table.'='.$added);
    check(array_slice($after[$table], 0, count($before[$table])) === $before[$table], 'preserves existing '.$table);
}
check(count(array_filter($after['clients'], fn($r)=>str_starts_with($r['code'],'DEMO-T2-') && str_contains($r['name'],'ficticia'))) === 8, 'clearly fictional labels');
$today = new DateTimeImmutable('today'); $bands = ['past'=>0,'soon'=>0,'later'=>0];
foreach (array_slice($after['memberships'],1) as $r) {
    $end = new DateTimeImmutable($r['end_date']);
    ++$bands[$end<$today ? 'past' : ($end<=$today->modify('+7 days') ? 'soon' : 'later')];
}
check($bands === ['past'=>2,'soon'=>3,'later'=>3], 'relative expiry boundaries yesterday/today/+7/+8');
check(count(array_filter(array_slice($after['requests'],1), fn($r)=>$r['status']==='resuelta')) === 1, 'pending and resolved synthetic requests');
[$code,$out] = seed($dsn);
check($code === 0 && str_contains($out,'no-op') && snapshot($db) === $after, 'rerun is exact no-op, no duplicate or date updates');
// Simulate a batch loaded earlier: subsequent invocations must use its original anchor.
foreach (['memberships'=>['start_date','end_date'],'consents'=>['granted_at'],'requests'=>['received_at','resolved_at']] as $table=>$columns) {
    foreach ($columns as $column) {
        $format = $table === 'memberships' ? 'date' : 'datetime';
        $db->exec("UPDATE $table SET $column=$format($column, '-2 days') WHERE client_id<>1");
    }
}
$aged = snapshot($db); [$code,$out] = seed($dsn);
check($code === 0 && str_contains($out,'no-op') && snapshot($db) === $aged, 'old batch retains original dates');
// Archival is an operator change, not an incomplete seed to repair or recreate.
// Each case gets a complete independent batch; rejection must preserve its exact snapshot.
foreach (['clients','plans','memberships'] as $table) {
    [$archivedDb,$archivedDsn] = fixture('archived-'.$table);
    [$code] = seed($archivedDsn);
    check($code === 0, 'archive fixture initial seed '.$table);
    $column = $table === 'clients' ? 'active' : 'archived';
    $value = $table === 'clients' ? 0 : 1;
    $archivedDb->exec("UPDATE $table SET $column=$value WHERE id=2");
    $archived = snapshot($archivedDb);
    [$code,$out,$err] = seed($archivedDsn);
    check($code !== 0 && $out === '' && snapshot($archivedDb) === $archived, 'archived '.$table.' fails closed without unarchiving, rewriting or recreating rows');
}
$db->exec("UPDATE clients SET contact='modified demo' WHERE code='DEMO-T2-03'");
$changed = snapshot($db); [$code] = seed($dsn);
check($code !== 0 && snapshot($db) === $changed, 'altered complete batch fails closed, no repair');
foreach (['client','plan','late-failure'] as $case) {
    [$db,$dsn] = fixture($case);
    if ($case === 'client') $db->exec("INSERT INTO clients(code,name) VALUES ('demo-t2-01','Unrelated collision')");
    if ($case === 'plan') $db->exec("INSERT INTO plans(name,days,amount) VALUES ('DEMO T2 Mensual',30,1)");
    if ($case === 'late-failure') $db->exec("CREATE TRIGGER reject_demo BEFORE INSERT ON requests WHEN NEW.client_id<>1 BEGIN SELECT RAISE(ABORT, 'fixture late failure'); END");
    $before = snapshot($db); [$code,$out,$err] = seed($dsn);
    check($code !== 0 && $out === '' && snapshot($db) === $before, $case.' failure leaves all tables unchanged (rollback)');
    check(!str_contains($err,'fixture late failure') && !str_contains($err,$dsn), 'failure does not expose SQL, DSN or exception');
}
[$code] = seed(''); check($code !== 0, 'missing runtime DSN fails closed');
[$code] = seed('mysql:host=remote.invalid;dbname=fixture'); check($code !== 0, 'remote DSN rejected before connection');
echo "PASS $checks checks; local SQLite only; fixtures: $run".PHP_EOL;
