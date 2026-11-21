<?php
declare(strict_types=1);
final class Schema {
    public static function create(PDO $db): void {
        $mysql = $db->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql';
        $id = $mysql ? 'INT PRIMARY KEY AUTO_INCREMENT' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $suffix = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $tables = [
            'users'=>"id $id, email VARCHAR(120) NOT NULL UNIQUE, password_hash VARCHAR(255) NOT NULL, role VARCHAR(20) NOT NULL CHECK(role IN ('admin','recepcion','entrenamiento')), active INTEGER NOT NULL DEFAULT 1",
            'clients'=>"id $id, code VARCHAR(30) NOT NULL UNIQUE, name VARCHAR(120) NOT NULL, contact VARCHAR(120) NOT NULL DEFAULT '', active INTEGER NOT NULL DEFAULT 1",
            'consents'=>"id $id, client_id INTEGER NOT NULL REFERENCES clients(id), kind VARCHAR(20) NOT NULL CHECK(kind IN ('administrativa','corporal')), version VARCHAR(30) NOT NULL, purpose VARCHAR(255) NOT NULL, evidence VARCHAR(255) NOT NULL, granted_at DATETIME NOT NULL, withdrawn_at DATETIME NULL",
            'plans'=>"id $id, name VARCHAR(80) NOT NULL, days INTEGER NOT NULL CHECK(days>0), amount DECIMAL(12,2) NOT NULL CHECK(amount>=0)",
            'memberships'=>"id $id, client_id INTEGER NOT NULL REFERENCES clients(id), plan_id INTEGER NOT NULL REFERENCES plans(id), start_date DATE NOT NULL, end_date DATE NOT NULL, amount DECIMAL(12,2) NOT NULL CHECK(amount>=0), CHECK(end_date>=start_date)",
            'measurements'=>"id $id, client_id INTEGER NOT NULL REFERENCES clients(id), consent_id INTEGER NOT NULL REFERENCES consents(id), user_id INTEGER NOT NULL REFERENCES users(id), measured_on DATE NOT NULL, kind VARCHAR(30) NOT NULL, value DECIMAL(8,2) NOT NULL CHECK(value>0), unit VARCHAR(10) NOT NULL",
            'requests'=>"id $id, client_id INTEGER NOT NULL REFERENCES clients(id), kind VARCHAR(20) NOT NULL CHECK(kind IN ('consulta','correccion','supresion')), received_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'pendiente', resolved_at DATETIME NULL, resolution TEXT NULL",
            'audit'=>"id $id, user_id INTEGER NULL REFERENCES users(id), happened_at DATETIME NOT NULL, action VARCHAR(40) NOT NULL, entity VARCHAR(40) NOT NULL, resource_id INTEGER NULL, result VARCHAR(20) NOT NULL"
        ];
        // MySQL requires table-level foreign keys (inline REFERENCES is not enforced).
        foreach ($tables as $name=>$columns) {
            if ($mysql) {
                $foreign = [];
                $columns = preg_replace_callback('/(\w+) INTEGER (NOT NULL|NULL) REFERENCES (\w+)\(id\)/', function($m) use (&$foreign) {
                    $foreign[] = "FOREIGN KEY ({$m[1]}) REFERENCES {$m[3]}(id) ON DELETE RESTRICT";
                    return "{$m[1]} INTEGER {$m[2]}";
                }, $columns);
                if ($foreign) $columns .= ', '.implode(', ', $foreign);
            }
            $db->exec("CREATE TABLE IF NOT EXISTS $name ($columns)$suffix");
        }
        // Run setup only on an empty database; these indexes deliberately surface repeat setup.
        $db->exec('CREATE INDEX memberships_end ON memberships(end_date)');
        $db->exec('CREATE INDEX measurements_history ON measurements(client_id, measured_on)');
        $db->exec('CREATE INDEX consents_client ON consents(client_id, kind)');
    }
}
