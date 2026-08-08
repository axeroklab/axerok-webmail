<?php
declare(strict_types=1);

namespace AxerokMail\Contacts;

final class RoundcubeReader
{
    private string $path;

    public function __construct(string $email)
    {
        if (!class_exists(\SQLite3::class)) throw new \RuntimeException('PHP no tiene habilitada la extensión SQLite3.');
        $home=$this->cpanelHome();
        [$local,$domain]=array_pad(explode('@',strtolower($email),2),2,'');
        if(!preg_match('/^[a-z0-9._+-]+$/',$local)||!preg_match('/^[a-z0-9.-]+$/',$domain))throw new \RuntimeException('La cuenta no permite localizar una base de Roundcube.');
        $candidates=[$home.'/etc/'.$domain.'/'.$local.'.rcube.db'];
        if($local===basename($home))$candidates[]=$home.'/etc/'.basename($home).'.rcube.db';
        $base=realpath($home.'/etc');
        foreach($candidates as $candidate){$real=realpath($candidate);if($real!==false&&$base!==false&&str_starts_with($real,$base.DIRECTORY_SEPARATOR)&&is_file($real)&&is_readable($real)&&$this->belongsToCurrentUser($real)){$this->path=$real;return;}}
        throw new \RuntimeException('No se encontró una base de Roundcube legible para esta cuenta. Podés importar un archivo vCard.');
    }

    /** @return array{personal:int,collected:int,carddav:int,identity:bool} */
    public function status(): array
    {
        $db=$this->open();
        try{return ['personal'=>$this->count($db,'contacts','del=0'),'collected'=>$this->count($db,'collected_addresses'),'carddav'=>$this->count($db,'carddav_contacts'),'identity'=>$this->count($db,'identities','del=0')>0];}finally{$db->close();}
    }

    /** @return array{contacts:list<array<string,string>>,identity:array<string,string>|null} */
    public function read(bool $collected=false,bool $identity=false): array
    {
        $db=$this->open();$contacts=[];$profile=null;
        try{
            $result=$db->query("SELECT name,email,vcard FROM contacts WHERE del=0 LIMIT 5000");
            while($result&&($row=$result->fetchArray(SQLITE3_ASSOC))){$cards=VCard::parse((string)($row['vcard']??''));if($cards!==[])foreach($cards as $card)$contacts[]=$card;else $contacts[]=['name'=>(string)($row['name']??''),'email'=>$this->firstEmail((string)($row['email']??'')),'phone'=>'','organization'=>''];}
            if($this->hasColumn($db,'carddav_contacts','vcard')){$result=$db->query("SELECT vcard FROM carddav_contacts LIMIT 5000");while($result&&($row=$result->fetchArray(SQLITE3_ASSOC))){foreach(VCard::parse((string)($row['vcard']??'')) as $card)$contacts[]=$card;}}
            if($collected){$result=$db->query("SELECT name,email FROM collected_addresses LIMIT 5000");while($result&&($row=$result->fetchArray(SQLITE3_ASSOC)))$contacts[]=['name'=>(string)($row['name']??''),'email'=>$this->firstEmail((string)($row['email']??'')),'phone'=>'','organization'=>''];}
            if($identity){$row=$db->querySingle('SELECT name,organization,"reply-to" AS reply_to,bcc,signature,html_signature FROM identities WHERE del=0 ORDER BY standard DESC,identity_id ASC LIMIT 1',true);if(is_array($row))$profile=['display_name'=>(string)($row['name']??''),'organization'=>(string)($row['organization']??''),'reply_to'=>(string)($row['reply_to']??''),'default_bcc'=>(string)($row['bcc']??''),'signature_html'=>(int)($row['html_signature']??0)===1?(string)($row['signature']??''):nl2br(htmlspecialchars((string)($row['signature']??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'))];}
        }finally{$db->close();}
        return ['contacts'=>$contacts,'identity'=>$profile];
    }

    private function open(): \SQLite3 { $db=new \SQLite3($this->path,SQLITE3_OPEN_READONLY);$db->exec('PRAGMA query_only=ON');$db->busyTimeout(1500);return $db; }
    private function count(\SQLite3 $db,string $table,string $where='1=1'): int { return (int)$db->querySingle("SELECT COUNT(*) FROM {$table} WHERE {$where}"); }
    private function hasColumn(\SQLite3 $db,string $table,string $column): bool { $result=$db->query("PRAGMA table_info({$table})");while($result&&($row=$result->fetchArray(SQLITE3_ASSOC))){if(($row['name']??null)===$column)return true;}return false; }
    private function firstEmail(string $value): string { if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i',$value,$match))return strtolower($match[0]);return trim($value); }
    private function belongsToCurrentUser(string $file): bool { return !function_exists('posix_geteuid')||fileowner($file)===posix_geteuid(); }
    private function cpanelHome(): string
    {
        $candidates=[];
        if(function_exists('posix_geteuid')&&function_exists('posix_getpwuid')){$user=posix_getpwuid(posix_geteuid());if(is_array($user)&&isset($user['dir']))$candidates[]=(string)$user['dir'];}
        $candidates[]=(string)($_SERVER['HOME']??'');
        if(preg_match('#^(/home/[^/]+)(?:/|$)#',dirname(__DIR__,2),$match))$candidates[]=$match[1];
        foreach($candidates as $candidate){$real=realpath($candidate);if($real!==false&&preg_match('#^/home/[^/]+$#',$real))return $real;}
        throw new \RuntimeException('No se pudo determinar la cuenta cPanel de la instalación.');
    }
}
