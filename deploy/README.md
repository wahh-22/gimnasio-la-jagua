# Lightsail — Ubuntu 24.04, 1 GiB (T1 / S1)

Solo demo sintética, servidor **nuevo y dedicado**. No accede a AWS ni publica nada. El operador/padre crea la instancia, configura red/DNS, sube el paquete y ejecuta. No es una migración ni una certificación productiva.

## Preparación del operador

1. Ubuntu **24.04 LTS**, arquitectura admitida por sus repositorios APT; plan de 1 GiB. Reservar una **IP pública estática** antes de emitir certificados. SSH/22 restringido a la IP del operador. Permitir TCP 80 y 443 (IPv4; IPv6 solo si realmente configurado). **Nunca** abrir 3306, 33060, 8080 ni 2019. Conservar la sesión SSH durante el setup. Estos scripts no cambian firewall AWS/UFW ni reglas SSH.
2. Elegir `IP-CON-GUIONES.sslip.io`, p. ej. IP ilustrativa `203.0.113.10` → `203-0-113-10.sslip.io`. No usar literalmente esa IP reservada. Confirmar desde fuera del servidor que A corresponde a su IP estática y que no hay AAAA incorrecto. También admite un FQDN propio en minúsculas. Dependencia: disponibilidad de sslip.io, DNS público y ACME; cuotas de certificados de dominios compartidos pueden impedir emisión. Si ocurre, usar dominio propio y repetir setup con ese nombre. No se prometen certificados públicos para IP desnuda: el Caddy de Ubuntu no implementa ese flujo; el script rechaza IPs. No hay fallback autofirmado ni `tls internal`.
3. El paquete contiene **solo el contenido de `4/prototipo`**. Después de que el padre incluya estos archivos en el commit seleccionado, puede producirlo con `git archive --format=tar HEAD:4/prototipo > gimnasio.tar` desde la raíz del repositorio. Un archivo no incluido en ese commit no entra en `git archive`. Transferir por SSH/SFTP sin secretos. Extraer como root en `/srv/gimnasio` (no `/srv/gimnasio/4/prototipo`):

   ```bash
   sudo install -d -o root -g root -m 0750 /srv/gimnasio
   sudo tar -xf /ruta/segura/gimnasio.tar -C /srv/gimnasio
   ```

   Solo sobre árbol nuevo o actualización revisada: no superponer cargas no confiables, enlaces simbólicos ni secretos. `public/index.php`, `app/Schema.php` y `deploy/setup.sh` deben existir debajo de `/srv/gimnasio`.

## Invocación exacta en el servidor

Sustituir hostname y correo por valores reales; no hay contraseñas en argumentos ni historial:

```bash
sudo bash /srv/gimnasio/deploy/setup.sh \
  --hostname 203-0-113-10.sslip.io \
  --acme-email operador@example.com
sudo bash /srv/gimnasio/deploy/verify.sh 203-0-113-10.sslip.io
```

No usar `bash -x`, no registrar el contenido de `/etc/gimnasio`, no pegar contraseñas en tickets/chat. El setup necesita salida a repositorios Ubuntu, DNS y ACME. Instala con APT sin PPAs, binarios descargados por curl ni plugins Caddy. Configura servicios para arrancar tras reinicio. La IP del ejemplo no es desplegable.

El setup instala PHP 8.3 Apache/CLI, PDO MySQL, mbstring, MySQL 8 de Ubuntu, Apache y `libapache2-mod-evasive`, Caddy, curl y herramientas DNS. Maneja exclusivamente el servidor dedicado: reemplaza `ports.conf`, su vhost y conf Apache, ini PHP, `zz-gimnasio.cnf` y Caddyfile; desactiva sitios Apache predeterminados. **No ejecutar en un host compartido** ni con configuraciones previas que se necesiten preservar. APT puede arrancar servicios predeterminados durante la instalación: mantener 80/443 cerrados inicialmente si se quiere evitar incluso esa ventana de landing page; abrirlos antes de comprobar ACME/HTTPS. No abrirlos temporalmente a MySQL.

