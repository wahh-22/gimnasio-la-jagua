<?php
// Isolated HTTP regression for Reportes (T1 / S1); never uses the demo database.
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
$root=dirname(__DIR__); $run=$root.'/var/test-reportes-'.date('Ymd-His').'-'.bin2hex(random_bytes(3));
mkdir($run,0700,true); mkdir($run.'/sessions',0700);
putenv('APP_DSN=sqlite:'.$run.'/reportes.sqlite'); putenv('APP_SESSION_PATH='.$run.'/sessions');
$db=database(); Schema::create($db); $m=new Model($db);
foreach(['admin','recepcion','entrenamiento'] as $role) $m->insert('users',['email'=>$role.'@example.test','role'=>$role,'password_hash'=>password_hash('Synthetic-reportes-2026',PASSWORD_DEFAULT)]);
$port=random_int(19000,28000); $url='http://127.0.0.1:'.$port;
$server=proc_open([PHP_BINARY,__DIR__.'/serve.php',(string)$port,$root.'/public'],[0=>['pipe','r'],1=>['file',$run.'/server.log','a'],2=>['file',$run.'/server.log','a']],$pipes,$root);
$cookie=''; $csrf=''; $checks=0;
function check(bool $ok,string $label): void { global $checks; if(!$ok) throw new RuntimeException('FAIL '.$label); ++$checks; echo "OK $label\n"; }
function request(string $page,int $expected,?array $post=null): string {
    global $url,$cookie,$csrf;
    $h=curl_init($url.'/?page='.$page); $headers=[];
    curl_setopt_array($h,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>10,CURLOPT_COOKIE=>$cookie,CURLOPT_HEADERFUNCTION=>function($h,$s)use(&$headers){$headers[]=$s;return strlen($s);}]);
    if($post!==null) curl_setopt_array($h,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($post+['csrf'=>$csrf])]);
    $body=curl_exec($h); $code=curl_getinfo($h,CURLINFO_RESPONSE_CODE); curl_close($h);
    foreach($headers as $s) if(preg_match('/^Set-Cookie: ([^;]+)/i',$s,$a)) $cookie=$a[1];
    if(preg_match('/name="csrf" value="([^"]+)"/',$body,$a)) $csrf=$a[1];
    check($code===$expected,"HTTP $page $expected (received $code)"); return $body;
}
function signIn(string $role): string { request('ingreso',200); request('ingreso',303,['email'=>$role.'@example.test','password'=>'Synthetic-reportes-2026']); return request('inicio',200); }
try {
    for($i=0;$i<60;$i++){ $s=@fsockopen('127.0.0.1',$port,$errno,$error,.1); if($s){fclose($s);break;} usleep(50000); }
    request('reportes',401);
    $home=signIn('admin'); check(str_contains($home,'href="?page=reportes"'),'Admin dashboard/nav link');
    $empty=request('reportes',200); check(str_contains($empty,'Aún no hay periodos registrados'),'Honest empty state');
    check(str_contains($empty,'data-kpi="clients">0<') && str_contains($empty,'data-kpi="periods">0<'),'Empty KPI zeros');
    foreach(['recepcion'=>['clientes','mensualidades'],'entrenamiento'=>['medidas']] as $role=>$pages) {
        request('salir',303,[]); $home=signIn($role);
        check(!str_contains($home,'href="?page=reportes"'),'Report hidden for '.$role);
        foreach($pages as $page) check(str_contains($home,'href="?page='.$page.'"'),'Prior navigation '.$role.' '.$page);
        request('reportes',403); request('reportes',403,['action'=>'crear']);
    }
    request('salir',303,[]); signIn('admin');
    $p1=$m->insert('plans',['name'=>'Plan <especial>','days'=>30,'amount'=>98765]);
    $p2=$m->insert('plans',['name'=>'Sin periodos','days'=>30,'amount'=>98765]);
    $c=$m->insert('clients',['code'=>'R1','name'=>'NO-EXPOSE-NAME','contact'=>'NO-EXPOSE-CONTACT']);
    $m->insert('clients',['code'=>'R2','name'=>'Inactive','active'=>0]);
    foreach([-1,0,7,8] as $offset) {
        $d=date('Y-m-d',strtotime("$offset days"));
        $m->insert('memberships',['client_id'=>$c,'plan_id'=>$p1,'start_date'=>$d,'end_date'=>$d,'amount'=>98765]);
    }
    $body=request('reportes',200);
    foreach(['clients'=>2,'periods'=>4,'soon'=>2] as $key=>$n) check(str_contains($body,'data-kpi="'.$key.'">'.$n.'<'),'KPI '.$key.' '.$n);
    foreach(['past'=>1,'soon'=>2,'later'=>1] as $key=>$n) check(str_contains($body,'data-band="'.$key.'">'.$n.'<'),'Boundary band '.$key);
    check(str_contains($body,'Plan &lt;especial&gt;') && str_contains($body,'data-plan="'.$p1.'">4<') && str_contains($body,'data-plan="'.$p2.'">0<'),'Plan distribution including zero and escaped name');
    check(!str_contains($body,'NO-EXPOSE') && !str_contains($body,'98765'),'No contacts/names/income');
    check(str_contains($body,'<meter') && str_contains($body,'<caption>'),'Native charts and table alternatives');
    request('reportes',403,['csrf'=>'invalid','action'=>'crear']);
    request('reportes',405,['action'=>'crear']);
    check((int)$m->query('SELECT COUNT(*) FROM memberships')->fetchColumn()===4,'Read only POST');
    // Query-level privacy guard: reporting must still work with no sensitive columns/table.
    $safe=new PDO('sqlite::memory:'); $safe->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    $safe->exec('CREATE TABLE clients(id INTEGER); CREATE TABLE memberships(id INTEGER,plan_id INTEGER,end_date TEXT); CREATE TABLE plans(id INTEGER,name TEXT);');
    $summary=(new Model($safe))->reportSummary();
    check($summary['periods']===0 && $summary['clients']===0,'Aggregates do not query contacts, amounts or measurements');
    echo "PASS $checks Reportes checks; evidence $run\n";
} finally { $s=proc_get_status($server); if($s['running']) posix_kill(-$s['pid'],SIGTERM); proc_close($server); }
