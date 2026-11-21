# Gimnasio piloto — Actividad 4, T1 / S1–S2

Prototipo académico para **un gimnasio por instalación**, PHP + PDO + MySQL, MVC sin dependencias externas. Interfaz en español. No incluye pagos, contabilidad ni publicación. Solo datos sintéticos: no hay autorización para tratar datos reales ni validación con participantes.

## Puesta en marcha MySQL

Requisitos: PHP 8.2 o posterior con `pdo_mysql`, `mbstring`, `session`; MySQL **8.0.16+** (CHECK e InnoDB). Crear previamente una base vacía `gimnasio` con utf8mb4, sin exponer MySQL a Internet. Un administrador SQL debe asignar una cuenta de instalación con CREATE, INDEX, SELECT e INSERT. El instalador no crea servidores, bases ni usuarios SQL.

Desde `4/prototipo`, en una terminal local (no guarde credenciales en el repositorio ni en `public/`):

```bash
export APP_DSN='mysql:host=127.0.0.1;dbname=gimnasio;charset=utf8mb4'
# Solicita las credenciales sin registrarlas en el historial:
read -r -p 'Usuario SQL: ' APP_DB_USER; export APP_DB_USER
read -r -s -p 'Clave SQL: ' APP_DB_PASSWORD; export APP_DB_PASSWORD; printf '\n'
read -r -p 'Correo administrativo: ' APP_ADMIN_EMAIL; export APP_ADMIN_EMAIL
read -r -s -p 'Clave administrativa (12+ caracteres): ' APP_ADMIN_PASSWORD; export APP_ADMIN_PASSWORD; printf '\n'
php bin/setup.php
unset APP_ADMIN_PASSWORD
php -S 127.0.0.1:8080 -t public
```

Los comandos `read -p` anteriores son para Bash. En otras shells use su mecanismo de ingreso privado de variables. Abrir `http://127.0.0.1:8080/?page=ingreso`. El instalador crea el esquema, una cuenta administrativa con hash y un plan sintético. **Ejecutarlo una sola vez sobre una base vacía**; no es una migración y no borra datos si se repite. Un fallo de DDL MySQL puede dejar tablas parciales: el operador debe revisar, no repetir ciegamente. Antes del servidor sustituya las credenciales SQL por una cuenta de ejecución limitada a SELECT, INSERT y UPDATE sobre esta base. No necesita DELETE ni DDL. `var/` debe poder escribirse por el proceso PHP, nunca publicarse. Solo `public/` es raíz web.

El servidor PHP es para demostración local. Cualquier piloto remoto requiere servidor web configurado, HTTPS, cookies seguras, límites de intentos de ingreso en el proxy, cuenta SQL mínima, respaldos cifrados fuera de la raíz pública y revisión de privacidad. No se ha desplegado ni se certifica seguridad productiva.

### Alternativa local sin servicio MySQL

SQLite se admite **solo como adaptador de pruebas/demostración**, no sustituye la verificación MySQL:

```bash
export APP_DSN="sqlite:$PWD/var/demo.sqlite"
# Configure APP_ADMIN_EMAIL y APP_ADMIN_PASSWORD como arriba.
php bin/setup.php
php -S 127.0.0.1:8080 -t public
```

Requiere `pdo_sqlite`. No usar el mismo archivo/base para pruebas y demostración.

## Recorrido demostrable

