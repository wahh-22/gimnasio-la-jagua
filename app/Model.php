<?php
declare(strict_types=1);
final class Model {
    public function __construct(public PDO $db) {}
    public function query(string $sql, array $args=[]): PDOStatement { $s=$this->db->prepare($sql); $s->execute($args); return $s; }
    public function rows(string $sql, array $args=[]): array { return $this->query($sql,$args)->fetchAll(); }
    public function one(string $sql, array $args=[]): array { return $this->query($sql,$args)->fetch() ?: []; }
    /** Administrative aggregates only: periods are records, not unique clients. */
    public function reportSummary(): array {
        $today=new DateTimeImmutable('today');
        $from=$today->format('Y-m-d'); $through=$today->modify('+7 days')->format('Y-m-d');
        $counts=$this->one('SELECT COUNT(*) AS periods,
            COALESCE(SUM(CASE WHEN end_date < ? THEN 1 ELSE 0 END),0) AS past,
            COALESCE(SUM(CASE WHEN end_date >= ? AND end_date <= ? THEN 1 ELSE 0 END),0) AS soon,
            COALESCE(SUM(CASE WHEN end_date > ? THEN 1 ELSE 0 END),0) AS later
            FROM memberships',[$from,$from,$through,$through]);
        $summary=array_map('intval',$counts);
        $summary['clients']=(int)$this->query('SELECT COUNT(*) FROM clients')->fetchColumn();
        $summary['from']=$from; $summary['through']=$through;
        $summary['plans']=$this->rows('SELECT p.id,p.name,COUNT(m.id) AS periods FROM plans p
            LEFT JOIN memberships m ON m.plan_id=p.id GROUP BY p.id,p.name ORDER BY p.name,p.id');
        return $summary;
    }
    public function audit(?int $user, string $action, string $entity, ?int $id=null, string $result='permitido'): void {
        $this->query('INSERT INTO audit(user_id,happened_at,action,entity,resource_id,result) VALUES(?,?,?,?,?,?)',[$user,date('Y-m-d H:i:s'),$action,$entity,$id,$result]);
    }
    public function transaction(callable $fn): mixed {
        $this->db->beginTransaction();
        try { $value=$fn(); $this->db->commit(); return $value; }
        catch (Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    public function lockClient(int $id): array {
        $lock=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql' ? ' FOR UPDATE' : '';
        $client=$this->one('SELECT * FROM clients WHERE id=?'.$lock,[$id]);
        if (!$client) throw new DomainException('Cliente inexistente.');
        return $client;
    }
    public function consent(int $id, string $kind): array {
        return $this->one('SELECT * FROM consents WHERE client_id=? AND kind=? AND withdrawn_at IS NULL ORDER BY id DESC LIMIT 1',[$id,$kind]);
    }
    public function insert(string $table, array $values): int {
        // Table/column names come exclusively from controller constants, never from HTTP input.
        $this->query('INSERT INTO '.$table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')',array_values($values));
        return (int)$this->db->lastInsertId();
    }
}
