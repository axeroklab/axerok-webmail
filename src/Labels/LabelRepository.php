<?php
declare(strict_types=1);

namespace AxerokMail\Labels;

final class LabelRepository
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db=new \PDO((string)$config['dsn'],$config['username']??null,$config['password']??null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $driver=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $id=$driver==='mysql'?'BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY':'INTEGER PRIMARY KEY AUTOINCREMENT';
        $unique=$driver==='mysql'?'UNIQUE KEY owner_name (owner,name), UNIQUE KEY owner_keyword (owner,keyword)':'UNIQUE(owner,name), UNIQUE(owner,keyword)';
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_labels (id {$id}, owner VARCHAR(255) NOT NULL, name VARCHAR(80) NOT NULL, color VARCHAR(7) NOT NULL, keyword VARCHAR(64) NOT NULL, created_at VARCHAR(32) NOT NULL, {$unique})");
    }

    public function all(string $owner): array
    {
        $s=$this->db->prepare('SELECT id,name,color,keyword FROM mail_labels WHERE owner=? ORDER BY name');$s->execute([$owner]);return $s->fetchAll()?:[];
    }

    public function create(string $owner,string $name,string $color): array
    {
        $name=$this->name($name);$color=$this->color($color);$keyword='AxerOK_'.bin2hex(random_bytes(8));
        $s=$this->db->prepare('INSERT INTO mail_labels(owner,name,color,keyword,created_at) VALUES(?,?,?,?,?)');$s->execute([$owner,$name,$color,$keyword,date(DATE_ATOM)]);
        return ['id'=>(int)$this->db->lastInsertId(),'name'=>$name,'color'=>$color,'keyword'=>$keyword];
    }

    public function update(string $owner,int $id,string $name,string $color): void
    {
        $s=$this->db->prepare('UPDATE mail_labels SET name=?,color=? WHERE owner=? AND id=?');$s->execute([$this->name($name),$this->color($color),$owner,$id]);if($s->rowCount()===0)throw new \RuntimeException('La etiqueta no existe.');
    }

    public function delete(string $owner,int $id): void
    {
        $s=$this->db->prepare('DELETE FROM mail_labels WHERE owner=? AND id=?');$s->execute([$owner,$id]);
    }

    public function find(string $owner,int $id): ?array
    {
        $s=$this->db->prepare('SELECT id,name,color,keyword FROM mail_labels WHERE owner=? AND id=?');$s->execute([$owner,$id]);$row=$s->fetch();return $row?:null;
    }

    private function name(string $name): string
    {
        $name=trim($name);if($name===''||strlen($name)>80||preg_match('/[\x00-\x1F\x7F]/',$name))throw new \RuntimeException('El nombre de la etiqueta no es válido.');return $name;
    }

    private function color(string $color): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i',$color)?strtolower($color):'#5b6f95';
    }
}
