<?php
declare(strict_types=1);

namespace AxerokMail\Preferences;

final class MailPreferenceRepository
{
    private \PDO $db;

    public function __construct(array $config)
    {
        $this->db=new \PDO((string)$config['dsn'],$config['username']??null,$config['password']??null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC]);
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_preferences (owner VARCHAR(255) PRIMARY KEY, signature_html TEXT NOT NULL, receipt_default INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL)");
        foreach(["display_name VARCHAR(160) NOT NULL DEFAULT ''","organization VARCHAR(160) NOT NULL DEFAULT ''","reply_to VARCHAR(255) NOT NULL DEFAULT ''","default_bcc VARCHAR(1000) NOT NULL DEFAULT ''","view_density VARCHAR(16) NOT NULL DEFAULT 'comfortable'","inbox_view VARCHAR(20) NOT NULL DEFAULT 'categories'"] as $column){try{$this->db->exec("ALTER TABLE mail_preferences ADD COLUMN {$column}");}catch(\Throwable){}}
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_drafts (owner VARCHAR(255) PRIMARY KEY, recipients_to TEXT NOT NULL, recipients_cc TEXT NOT NULL, recipients_bcc TEXT NOT NULL, subject VARCHAR(300) NOT NULL, body_html TEXT NOT NULL, receipt_requested INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL)");
        try{$this->db->exec("ALTER TABLE mail_drafts ADD COLUMN priority VARCHAR(10) NOT NULL DEFAULT 'normal'");}catch(\Throwable){}
    }

    /** @return array<string,mixed> */
    public function preferences(string $owner): array
    {
        $s=$this->db->prepare('SELECT signature_html,receipt_default,display_name,organization,reply_to,default_bcc,view_density,inbox_view FROM mail_preferences WHERE owner=?');$s->execute([$owner]);$row=$s->fetch();
        return ['signature_html'=>(string)($row['signature_html']??''),'receipt_default'=>(bool)($row['receipt_default']??false),'display_name'=>(string)($row['display_name']??''),'organization'=>(string)($row['organization']??''),'reply_to'=>(string)($row['reply_to']??''),'default_bcc'=>(string)($row['default_bcc']??''),'view_density'=>(string)($row['view_density']??'comfortable'),'inbox_view'=>(string)($row['inbox_view']??'categories')];
    }

    public function savePreferences(string $owner,string $signatureHtml,bool $receiptDefault,array $identity=[]): void
    {
        $exists=$this->db->prepare('SELECT owner FROM mail_preferences WHERE owner=?');$exists->execute([$owner]);$now=date(DATE_ATOM);
        $values=[(string)($identity['display_name']??''),(string)($identity['organization']??''),(string)($identity['reply_to']??''),(string)($identity['default_bcc']??''),in_array(($identity['view_density']??''),['default','comfortable','compact'],true)?$identity['view_density']:'comfortable',in_array(($identity['inbox_view']??''),['default','categories','unread','starred'],true)?$identity['inbox_view']:'categories'];
        if($exists->fetchColumn()){$s=$this->db->prepare('UPDATE mail_preferences SET signature_html=?,receipt_default=?,display_name=?,organization=?,reply_to=?,default_bcc=?,view_density=?,inbox_view=?,updated_at=? WHERE owner=?');$s->execute([$signatureHtml,$receiptDefault?1:0,...$values,$now,$owner]);return;}
        $s=$this->db->prepare('INSERT INTO mail_preferences(owner,signature_html,receipt_default,display_name,organization,reply_to,default_bcc,view_density,inbox_view,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)');$s->execute([$owner,$signatureHtml,$receiptDefault?1:0,...$values,$now]);
    }

    public function draft(string $owner): ?array
    {
        $s=$this->db->prepare('SELECT recipients_to AS `to`,recipients_cc AS cc,recipients_bcc AS bcc,subject,body_html,receipt_requested,priority,updated_at FROM mail_drafts WHERE owner=?');$s->execute([$owner]);$row=$s->fetch();return $row?:null;
    }

    public function saveDraft(string $owner,array $draft): void
    {
        $priority=in_array(($draft['priority']??'normal'),['low','normal','high'],true)?(string)$draft['priority']:'normal';$values=[(string)($draft['to']??''),(string)($draft['cc']??''),(string)($draft['bcc']??''),(string)($draft['subject']??''),(string)($draft['body_html']??''),!empty($draft['receipt_requested'])?1:0,$priority,date(DATE_ATOM)];
        $exists=$this->db->prepare('SELECT owner FROM mail_drafts WHERE owner=?');$exists->execute([$owner]);
        if($exists->fetchColumn()){$s=$this->db->prepare('UPDATE mail_drafts SET recipients_to=?,recipients_cc=?,recipients_bcc=?,subject=?,body_html=?,receipt_requested=?,priority=?,updated_at=? WHERE owner=?');$s->execute([...$values,$owner]);return;}
        $s=$this->db->prepare('INSERT INTO mail_drafts(owner,recipients_to,recipients_cc,recipients_bcc,subject,body_html,receipt_requested,priority,updated_at) VALUES(?,?,?,?,?,?,?,?,?)');$s->execute([$owner,...$values]);
    }

    public function deleteDraft(string $owner): void { $s=$this->db->prepare('DELETE FROM mail_drafts WHERE owner=?');$s->execute([$owner]); }
}
