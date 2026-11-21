<?php
$view = $view ?? ['page'=>'inicio', 'rows'=>[], 'plans'=>[]];
$page=$view['page'];
$names=['reportes'=>'Reportes','inicio'=>'Inicio','clientes'=>'Clientes','mensualidades'=>'Mensualidades y vencimientos','medidas'=>'Historial corporal','autorizaciones'=>'Autorizaciones','solicitudes'=>'Solicitudes del titular','bitacora'=>'Bitácora','cuentas'=>'Cuentas y permisos','planes'=>'Planes','ingreso'=>'Ingreso','privacidad'=>'Aviso de privacidad'];
// Decorative icons always accompany text, except the explicitly named theme toggle.
function icon(string $name, string $class=''): string {
    return '<svg class="icon '.escape($class).'" aria-hidden="true" focusable="false" width="24" height="24"><use href="/icons.svg?v=iconos-20261121-1#'.escape($name).'"></use></svg>';
}
$moduleIcons=['inicio'=>'home','clientes'=>'users','mensualidades'=>'calendar','medidas'=>'ruler','autorizaciones'=>'shield','solicitudes'=>'file','cuentas'=>'key','planes'=>'list','bitacora'=>'list','reportes'=>'chart','ingreso'=>'login','privacidad'=>'shield'];
function field(string $name,string $label,string $type='text',bool $required=true): void {
    echo '<label>'.escape($label).'<input name="'.escape($name).'" type="'.escape($type).'" '.($required?'required':'').' '.($type==='number'?'step="any"':'').' maxlength="4000"></label>';
}
function selectField(string $name,string $label,array $options): void {
    echo '<label>'.escape($label).'<select required name="'.escape($name).'">';
    if (!$options) echo '<option value="">No hay opciones disponibles</option>';
    foreach($options as $value=>$text) echo '<option value="'.escape($value).'">'.escape($text).'</option>';
    echo '</select>'.(!$options?'<small>Consulte con administración para habilitar opciones.</small>':'').'</label>';
}
function startForm(string $action='crear'): void { echo '<form method="post" data-entry><input type="hidden" name="csrf" value="'.escape($_SESSION['csrf']).'"><input type="hidden" name="action" value="'.escape($action).'">'; }
function endForm(string $label='Guardar'): void {
    $symbol=match(true) {
        $label==='Ingresar'=>'login',
        str_starts_with($label,'Retirar')=>'stop',
        str_starts_with($label,'Actualizar')=>'edit',
        $label==='Aplicar permiso'=>'key',
        $label==='Registrar resolución'=>'check',
        default=>'save'
    };
    echo '<button>'.icon($symbol).escape($label).'</button></form>';
}
function checkField(string $name,string $label,bool $required=false): void { echo '<label class="check"><input type="checkbox" name="'.escape($name).'" value="si" '.($required?'required':'').'>'.escape($label).'</label>'; }
require __DIR__.'/row-actions.php';
$clientOptions=[]; foreach($view['clients']??[] as $c) $clientOptions[$c['id']]=$c['code'];
?>
<?php
$role=$view['u']['role']??null;
$roleNames=['admin'=>'Administración','recepcion'=>'Recepción','entrenamiento'=>'Entrenamiento'];
$links=match($role) {'admin'=>['clientes','mensualidades','planes','autorizaciones','solicitudes','cuentas','bitacora','reportes'],'recepcion'=>['clientes','mensualidades'],'entrenamiento'=>['medidas'],default=>['ingreso']};
$descriptions=['reportes'=>'Consulte clientes y periodos registrados, sin datos corporales, contactos ni ingresos financieros. Solo lectura.','clientes'=>'Registre clientes, consulte por código y mantenga sus datos al día.','mensualidades'=>'Organice los periodos de membresía y revise sus vencimientos.','medidas'=>'Consulte y registre el seguimiento con autorización corporal vigente.','autorizaciones'=>'Gestione cada finalidad por separado y conserve su soporte.','solicitudes'=>'Acompañe las solicitudes del titular y documente su atención.','cuentas'=>'Administre accesos individuales y permisos del equipo.','planes'=>'Prepare las opciones de membresía de su gimnasio.','bitacora'=>'Revise los eventos registrados. La bitácora es de solo lectura.','ingreso'=>'Ingrese con su cuenta individual para continuar.'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=escape($names[$page]??'Gimnasio')?> · Gimnasio piloto</title><script src="/ui.js?v=acciones-20261121-2"></script><link rel="stylesheet" href="/style.css?v=acciones-20261121-2"></head><body>
<a class="skip" href="#contenido">Saltar al contenido</a>
<header><a class="brand" href="?page=inicio"><span class="brand-mark" aria-hidden="true">G</span><span>Gimnasio piloto<small>Tu espacio de gestión</small></span></a><div class="header-actions"><span class="role"><?=escape($roleNames[$role]??'Acceso individual')?></span><button id="theme-toggle" class="secondary" type="button" aria-label="Modo oscuro" title="Cambiar a modo claro" aria-pressed="true" hidden><?=icon('sun','theme-sun')?><?=icon('moon','theme-moon')?></button></div></header>
<div class="shell"><aside><nav aria-label="Principal"><?php
foreach(array_merge(['inicio'],$links,['privacidad']) as $link) echo '<a href="?page='.$link.'"'.($page===$link?' aria-current="page"':'').'>'.icon($moduleIcons[$link]).'<span>'.escape($names[$link]).'</span></a>';
?></nav><?php if(isset($_SESSION['uid'])) { echo '<div class="session-action"><form method="post" action="?page=salir"><input type="hidden" name="csrf" value="'.escape($_SESSION['csrf']).'"><button class="secondary">'.icon('logout').'Cerrar sesión</button></form></div>'; } ?><p class="nav-note">Demo académica<br>Solo datos sintéticos</p></aside><main id="contenido" tabindex="-1"><div class="page-heading"><p class="eyebrow">GIMNASIO / <?=escape($roleNames[$role]??'BIENVENIDA')?></p><h1><?=escape($names[$page]??'Recurso')?></h1><p><?=escape($descriptions[$page]??'Un lugar para organizar el día a día de su gimnasio.')?></p></div>
<?php if(isset($view['error'])): ?><p role="alert"><?=escape($view['error'])?></p><p><a href="?page=<?=escape($page)?>">Volver al formulario</a> · <a href="?page=ingreso">Iniciar sesión</a></p>
<?php else:
switch($page):
case 'ingreso': startForm(); field('email','Correo individual','email'); field('password','Contraseña','password'); endForm('Ingresar'); break;
case 'inicio': ?><section class="welcome"><h2>Todo listo para comenzar</h2><p>Elija una tarea. Aquí encontrará únicamente los módulos disponibles para su rol.</p></section><div class="modules"><?php foreach($links as $link): ?><a class="module" href="?page=<?=escape($link)?>"><h2><?=icon($moduleIcons[$link])?><span><?=escape($names[$link])?></span></h2><p><?=escape($descriptions[$link]??'Consultar el módulo')?></p><span class="module-action">Abrir módulo <?=icon('arrow')?></span></a><?php endforeach ?></div><p class="notice">El seguimiento corporal requiere una cuenta de entrenamiento y autorización vigente. Administración no tiene lectura corporal automática.</p><?php break;
case 'reportes': require __DIR__.'/reportes.php'; break;
case 'privacidad': ?><article><h3>Aviso versión 1.0 — demostración sintética</h3><p>Finalidad administrativa: gestionar clientes y vigencias. Finalidad corporal independiente y opcional: seguimiento de peso y perímetros, sin diagnósticos. Negarse no impide la membresía. Puede retirar la autorización y solicitar consulta, corrección o supresión ante la administración del gimnasio, que registra y documenta su atención en Solicitudes.</p><p>El soporte explícito de autorización se conserva fuera del directorio público; aquí solo se registra su localizador. No adjunte documentos ni datos sensibles en texto libre. El responsable, canal de contacto, plazo de conservación, manejo de respaldos y obligaciones legales deberán definirse antes de usar datos reales. Esta demostración no certifica cumplimiento jurídico ni admite datos reales.</p></article><?php break;
case 'clientes': ?>
<?php if(is_string($_GET['q']??null) && trim($_GET['q'])!==''): ?><p class="notice">Búsqueda por código: <strong><?=escape($_GET['q'])?></strong> · <a href="?page=clientes">Ver todos los clientes</a></p><?php endif ?>
<form method="get" data-entry><input type="hidden" name="page" value="clientes"><?php if(($_GET['archivados']??'')==='1') rowHidden('archivados','1'); field('q','Buscar por código interno'); ?><button><?=icon('search')?>Buscar</button></form>
<h3>Registrar cliente</h3><?php startForm(); field('code','Código interno inmutable (máximo 30)'); field('name','Nombre (máximo 120)'); field('contact','Contacto opcional','text',false); field('evidence','Localizador del soporte administrativo (máximo 255)'); checkField('administrative','Autorización administrativa informada, versión 1.0. No incluye consentimiento corporal.',true); endForm(); ?>
<?php break;
case 'mensualidades': startForm(); selectField('client_id','Cliente',$clientOptions); $opts=[]; foreach($view['plans'] as $p) $opts[$p['id']]=$p['name']; selectField('plan_id','Plan',$opts); field('start_date','Inicio','date'); field('end_date','Vencimiento','date'); field('amount','Valor acordado COP (no es pago)','number'); checkField('confirm_overlap','He revisado y confirmo el solapamiento de periodos'); endForm('Registrar nuevo periodo'); break;
case 'medidas': ?>
<form method="get" data-entry><input type="hidden" name="page" value="medidas"><?php selectField('client_id','Consultar cliente autorizado',$clientOptions); ?><button><?=icon('search')?>Consultar historial por fecha</button></form>
<?php startForm(); selectField('client_id','Cliente autorizado',$clientOptions); field('measured_on','Fecha (no futura)','date'); selectField('kind','Tipo',['peso'=>'Peso','cintura'=>'Cintura','brazo'=>'Brazo']); field('value','Valor positivo','number'); selectField('unit','Unidad compatible',['kg'=>'kg (peso)','cm'=>'cm (perímetros)']); endForm('Añadir medición histórica'); break;
case 'autorizaciones': startForm(); selectField('client_id','Titular',$clientOptions); selectField('kind','Finalidad separada',['corporal'=>'Corporal opcional','administrativa'=>'Administrativa']); field('evidence','Localizador de soporte explícito privado'); checkField('explicit','El titular autorizó expresamente esta finalidad, aviso versión 1.0',true); endForm('Registrar autorización'); ?>
<?php break;
case 'solicitudes': startForm(); selectField('client_id','Titular',$clientOptions); selectField('kind','Solicitud',['consulta'=>'Consulta','correccion'=>'Corrección','supresion'=>'Supresión']); endForm('Registrar solicitud pendiente'); ?>
<?php break;
case 'cuentas': startForm(); field('email','Correo individual','email'); field('password','Contraseña (mínimo 12 caracteres)','password'); selectField('role','Rol',['recepcion'=>'Recepción','entrenamiento'=>'Entrenamiento','admin'=>'Administración']); endForm('Crear cuenta'); ?>
<?php break;
case 'planes': startForm(); field('name','Nombre del plan'); field('days','Duración sugerida (días)','number'); field('amount','Valor de referencia COP','number'); endForm(); break;
endswitch;
$columnNames=['id'=>'ID','codigo'=>'Código interno','nombre'=>'Nombre','contacto'=>'Contacto','activo'=>'Activo','cliente'=>'Cliente','inicio'=>'Inicio','vencimiento'=>'Vencimiento','valor'=>$page==='mensualidades'?'Valor (COP)':'Valor','estado'=>'Estado','fecha'=>'Fecha','tipo'=>'Tipo','unidad'=>'Unidad','version'=>'Versión','finalidad'=>'Finalidad','soporte'=>'Soporte','otorgada'=>'Otorgada','retirada'=>'Retirada','client_id'=>'ID del cliente','kind'=>'Tipo de solicitud','status'=>'Estado','received_at'=>'Recibida el','resolved_at'=>'Resuelta el','resolution'=>'Resolución','user_id'=>'ID de usuario','happened_at'=>'Fecha y hora','action'=>'Acción','entity'=>'Recurso','resource_id'=>'ID del recurso','result'=>'Resultado','email'=>'Correo electrónico','role'=>'Rol','active'=>'Activa','name'=>'Nombre del plan','days'=>'Duración (días)','amount'=>'Valor (COP)'];
$rowActionPage=in_array($page,['clientes','planes','mensualidades','autorizaciones','solicitudes','cuentas'],true);
$hiddenColumns=$page==='mensualidades'?['client_id','plan_id']:[];
if (in_array($page,['clientes','planes','mensualidades'],true)): ?>
<nav class="record-filters" aria-label="Estado de registros"><a href="?page=<?=escape($page)?>" <?=($_GET['archivados']??'')!=='1'?'aria-current="page"':''?>>Activos</a><a href="?page=<?=escape($page)?>&amp;archivados=1" <?=($_GET['archivados']??'')==='1'?'aria-current="page"':''?>>Archivados</a></nav>
<?php endif;
if (!empty($view['rows'])): ?><section class="records"><h2>Registros consultados</h2><p class="table-help"><?=$rowActionPage?'En pantallas pequeñas, cada registro se muestra con sus campos y Acciones.':'Deslice la tabla para ver todas las columnas.'?> Clientes: últimos 100; bitácora: últimos 200.</p><div class="table" role="region" aria-label="Registros consultados" tabindex="0"><table class="<?=$rowActionPage?'row-records':''?>" role="table"><caption class="sr-only"><?=escape($names[$page]??'Registros')?></caption><thead role="rowgroup"><tr role="row"><?php foreach(array_diff(array_keys($view['rows'][0]),$hiddenColumns) as $key): ?><th scope="col"><?=escape($columnNames[$key]??ucfirst(str_replace('_',' ',$key)))?></th><?php endforeach ?><?php if($rowActionPage): ?><th scope="col">Acciones</th><?php endif ?></tr></thead><tbody role="rowgroup"><?php foreach($view['rows'] as $row): ?><tr role="row"><?php foreach($row as $key=>$v): if(in_array($key,$hiddenColumns,true)) continue; ?><td role="cell" data-label="<?=escape($columnNames[$key]??ucfirst(str_replace('_',' ',$key)))?>"><?=escape($v)?></td><?php endforeach ?><?php if($rowActionPage): ?><td role="cell" class="actions-cell"><?php rowActions($page,$row,$role,$view); ?></td><?php endif ?></tr><?php endforeach ?></tbody></table></div></section><?php elseif(!in_array($page,['ingreso','inicio','privacidad','reportes'])): ?><div class="empty"><h2>Aún no hay resultados</h2><p>No hay registros para esta consulta.</p><p>Registre el primer dato o revise el código buscado.</p><?php if($page==='clientes' && isset($_GET['q'])): ?><a href="?page=clientes">Quitar búsqueda y ver clientes</a><?php endif ?></div><?php endif; endif; ?>
</main></div><footer>Prototipo académico · sin pagos electrónicos · solo datos sintéticos.<br>Pruebas técnicas no equivalen a validación por participantes.</footer></body></html>
