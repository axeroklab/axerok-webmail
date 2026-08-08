<?php
declare(strict_types=1);

namespace AxerokMail\Preferences;

final class MailPreferenceRepository
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db=new \PDO((string)$config['dsn'],$config['username']??null,$config['password']??null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        if($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME)==='sqlite'){$this->db->exec('PRAGMA busy_timeout=5000');$this->db->exec('PRAGMA journal_mode=WAL');}
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_preferences (owner VARCHAR(255) PRIMARY KEY, signature_html TEXT NOT NULL, receipt_default INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL)");
        foreach(["display_name VARCHAR(160) NOT NULL DEFAULT ''","organization VARCHAR(160) NOT NULL DEFAULT ''","reply_to VARCHAR(255) NOT NULL DEFAULT ''","default_bcc VARCHAR(1000) NOT NULL DEFAULT ''","view_density VARCHAR(16) NOT NULL DEFAULT 'comfortable'","inbox_view VARCHAR(20) NOT NULL DEFAULT 'categories'"] as $column)$this->ensureColumn('mail_preferences',$column);
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_drafts (owner VARCHAR(255) PRIMARY KEY, recipients_to TEXT NOT NULL, recipients_cc TEXT NOT NULL, recipients_bcc TEXT NOT NULL, subject VARCHAR(300) NOT NULL, body_html TEXT NOT NULL, receipt_requested INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL)");
        $this->ensureColumn('mail_drafts',"priority VARCHAR(10) NOT NULL DEFAULT 'normal'");
    }

    /** @return array<string,mixed> */
    public function preferences(string $owner): array
    {
        $s=$this->db->prepare('SELECT signature_html,receipt_default,display_name,organization,reply_to,default_bcc,view_density,inbox_view FROM mail_preferences WHERE owner=?');$s->execute([$owner]);$row=$s->fetch();
        return ['signature_html'=>(string)($row['signature_html']??''),'receipt_default'=>(bool)($row['receipt_default']??false),'display_name'=>(string)($row['display_name']??''),'organization'=>(string)($row['organization']??''),'reply_to'=>(string)($row['reply_to']??''),'default_bcc'=>(string)($row['default_bcc']??''),'view_density'=>(string)($row['view_density']??'comfortable'),'inbox_view'=>(string)($row['inbox_view']??'categories')];
    }

    public function savePreferences(string $owner,string $signatureHtml,bool $receiptDefault,array $identity=[]): void
    {
        $now=date(DATE_ATOM);
        $values=[(string)($identity['display_name']??''),(string)($identity['organization']??''),(string)($identity['reply_to']??''),(string)($identity['default_bcc']??''),in_array(($identity['view_density']??''),['default','comfortable','compact'],true)?$identity['view_density']:'comfortable',in_array(($identity['inbox_view']??''),['default','categories','unread','starred'],true)?$identity['inbox_view']:'categories'];
        $updated=$this->db->prepare('UPDATE mail_preferences SET signature_html=?,receipt_default=?,display_name=?,organization=?,reply_to=?,default_bcc=?,view_density=?,inbox_view=?,updated_at=? WHERE owner=?');$updated->execute([$signatureHtml,$receiptDefault?1:0,...$values,$now,$owner]);if($updated->rowCount()>0)return;
        try{$s=$this->db->prepare('INSERT INTO mail_preferences(owner,signature_html,receipt_default,display_name,organization,reply_to,default_bcc,view_density,inbox_view,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$owner,$signatureHtml,$receiptDefault?1:0,...$values,$now]);}catch(\PDOException){$updated->execute([$signatureHtml,$receiptDefault?1:0,...$values,$now,$owner]);}
    }

    public function draft(string $owner): ?array
    {
        $s=$this->db->prepare('SELECT recipients_to AS `to`,recipients_cc AS cc,recipients_bcc AS bcc,subject,body_html,receipt_requested,priority,updated_at FROM mail_drafts WHERE owner=?');$s->execute([$owner]);$row=$s->fetch();return $row?:null;
    }

    public function saveDraft(string $owner,array $draft): void
    {
        $priority=in_array(($draft['priority']??'normal'),['low','normal','high'],true)?(string)$draft['priority']:'normal';$values=[(string)($draft['to']??''),(string)($draft['cc']??''),(string)($draft['bcc']??''),(string)($draft['subject']??''),(string)($draft['body_html']??''),!empty($draft['receipt_requested'])?1:0,$priority,date(DATE_ATOM)];
        $updated=$this->db->prepare('UPDATE mail_drafts SET recipients_to=?,recipients_cc=?,recipients_bcc=?,subject=?,body_html=?,receipt_requested=?,priority=?,updated_at=? WHERE owner=?');$updated->execute([...$values,$owner]);if($updated->rowCount()>0)return;
        try{$s=$this->db->prepare('INSERT INTO mail_drafts(owner,recipients_to,recipients_cc,recipients_bcc,subject,body_html,receipt_requested,priority,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$owner,...$values]);}catch(\PDOException){$updated->execute([...$values,$owner]);}
    }

    public function deleteDraft(string $owner): void { $s=$this->db->prepare('DELETE FROM mail_drafts WHERE owner=?');$s->execute([$owner]); }

    private function ensureColumn(string $table,string $definition): void
    {
        $column=strtok($definition,' ');$driver=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){$rows=$this->db->query('PRAGMA table_info('.$table.')')->fetchAll();foreach($rows as $row)if(($row['name']??null)===$column)return;}
        else{$query=$this->db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$query->execute([$table,$column]);if($query->fetchColumn())return;}
        $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
}
