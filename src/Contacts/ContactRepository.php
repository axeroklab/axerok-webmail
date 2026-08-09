<?php
declare(strict_types=1);

namespace AxerokMail\Contacts;

final class ContactRepository
{
    private \PDO $db;
    public function __construct(array $config)
    {
        $this->db = new \PDO((string)$config['dsn'], $config['username'] ?? null, $config['password'] ?? null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $driver=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){$this->db->exec('PRAGMA busy_timeout=5000');$this->db->exec('PRAGMA journal_mode=WAL');}
        $id=$driver==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $this->db->exec("CREATE TABLE IF NOT EXISTS contacts (id {$id}, owner VARCHAR(255) NOT NULL, email VARCHAR(320) NOT NULL, name VARCHAR(255) NOT NULL DEFAULT '', phone VARCHAR(80) NOT NULL DEFAULT '', organization VARCHAR(255) NOT NULL DEFAULT '', created_at VARCHAR(32) NOT NULL, updated_at VARCHAR(32) NOT NULL, UNIQUE(owner,email))");
    }
    public function all(string $owner): array { $s=$this->db->prepare('SELECT id,email,name,phone,organization FROM contacts WHERE owner=? ORDER BY name,email');$s->execute([$owner]);return $s->fetchAll(); }
    public function upsert(string $owner, array $contact): string
    {
        $email=strtolower(trim((string)($contact['email']??''))); if(!filter_var($email,FILTER_VALIDATE_EMAIL)){return 'invalid';}
        $name=trim((string)($contact['name']??''));$phone=trim((string)($contact['phone']??''));$organization=trim((string)($contact['organization']??''));
        if(self::length($name)>255||self::length($phone)>80||self::length($organization)>255)return 'invalid';
        $existing=$this->db->prepare('SELECT id FROM contacts WHERE owner=? AND email=?');$existing->execute([$owner,$email]);$wasExisting=(bool)$existing->fetchColumn();$now=date(DATE_ATOM);$driver=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $values=[$name,$phone,$organization,$now,$owner,$email];
        if($wasExisting){
            $s=$this->db->prepare('UPDATE contacts SET name=?,phone=?,organization=?,updated_at=? WHERE owner=? AND email=?');
            $s->execute($values);
            return 'updated';
        }
        try {
            $s=$this->db->prepare('INSERT INTO contacts(owner,email,name,phone,organization,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
            $s->execute([$owner,$email,$name,$phone,$organization,$now,$now]);
            return 'created';
        } catch(\PDOException) {
            // A concurrent import may have inserted the same owner/email.
            $s=$this->db->prepare('UPDATE contacts SET name=?,phone=?,organization=?,updated_at=? WHERE owner=? AND email=?');
            $s->execute($values);
            return 'updated';
        }
    }

    public function import(string $owner,array $contacts): array
    {
        $stats=['created'=>0,'updated'=>0,'invalid'=>0];$this->db->beginTransaction();
        try{foreach($contacts as $contact){$result=$this->upsert($owner,$contact);$stats[$result]++;}$this->db->commit();return $stats;}catch(\Throwable $error){if($this->db->inTransaction())$this->db->rollBack();throw $error;}
    }

    private static function length(string $value): int { return function_exists('mb_strlen')?mb_strlen($value,'UTF-8'):strlen($value); }
}
