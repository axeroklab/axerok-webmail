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
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_drafts_v2 (id VARCHAR(64) NOT NULL, owner VARCHAR(255) NOT NULL, recipients_to TEXT NOT NULL, recipients_cc TEXT NOT NULL, recipients_bcc TEXT NOT NULL, subject VARCHAR(300) NOT NULL, body_html TEXT NOT NULL, receipt_requested INTEGER NOT NULL DEFAULT 0, priority VARCHAR(10) NOT NULL DEFAULT 'normal', updated_at VARCHAR(32) NOT NULL, PRIMARY KEY(owner,id))");
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_templates (id VARCHAR(64) NOT NULL, owner VARCHAR(255) NOT NULL, name VARCHAR(120) NOT NULL, subject VARCHAR(300) NOT NULL, body_html TEXT NOT NULL, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY(owner,id))");
        $this->db->exec("CREATE TABLE IF NOT EXISTS blocked_senders (owner VARCHAR(255) NOT NULL, sender VARCHAR(255) NOT NULL, created_at VARCHAR(32) NOT NULL, PRIMARY KEY(owner,sender))");
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

    /** @return array<int,array<string,mixed>> */
    public function drafts(string $owner): array
    {
        $this->importLegacyDraft($owner);$s=$this->db->prepare('SELECT id,recipients_to AS `to`,recipients_cc AS cc,recipients_bcc AS bcc,subject,body_html,receipt_requested,priority,updated_at FROM mail_drafts_v2 WHERE owner=? ORDER BY updated_at DESC');$s->execute([$owner]);return $s->fetchAll()?:[];
    }

    /** @return array<string,mixed> */
    public function saveDraft(string $owner,array $draft): array
    {
        $id=(string)($draft['id']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$id))$id=bin2hex(random_bytes(16));$priority=in_array(($draft['priority']??'normal'),['low','normal','high'],true)?(string)$draft['priority']:'normal';$values=[(string)($draft['to']??''),(string)($draft['cc']??''),(string)($draft['bcc']??''),(string)($draft['subject']??''),(string)($draft['body_html']??''),!empty($draft['receipt_requested'])?1:0,$priority,date(DATE_ATOM)];
        $updated=$this->db->prepare('UPDATE mail_drafts_v2 SET recipients_to=?,recipients_cc=?,recipients_bcc=?,subject=?,body_html=?,receipt_requested=?,priority=?,updated_at=? WHERE owner=? AND id=?');$updated->execute([...$values,$owner,$id]);if($updated->rowCount()===0){try{$s=$this->db->prepare('INSERT INTO mail_drafts_v2(id,owner,recipients_to,recipients_cc,recipients_bcc,subject,body_html,receipt_requested,priority,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$id,$owner,...$values]);}catch(\PDOException){$updated->execute([...$values,$owner,$id]);}}
        return ['id'=>$id,'to'=>$values[0],'cc'=>$values[1],'bcc'=>$values[2],'subject'=>$values[3],'body_html'=>$values[4],'receipt_requested'=>(bool)$values[5],'priority'=>$priority,'updated_at'=>$values[7]];
    }

    public function deleteDraft(string $owner,string $id=''): void {if($id==='')return;$s=$this->db->prepare('DELETE FROM mail_drafts_v2 WHERE owner=? AND id=?');$s->execute([$owner,$id]);}

    private function importLegacyDraft(string $owner): void
    {
        $check=$this->db->prepare('SELECT 1 FROM mail_drafts_v2 WHERE owner=? LIMIT 1');$check->execute([$owner]);if($check->fetchColumn())return;$legacy=$this->db->prepare('SELECT recipients_to,recipients_cc,recipients_bcc,subject,body_html,receipt_requested,priority,updated_at FROM mail_drafts WHERE owner=?');$legacy->execute([$owner]);$row=$legacy->fetch();if(!$row)return;$insert=$this->db->prepare('INSERT INTO mail_drafts_v2(id,owner,recipients_to,recipients_cc,recipients_bcc,subject,body_html,receipt_requested,priority,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$insert->execute([bin2hex(random_bytes(16)),$owner,$row['recipients_to'],$row['recipients_cc'],$row['recipients_bcc'],$row['subject'],$row['body_html'],$row['receipt_requested'],$row['priority'],$row['updated_at']]);
    }

    /** @return array<int,array<string,mixed>> */
    public function templates(string $owner): array {$s=$this->db->prepare('SELECT id,name,subject,body_html,updated_at FROM mail_templates WHERE owner=? ORDER BY name');$s->execute([$owner]);return $s->fetchAll()?:[];}

    /** @return array<string,mixed> */
    public function saveTemplate(string $owner,array $template): array
    {
        $id=(string)($template['id']??'');if(!preg_match('/^[a-zA-Z0-9-]{16,64}$/',$id))$id=bin2hex(random_bytes(16));$name=trim((string)($template['name']??''));if($name===''||strlen($name)>240)throw new \RuntimeException('El nombre de la plantilla no es válido.');$subject=substr((string)($template['subject']??''),0,300);$body=(string)($template['body_html']??'');$updatedAt=date(DATE_ATOM);
        $updated=$this->db->prepare('UPDATE mail_templates SET name=?,subject=?,body_html=?,updated_at=? WHERE owner=? AND id=?');$updated->execute([$name,$subject,$body,$updatedAt,$owner,$id]);if($updated->rowCount()===0){try{$insert=$this->db->prepare('INSERT INTO mail_templates(id,owner,name,subject,body_html,updated_at) VALUES(?,?,?,?,?,?)');$insert->execute([$id,$owner,$name,$subject,$body,$updatedAt]);}catch(\PDOException){$updated->execute([$name,$subject,$body,$updatedAt,$owner,$id]);}}return ['id'=>$id,'name'=>$name,'subject'=>$subject,'body_html'=>$body,'updated_at'=>$updatedAt];
    }

    public function deleteTemplate(string $owner,string $id): void {$s=$this->db->prepare('DELETE FROM mail_templates WHERE owner=? AND id=?');$s->execute([$owner,$id]);}

    /** @return array<int,string> */
    public function blockedSenders(string $owner): array {$s=$this->db->prepare('SELECT sender FROM blocked_senders WHERE owner=? ORDER BY sender');$s->execute([$owner]);return array_map(static fn(array $row):string=>(string)$row['sender'],$s->fetchAll()?:[]);}
    public function blockSender(string $owner,string $sender): void {$sender=strtolower(trim($sender));if(!filter_var($sender,FILTER_VALIDATE_EMAIL))throw new \RuntimeException('El remitente no es válido.');try{$s=$this->db->prepare('INSERT INTO blocked_senders(owner,sender,created_at) VALUES(?,?,?)');$s->execute([$owner,$sender,date(DATE_ATOM)]);}catch(\PDOException){}}
    public function unblockSender(string $owner,string $sender): void {$s=$this->db->prepare('DELETE FROM blocked_senders WHERE owner=? AND sender=?');$s->execute([$owner,strtolower(trim($sender))]);}

    private function ensureColumn(string $table,string $definition): void
    {
        $column=strtok($definition,' ');$driver=$this->db->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if($driver==='sqlite'){$rows=$this->db->query('PRAGMA table_info('.$table.')')->fetchAll();foreach($rows as $row)if(($row['name']??null)===$column)return;}
        else{$query=$this->db->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?');$query->execute([$table,$column]);if($query->fetchColumn())return;}
        $this->db->exec("ALTER TABLE {$table} ADD COLUMN {$definition}");
    }
}
