<?php
// All row values are escaped at the output boundary; identifiers are never typed.
function rowHidden(string $name, mixed $value): void {
    echo '<input type="hidden" name="'.escape($name).'" value="'.escape($value).'">';
}
function rowField(string $name, string $label, mixed $value, string $type='text', bool $required=true): void {
    echo '<label>'.escape($label).'<input name="'.escape($name).'" type="'.escape($type).'" value="'.escape($value).'" '.($required?'required':'').' '.($type==='number'?'step="any" min="0"':'').' maxlength="4000"></label>';
}
function rowSelect(string $name, string $label, array $options, mixed $selected): void {
    echo '<label>'.escape($label).'<select required name="'.escape($name).'">';
    foreach($options as $value=>$text) echo '<option value="'.escape($value).'"'.((string)$value===(string)$selected?' selected':'').'>'.escape($text).'</option>';
    echo '</select></label>';
}
function rowStart(string $action, array $row, string $context): void {
    startForm($action);
    echo '<p class="row-context">'.escape($context).'</p>';
    rowHidden('id',$row['id']);
}
function rowActions(string $page, array $row, ?string $role, array $view): void {
    $label=(string)($row['codigo']??$row['cliente']??$row['email']??$row['name']??$row['client_id']??$row['id']);
    $context=$label.' · Registro #'.$row['id'];
    $archivable=in_array($page,['clientes','planes','mensualidades'],true);
    $archived=isset($_GET['archivados']) && $_GET['archivados']==='1';
    $canEdit=$archivable && !$archived;
    $canWithdraw=$page==='autorizaciones' && empty($row['retirada']);
    $canResolve=$page==='solicitudes' && ($row['status']??'')!=='resuelta';
    $canPermit=$page==='cuentas' && (string)$row['id']!==(string)($_SESSION['uid']??'');
    if (!$canEdit && !($archivable && $role==='admin') && !$canWithdraw && !$canResolve && !$canPermit) {
        echo '<span>Sin acciones disponibles</span>'; return;
    }
    echo '<div class="row-actions"><div class="row-action-list">';
    if ($canEdit) {
        rowStart('editar',$row,$context);
        if ($page==='clientes') {
            rowField('name','Nombre',$row['nombre']);
            rowField('contact','Contacto opcional',$row['contacto']??'','text',false);
        } elseif ($page==='planes') {
            rowField('name','Nombre del plan',$row['name']);
            rowField('days','Duración sugerida (días)',$row['days'],'number');
            rowField('amount','Valor de referencia COP',$row['amount'],'number');
        } else {
            rowHidden('client_id',$row['client_id']);
            echo '<p>El cliente de este periodo no se puede cambiar.</p>';
            $options=[]; foreach($view['plans']??[] as $plan) $options[$plan['id']]=$plan['name'];
            if (!isset($options[$row['plan_id']])) $options[$row['plan_id']]='Plan actual #'.$row['plan_id'];
            rowSelect('plan_id','Plan',$options,$row['plan_id']);
            rowField('start_date','Inicio',$row['inicio'],'date');
            rowField('end_date','Vencimiento',$row['vencimiento'],'date');
            rowField('amount','Valor acordado COP (no es pago)',$row['valor'],'number');
            checkField('confirm_overlap','He revisado y confirmo el solapamiento de periodos');
        }
        endForm('Guardar cambios');
    }
    if ($archivable && $role==='admin') {
        rowStart($archived?'restaurar':'archivar',$row,$context);
        if (!$archived) {
            echo '<p>Archivar oculta este registro de los listados activos; no elimina su historial. Puede restaurarlo desde Archivados.</p>';
            checkField('confirmar','Confirmo archivar '.$context,true);
        } else echo '<p>El registro volverá al listado activo. Se conserva su historial.</p>';
        endForm($archived?'Restaurar registro':'Archivar registro');
    }
    if ($canWithdraw) {
        rowStart('retirar',$row,$context);
        rowHidden('client_id',$row['cliente']);
        echo '<p>Finalidad: '.escape($row['tipo']).'. El retiro es irreversible y bloquea captura y consulta corporal cuando corresponde.</p>';
        endForm('Retirar autorización');
    }
    if ($canResolve) {
        rowStart('resolver',$row,$context);
        rowHidden('client_id',$row['client_id']);
        echo '<p>Documente decisión, fundamento y acciones. Resolver no elimina datos. Verifique la eliminación y el tratamiento de respaldos antes de cerrar una supresión concedida.</p>';
        field('resolution','Resolución y fundamento (sin valores corporales)');
        endForm('Registrar resolución');
    }
    if ($canPermit) {
        rowStart('permiso',$row,$context);
        rowSelect('role','Rol',['recepcion'=>'Recepción','entrenamiento'=>'Entrenamiento','admin'=>'Administración'],$row['role']);
        rowSelect('active','Estado',['1'=>'Activa','0'=>'Bloqueada'],$row['active']);
        endForm('Aplicar permiso');
    }
    echo '</div></div>';
}
