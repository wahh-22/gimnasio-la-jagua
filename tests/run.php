<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$root=dirname(__DIR__);
$run=$root.'/var/test-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run,0700,true); mkdir($run.'/sessions',0700);
$mysql = getenv('APP_TEST_MYSQL_DSN') ?: '';
if ($mysql && !preg_match('/^mysql:host=127\.0\.0\.1;port=\d+;dbname=fixture;charset=utf8mb4$/D',$mysql)) throw new RuntimeException('DSN de prueba MySQL no aislado.');
putenv('APP_DSN='.($mysql ?: 'sqlite:'.$run.'/setup.sqlite'));
putenv('APP_ADMIN_EMAIL='.($mysql ? 'admin@example.test' : 'setup@example.test'));
putenv('APP_ADMIN_PASSWORD='.($mysql ? 'Sintetica-2026!' : 'Configuracion-2026!'));
$setup=proc_open([PHP_BINARY,$root.'/bin/setup.php'],[0=>['pipe','r'],1=>['file',$run.'/setup.log','a'],2=>['file',$run.'/setup.log','a']],$setupPipes,$root);
if (!is_resource($setup) || proc_close($setup)!==0) {
    if ($mysql) Schema::create(database()); // diagnostic DDL, synthetic isolated database only
    throw new RuntimeException('Falló setup CLI.');
}
$setupDb=database(); $setupUser=$setupDb->query('SELECT * FROM users')->fetch();
if (!$setupUser || !password_verify($mysql ? 'Sintetica-2026!' : 'Configuracion-2026!',$setupUser['password_hash'])) throw new RuntimeException('Setup no conserva hash válido.');
echo "OK setup CLI: esquema y contraseña con hash\n";
putenv('APP_ADMIN_PASSWORD');
putenv('APP_DSN='.($mysql ?: 'sqlite:'.$run.'/test.sqlite')); putenv('APP_SESSION_PATH='.$run.'/sessions');
$db=database(); if (!$mysql) Schema::create($db); $m=new Model($db);
foreach ($mysql ? ['recepcion','entrenamiento'] : ['admin','recepcion','entrenamiento'] as $role) $m->insert('users',['email'=>$role.'@example.test','password_hash'=>password_hash('Sintetica-2026!',PASSWORD_DEFAULT),'role'=>$role]);
if (!$mysql) $m->insert('plans',['name'=>'Plan prueba','days'=>30,'amount'=>50000]);
$port=19000+random_int(0,9000); $url='http://127.0.0.1:'.$port;
$server=proc_open([PHP_BINARY,__DIR__.'/serve.php',(string)$port,$root.'/public'],[0=>['pipe','r'],1=>['file',$run.'/server.log','a'],2=>['file',$run.'/server.log','a']],$pipes,$root);
if (!is_resource($server)) throw new RuntimeException('No se pudo iniciar servidor HTTP.');
$cookie=''; $csrf=''; $checks=0;
function assertThat(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException('FALLO: '.$label); ++$checks; echo "OK $label\n"; }
function http(string $page,array|null $post=null,?int $expected=null): string {
    global $url,$cookie,$csrf;
    $h=curl_init($url.'/?page='.$page); $headers=[];
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIE=>$cookie,CURLOPT_HEADERFUNCTION=>function($h,$s)use(&$headers){$headers[]=$s;return strlen($s);}]);
    if ($post!==null) curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post+['csrf'=>$csrf])]);
    $body=curl_exec($h); $code=(int)curl_getinfo($h,CURLINFO_RESPONSE_CODE);
    if ($body===false) throw new RuntimeException(curl_error($h));
    curl_close($h);
    foreach($headers as $header) if(preg_match('/^Set-Cookie: ([^;]+)/i',$header,$match)) $cookie=$match[1];
    if(preg_match('/name="csrf" value="([^"]+)"/',$body,$match)) $csrf=$match[1];
    if ($expected!==null) assertThat($code===$expected,"HTTP $page => $expected (obtenido $code)");
    return $body;
}
function login(string $role): void { http('ingreso',null,200); http('ingreso',['email'=>$role.'@example.test','password'=>'Sintetica-2026!'],303); http('inicio',null,200); }
function countTable(string $t): int { global $m; return (int)$m->query('SELECT COUNT(*) FROM '.$t)->fetchColumn(); }
try {
    $ready=false;
    for($i=0;$i<60;$i++) { $s=@fsockopen('127.0.0.1',$port,$errno,$errstr,.1); if($s){fclose($s);$ready=true;break;} usleep(50000); }
    assertThat($ready,'Servidor disponible');
    http('clientes',null,401); http('ingreso',null,200);
    http('ingreso',['email'=>'admin@example.test','password'=>'incorrecta'],401);
    login('recepcion');
    http('clientes',['code'=>'C001','name'=>'Cliente sintético','administrative'=>'si','evidence'=>'soporte/sintetico-1'],303);
    http('clientes',['code'=>'C001','name'=>'Duplicado','administrative'=>'si','evidence'=>'soporte/2'],422);
    http('clientes',['code'=>'C002','administrative'=>'si','evidence'=>'soporte/2'],422);
    assertThat(countTable('clients')===1,'CP01: alta válida, duplicado e incompleto sin mutación');
    $body=http('clientes&q='.urlencode("' OR 1=1 --"),null,200);
    assertThat(!str_contains($body,'<td>C001</td>'),'Búsqueda parametrizada no interpreta SQL');
    http('clientes',['action'=>'editar','id'=>1,'name'=>'Cliente actualizado','contact'=>'contacto sintético'],303);
    $body=http('clientes&q=C001',null,200);
    assertThat(str_contains($body,'Cliente actualizado') && str_contains($body,'C001'),'CP02: búsqueda exacta y actualización persistente');
    http('clientes',['csrf'=>'manipulado','code'=>'CSRF','name'=>'Ataque'],403);
    http('mensualidades',['client_id'=>1,'plan_id'=>1,'start_date'=>'2026-02-30','end_date'=>'2026-03-20','amount'=>1],422);
    http('mensualidades',['client_id'=>1,'plan_id'=>1,'start_date'=>'2026-04-20','end_date'=>'2026-03-20','amount'=>1],422);
    http('mensualidades',['client_id'=>999,'plan_id'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-02-01','amount'=>1],422);
    foreach([-1,0,7,8] as $offset) {
        $day=date('Y-m-d',strtotime("$offset days"));
        http('mensualidades',['client_id'=>1,'plan_id'=>1,'start_date'=>$day,'end_date'=>$day,'amount'=>100],303);
    }
    $body=http('mensualidades',null,200);
    assertThat(substr_count($body,'Vencida')===1 && substr_count($body,'Próxima (hoy a +7 días)')===2 && substr_count($body,'Posterior')===1,'CP03–04: límites ayer/hoy/+7/+8');
    http('medidas&client_id=1',null,403); http('medidas',['client_id'=>1],403); http('bitacora',null,403); http('autorizaciones',null,403); http('cuentas',null,403);
    http('salir',[],303); http('clientes',null,401);
    login('entrenamiento');
    $measurement=['client_id'=>1,'measured_on'=>date('Y-m-d'),'kind'=>'peso','value'=>70,'unit'=>'kg'];
    http('medidas',$measurement,403); http('clientes',null,403); http('salir',[],303);
    login('admin'); http('medidas&client_id=1',null,403);
    $report=http('reportes',null,200);
    foreach(['clients'=>1,'periods'=>4,'soon'=>2] as $key=>$n) assertThat(str_contains($report,'data-kpi="'.$key.'">'.$n.'<'),'Reportes agregado '.$key);
    foreach(['past'=>1,'soon'=>2,'later'=>1] as $key=>$n) assertThat(str_contains($report,'data-band="'.$key.'">'.$n.'<'),'Reportes límite '.$key);
    assertThat(str_contains($report,'data-plan="1">4<'),'Reportes agrupa por plan SQL portable');
    http('autorizaciones',['client_id'=>1,'kind'=>'corporal','explicit'=>'si','evidence'=>'soporte/corporal-sintetico'],303);
    http('clientes',['code'=>'C002','name'=>'Segundo sintético','administrative'=>'si','evidence'=>'soporte/2'],303);
    $secondClient=(int)$m->one('SELECT id FROM clients WHERE code=?',['C002'])['id'];
    http('autorizaciones',['client_id'=>$secondClient,'kind'=>'corporal','explicit'=>'si','evidence'=>'soporte/corporal-2'],303);
    http('salir',[],303); login('entrenamiento');
    http('medidas',array_replace($measurement,['value'=>-1]),422);
    http('medidas',array_replace($measurement,['unit'=>'cm']),422);
    http('medidas',array_replace($measurement,['measured_on'=>date('Y-m-d',strtotime('+1 day'))]),422);
    http('medidas',array_replace($measurement,['value'=>'']),422);
    http('medidas',$measurement,303);
    http('medidas',array_replace($measurement,['measured_on'=>date('Y-m-d',strtotime('-1 day')),'value'=>71]),303);
    http('medidas',array_replace($measurement,['client_id'=>$secondClient,'value'=>99]),303);
    $body=http('medidas&client_id=1',null,200);
    assertThat(str_contains($body,'70') && str_contains($body,'71') && !str_contains($body,'<td>99</td>'),'CP05–06: histórico separado, sin sobrescritura ni cruce');
    http('salir',[],303); login('admin');
    http('autorizaciones',['action'=>'retirar','client_id'=>1,'id'=>2],303);
    http('solicitudes',['client_id'=>1,'kind'=>'supresion'],303);
    http('solicitudes',['action'=>'resolver','client_id'=>1,'id'=>1,'resolution'=>'Solicitud sintética denegada por obligación de conservación simulada; revisar plazo con responsable.'],303);
    assertThat($m->one('SELECT status,resolution FROM requests WHERE id=1')['status']==='resuelta','CP08: retiro y resolución de solicitud documentada');
    http('cuentas',['email'=>'otra@example.test','password'=>'Sintetica-2026!','role'=>'recepcion'],303);
    http('cuentas',['action'=>'permiso','id'=>4,'role'=>'entrenamiento','active'=>'0'],303);
    assertThat((int)$m->one('SELECT active FROM users WHERE id=4')['active']===0,'Permisos y bloqueo persistentes');
    $body=http('bitacora',null,200);
    assertThat(str_contains($body,'consulta_corporal') && !str_contains($body,'<td>70</td>'),'CP09 parcial: auditoría sin valores corporales');
    http('salir',[],303); login('entrenamiento');
    $auditBefore=countTable('audit');
    http('medidas',$measurement,403); http('medidas&client_id=1',null,403);
    $denials=$m->rows("SELECT user_id,action,entity,resource_id,result FROM audit WHERE result='denegado' AND resource_id=1 AND action IN ('captura_corporal','consulta_corporal') ORDER BY id DESC LIMIT 2");
    assertThat(countTable('audit')===$auditBefore+2 && count($denials)===2,'Denegaciones GET y POST persisten tras rollback');
    assertThat($denials[0]['action']==='consulta_corporal' && $denials[1]['action']==='captura_corporal' && (int)$denials[0]['user_id']===3 && $denials[0]['entity']==='clients','Auditoría denegada conserva solo usuario, operación y recurso');
    assertThat(countTable('measurements')===3,'Retiro bloquea escritura y consulta sin alterar historial');
    try { $m->insert('memberships',['client_id'=>9999,'plan_id'=>1,'start_date'=>'2026-01-01','end_date'=>'2026-02-01','amount'=>1]); throw new RuntimeException('Huérfano admitido'); } catch(PDOException $e) { assertThat(true,'FK rechaza membresía huérfana'); }
    // Backdate only this synthetic test session; no production clock override or endpoint.
    http('salir',[],303); login('recepcion');
    $sid=substr($cookie,strpos($cookie,'=')+1);
    if(!preg_match('/^[a-zA-Z0-9,-]+$/D',$sid)) throw new RuntimeException('Cookie sintética inválida');
    $sessionFile=$run.'/sessions/sess_'.$sid;
    $sessionData=file_get_contents($sessionFile);
    assertThat(str_contains($sessionData,'last_activity|i:'),'Sesión registra actividad');
    $sessionData=preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:'.(time()-1190).';',$sessionData);
    file_put_contents($sessionFile,$sessionData);
    http('clientes',null,200);
    $sessionData=file_get_contents($sessionFile);
    assertThat(str_contains($sessionData,'last_activity|i:'.time()) || preg_match('/last_activity\|i:'.(time()-1).';/',$sessionData)===1,'Actividad válida renueva plazo de inactividad');
    file_put_contents($sessionFile,preg_replace('/last_activity\|i:\d+;/', 'last_activity|i:'.(time()-1200).';',$sessionData));
    $beforeExpiry=countTable('audit'); $clientsBefore=countTable('clients');
    http('clientes',['code'=>'EXPIRADA','name'=>'No guardar','administrative'=>'si','evidence'=>'sintetico'],401);
    assertThat(countTable('clients')===$clientsBefore && countTable('audit')===$beforeExpiry+1,'A los 1200 segundos bloquea POST y registra expiración sin mutación');
    http('clientes',null,401);
    http('ingreso',null,200);
    assertThat($m->one('SELECT action FROM audit ORDER BY id DESC LIMIT 1')['action']==='sesion_expirada','Expiración auditada fuera de transacción de negocio');
    if ($mysql) {
        require __DIR__.'/mysql-extra.php';
    } else {
    // Independent SQLite backup/restore verification; not evidence for MySQL recovery.
    $source=new SQLite3($run.'/test.sqlite'); $backup=new SQLite3($run.'/restored.sqlite'); $source->backup($backup);
    assertThat((int)$backup->querySingle('SELECT COUNT(*) FROM measurements')===3 && $backup->querySingle('PRAGMA integrity_check')==='ok','CP09 parcial: respaldo SQLite recupera conteos e integridad');
    assertThat($backup->querySingle('PRAGMA foreign_key_check')===null,'Relaciones válidas en respaldo');
    $backup->close(); $source->close();
    }
    $version=$db->query($mysql ? 'SELECT VERSION()' : 'SELECT sqlite_version()')->fetchColumn();
    echo "PASS $checks comprobaciones. PHP ".PHP_VERSION."; ".($mysql?'MySQL':'SQLite')." $version. Evidencia: $run\n";
    echo "Pendientes: supresión efectiva, CP10 participantes, CP12 Chrome/Firefox; ver evidencia para límites de carga y concurrencia.\n";
} finally { $status=proc_get_status($server); if ($status['running']) posix_kill(-$status['pid'],SIGTERM); proc_close($server); }