1. Administración crea dos cuentas individuales en **Cuentas y permisos**: recepción y entrenamiento. Puede cambiar roles o bloquear cuentas; los permisos se releen en cada petición. No puede bloquearse/cambiarse a sí misma.
2. Recepción registra un cliente con código interno único, nombre y referencia de soporte administrativo sintético. El contacto es opcional. No se solicita consentimiento corporal: negarlo no impide registrar mensualidades. Buscar por código exacto y editar conservando código.
3. Recepción registra periodo con plan, inicio, vencimiento y valor acordado (no transacción financiera). Fechas invertidas o inexistentes son rechazadas. Renovar añade otro periodo; un solapamiento exige confirmación. La tabla distingue vencidas, próximas (hoy hasta +7 inclusive) y posteriores. Se conserva todo el historial, sin inferir estado global a partir de un único periodo antiguo.
4. Administración registra autorización corporal explícita por titular: aviso 1.0, finalidad y localizador del soporte privado. Administración **no puede consultar medidas**. Recepción tampoco, incluso mediante URL o POST directo.
5. Entrenamiento selecciona cliente autorizado, añade peso en kg o perímetro en cm y consulta historial ordenado por fecha. No modifica registros anteriores. No tiene acceso al contacto, cuentas ni bitácora. La selección inicial de clientes solo contiene código e ID con consentimiento vigente.
6. Administración retira una autorización corporal. Registro y consulta quedan bloqueados si no hay otra autorización corporal vigente; retirar todas las vigentes si existen varias. La captura y el retiro bloquean la misma fila de cliente en transacciones MySQL para serializar la comprobación. No se sobrescribe el soporte histórico.
7. Administración recibe solicitudes de consulta/corrección/supresión y documenta decisión/fundamento. **Resolver no ejecuta una supresión**: la eliminación/anonimización efectiva y el tratamiento de respaldos están pendientes de procedimiento e implementación. No confundir desactivación, retiro o cierre con eliminación.
8. Administración consulta bitácora (últimos 200 eventos). Contiene usuario, fecha, acción, entidad, ID y resultado; no nombres, contraseñas ni valores corporales. Altas, ediciones, permisos, retiros, ingreso, cierre, consultas corporales y accesos denegados se registran. No hay endpoint de edición de bitácora. Cada mutación de negocio y su evento son atómicos.

### Acciones por registro

En Clientes, Planes y Mensualidades, pulse el icono de edición de la fila elegida para editar los valores precargados; no se escribe el ID. El código del cliente y el cliente de un periodo no se cambian. Solo administración ve **Archivar registro** (exige confirmar la identidad mostrada) y **Restaurar registro**. Los enlaces **Activos / Archivados** permiten recuperar registros sin borrar historial.

Retirar autorización, resolver solicitud y cambiar permisos/bloquear cuenta también parten de la fila y quedan ligados a su titular/registro. No se ofrece cambiar el propio acceso, resolver solicitudes resueltas ni retirar una autorización ya retirada. El retiro es irreversible. Bitácora, reportes e historial corporal siguen sin acciones para modificar registros anteriores.

En móvil (hasta 760 px), las tablas con acciones presentan cada registro como un bloque de campos etiquetados: los datos y los botones de acción adyacentes caben sin desplazamiento horizontal. En escritorio se conserva la comparación por columnas. Las tablas de solo lectura no cambian.

Con JavaScript, los botones de fila muestran solo iconos distintos para editar, archivar, restaurar, retirar, resolver y permisos, sin desplegable. Cada botón tiene un nombre accesible que incluye la identidad del registro y un tooltip descriptivo en español al pasar el puntero o recibir foco. Los tooltips están fuera de la tabla para evitar recortes, admiten hover y se descartan con Escape. Un clic o toque abre directamente el diálogo nativo existente, con foco contenido y regreso al botón al cancelar/Escape. Sin JavaScript (o sin soporte de dialog), se muestran los mismos formularios HTML completos y precargados, con CSRF e identificadores ocultos. Los errores del servidor siguen siendo respuestas de página (no conservan un borrador tras enviar).

El runner `tests/browser/cp12.mjs` comprueba también límites y tamaño táctil de las acciones directas en móvil/escritorio, ausencia de scroll horizontal en registros móviles y controles de fila sin JS dentro del viewport. Incluye regresiones de selección por fila, precarga/escape, archivo/restauración en los tres módulos, confirmación, teclado/foco a 360/1366 px, retiro/resolución/permisos, protección del propio acceso, recepción sin archivo y edición sin JavaScript. Ejecutarlo solo en su base aislada; genera `var/test-browser-*`.

