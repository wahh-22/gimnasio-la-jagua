# Datos sintéticos DEMO — T2 / S3

Herramienta **solo CLI**, aditiva, para el esquema existente de `app/Schema.php`.
No ejecuta setup, DDL, UPDATE ni DELETE. No crea usuarios, contraseñas, sesiones,
permisos, mediciones corporales ni entradas de auditoría atribuidas a personas reales.
No contiene datos personales ni contactos utilizables. Las autorizaciones y sus
“evidencias” son explícitamente ficticias: **no representan consentimiento legal**.

## Ejecución por el padre/operador (no ejecutada en el servidor en esta tarea)

Una vez que el responsable haya colocado `bin/seed-demo.php` en
`/srv/gimnasio/bin/seed-demo.php`, revisar/resguardar la base por el procedimiento
operativo vigente y evitar ediciones simultáneas del espacio DEMO durante la carga.
No ejecutar `bin/setup.php` ni `deploy/provision.php` para esta tarea.

Comando exacto a ejecutar **localmente en el servidor**, desde su terminal protegida,
como operador autorizado. Importa únicamente las tres variables PDO del archivo
runtime que ya genera el despliegue; no cambia ni imprime credenciales. No usar
`set -x`, `env`, `printenv`, ni copiar valores a argumentos o al historial:

```sh
sudo php -r '
try {
    $lines = @file("/etc/gimnasio/apache-env.conf", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) throw new RuntimeException();
    $runtime = [];
    foreach ($lines as $line) {
        if (preg_match("/^SetEnv (APP_DSN|APP_DB_USER|APP_DB_PASSWORD) (.+)$/D", $line, $m)) {
            if (isset($runtime[$m[1]])) throw new RuntimeException();
            $runtime[$m[1]] = $m[2];
        }
    }
    if (count($runtime) !== 3) throw new RuntimeException();
    foreach ($runtime as $key => $value) putenv($key."=".$value);
    unset($lines, $line, $m, $runtime, $key, $value);
    require "/srv/gimnasio/bin/seed-demo.php";
} catch (Throwable $e) {
    fwrite(STDERR, "Carga DEMO detenida: revisar runtime local sin mostrar credenciales.\n");
    exit(1);
}
'
```

Si las variables runtime ya están exportadas de forma segura en el proceso padre:

```sh
php /srv/gimnasio/bin/seed-demo.php
```

`APP_DSN` es obligatorio, sin valor por defecto; `APP_DB_USER` y
`APP_DB_PASSWORD` se toman del entorno PDO (SQLite no necesita credenciales).
Se aceptan SQLite y MySQL local: host `127.0.0.1`/`localhost` con puerto opcional,
o `unix_socket` absoluto, seguidos de `dbname` alfanumérico/con guiones bajos y
`charset=utf8mb4` opcional. No se permite un DSN remoto ni parámetros duplicados.
La carga no lee archivos de secretos: solo el comando padre importa el runtime.
No necesita permisos administrativos de MySQL, DDL, nuevos grants ni acceso remoto.

## Resultado y conteos

En una transacción se añaden:

| Tabla | Nuevos registros |
| --- | ---: |
| clients | 8 |
| plans | 3 |
| memberships | 8 |
| consents | 16 (8 administrativas + 8 corporales ficticias) |
| requests | 3 (consulta y supresión pendientes; corrección resuelta) |
| users / measurements / audit | 0 / 0 / 0 |

Prefijos reservados: códigos `DEMO-T2-01` a `DEMO-T2-08`, nombres de planes
`DEMO T2 Mensual`, `DEMO T2 Trimestral`, `DEMO T2 Semanal`; nombres de clientes
`DEMO Persona ficticia NN`. Contactos y evidencias no contienen datos reales.
Planes: 30 días / 50000, 90 días / 135000 y 7 días / 18000.

Fecha base: día de la primera carga en `America/Bogota`. Vencimientos relativos:
**-12, -1, 0, +3, +7, +8, +30, +60 días**. Al cargar: 2 vencidas,
3 próximas (hoy a +7 inclusive), 3 posteriores. Cada inicio corresponde a la
duración del plan, incluyendo ambos extremos. Hay períodos aún no iniciados.
La salida CLI indica fecha base y conteos **del lote**, no totales de la base.
Un éxito inicial empieza con `DEMO insertado`; una repetición íntegra con
`DEMO no-op; lote íntegro existente`. Código de salida 0 en ambos casos, 1 al fallar.

## Repetición, colisiones y límites

- Se valida el contenido completo del lote (clientes, planes y todos sus períodos,
  autorizaciones y solicitudes), no solo la existencia de un código. Se recupera
  la fecha original de la autorización administrativa del primer cliente.
- Una repetición válida no escribe, duplica, repara ni desplaza fechas. Los datos
  envejecen naturalmente: para grabaciones posteriores, revisar los vencimientos;
  esta herramienta no refresca un lote antiguo.
- Un prefijo ocupado, lote parcial, valores modificados, filas adicionales en sus
  relaciones o mediciones sobre clientes DEMO provocan fallo sin reparación.
  No intenta adivinar la procedencia de un lote idéntico al manifiesto: el esquema
  no tiene metadatos criptográficos de propiedad.
- SQLite usa `BEGIN IMMEDIATE`; MySQL exige tablas InnoDB y usa una transacción y
  un advisory lock por base para serializar invocaciones de esta herramienta.
  El resto de la aplicación no comparte ese lock: evitar cambios concurrentes
  sobre DEMO. No se reserva el prefijo frente a escritores externos arbitrarios.
- No altera registros existentes, credenciales, roles, dominio ni despliegue. En
  MySQL un rollback puede dejar huecos en AUTO_INCREMENT; no es pérdida de filas.
- En un fallo solo aparece un mensaje genérico, sin excepción PDO, SQL ni DSN.
  Revisar localmente el entorno/esquema y escalar colisiones; **no borrar ni editar
  registros existentes para forzar una repetición**.

## Evidencia S3 (local, no evidencia de producción)

Validaciones reproducibles, sin servidor HTTP ni acceso remoto:

```sh
php -l 4/prototipo/bin/seed-demo.php
php -l 4/prototipo/tests/seed-demo.php
php 4/prototipo/tests/seed-demo.php
```

Prueba SQLite aislada: **31 comprobaciones PASS**. Incluye conteos iniciales,
las tres bandas de vencimiento con límites hoy/+7/+8, nombres ficticios,
solicitudes pendientes/resueltas, no-op exacto, conservación de fecha original,
rechazo de lote alterado, colisiones de código (también minúsculas) y plan,
rollback tras fallo tardío al insertar solicitudes y conservación de todas las
filas ajenas (incluidos usuario/hash, medición y auditoría). También rechaza
entorno ausente y DSN remoto antes de conectar. Los fixtures permanecen bajo
`4/prototipo/var/test-seed-*/`; no se borran automáticamente.

Strict TDD no fue activado: no se afirma evidencia RED/GREEN.
MySQL real y ejecución en producción **pendientes del padre/operador**: no se
usaron credenciales, conexiones remotas, despliegues ni commits en esta tarea.
Tras la ejecución autorizada, registrar salida/conteos sin secretos y comprobar
clientes, mensualidades, autorizaciones, solicitudes e informes con una cuenta
existente; nunca usar estas autorizaciones ficticias para personas reales.
