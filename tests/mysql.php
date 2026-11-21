<?php
declare(strict_types=1);
// Own container only: tmpfs data, no host mounts/volumes, random loopback port.
$root=dirname(__DIR__); $name='unad4-mysql-'.date('YmdHis').'-'.bin2hex(random_bytes(3));
function command(array $args): string {
    $p=proc_open($args,[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$GLOBALS['run'].'/docker-test.log','a']],$pipes);
    if (!is_resource($p)) throw new RuntimeException('No se pudo ejecutar herramienta.');
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]);
    if(proc_close($p)!==0) throw new RuntimeException('Falló comando: '.implode(' ',array_slice($args,0,3)));
    return trim($out);
}
$run=$root.'/var/test-mysql-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run,0700,true);
$started=false;
try {
    command(['docker','run','--pull=never','-d','--name',$name,'--label','unad4.synthetic=true','--tmpfs','/var/lib/mysql:rw,size=1g','-p','127.0.0.1::3306','-e','MYSQL_ALLOW_EMPTY_PASSWORD=yes','-e','MYSQL_DATABASE=fixture','-e','MYSQL_USER=synthetic','-e','MYSQL_PASSWORD=Synthetic-only-2026','mysql:8.4']);
    $started=true; echo "Contenedor propio: $name\n";
    $mapping=command(['docker','port',$name,'3306/tcp']); $port=(int)substr(strrchr($mapping,':'),1);
    $dsn="mysql:host=127.0.0.1;port=$port;dbname=fixture;charset=utf8mb4";
    $ready=false;
    for($i=0;$i<120;$i++) {
        try { $db=new PDO($dsn,'synthetic','Synthetic-only-2026'); $db->query('SELECT 1'); $ready=true; break; }
        catch(PDOException $e) { usleep(500000); }
    }
    if(!$ready) throw new RuntimeException('MySQL no disponible en 60 segundos.');
    echo 'MySQL '.$db->query('SELECT VERSION()')->fetchColumn()."; puerto $port\n";
    command(['docker','exec',$name,'mysql','-uroot','-e','CREATE DATABASE restored CHARACTER SET utf8mb4; GRANT ALL ON restored.* TO synthetic;']);
    putenv('APP_TEST_MYSQL_DSN='.$dsn); putenv('APP_DB_USER=synthetic'); putenv('APP_DB_PASSWORD=Synthetic-only-2026');
    putenv('APP_TEST_MYSQL_CONTAINER='.$name); putenv('PHP_CLI_SERVER_WORKERS=5');
    // Separate process group lets us stop every owned PHP worker without touching other servers.
    $p=proc_open([PHP_BINARY,$root.'/tests/run.php'],[0=>['pipe','r'],1=>STDOUT,2=>STDERR],$pipes,$root);
    if (!is_resource($p) || proc_close($p)!==0) throw new RuntimeException('Suite MySQL falló.');
} finally {
    if($started) { command(['docker','stop',$name]); echo "Contenedor propio detenido: $name (tmpfs descartado; sin volúmenes; contenedor detenido conservado, no se ejecuta rm).\n"; }
}
