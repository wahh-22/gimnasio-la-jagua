<?php
declare(strict_types=1);
final class ConsentDenied extends RuntimeException {
    public function __construct(public int $clientId, public string $operation) {
        parent::__construct('Sin consentimiento corporal vigente.',403);
    }
}
final class Controller {
    private const ROLES = [
        'clientes'=>['admin','recepcion'], 'mensualidades'=>['admin','recepcion'],
        'medidas'=>['entrenamiento'], 'autorizaciones'=>['admin'], 'solicitudes'=>['admin'],
        'bitacora'=>['admin'], 'cuentas'=>['admin'], 'planes'=>['admin'], 'reportes'=>['admin']
    ];
    public function __construct(private Model $m) {}
    private function text(array $d,string $key,int $max=120): string {
        $v=$d[$key]??'';
        if (!is_string($v) || trim($v)==='' || mb_strlen(trim($v))>$max) throw new DomainException("Campo $key obligatorio o demasiado largo.");
        return trim($v);
    }
    private function date(array $d,string $key): string {
        $v=$this->text($d,$key,10); $date=DateTimeImmutable::createFromFormat('!Y-m-d',$v);
        if (!$date || $date->format('Y-m-d')!==$v) throw new DomainException("Fecha $key inválida.");
        return $v;
    }
    private function number(array $d,string $key,bool $zero=false): float {
        $v=$d[$key]??null;
        if (!is_scalar($v) || !is_numeric($v) || !is_finite((float)$v) || ($zero ? (float)$v<0 : (float)$v<=0) || (float)$v>99999999) throw new DomainException("Valor $key inválido.");
        return (float)$v;
    }
    private function id(array $d,string $key='client_id'): int {
        if (!isset($d[$key]) || (!is_int($d[$key]) && !is_string($d[$key]))) throw new DomainException("Identificador $key inválido.");
        $v=filter_var($d[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($v===false) throw new DomainException("Identificador $key inválido.");
        return $v;
    }
    private function choice(array $d,string $key,array $choices): string {
        $v=$this->text($d,$key); if (!in_array($v,$choices,true)) throw new DomainException("Opción $key inválida."); return $v;
    }
    public function handle(string $page,string $method,array $data): array {
        if (isset($_SESSION['uid']) && time()-(int)($_SESSION['last_activity']??0)>=1200) {
            $this->m->audit((int)$_SESSION['uid'],'sesion_expirada','users',(int)$_SESSION['uid']);
            $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
            throw new RuntimeException('Sesión caducada por 20 minutos de inactividad. Inicie sesión de nuevo.',401);
        }
        if ($page==='privacidad') return ['page'=>$page,'rows'=>[]];
        if ($method==='POST' && (!isset($data['csrf']) || !is_string($data['csrf']) || !hash_equals($_SESSION['csrf'],$data['csrf']))) throw new RuntimeException('Solicitud sin token de seguridad válido.',403);
        if ($page==='ingreso') {
            if ($method==='POST') {
                $email=strtolower($this->text($data,'email')); $password=$this->text($data,'password',200);
                $u=$this->m->one('SELECT * FROM users WHERE email=? AND active=1',[$email]);
                if (!$u || !password_verify($password,$u['password_hash'])) {
                    $this->m->audit(null,'ingreso','users',null,'denegado');
                    throw new RuntimeException('Credenciales inválidas.',401);
                }
                session_regenerate_id(true); $_SESSION['uid']=(int)$u['id']; $_SESSION['last_activity']=time(); $_SESSION['csrf']=bin2hex(random_bytes(32));
                $this->m->audit((int)$u['id'],'ingreso','users',(int)$u['id']);
                return ['redirect'=>'inicio'];
            }
            return ['page'=>$page,'rows'=>[]];
        }
        $u=$this->m->one('SELECT id,email,role FROM users WHERE id=? AND active=1',[$_SESSION['uid']??0]);
        if (!$u) throw new RuntimeException('Debe iniciar sesión.',401);
        $uid=(int)$u['id']; $_SESSION['last_activity']=time();
        if ($page==='salir') {
            if ($method!=='POST') throw new RuntimeException('Use el botón Cerrar sesión.',405);
            $this->m->audit($uid,'cierre','users',$uid); $_SESSION=[]; session_regenerate_id(true); $_SESSION['csrf']=bin2hex(random_bytes(32));
            return ['redirect'=>'ingreso'];
        }
        if ($page!=='inicio' && (!isset(self::ROLES[$page]) || !in_array($u['role'],self::ROLES[$page],true))) {
            $this->m->audit($uid,'acceso','recurso',null,'denegado'); throw new RuntimeException('Acceso denegado para su rol.',403);
        }
        if ($page==='reportes') {
            if ($method!=='GET') throw new RuntimeException('Reportes es de solo lectura.',405);
            return ['page'=>$page,'rows'=>[],'u'=>$u,'report'=>$this->m->reportSummary()];
        }
        if ($method==='POST') {
            try {
                $this->m->transaction(function() use($page,$data,$uid,$u) { $this->mutate($page,$data,$uid,$u); });
            } catch (ConsentDenied $e) {
                // Business changes have rolled back; the denial event must survive independently.
                $this->m->audit($uid,$e->operation,'clients',$e->clientId,'denegado');
                throw $e;
            }
            return ['redirect'=>$page];
        }
        $rows=[]; $clients=[]; $plans=[]; $history=[];
        $archived=($data['archivados']??'')==='1';
        switch ($page) {
            case 'clientes':
                $q=isset($data['q']) && is_string($data['q']) ? trim($data['q']) : '';
                $rows=$this->m->rows('SELECT id,code AS codigo,name AS nombre,contact AS contacto,active AS activo FROM clients WHERE active=?'.($q!==''?' AND code=?':'').' ORDER BY id DESC LIMIT 100',$q!==''?[$archived?0:1,$q]:[$archived?0:1]); break;
            case 'mensualidades':
                $rows=$this->m->rows('SELECT m.id,m.client_id,m.plan_id,c.code AS cliente,m.start_date AS inicio,m.end_date AS vencimiento,m.amount AS valor FROM memberships m JOIN clients c ON c.id=m.client_id WHERE m.archived=? ORDER BY m.end_date,m.id',[$archived?1:0]);
                $today=date('Y-m-d'); $week=date('Y-m-d',strtotime('+7 days'));
                foreach ($rows as &$r) $r['estado']=$r['vencimiento']<$today?'Vencida':($r['vencimiento']<=$week?'Próxima (hoy a +7 días)':'Posterior'); unset($r); break;
            case 'medidas':
                $clients=$this->m->rows("SELECT c.id,c.code FROM clients c WHERE c.active=1 AND EXISTS(SELECT 1 FROM consents a WHERE a.client_id=c.id AND a.kind='corporal' AND a.withdrawn_at IS NULL)");
                if (!empty($data['client_id'])) {
                    $id=$this->id($data); $this->m->db->beginTransaction();
                    try {
                        $client=$this->m->lockClient($id);
                        if (!$client['active'] || !$this->m->consent($id,'corporal')) throw new ConsentDenied($id,'consulta_corporal');
                        $rows=$this->m->rows('SELECT measured_on AS fecha,kind AS tipo,value AS valor,unit AS unidad FROM measurements WHERE client_id=? ORDER BY measured_on,id',[$id]);
                        $this->m->audit($uid,'consulta_corporal','clients',$id); $this->m->db->commit();
                    } catch(Throwable $e) {
                        $this->m->db->rollBack();
                        if ($e instanceof ConsentDenied) $this->m->audit($uid,$e->operation,'clients',$e->clientId,'denegado');
                        throw $e;
                    }
                } break;
            case 'autorizaciones': $rows=$this->m->rows('SELECT id,client_id AS cliente,kind AS tipo,version,purpose AS finalidad,evidence AS soporte,granted_at AS otorgada,withdrawn_at AS retirada FROM consents ORDER BY id DESC'); break;
            case 'solicitudes': $rows=$this->m->rows('SELECT * FROM requests ORDER BY id DESC'); break;
            case 'bitacora': $rows=$this->m->rows('SELECT * FROM audit ORDER BY id DESC LIMIT 200'); break;
            case 'cuentas': $rows=$this->m->rows('SELECT id,email,role,active FROM users'); break;
            case 'planes': $rows=$this->m->rows('SELECT id,name,days,amount FROM plans WHERE archived=?',[$archived?1:0]); break;
        }
        if (in_array($page,['mensualidades','autorizaciones','solicitudes'],true)) $clients=$this->m->rows('SELECT id,code FROM clients WHERE active=1 ORDER BY code');
        if ($page==='mensualidades') $plans=$this->m->rows('SELECT id,name FROM plans WHERE archived=0');
        return compact('page','rows','clients','plans','u');
    }
    private function mutate(string $page,array $d,int $uid,array $u): void {
        $actions=match($page) {
            'clientes','planes','mensualidades'=>['crear','editar','archivar','restaurar'], 'autorizaciones'=>['crear','retirar'],
            'solicitudes'=>['crear','resolver'], 'cuentas'=>['crear','permiso'],
            'medidas'=>['crear'], default=>[]
        };
        $d['action'] ??= 'crear';
        $action=$this->choice($d,'action',$actions); $id=null; $entity=$page;
        if (in_array($action,['archivar','restaurar'],true)) {
            if ($u['role']!=='admin') throw new RuntimeException('Solo administración puede archivar o restaurar.',403);
            if ($action==='archivar' && ($d['confirmar']??'')!=='si') throw new DomainException('Confirme el archivo explícitamente.');
            $id=$this->id($d,'id');
            $table=match($page) {'clientes'=>'clients','planes'=>'plans','mensualidades'=>'memberships'};
            $row=$this->lockRow($table,$id);
            $column=$page==='clientes'?'active':'archived';
            $value=$page==='clientes'?($action==='archivar'?0:1):($action==='archivar'?1:0);
            if ((int)$row[$column]===$value) throw new DomainException('El registro ya tiene ese estado.');
            if ($page==='mensualidades' && $action==='restaurar') $this->period($row,$id,true);
            $this->m->query("UPDATE $table SET $column=? WHERE id=?",[$value,$id]);
            $this->m->audit($uid,$action,$entity,$id);
            return;
        }
        switch ($page) {
            case 'clientes':
                if ($action==='editar') {
                    $id=$this->id($d,'id'); $this->m->lockClient($id);
                    $this->m->query('UPDATE clients SET name=?,contact=? WHERE id=?',[$this->text($d,'name'),isset($d['contact'])&&$d['contact']!==''?$this->text($d,'contact'):'',$id]);
                } else {
                    if (($d['administrative']??'')!=='si') throw new DomainException('Debe documentar la autorización administrativa, separada de la corporal.');
                    $id=$this->m->insert('clients',['code'=>$this->text($d,'code',30),'name'=>$this->text($d,'name'),'contact'=>isset($d['contact'])&&$d['contact']!==''?$this->text($d,'contact'):'']);
                    $this->grant($id,'administrativa',$this->text($d,'evidence',255));
                } break;
            case 'mensualidades':
                if ($action==='editar') {
                    $id=$this->id($d,'id'); $existing=$this->lockRow('memberships',$id);
                    if ($existing['archived']) throw new DomainException('Restaure el periodo antes de editarlo.');
                    $d['client_id']=$existing['client_id']; // Never rebind an existing period from HTTP input.
                }
                $values=$this->period($d,$id);
                if ($id===null) $id=$this->m->insert('memberships',$values);
                else $this->m->query('UPDATE memberships SET plan_id=?,start_date=?,end_date=?,amount=? WHERE id=?',[$values['plan_id'],$values['start_date'],$values['end_date'],$values['amount'],$id]);
                break;
            case 'medidas':
                $cid=$this->id($d); $c=$this->m->lockClient($cid); $a=$this->m->consent($cid,'corporal');
                if (!$c['active'] || !$a) throw new ConsentDenied($cid,'captura_corporal');
                $date=$this->date($d,'measured_on'); if ($date>date('Y-m-d')) throw new DomainException('La medición no puede ser futura.');
                $kind=$this->choice($d,'kind',['peso','cintura','brazo']); $unit=$kind==='peso'?'kg':'cm';
                if (($d['unit']??'')!==$unit) throw new DomainException('Unidad incompatible con el tipo.');
                $id=$this->m->insert('measurements',['client_id'=>$cid,'consent_id'=>$a['id'],'user_id'=>$uid,'measured_on'=>$date,'kind'=>$kind,'value'=>$this->number($d,'value'),'unit'=>$unit]); break;
            case 'autorizaciones':
                $cid=$this->id($d); $this->m->lockClient($cid);
                if ($action==='retirar') {
                    $id=$this->id($d,'id');
                    $a=$this->m->one('SELECT * FROM consents WHERE id=? AND client_id=? AND withdrawn_at IS NULL',[$id,$cid]);
                    if (!$a) throw new DomainException('Autorización vigente no encontrada para este titular.');
                    $this->m->query('UPDATE consents SET withdrawn_at=? WHERE id=?',[date('Y-m-d H:i:s'),$id]);
                } else {
                    if (($d['explicit']??'')!=='si') throw new DomainException('Se requiere autorización explícita del titular.');
                    $kind=$this->choice($d,'kind',['administrativa','corporal']);
                    $id=$this->grant($cid,$kind,$this->text($d,'evidence',255));
                } break;
            case 'solicitudes':
                $cid=$this->id($d); $this->m->lockClient($cid);
                if ($action==='resolver') {
                    $id=$this->id($d,'id'); $r=$this->m->one('SELECT * FROM requests WHERE id=? AND client_id=? AND status<>?',[$id,$cid,'resuelta']);
                    if (!$r) throw new DomainException('Solicitud pendiente no encontrada.');
                    $this->m->query('UPDATE requests SET status=?,resolved_at=?,resolution=? WHERE id=?',['resuelta',date('Y-m-d H:i:s'),$this->text($d,'resolution',4000),$id]);
                } else $id=$this->m->insert('requests',['client_id'=>$cid,'kind'=>$this->choice($d,'kind',['consulta','correccion','supresion']),'received_at'=>date('Y-m-d H:i:s')]);
                break;
            case 'cuentas':
                if ($action==='permiso') {
                    $id=$this->id($d,'id'); if ($id===$uid) throw new DomainException('No puede cambiar su propio acceso.');
                    if (!$this->m->one('SELECT id FROM users WHERE id=?',[$id])) throw new DomainException('Cuenta inexistente.');
                    $role=$this->choice($d,'role',['admin','recepcion','entrenamiento']); $active=$this->choice($d,'active',['0','1']);
                    $this->m->query('UPDATE users SET role=?,active=? WHERE id=?',[$role,$active,$id]);
                } else {
                    $email=strtolower($this->text($d,'email')); if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new DomainException('Correo inválido.');
                    $password=$this->text($d,'password',200); if (strlen($password)<12) throw new DomainException('Contraseña mínima de 12 caracteres.');
                    $id=$this->m->insert('users',['email'=>$email,'password_hash'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$this->choice($d,'role',['admin','recepcion','entrenamiento'])]);
                } break;
            case 'planes':
                $days=$this->id($d,'days'); if ($days>3650) throw new DomainException('Duración máxima: 3650 días.');
                $values=['name'=>$this->text($d,'name',80),'days'=>$days,'amount'=>$this->number($d,'amount',true)];
                if ($action==='editar') {
                    $id=$this->id($d,'id'); $this->lockRow('plans',$id);
                    $this->m->query('UPDATE plans SET name=?,days=?,amount=? WHERE id=?',[...array_values($values),$id]);
                } else $id=$this->m->insert('plans',$values);
                break;
            default: throw new RuntimeException('Operación no permitida.',405);
        }
        $this->m->audit($uid,(string)$action,$entity,$id);
    }
    /** Table names are internal constants only. */
    private function lockRow(string $table,int $id): array {
        $lock=$this->m->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $row=$this->m->one("SELECT * FROM $table WHERE id=?".$lock,[$id]);
        if (!$row) throw new DomainException('Registro inexistente.');
        return $row;
    }
    private function period(array $d,?int $exclude=null,bool $restore=false): array {
        $cid=$this->id($d); $c=$this->m->lockClient($cid);
        if (!$c['active'] || !$this->m->consent($cid,'administrativa')) throw new DomainException('Cliente sin autorización administrativa vigente.');
        $start=$this->date($d,'start_date'); $end=$this->date($d,'end_date');
        if ($end<$start) throw new DomainException('Vencimiento anterior al inicio.');
        $pid=$this->id($d,'plan_id'); $plan=$this->lockRow('plans',$pid);
        if ($plan['archived']) throw new DomainException('Plan archivado.');
        // Current locking read, serialized by client: includes a preceding transaction's committed period.
        $lock=$this->m->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
        $overlap=$this->m->one('SELECT id FROM memberships WHERE client_id=? AND archived=0 AND start_date<=? AND end_date>=? AND id<>?'.$lock,[$cid,$end,$start,$exclude??0]);
        if ($overlap && ($restore || ($d['confirm_overlap']??'')!=='si')) throw new DomainException('Periodo solapado: revise y confirme explícitamente.');
        return ['client_id'=>$cid,'plan_id'=>$pid,'start_date'=>$start,'end_date'=>$end,'amount'=>$this->number($d,'amount',true)];
    }
    private function grant(int $cid,string $kind,string $evidence): int {
        return $this->m->insert('consents',['client_id'=>$cid,'kind'=>$kind,'version'=>'1.0','purpose'=>$kind==='corporal'?'Seguimiento corporal opcional':'Gestión de clientes y vigencias','evidence'=>$evidence,'granted_at'=>date('Y-m-d H:i:s')]);
    }
}
