<?php
/** Synthetic, additive data only. Never run setup or print PDO exceptions here. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('America/Bogota');

function demoRows(DateTimeImmutable $base, array $clients, array $plans): array {
    $date = fn(int $days): string => $base->modify("$days days")->format('Y-m-d');
    $rows = ['clients'=>[], 'plans'=>[], 'memberships'=>[], 'consents'=>[], 'requests'=>[]];
    foreach ([['Mensual',30,50000],['Trimestral',90,135000],['Semanal',7,18000]] as $i=>$plan) {
        $rows['plans'][] = ['name'=>'DEMO T2 '.$plan[0], 'days'=>$plan[1], 'amount'=>$plan[2], 'archived'=>0];
    }
    foreach ([-12,-1,0,3,7,8,30,60] as $i=>$offset) {
        $n = $i+1; $client = $clients[$i] ?? 0; $plan = $plans[$i%3] ?? 0;
        $rows['clients'][] = ['code'=>sprintf('DEMO-T2-%02d', $n), 'name'=>sprintf('DEMO Persona ficticia %02d', $n), 'contact'=>'FICTICIO; sin contacto real', 'active'=>1];
        $rows['memberships'][] = ['client_id'=>$client,'plan_id'=>$plan,'start_date'=>$date($offset-[30,90,7][$i%3]+1),'end_date'=>$date($offset),'amount'=>[50000,135000,18000][$i%3],'archived'=>0];
        foreach (['administrativa','corporal'] as $kind) {
            $rows['consents'][] = ['client_id'=>$client,'kind'=>$kind,'version'=>'DEMO-T2-v1','purpose'=>'DEMO: simulación ficticia, no autorización real','evidence'=>'DEMO-T2: evidencia sintética sin validez legal','granted_at'=>$date(0).' 09:00:00','withdrawn_at'=>null];
        }
        if ($i < 3) {
            $resolved = $i === 1;
            $rows['requests'][] = ['client_id'=>$client,'kind'=>['consulta','correccion','supresion'][$i],'received_at'=>$date(0).' 10:00:00','status'=>$resolved ? 'resuelta' : 'pendiente','resolved_at'=>$resolved ? $date(0).' 11:00:00' : null,'resolution'=>$resolved ? 'DEMO: resolución ficticia sin efecto real' : null];
        }
    }
    return $rows;
}

function demoSelect(PDO $db, string $sql, array $args = []): array {
    $q = $db->prepare($sql); $q->execute($args); return $q->fetchAll(PDO::FETCH_ASSOC);
}

function demoSame(array $actual, array $expected): bool {
    // PDO MySQL returns numeric columns as strings; normalize numeric fields only.
    // Keep archive state in the exact comparison: archived fixtures fail closed, never repair.
    $normalize = function(array $rows): array {
        foreach ($rows as &$row) {
            unset($row['id']);
            foreach (['days','amount','active','archived','client_id','plan_id'] as $key) {
                if (isset($row[$key])) $row[$key] = (string)(0+$row[$key]);
            }
            ksort($row);
            $row = json_encode($row, JSON_THROW_ON_ERROR);
        }
        unset($row); sort($rows); return $rows;
    };
    return $normalize($actual) === $normalize($expected);
}

$db = null; $transaction = false; $locked = false; $sqlite = false;
try {
    if (count($argv) !== 1) throw new RuntimeException('arguments');
    $dsn = getenv('APP_DSN');
    if (!$dsn || (!str_starts_with($dsn, 'sqlite:') && !preg_match('~^mysql:(?:host=(?:127\.0\.0\.1|localhost)(?:;port=[0-9]+)?|unix_socket=/[^;]+);dbname=[A-Za-z0-9_]+(?:;charset=utf8mb4)?$~D', $dsn))) throw new RuntimeException('runtime');
    $db = new PDO($dsn, getenv('APP_DB_USER') ?: '', getenv('APP_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $sqlite = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    if ($sqlite) {
        $db->exec('PRAGMA foreign_keys=ON');
        $db->exec('PRAGMA busy_timeout=5000');
        $db->exec('BEGIN IMMEDIATE');
    } else {
        // All seed writers use this database-scoped advisory lock; no schema changes.
        $locked = (int)$db->query("SELECT GET_LOCK(CONCAT('demo-t2:', DATABASE()), 5)")->fetchColumn() === 1;
        if (!$locked) throw new RuntimeException('lock');
        $engines = $db->query("SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('clients','plans','memberships','consents','requests')")->fetchAll();
        if (count($engines) !== 5) throw new RuntimeException('schema');
        foreach ($engines as $table) if (strtoupper($table['ENGINE']) !== 'INNODB') throw new RuntimeException('engine');
        $db->beginTransaction();
    }
    $transaction = true;
    $clients = demoSelect($db, "SELECT * FROM clients WHERE UPPER(code) LIKE 'DEMO-T2-%' ORDER BY code");
    $plans = demoSelect($db, "SELECT * FROM plans WHERE UPPER(name) LIKE 'DEMO T2 %' ORDER BY id");
    $existing = (bool)($clients || $plans);
    $base = new DateTimeImmutable('today');
    if ($existing) {
        if (count($clients) !== 8 || count($plans) !== 3) throw new RuntimeException('collision');
        // Recover the original date, never slide dates or repair an altered/partial batch.
        $anchor = demoSelect($db, "SELECT granted_at FROM consents WHERE client_id=? AND kind='administrativa' AND version='DEMO-T2-v1'", [$clients[0]['id']]);
        if (count($anchor) !== 1) throw new RuntimeException('collision');
        $base = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $anchor[0]['granted_at']);
        if (!$base || $base->format('Y-m-d H:i:s') !== $anchor[0]['granted_at']) throw new RuntimeException('collision');
        $expected = demoRows($base, array_column($clients, 'id'), array_column($plans, 'id'));
        foreach (['clients','plans'] as $table) if (!demoSame($$table, $expected[$table])) throw new RuntimeException('collision');
        $ids = array_column($clients, 'id'); $marks = implode(',', array_fill(0, count($ids), '?'));
        foreach (['memberships','consents','requests'] as $table) {
            if (!demoSame(demoSelect($db, "SELECT * FROM $table WHERE client_id IN ($marks)", $ids), $expected[$table])) throw new RuntimeException('collision');
        }
        if (demoSelect($db, "SELECT id FROM measurements WHERE client_id IN ($marks)", $ids)) throw new RuntimeException('collision');
    } else {
        $expected = demoRows($base, [], []);
        $ids = ['clients'=>[], 'plans'=>[]];
        $insert = function(string $table, array $row) use ($db): int {
            $sql = 'INSERT INTO '.$table.' ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')';
            $db->prepare($sql)->execute(array_values($row));
            return (int)$db->lastInsertId();
        };
        foreach (['clients','plans'] as $table) foreach ($expected[$table] as $row) $ids[$table][] = $insert($table, $row);
        $expected = demoRows($base, $ids['clients'], $ids['plans']);
        foreach (['memberships','consents','requests'] as $table) foreach ($expected[$table] as $row) $insert($table, $row);
    }
    if ($sqlite) $db->exec('COMMIT'); else $db->commit();
    $transaction = false;
    echo ($existing ? 'DEMO no-op; lote íntegro existente' : 'DEMO insertado').'; base='.$base->format('Y-m-d').'; clients=8 plans=3 memberships=8 consents=16 requests=3; users=0 measurements=0 audit=0'.PHP_EOL;
} catch (Throwable $e) {
    if ($transaction && $db) {
        try { if ($sqlite) $db->exec('ROLLBACK'); else $db->rollBack(); } catch (Throwable $ignored) {}
    }
    fwrite(STDERR, "DEMO detenido sin cambios: revise entorno PDO, esquema transaccional y colisiones del lote DEMO-T2. No se repararon registros ni se muestran credenciales.\n");
    exit(1);
} finally {
    if ($locked && $db) { try { $db->query("SELECT RELEASE_LOCK(CONCAT('demo-t2:', DATABASE()))"); } catch (Throwable $ignored) {} }
}