### Iconos por fila T1 / S1 (`odd/tasks/iconos-fila.md`)

Sin cambios de datos, roles, autorización ni backend. Se conserva el fallback completo sin JS (más largo que la vista mejorada), el diseño móvil y la omisión de acciones/tooltips al imprimir. Recursos versionados con `fila-20261121-1`.

Validación local aislada: `node 4/prototipo/tests/browser/cp12.mjs` PASS en Chrome 157 a 360/1366 px (tooltip hover/foco/Escape, apertura por teclado y toque, impresión, precarga, confirmación, mutaciones existentes y fallback sin JS); `php 4/prototipo/tests/run.php` PASS 104 comprobaciones; lint PHP sin errores. Firefox no instalado, lector de pantalla no ejercitado, MySQL no repetido. Informe final: `var/test-browser-2026-11-21T17-29-02-720Z/report.json`; se conservan también ejecuciones intermedias fallidas por foco de la página headless, resueltas trayendo el target CDP al frente. Sin AWS, publicación ni commit.

Manifiesto para despliegue del padre (solo runtime): `views/row-actions.php`, `views/page.php`, `public/ui.js`, `public/style.css`, `public/icons.svg`. Desarrollo/evidencia, no desplegar: `tests/browser/cp12.mjs`, este README y `var/test-*`.

## Arquitectura y controles

- `public/index.php`: sesión estricta, cookie HttpOnly/SameSite, CSRF para POST, encabezados y manejo de errores sin SQL/credenciales.
- `app/Controller.php`: autorización por rol en servidor, validaciones, casos de uso y transacciones.
- `app/Model.php`: PDO parametrizado, persistencia y bitácora; nunca recibe nombres SQL del navegador.
- `app/Schema.php`: esquema MySQL con FK explícitas ON DELETE RESTRICT, CHECK e índices; adaptador SQLite para pruebas.
- `views/page.php`, `public/style.css`, `public/ui.js`: dashboard y navegación por rol con página actual, columnas en español y tablas desplazables. Los formularios de entrada y búsqueda se abren en diálogos nativos titulados; ingreso abre inmediatamente. Tab permanece dentro, Escape y Cancelar cierran sin enviar y devuelven el foco al botón. Los borradores permanecen al cerrar, hasta navegar/recargar; los errores siguen siendo respuestas del servidor. Cerrar sesión sigue siendo POST directo con CSRF. Sin JS o sin soporte de dialog, los formularios permanecen en página. La CSP existente permite `/ui.js` mediante `default-src 'self'`; no hay scripts inline, CDN, npm ni dependencias nuevas.
- `bin/setup.php`: creación explícita por CLI, sin contraseñas predeterminadas ni endpoint instalador público.

La administración mínima, datos corporales y atención de derechos son permisos distintos. El titular no posee portal de autoservicio; el personal documenta el soporte fuera del directorio público. El aviso sintético no sustituye la política del responsable, su contacto ni plazos de conservación.

## Pruebas reproducibles

Desde la raíz del repositorio:

```bash
php 4/prototipo/tests/run.php
# Con Docker disponible: nueva instancia sintética MySQL 8.4, sin volúmenes existentes
php 4/prototipo/tests/mysql.php
find 4/prototipo/app 4/prototipo/public 4/prototipo/views 4/prototipo/bin 4/prototipo/tests -name '*.php' -exec php -l {} \;
```

`tests/run.php` requiere PHP CLI con `curl`, `pdo_sqlite`, `sqlite3`, `mbstring`, `posix`, `pcntl` y `proc_open`;  inicia un servidor HTTP local con puerto aleatorio, realiza peticiones reales, cookies/sesiones y comprobaciones de persistencia. No descarga paquetes. Cada ejecución crea un directorio nuevo `var/test-*` (base, recuperación SQLite, sesiones sintéticas y logs), ignorado por Git. No altera la base MySQL ni utiliza datos reales. Finaliza el servidor que inició y conserva los artefactos para inspección. Las contraseñas de prueba son exclusivamente sintéticas y no son credenciales instaladas en la aplicación.

