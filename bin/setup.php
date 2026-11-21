<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
if (PHP_SAPI!=='cli') exit(1);
$email=getenv('APP_ADMIN_EMAIL') ?: '';
$password=getenv('APP_ADMIN_PASSWORD') ?: '';
if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12) {
    fwrite(STDERR,"Configure APP_ADMIN_EMAIL y APP_ADMIN_PASSWORD (mínimo 12 caracteres). Use una base vacía.\n"); exit(1);
}
try {
    $db=database(); Schema::create($db); $m=new Model($db);
    $m->transaction(function() use($m,$email,$password) {
        $id=$m->insert('users',['email'=>strtolower($email),'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin']);
        $m->insert('plans',['name'=>'Mensual sintético','days'=>30,'amount'=>50000]);
        $m->audit($id,'alta','users',$id);
    });
    echo "Esquema y cuenta administrativa creados. Sin clientes reales.\n";
} catch(Throwable $e) { fwrite(STDERR,"Configuración fallida: revise conexión, permisos y base vacía.\n"); exit(1); }