## Arquitectura y secretos

- Internet → Caddy 80/443 (redirección HTTPS + certificado público renovable) → Apache **127.0.0.1:8080**, mod_php 8.3. Solo `public/` es raíz HTTP. Caddy no necesita leer el código ni las credenciales. El API administrativo Caddy queda en 127.0.0.1:2019.
- Apache confía `X-Forwarded-For` solo de 127.0.0.1; Caddy gestiona ese encabezado sin confiar encabezados arbitrarios de Internet. `HTTPS=on` y `session.cookie_secure=1` aseguran cookie Secure detrás del proxy. No exponer el backend ni añadir otros proxies confiables sin revisión.
- `mod_evasive` limita ráfagas generales: 5 accesos a una misma página/2 segundos o 30 al sitio/2 segundos, bloqueo aproximado 60 segundos, contadores por proceso Apache. No distingue fallos de contraseña ni sustituye bloqueo de cuentas, un WAF o protección DDoS. Comprobar en servidor real que la versión empaquetada y `mod_remoteip` atribuyen al visitante y no agrupan todos bajo la IP del proxy; es condición de aceptación operativa, no evidencia local. Hasta cuatro procesos amplían el umbral observado. Caddy no tiene un módulo de rate limit externo.
- MySQL clásico y X Protocol en **127.0.0.1**; raíz SQL vía socket y autenticación local Ubuntu. No cambia contraseña raíz ni ejecuta asistentes interactivos. Sin permisos SQL remotos. Buffer InnoDB 128 MiB, conexiones 20, performance_schema desactivado. Apache máximo cuatro procesos, PHP 64 MiB por petición. No crea swap ni volúmenes; vigilar memoria/OOM/disco y ajustar antes de carga. No promete rendimiento sostenido en 1 GiB.
- Código root:www-data, directorios 0750 y archivos 0640; no escribible por PHP. Sesiones fuera del paquete: `/var/lib/gimnasio/sessions`, www-data, 0700. El `var/` distribuido no necesita escritura en este despliegue.
- Secretos se generan **solo remotamente** con `random_bytes`: clave SQL 32 bytes y clave administrativa 24 bytes, representadas en hexadecimal. `/etc/gimnasio/state.json` (root:root 0600 dentro de directorio 0700) contiene `admin_email`, `admin_password`, `db_password` y fase. El login inicial es `admin@sintetico.invalid`, sin clientes reales; solo plan sintético. Recuperar la clave exclusivamente mediante un canal privado autorizado de administración del servidor; los scripts nunca la imprimen. El archivo es secreto, no evidencia publicable. Custodiarlo cifrado fuera del servidor junto con el procedimiento de recuperación.
- `/etc/gimnasio/apache-env.conf`, root:root 0600, aporta únicamente la conexión de ejecución a Apache. Apache lo lee en el proceso padre root; no hace falta hacerlo legible para www-data. No hay `.env` ni credenciales en Git o `public/`.

### Privilegios según consultas actuales

Instalación usa root SQL por socket, **no** desde peticiones web. Cuenta web fija `gimnasio_app@127.0.0.1`, grants por tabla en `gimnasio`:

| Tablas | Permisos |
| --- | --- |
| users, clients, consents, requests | SELECT, INSERT, UPDATE |
| plans, memberships, measurements, audit | SELECT, INSERT |

Corresponde a `Controller.php`, `Model.php` (incluye agregados de reportes y `SELECT … FOR UPDATE` sobre clients). Sin DELETE, CREATE, INDEX, ALTER, DROP, FILE, privilegios globales o GRANT OPTION. La bitácora no es actualizable. El setup valida la lista exacta de grants y se detiene ante deriva en vez de revocar permisos a ciegas. Cambios futuros de consultas/esquema exigen revisar esta matriz.

## Repetición y fallos

