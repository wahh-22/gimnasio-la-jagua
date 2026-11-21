<?php
declare(strict_types=1);
require __DIR__.'/../app/bootstrap.php';
ini_set('session.use_strict_mode','1');
ini_set('session.use_only_cookies','1');
$sessionPath=getenv('APP_SESSION_PATH') ?: __DIR__.'/../var/sessions';
if (!is_dir($sessionPath)) mkdir($sessionPath,0700,true);
session_save_path($sessionPath);
session_set_cookie_params(['httponly'=>true,'secure'=>!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS']!=='off','samesite'=>'Strict','path'=>'/']);
session_start();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
header('Content-Type: text/html; charset=UTF-8');
header("Content-Security-Policy: default-src 'self'; style-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
$page=is_string($_GET['page']??null)?$_GET['page']:'inicio';
try {
    $controller=new Controller(new Model(database()));
    $view=$controller->handle($page,$_SERVER['REQUEST_METHOD'],$_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
    if (isset($view['redirect'])) { header('Location: ?page='.$view['redirect'],true,303); exit; }
} catch (DomainException $e) { http_response_code(422); $view=['page'=>$page,'error'=>$e->getMessage(),'rows'=>[]]; }
catch (PDOException $e) { http_response_code(422); $view=['page'=>$page,'error'=>'No se pudo guardar o consultar. Revise identificadores duplicados, relaciones y configuración.','rows'=>[]]; }
catch (RuntimeException $e) { http_response_code(in_array($e->getCode(),[401,403,405],true)?$e->getCode():500); $view=['page'=>$page,'error'=>$e->getMessage(),'rows'=>[]]; }
catch (Throwable $e) { http_response_code(500); $view=['page'=>$page,'error'=>'Error interno. Consulte al responsable técnico.','rows'=>[]]; }
require __DIR__.'/../views/page.php';
