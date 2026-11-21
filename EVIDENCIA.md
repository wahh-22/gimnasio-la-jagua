# Evidencia técnica T1 / S1

## Estado final observado

**S1 parcialmente cubierto**, con validación real MySQL completada en entorno sintético controlado. No hay publicación, commits, participantes ni declaración de TRL. S2/S3 fuera de este incremento.

- PHP CLI 8.4.7; SQLite 3.53.3; Docker Server 29.8.1.
- MySQL **8.4.13**, imagen `mysql:8.4`, arm64, digest `mysql@sha256:a81d043930e51745522bd5c430961cf8286f0a23f0a9a2a6e0108f7ea71b0902`.
- Host local; HTTP PHP en loopback con `PHP_CLI_SERVER_WORKERS=5`; MySQL Docker con puerto aleatorio ligado a 127.0.0.1 y `/var/lib/mysql` en tmpfs de 1 GiB. No se configuraron límites CPU/RAM adicionales. No extrapolar a alojamiento ni red reales.
- Fechas de artefactos: 20 de noviembre de 2026 America/Bogota; nombres Docker: 21 de noviembre UTC, mismo intervalo.
- No se modificaron contenedores/volúmenes preexistentes. Se descargó una imagen de prueba y se crearon cuatro contenedores propios durante diagnóstico/repetición. Los cuatro quedaron **detenidos**, `mounts=[]`, sin volúmenes ni bind mounts; tmpfs descartado al detenerlos. No se ejecutó `rm`, prune ni limpieza global. Imagen y metadatos de contenedores detenidos se conservan.

## Comandos y resultados reales

### `php 4/prototipo/tests/mysql.php`

1. Primer intento: instalador CLI falló; segundo intento diagnóstico reveló MySQL **3813**, `Column check constraint 'memberships_chk_1' references other column`. La comprobación de vencimiento debe ser CHECK **de tabla**, no de columna, en MySQL. Corregido en `app/Schema.php`. No se ocultó este fallo ni se atribuyó éxito a SQLite como sustituto.
2. Tras corrección: **169 comprobaciones superadas** con setup CLI real, HTTP, carreras, carga y restauración.
3. Tras añadir auditoría de denegaciones y caducidad de sesión: **183 comprobaciones superadas**, más verificación CLI independiente del esquema y contraseña con hash.
   - Contenedor final: `unad4-mysql-20261121025146-acced8`; puerto local 53682, detenido al terminar.
   - Artefactos: `var/test-20261120-215151-c3bfa2/` (ignorados por Git, solo datos sintéticos).
   - `setup.log`, `server.log`, `fixture.sql`, `dump.log`, `restore.log`, `restore-counts.json`, `load.json` y sesiones sintéticas conservados.

### `php 4/prototipo/tests/run.php`

- Última ejecución: **96 comprobaciones superadas**, más setup CLI independiente.
- Artefactos: `var/test-20261120-215144-371428/`.
- Mantiene regresión SQLite, pero la evidencia MySQL anterior procede de servidor real, no de equivalencia entre adaptadores.

### Sintaxis

`find 4/prototipo/app 4/prototipo/public 4/prototipo/views 4/prototipo/bin 4/prototipo/tests -name '*.php' -exec php -l {} \;`

**11 archivos PHP sin errores de sintaxis.**

### Aislamiento Docker

`docker inspect --format '{{.Name}} status={{.State.Status}} mounts={{json .Mounts}} tmpfs={{json .HostConfig.Tmpfs}}'` sobre los cuatro nombres propios mostró `status=exited`, `mounts=[]`, `tmpfs={"/var/lib/mysql":"rw,size=1g"}`:

- `unad4-mysql-20261121024858-07a407` (primer intento fallido).
- `unad4-mysql-20261121025032-60b38d` (diagnóstico 3813).
- `unad4-mysql-20261121025048-ec0ca7` (169 comprobaciones).
- `unad4-mysql-20261121025146-acced8` (183 comprobaciones).

Strict TDD no fue activado. RED/GREEN no aplican: los fallos de diagnóstico y pases anteriores son evidencia observada de validación, no un ciclo TDD declarado.

## Trazabilidad funcional

| Caso | Evidencia final en MySQL real | Límite |
|---|---|---|
| CP01 | Alta válida; duplicado e incompleto 422 sin filas extra | Datos sintéticos |
| CP02 | Búsqueda exacta y edición recargada preservan código; búsqueda parametrizada | Identificadores obtenidos de BD, sin asumir secuencias consecutivas tras duplicados MySQL |
| CP03 | Fecha inexistente/invertida y cliente ausente rechazados; periodos válidos persistidos | CHECK/FK también comprobados directamente |
| CP04 | Ayer vencido; hoy y +7 próximos; +8 posterior | Zona America/Bogota |
| CP05 | Sin autorización 403; valor negativo/vacío, unidad incompatible y fecha futura 422; captura válida 303 | Concurrencia adicional descrita abajo |
| CP06 | Fechas anteriores preservadas y segundo cliente aislado | Sin edición del historial |
| CP07 | Credenciales inválidas 401; cierre y recurso protegido 401; recepción GET/POST corporal 403; administración corporal 403 | No prueba HTTPS alojado ni rate-limiting |
| CP08 | Consentimiento separado, retiro, bloqueo de captura/consulta, solicitud y resolución persistidas | Supresión efectiva no implementada |
| CP09 | Auditoría restringida, eventos sin valores corporales, denegaciones que sobreviven rollback, FK, dump y restore exactos | Falta evento/eliminación efectiva y política operativa de copias |
| CP10 | No ejecutado | Requiere participante autorizado, tiempos, errores y valoración |
| CP11 | 1000 clientes, cinco sesiones y 30 respuestas completas: 30/30 ≤3 s | Entorno local controlado; no carga sostenida ni SLA |
| CP12 | No ejecutado | CSS adaptable no prueba Chrome/Firefox a 360/1366 px |