`setup.sh` usa lock no bloqueante. Repetir la misma invocación converge configuración y servicios, reutiliza secretos, esquema y cuentas, sin reiniciar datos. Puede reintentar APT y reinicia servicios (interrupción breve); no es despliegue sin downtime.

- Sin state, si ya hay base `gimnasio` o cualquier cuenta `gimnasio_app`, **se detiene**: no adopta, borra ni cambia claves de objetos ajenos.
- Genera state antes de crear objetos SQL. Solo una base vacía en fase `allocated` admite `Schema::create`. Si un fallo dejó tablas parciales (DDL MySQL no transaccional), **se detiene** para revisión humana. No implementa recuperación destructiva automática. Nunca borrar state para «arreglarlo», ni ejecutar `bin/setup.php` otra vez.
- Fase `ready`: verifica tablas/acceso/admin activo y grants; no repite DDL, seed ni creación/restablecimiento del administrador. Desaparición de tablas/cuenta o deriva de grants provoca fallo, no reconstrucción. State corrupto/inseguro también falla cerrado. La clave administrativa guardada es la inicial, no una función de restablecimiento.
- Después de corregir una causa de red/ACME/configuración, repetir setup. Si `verify.sh` falla por ACME, dejar Caddy activo y revisar DNS, reglas 80/443, hora, cuotas y `sudo journalctl -u caddy` localmente. No desactivar validación TLS.

## Validación y entrega al operador

Comprobación local sin red, AWS, servicios ni generación de secretos, desde la raíz del repo:

```bash
bash -n 4/prototipo/deploy/setup.sh
bash -n 4/prototipo/deploy/verify.sh
php -l 4/prototipo/deploy/provision.php
```

`verify.sh` en el servidor comprueba servicios, versión/extensiones PHP, cuenta/grants/tablas, configuración Apache/Caddy, mod_evasive cargado, listeners MySQL/Apache privados, certificado confiable con hostname, redirección HTTP, atributos Secure/HttpOnly/SameSite y rutas privadas inaccesibles. Usa `--resolve` hacia loopback **sin** saltar verificación TLS. Las comprobaciones HTTP crean sesiones anónimas y logs normales, sin autenticación ni cambios de negocio. Espera la emisión aproximadamente unos minutos. No prueba alcance externo.

Desde **otra máquina**, después del setup:

```bash
curl --fail --silent --show-error -o /dev/null https://HOST/?page=ingreso
curl --silent --show-error -I http://HOST/
```

Usar hostname real. Confirmar certificado público válido en navegador y flujo de ingreso con la credencial custodiada, crear solo cuentas/datos sintéticos y comprobar roles/reportes. Revisar desde fuera que 3306/33060/8080/2019 no están accesibles, y repetir `verify.sh` tras un reinicio autorizado.

Para probar `mod_evasive` desde una IP de prueba externa autorizada, sin enviar credenciales: enviar una ráfaga acotada de 80 GET **secuenciales** a `https://HOST/?page=ingreso` (ej. un bucle de curl, registrar solo códigos HTTP). Debe observarse 403; esperar al menos 65 s sin solicitudes desde esa IP y confirmar recuperación 200. Revisar logs `/var/log/apache2/gimnasio-access.log` y `/var/log/apache2/evasive` localmente. Verificar que otra IP autorizada sigue recibiendo 200. Si no bloquea o agrupa visitantes, no dar el límite por validado: revisar interacción módulo/proxy antes de entrega. No ejecutar ataques abiertos ni pruebas de carga desde este paquete.

**Límites de evidencia:** aquí solo se valida sintaxis y revisión estática; instalación APT Ubuntu 24.04, RAM real, grants reales, certificados públicos/renovación, atribución y bloqueo mod_evasive, SSH/firewall y recorrido de navegador quedan para ejecución del padre en el servidor. No hay acceso AWS, publicación, migraciones, backups operativos ni prueba remota en esta implementación. Mantener actualizaciones de seguridad, monitoreo, retención/logrotate y backups cifrados probados antes de cualquier uso distinto de demo sintética.
