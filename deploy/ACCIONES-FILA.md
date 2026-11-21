# Acciones por fila: actualización aditiva (T1)

## Manifiesto exacto

- `app/Controller.php`: editar/archivar/restaurar; filtros; opciones activas; validaciones y auditoría transaccional.
- `app/Schema.php`: instalaciones nuevas y migración aditiva repetible MySQL 8 / SQLite.
- `bin/migrate-actions.php`: migrador CLI explícito para instalaciones no administradas por provision.php.
- `deploy/provision.php`: concesiones UPDATE mínimas para planes/mensualidades; migración privilegiada con validación estricta previa y posterior.
- `tests/acciones-fila.php`: pruebas sintéticas aisladas en memoria, sin leer configuración ni datos de demostración.
- `deploy/ACCIONES-FILA.md`: este procedimiento.

## Base existente administrada por deploy/provision.php

Revisar el diff y disponer de un respaldo verificado antes de la ventana de mantenimiento. Detener las escrituras de la aplicación durante la actualización; no ejecutar setup.php sobre una base existente. Instalar los archivos nuevos por el procedimiento aprobado, sin publicar este cambio desde las pruebas.

Invocaciones propuestas para el operador, **no ejecutadas durante desarrollo**:

```sh
sudo php /srv/gimnasio/deploy/provision.php --migrate-actions
sudo php /srv/gimnasio/deploy/provision.php --check
```

El primer comando exige estado `ready`, valida la cuenta/host y todas las concesiones exactas antes de cambios. Solo admite que los dos permisos revisados estén en su versión anterior o nueva (para reanudar interrupciones). Añade `plans.archived` y `memberships.archived`, INTEGER NOT NULL DEFAULT 0 CHECK IN (0,1), y concede SELECT, INSERT, UPDATE únicamente en esas dos tablas. No concede DELETE ni DDL al usuario de aplicación; no cambia credenciales, configuración Apache ni estado de aprovisionamiento. Valida nuevamente concesiones y acceso a ambas columnas. Un `--check` ordinario con el esquema/permisos anteriores falla de forma cerrada.

DDL de MySQL confirma implícitamente cada ALTER. La migración no simula atomicidad: tras una interrupción se revisa y se repite el mismo comando. No elimina ni recrea tablas; no cambia filas comerciales, autorizaciones ni demo. Las nuevas columnas comienzan en cero. `clients.active` ya existe y no se modifica durante la migración. Las columnas preexistentes incompatibles se rechazan antes de iniciar ALTER.

## Instalación nueva

El flujo normal `sudo php /srv/gimnasio/deploy/provision.php` crea el esquema nuevo y los permisos mínimos actualizados. No utilizar ese flujo para migrar tablas existentes ni borrar estado para forzarlo.

## Instalaciones independientes / SQLite

Con el APP_DSN explícito del entorno autorizado y credenciales administradas por el operador:

```sh
php 4/prototipo/bin/migrate-actions.php --apply
```

Para SQLite, por ejemplo, APP_DSN debe ser `sqlite:/ruta/absoluta/base.sqlite`. El comando no utiliza el DSN predeterminado y no imprime secretos. En MySQL independiente se necesitan privilegios ALTER del operador; el migrador genérico no gestiona concesiones. En despliegues administrados usar el comando de provision.php en su lugar.

## Contrato con T2

- POST `action=editar|archivar|restaurar`, `id`, CSRF; archivar exige `confirmar=si`. Archivo/restauración solo admin; edición mantiene roles existentes.
- GET `archivados=1` lista archivados en lugar de operativos. Clientes usan active; planes/mensualidades usan archived. No hay cascada de archivo y las referencias/historial permanecen.
- Las mensualidades conservan `id, cliente, inicio, vencimiento, valor, estado`, agregando `client_id, plan_id` exclusivamente para formularios. **La UI debe ocultar esas dos columnas crudas.** Planes conserva `id,name,days,amount`, sin exponer archived como columna nueva.
- Edición de mensualidad ignora client_id enviado: usa el titular guardado. Excluye el propio periodo del solapamiento; solo compara periodos no archivados del mismo cliente, con límites inclusivos. Crear/editar exige `confirm_overlap=si` cuando corresponde; restaurar rechaza cualquier solapamiento incluso con confirmación.
- Cliente activo, plan no archivado y consentimiento administrativo vigente son obligatorios al crear/editar/restaurar una mensualidad. Restaurar un cliente no restaura consentimientos retirados. Cambiar fechas/plan/importe no modifica mediciones ni consentimientos.
- Opciones de nuevas mensualidades omiten clientes/planes archivados. Los formularios contextuales de retirar consentimiento/resolver solicitud deben mantener el titular de la fila, no sustituirlo por una selección de opciones activas. El servidor mantiene ambas comprobaciones de pertenencia.
- Reportes y bitácora siguen históricos/de solo lectura. No se habilitan ediciones/archivo de mediciones, consentimientos ni solicitudes; se mantienen sus operaciones específicas autorizadas.

## Verificación

```sh
php 4/prototipo/tests/acciones-fila.php
```

La prueba SQLite usa exclusivamente `sqlite::memory:` e incluye migración repetible y rechazo de columnas incompatibles. No requiere demo, secretos ni servidor HTTP. La validación real MySQL y del aprovisionador root se debe realizar con infraestructura sintética aprobada, nunca sobre el entorno desplegado como prueba funcional.
