<?php
declare(strict_types=1);
date_default_timezone_set('America/Bogota');
require_once __DIR__.'/Schema.php';
require_once __DIR__.'/Model.php';
require_once __DIR__.'/Controller.php';
function database(): PDO {
    $dsn = getenv('APP_DSN') ?: 'mysql:host=127.0.0.1;dbname=gimnasio;charset=utf8mb4';
    $db = new PDO($dsn, getenv('APP_DB_USER') ?: '', getenv('APP_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite') $db->exec('PRAGMA foreign_keys=ON');
    else $db->exec("SET time_zone = '-05:00'");
    return $db;
}
function escape(mixed $value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