### Concurrencia retiro/captura

Cinco clientes sintéticos independientes, cada uno con consentimiento vigente. Una tercera conexión mantiene `SELECT ... FOR UPDATE` sobre el cliente mientras se envían **dos HTTP concurrentes** con sesiones independientes (entrenamiento captura, administración retira). Durante 300 ms ambos permanecen activos esperando el bloqueo. Al liberar la fila:

- Retiro: 303 en las cinco iteraciones.
- Captura: solo 303 o 403 según orden de adquisición, nunca error 500.
- La cantidad persistida coincide exactamente con el resultado: una si la captura precedió al retiro, ninguna si fue denegada. El respaldo final contiene seis medidas: tres del flujo base y tres ganadoras de estas carreras.
- Una nueva captura posterior al retiro devuelve 403 en las cinco iteraciones.

Esto verifica serialización observable en esas cinco carreras, no todos los intercalados ni contención sostenida.

### Recuperación MySQL

`mysqldump --single-transaction` de la base `fixture` y restauración con cliente `mysql` a la base separada inicialmente vacía `restored`, ambas dentro del contenedor propio. Se compararon **todas las filas, todos los campos y orden por ID** de ocho tablas, no solo sus conteos:

| Tabla | Filas recuperadas |
|---|---:|
| users | 4 |
| clients | 1000 |
| consents | 9 |
| plans | 1 |
| memberships | 997 |
| measurements | 6 |
| requests | 1 |
| audit | 69 |

Consulta adicional: cero medidas sin cliente, autorización o usuario, y cero cruces entre titular del consentimiento y cliente de la medida. Los datos restaurados igualan exactamente los originales. El dump es sintético, no cifrado ni respaldo operativo; no se probó restauración en otro servidor/proveedor.

### Rendimiento CP11

1000 clientes sintéticos y 997 periodos en MySQL; cinco sesiones HTTP independientes de recepción. Seis tandas de cinco solicitudes simultáneas, alternando búsqueda exacta de cliente y listado de mensualidades. Se verificó HTTP 200 y recepción completa de cada cuerpo. `curl` mide solicitud-respuesta completa; no renderizado de navegador. `load.json` conserva los 30 tiempos:

- **30/30 ≤3 s** (meta: al menos 29).
- Máximo **0.0187 s** en la ejecución final.
- Sesiones, fixture y conexiones locales; no extrapolar a concurrencia municipal ni disponibilidad productiva.

## Regresiones defensivas adicionales

- GET y POST corporales denegados tras retiro generan exactamente dos eventos persistidos, aunque se revierta la transacción de negocio; conserva operación (`consulta_corporal` / `captura_corporal`), usuario, recurso y resultado. El número de medidas no cambia. No se copian campos enviados ni valores corporales a la bitácora.
- Sesiones: se verificó presencia de `last_activity`, acceso permitido antes del límite y renovación de actividad. Solo se retrofechó el archivo de sesión sintético de esa ejecución para comprobar el límite sin esperar veinte minutos. A los **1200 segundos**: POST devuelve 401, no crea cliente, registra un único `sesion_expirada`, borra autenticación y el siguiente GET protegido continúa en 401. No hay bypass HTTP de reloj en la aplicación.
- CSRF inválido 403, cuentas, rol/bloqueo persistente y accesos administrativos denegados a recepción también siguen verdes.

## Pendientes explícitos

- Supresión/anonimización efectiva, evento de eliminación y política de conservación/respaldos; resolver solicitud no elimina datos.
- Limitación operativa de intentos de ingreso/rate-limiting en proxy/servidor; el timeout no la reemplaza.
- CP10 con participantes y CP12 en navegadores reales; no se inventa participación.
- Despliegue HTTPS, permisos SQL mínimos reales (el usuario de fixture tiene permisos de instalación), cifrado/custodia de respaldos, seguimiento de términos legales y revisión independiente de seguridad.

RNF05 tiene evidencia local CP11; RNF04 recuperación MySQL y controles principales RNF02/RNF03 ejercitados. RNF01/RNF06 siguen pendientes. RF08/RF09 no están completos respecto a eliminación efectiva. S1 continúa parcial por estos límites; ningún resultado técnico equivale a validación por participantes o cumplimiento jurídico certificado.