`tests/mysql.php` requiere además Docker, `pdo_mysql` y la imagen `mysql:8.4` ya disponible localmente (`--pull=never`; no descarga imágenes), crea un contenedor de nombre único `unad4-mysql-*`, puerto aleatorio ligado a 127.0.0.1 y datos en tmpfs de 1 GiB, **sin bind mounts ni volúmenes**. Solo inicia/detiene ese contenedor; no inspecciona ni modifica contenedores preexistentes. Usa cuentas/clave exclusivamente sintéticas y raíz SQL solo para preparar el esquema de recuperación y dump/restore dentro de esa instancia. Conserva el contenedor detenido (sin datos tmpfs) y la imagen; no ejecuta eliminación ni limpieza global de Docker. No usar estas credenciales/configuración en producción. Se conserva el dump sintético y mediciones en `var/test-*`. El servidor PHP de prueba y sus trabajadores comparten grupo de procesos propio, terminado al finalizar.

### Controles defensivos adicionales

- Las denegaciones corporales por falta/retiro de consentimiento en GET y POST se registran **después** del rollback: usuario, operación, recurso y resultado, nunca datos corporales ni campos enviados.
- La sesión autenticada caduca en servidor tras **1200 segundos de inactividad**; una petición protegida renueva el plazo, una sesión caducada rota identificador/token, borra autenticación, registra expiración y rechaza la operación. Las regresiones modifican únicamente archivos de sesión sintéticos de su ejecución; no existe endpoint ni reloj manipulable en producción.
- El límite de intentos/rate-limiting sigue siendo responsabilidad operativa pendiente del proxy/servidor: la caducidad de sesión no lo sustituye. No hay afirmación de protección contra ataques automatizados.

### Evidencia y límites

Ver `EVIDENCIA.md`. Resultado final: **96 comprobaciones SQLite y 183 MySQL 8.4.13**, más setup CLI. CP01–CP08 ejercitados por HTTP; CP09 incluye ahora restauración MySQL íntegra y queda parcial por falta de eliminación efectiva. CP11: 1000 clientes, cinco sesiones, 30 respuestas HTTP completas, 30/30 ≤3 s (máximo 0.0187 s local). Se verificaron cinco carreras captura/retiro con bloqueo real. Estos resultados no acreditan todos los motores/versiones, cargas sostenidas ni despliegue productivo. Quedan pendientes:
- Implementar y verificar supresión efectiva/anonimización y evento de eliminación (CP08/CP09 completos); gestionar plazos y respaldos según responsable.
- CP10: tres tareas con participante autorizado, tiempos, errores y valoración; no sustituir por automatización.
- Rendimiento y recuperación en alojamiento real, contención sostenida, cifrado/custodia de copias y política de conservación; el dump sintético de prueba no es respaldo operativo.
- CP12: Chrome ejercitado a 360/1366 px; Firefox pendiente (no instalado). No acredita lectores de pantalla, teclado virtual móvil ni validación con participantes.
- Endurecimiento productivo: limitación de ingreso, paginación de grandes tablas, retención, seguimiento de términos legales, revisión de permisos SQL/backup y revisión de seguridad independiente.

### Interfaz T1, S1–S2 (`odd/tasks/interfaz-gimnasio.md`)

Ejecutar `node 4/prototipo/tests/browser/cp12.mjs` desde la raíz: Node 22+ con WebSocket, Chrome instalado en macOS y PHP/pdo_sqlite. Sin instalar paquetes. Crea base, sesiones, perfil Chrome y capturas nuevos en `var/test-browser-*/`, usa puerto aleatorio distinto de 8080 y termina únicamente sus propios procesos. No reinicia ni modifica la demo.

