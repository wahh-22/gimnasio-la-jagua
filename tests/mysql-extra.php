<?php
declare(strict_types=1);
// Included by run.php only while the owned HTTP server is running.
function batch(array $requests,?callable $whileBlocked=null): array {
    global $url;
    $multi=curl_multi_init(); $handles=[];
    foreach($requests as $r) {
        $h=curl_init($url.'/?page='.$r['page']);
        curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIE=>$r['cookie']]);
        if(isset($r['post'])) curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($r['post']+['csrf'=>$r['csrf']])]);
        curl_multi_add_handle($multi,$h); $handles[]=$h;
    }
    if($whileBlocked) {
        $until=microtime(true)+.3;
        do { curl_multi_exec($multi,$active); usleep(10000); } while(microtime(true)<$until);
        assertThat($active===count($requests),'Peticiones concurrentes esperan bloqueo real de cliente');
        $whileBlocked();
    }
    do { curl_multi_exec($multi,$active); if($active) curl_multi_select($multi,.1); } while($active);
    $out=[];
    foreach($handles as $h) {
        $out[]=['code'=>(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE),'seconds'=>curl_getinfo($h,CURLINFO_TOTAL_TIME),'body'=>curl_multi_getcontent($h)];
        if(curl_errno($h)) throw new RuntimeException(curl_error($h));
        curl_multi_remove_handle($multi,$h); curl_close($h);
    }
    curl_multi_close($multi); return $out;
}
$mysql=$mysql ?? '';
$m=$m ?? throw new RuntimeException('Contexto ausente');
$run=$run ?? '';
login('admin'); $admin=['cookie'=>$cookie,'csrf'=>$csrf];
http('salir',[],303); login('entrenamiento'); $trainer=['cookie'=>$cookie,'csrf'=>$csrf];
// Do not log out admin: both sessions must remain live and independent.
login('admin'); $admin=['cookie'=>$cookie,'csrf'=>$csrf];
// Login above shares current trainer cookie; create a fresh trainer session now.
$cookie=''; $csrf=''; login('entrenamiento'); $trainer=['cookie'=>$cookie,'csrf'=>$csrf];
for($attempt=0;$attempt<5;$attempt++) {
    $cid=$m->insert('clients',['code'=>'RACE'.$attempt,'name'=>'Carrera sintética']);
    $aid=$m->insert('consents',['client_id'=>$cid,'kind'=>'corporal','version'=>'1.0','purpose'=>'Prueba sintética','evidence'=>'sintetico/race','granted_at'=>date('Y-m-d H:i:s')]);
    $gate=database(); $gate->beginTransaction(); $gate->query('SELECT id FROM clients WHERE id='.$cid.' FOR UPDATE')->fetch();
    try {
        $results=batch([
            $trainer+['page'=>'medidas','post'=>['client_id'=>$cid,'measured_on'=>date('Y-m-d'),'kind'=>'peso','value'=>75,'unit'=>'kg']],
            $admin+['page'=>'autorizaciones','post'=>['action'=>'retirar','client_id'=>$cid,'id'=>$aid]]
        ],function()use($gate){$gate->commit();});
    } finally { if($gate->inTransaction()) $gate->rollBack(); }
    assertThat(in_array($results[0]['code'],[303,403],true) && $results[1]['code']===303,'Carrera captura/retiro serializable sin error 500');
    $n=(int)$m->query('SELECT COUNT(*) FROM measurements WHERE client_id=?',[$cid])->fetchColumn();
    assertThat($n===($results[0]['code']===303?1:0),'Captura solo persiste si precede al retiro');
    $after=batch([$trainer+['page'=>'medidas','post'=>['client_id'=>$cid,'measured_on'=>date('Y-m-d'),'kind'=>'peso','value'=>75,'unit'=>'kg']]]);
    assertThat($after[0]['code']===403,'Captura posterior al retiro denegada');
}
// CHECK enforcement is verified directly, independently of controller validation.
try { $m->insert('plans',['name'=>'Inválido','days'=>0,'amount'=>1]); throw new RuntimeException('CHECK no aplicado'); }
catch(PDOException $e) { assertThat($e->errorInfo[1]===3819,'MySQL aplica CHECK'); }
// Exactly 1000 synthetic clients, with memberships for the added workload.
$m->transaction(function() use($m) {
    $existing=(int)$m->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    for($i=$existing;$i<1000;$i++) {
        $cid=$m->insert('clients',['code'=>'LOAD'.$i,'name'=>'Carga sintética '.$i]);
        $m->insert('memberships',['client_id'=>$cid,'plan_id'=>1,'start_date'=>date('Y-m-d'),'end_date'=>date('Y-m-d',strtotime('+7 days')),'amount'=>50000]);
    }
});
$sessions=[];
for($i=0;$i<5;$i++) { $cookie=''; $csrf=''; login('recepcion'); $sessions[]=['cookie'=>$cookie,'csrf'=>$csrf]; }
$times=[];
for($wave=0;$wave<6;$wave++) {
    $requests=[];
    for($i=0;$i<5;$i++) $requests[]=$sessions[$i]+['page'=>(($wave+$i)%2?'clientes&q=C001':'mensualidades')];
    foreach(batch($requests) as $r) { assertThat($r['code']===200,'CP11 consulta HTTP completa 200'); $times[]=$r['seconds']; }
}
$within=count(array_filter($times,fn($t)=>$t<=3));
assertThat($within>=29,'CP11 al menos 29/30 consultas en tres segundos (1000 clientes, cinco sesiones)');
file_put_contents($run.'/load.json',json_encode(['clients'=>1000,'sessions'=>5,'workers'=>5,'seconds'=>$times,'within_3s'=>$within,'max_seconds'=>max($times)],JSON_PRETTY_PRINT));
echo "CP11: $within/30 <=3s; máximo ".max($times)."s\n";
// Dump from own container only, restore to a separate initially empty schema.
$container=getenv('APP_TEST_MYSQL_CONTAINER');
if(!$container || !preg_match('/^unad4-mysql-[a-z0-9-]+$/D',$container)) throw new RuntimeException('Contenedor no aislado.');
$dump=$run.'/fixture.sql';
$p=proc_open(['docker','exec',$container,'mysqldump','-uroot','--single-transaction','--skip-comments','--set-gtid-purged=OFF','fixture'],[0=>['pipe','r'],1=>['file',$dump,'w'],2=>['file',$run.'/dump.log','a']],$pipes);
if(!is_resource($p)||proc_close($p)!==0) throw new RuntimeException('Falló dump');
$p=proc_open(['docker','exec','-i',$container,'mysql','-uroot','restored'],[0=>['file',$dump,'r'],1=>['file',$run.'/restore.log','a'],2=>['file',$run.'/restore.log','a']],$pipes);
if(!is_resource($p)||proc_close($p)!==0) throw new RuntimeException('Falló restore');
$restored=new PDO(str_replace('dbname=fixture','dbname=restored',$mysql),'synthetic','Synthetic-only-2026',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$counts=[];
foreach(['users','clients','consents','plans','memberships','measurements','requests','audit'] as $table) {
    $before=$m->rows('SELECT * FROM '.$table.' ORDER BY id'); $after=$restored->query('SELECT * FROM '.$table.' ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    assertThat($before===$after,'Restauración MySQL conserva todas las filas y relaciones: '.$table); $counts[$table]=count($after);
}
$orphans=$restored->query('SELECT COUNT(*) FROM measurements m LEFT JOIN clients c ON c.id=m.client_id LEFT JOIN consents a ON a.id=m.consent_id LEFT JOIN users u ON u.id=m.user_id WHERE c.id IS NULL OR a.id IS NULL OR u.id IS NULL OR a.client_id<>m.client_id')->fetchColumn();
assertThat((int)$orphans===0,'Restauración sin medidas huérfanas ni titular cruzado');
file_put_contents($run.'/restore-counts.json',json_encode($counts,JSON_PRETTY_PRINT));