Prueba primero observada en rojo: ingreso sin diálogo. Validación final: Chrome 157.0.8103.79, creación/consulta a 360 y 1366 px, navegación de administración/recepción/entrenamiento, diálogos de entrada/búsqueda, foco, Tab, Escape, Cancelar, scroll a 360×520, salida directa y fallback sin JavaScript: PASS. Suite PHP existente: 96 comprobaciones PASS (PHP 8.4.7, SQLite 3.53.3); MySQL no se repitió en este cambio de presentación. Informes y capturas de nuevas ejecuciones quedan en `var/test-browser-*/`; los fallos intermedios también se conservan.

## Reportes T1 / S1 (`odd/tasks/reportes-gimnasio.md`)

Administración abre **Reportes** desde navegación o Inicio (`?page=reportes`). Recepción y entrenamiento no ven el enlace y reciben 403 por URL/POST directo; sin sesión, 401. Usa la autorización existente y relee el rol en servidor. GET de solo lectura; POST administrativo con CSRF válido devuelve 405, sin mutaciones de negocio.

- KPIs: todos los clientes (activos e inactivos), todos los periodos y periodos con vencimiento desde hoy hasta +7 días inclusive. Se usa la fecha del servidor, indicada en pantalla. Un periodo es un registro de mensualidad, **no un cliente único**; incluye históricos y solapamientos y no infiere vigencia global de un cliente.
- Gráficos nativos `<meter>` por franja (pasados, hoy..+7, posteriores) y por plan, con conteos numéricos, etiquetas accesibles y tablas equivalentes. Los planes sin periodos muestran cero. Vacío real sin datos inventados. Sin dependencias/CDN/JS de gráficos; impresión del navegador conserva los conteos y omite navegación/medidores.
- SQL parametrizado `COUNT`, `SUM(CASE...)`, `COALESCE` y agrupación explícita, verificado en SQLite y MySQL. Solo consulta conteos de clientes, fechas/plan de periodos y nombres de planes: no medidas corporales, contactos, nombres de clientes ni importes/ingresos financieros.
- Sistema visual sincronizado antes de estilizar en `design/system.md` y `design/system.html` desde `public/style.css`: tres custom properties existentes, colores/fuente/espacios/radios documentados. No es un sistema propuesto; reutiliza la identidad de la demo.

Validación aislada desde la raíz (nunca prueba en :8080 ni modifica la base de demo):

```bash
php 4/prototipo/tests/reportes.php
php 4/prototipo/tests/run.php
php 4/prototipo/tests/mysql.php
node 4/prototipo/tests/browser/cp12.mjs
find 4/prototipo/app 4/prototipo/public 4/prototipo/views 4/prototipo/bin 4/prototipo/tests -name '*.php' -exec php -l {} \;
```

Evidencia T1/S1: prueba inicial roja por falta de enlace Reportes; luego **43 comprobaciones específicas**, **104 SQLite**, **191 MySQL 8.4.13** y lint PHP sin errores. Las específicas cubren autenticación/roles GET y POST, CSRF, solo lectura, límites ayer/hoy/+7/+8, varios periodos de un cliente, clientes inactivos, vacío, planes con cero, escape HTML y agregación sobre esquema sin campos sensibles. Chrome real 157 a 360/1366 px verifica vacío/conteos/etiquetas/impresión, navegación previa y diálogos; Firefox no instalado y lector de pantalla no ejercitado. Artefactos nuevos únicamente en `var/test-*` (incluido log Docker). No reinicio, publicación ni datos de ejemplo en la demo.

Ejecuciones HTTP: `var/test-reportes-20261121-101256-2dffbe`, `var/test-20261121-101257-57bd88` (SQLite), `var/test-20261121-101304-ed21cf` (MySQL). Chrome conserva `report.json` y capturas en cada `var/test-browser-*`. Estas verificaciones no acreditan accesibilidad integral ni uso con participantes.

Scrum/CDIO: este incremento implementa S1–S2 de la especificación de interfaz y pruebas controladas; no acredita reuniones, diagnóstico de gimnasios, validación humana, madurez TRL, conformidad integral de accesibilidad ni despliegue. No modifica los documentos académicos originales ni sus otros requisitos S2/S3.
