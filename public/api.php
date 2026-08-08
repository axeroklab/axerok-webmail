<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use AxerokMail\Contacts\ContactRepository;
use AxerokMail\Contacts\VCard;
use AxerokMail\Contacts\RoundcubeReader;
use AxerokMail\Mail\ImapClient;
use AxerokMail\Mail\MailException;
use AxerokMail\Mail\SmtpClient;
use AxerokMail\Labels\LabelRepository;
use AxerokMail\Preferences\MailPreferenceRepository;
use AxerokMail\Security\Credentials;
use AxerokMail\Security\LoginRateLimiter;

header('Content-Type: application/json; charset=utf-8');
$apiStartedAt = hrtime(true);
$apiTimings = [];

function api_timing(string $name, int $startedAt): void
{
    global $apiTimings;
    $safe=preg_replace('/[^a-z0-9_-]/i','',$name)?:'operation';
    $apiTimings[]=$safe.';dur='.number_format((hrtime(true)-$startedAt)/1000000,1,'.','');
}

function json_response(array $payload, int $status = 200): never
{
    global $apiStartedAt,$apiTimings;
    $apiTimings[]='total;dur='.number_format((hrtime(true)-$apiStartedAt)/1000000,1,'.','');
    header('Server-Timing: '.implode(', ',$apiTimings));
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}

function api_cache_file(string $owner, string $namespace, string $key): ?string
{
    $directory=runtime_storage_path('api-cache');
    if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))return null;
    return $directory.'/'.hash_hmac('sha256',$owner.'|'.$namespace.'|'.$key,(string)config('app.key')).'.json';
}

function api_cache_get(string $owner,string $namespace,string $key,int $ttl): mixed
{
    $file=api_cache_file($owner,$namespace,$key);if($file===null||!is_file($file)||time()-(int)filemtime($file)>$ttl)return null;$json=@file_get_contents($file);if($json===false)return null;$decoded=json_decode($json,true);return is_array($decoded)?$decoded:null;
}

function api_cache_set(string $owner,string $namespace,string $key,array $value): void
{
    $file=api_cache_file($owner,$namespace,$key);if($file===null)return;$temporary=$file.'.'.bin2hex(random_bytes(4));if(@file_put_contents($temporary,json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),LOCK_EX)===false)return;@chmod($temporary,0600);@rename($temporary,$file);
}

function api_cache_delete(string $owner,string $namespace,string $key): void
{
    $file=api_cache_file($owner,$namespace,$key);if($file!==null&&is_file($file))@unlink($file);
}

/** @return array{0:resource,1:string,2:array<string,mixed>} */
function api_send_lock(string $owner,string $key): array
{
    if(!preg_match('/^[a-f0-9-]{20,80}$/i',$key))throw new RuntimeException('La clave de envío no es válida.');
    $directory=runtime_storage_path('send-idempotency');if(!is_dir($directory)&&!@mkdir($directory,0700,true)&&!is_dir($directory))throw new RuntimeException('No se pudo proteger el envío.');
    $file=$directory.'/'.hash_hmac('sha256',$owner.'|'.$key,(string)config('app.key')).'.json';$handle=fopen($file,'c+');if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('No se pudo proteger el envío.');
    rewind($handle);$state=json_decode(stream_get_contents($handle)?:'[]',true);return [$handle,$file,is_array($state)?$state:[]];
}

function api_send_commit($handle,string $file,array $result): void
{
    ftruncate($handle,0);rewind($handle);fwrite($handle,json_encode(['sent_at'=>time(),'result'=>$result],JSON_THROW_ON_ERROR));fflush($handle);@chmod($file,0600);flock($handle,LOCK_UN);fclose($handle);
}

function api_require_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        json_response(['error'=>'La sesión expiró. Recargá la página e intentá nuevamente.'],419);
    }
}

/** @return array<int,array{name:string,type:string,data:string}> */
function api_attachments(array $files): array
{
    if(!isset($files['name'])||!is_array($files['name']))return [];
    if(count($files['name'])>10)throw new RuntimeException('Podés adjuntar como máximo 10 archivos.');$result=[];$total=0;
    foreach($files['name'] as $i=>$name){$uploadError=(int)($files['error'][$i]??UPLOAD_ERR_NO_FILE);if($uploadError===UPLOAD_ERR_NO_FILE)continue;if(in_array($uploadError,[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new RuntimeException('Uno de los adjuntos supera el límite de PHP.');if($uploadError!==UPLOAD_ERR_OK)throw new RuntimeException('No se pudo subir uno de los adjuntos.');$size=(int)$files['size'][$i];$total+=$size;if($size>10*1024*1024||$total>20*1024*1024)throw new RuntimeException('Los adjuntos superan el límite permitido.');$tmp=(string)$files['tmp_name'][$i];if(!is_uploaded_file($tmp))throw new RuntimeException('El archivo recibido no es válido.');$data=file_get_contents($tmp);if($data===false)throw new RuntimeException('No se pudo leer un adjunto.');$mime=function_exists('mime_content_type')?mime_content_type($tmp):false;$result[]=['name'=>basename((string)$name),'type'=>(string)($mime?:'application/octet-stream'),'data'=>$data];}
    return $result;
}

$action = (string)($_GET['action'] ?? 'session');
$requestedAccount = strtolower(trim((string)($_SERVER['HTTP_X_AXEROK_ACCOUNT'] ?? $_GET['account'] ?? '')));
$sessionPayload = static function (?string $requested = null): array {
    $email=Credentials::email($requested);
    return ['authenticated'=>$email!==null,'email'=>$email,'accounts'=>Credentials::accounts(),'csrf'=>(string)$_SESSION['csrf'],'version'=>(string)config('app.version','0.4.0-preview14'),'page_size'=>max(10,min(100,(int)config('mail.page_size',40))),'webmail_home_url'=>\AxerokMail\Runtime::webmailHomeUrl()];
};

if ($action === 'session') {
    if($requestedAccount!==''&&!Credentials::has($requestedAccount))json_response(['error'=>'La cuenta solicitada ya no está disponible.'],404);
    json_response($sessionPayload($requestedAccount));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    api_require_csrf();
    $email = strtolower(trim((string)($_POST['email'] ?? ''))); $password = (string)($_POST['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') { json_response(['error'=>'Ingresá una cuenta completa y su contraseña.'],422); }
    $limiter = new LoginRateLimiter(runtime_storage_path('login-attempts')); $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    try {
        $limiter->assertAllowed($ip,$email);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$imap->close();$limiter->success($ip,$email);Credentials::store($email,$password,app_credential_key());
        json_response($sessionPayload($email));
    } catch(Throwable $e) {
        if($e instanceof MailException){try{$limiter->failure($ip,$email);}catch(Throwable){}}
        error_log('[AxerOK Mail API] Login failure: '.str_replace(["\r","\n"],' ',$e->getMessage()));json_response(['error'=>'No se pudo iniciar sesión. Verificá la cuenta y la contraseña.'],401);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'logout') { api_require_csrf();Credentials::clear();json_response(['ok'=>true]); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'account-switch') { api_require_csrf();$candidate=strtolower(trim((string)($_POST['email']??'')));if(!Credentials::setActive($candidate))json_response(['error'=>'La cuenta ya no está disponible.'],404);json_response($sessionPayload($candidate)); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'account-remove') { api_require_csrf();Credentials::remove((string)($_POST['email']??''));json_response($sessionPayload()); }

if($requestedAccount!==''&&!Credentials::has($requestedAccount))json_response(['error'=>'La cuenta solicitada ya no está disponible.'],404);
$email=Credentials::email($requestedAccount);$password=Credentials::password(app_credential_key(),$requestedAccount);
if(!$email||!$password){json_response(['error'=>'La sesión expiró.'],401);}
session_write_close();

try {
    if ($action==='attachment'||$action==='inline') {
        $folder=(string)($_GET['folder']??'INBOX');$uid=(int)($_GET['uid']??0);$section=(string)($_GET['section']??'');$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$file=$imap->attachment($folder,$uid,$section);$imap->close();$safe=preg_replace('/[^\pL\pN._ -]/u','_',(string)$file['name'])?:'archivo';header('Content-Type: '.((string)$file['type']?:'application/octet-stream'));header('Content-Length: '.strlen((string)$file['data']));header('Cache-Control: private, max-age=300');header('Content-Disposition: '.($action==='inline'?'inline':'attachment')."; filename*=UTF-8''".rawurlencode($safe));header('X-Content-Type-Options: nosniff');echo $file['data'];exit;
    }
    if ($action==='message-body'&&($_GET['remote']??'')==='1') {
        $folder=(string)($_GET['folder']??'INBOX');$uid=(int)($_GET['uid']??0);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$message=$imap->message($folder,$uid,false);$imap->close();$resolved=resolve_email_cids((string)$message['html'],(array)($message['inline']??[]),$folder,$uid,$email);header('Content-Type: text/html; charset=utf-8');header("Content-Security-Policy: default-src 'none'; img-src 'self' data: https:; style-src 'unsafe-inline'; font-src data: https:; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'",true);echo safe_email_html($resolved,true);exit;
    }
    if ($action === 'send' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();[$sendHandle,$sendFile,$sendState]=api_send_lock($email,(string)($_POST['idempotency_key']??''));if(isset($sendState['result'])&&is_array($sendState['result'])){flock($sendHandle,LOCK_UN);fclose($sendHandle);json_response($sendState['result']);}$to=trim((string)($_POST['to']??''));$cc=trim((string)($_POST['cc']??''));$bcc=trim((string)($_POST['bcc']??''));$subject=trim((string)($_POST['subject']??''))?:'(Sin asunto)';$html=clean_composer_html((string)($_POST['body_html']??''));$body=composer_plain_text($html);$receipt=($_POST['receipt_requested']??'')==='1';$priority=(string)($_POST['priority']??'normal');if(!in_array($priority,['low','normal','high'],true))throw new RuntimeException('La prioridad no es válida.');
        if($body==='')throw new RuntimeException('Escribí el contenido del mensaje.');if(unicode_length($subject)>300)throw new RuntimeException('El asunto supera 300 caracteres.');if(strlen($html)>1048576)throw new RuntimeException('El cuerpo supera 1 MB.');$attachments=api_attachments($_FILES['attachments']??[]);
        $identity=(new MailPreferenceRepository((array)config('contacts')))->preferences($email);
        $raw=(new SmtpClient((array)config('mail')))->send($email,$password,$to,$cc,$bcc,$subject,$body,$html,$receipt,$priority,$attachments,(string)$identity['display_name'],(string)$identity['reply_to'],(string)$identity['organization']);$warning='';
        try{$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$sent=null;foreach($imap->folders() as $candidate){if($candidate['special']==='sent'){$sent=$candidate['name'];break;}}if($sent!==null)$imap->append($sent,$raw);else$warning='No se encontró la carpeta Enviados.';$imap->close();}catch(Throwable){$warning='El correo salió, pero no se pudo copiar a Enviados.';}
        (new MailPreferenceRepository((array)config('contacts')))->deleteDraft($email,(string)($_POST['id']??''));$result=['ok'=>true,'warning'=>$warning];api_send_commit($sendHandle,$sendFile,$result);json_response($result);
    }
    if ($action === 'preferences') {
        $repo=new MailPreferenceRepository((array)config('contacts'));if($_SERVER['REQUEST_METHOD']==='POST'){api_require_csrf();$signature=clean_composer_html((string)($_POST['signature_html']??''));if(strlen($signature)>100000)throw new RuntimeException('La firma supera el límite permitido.');$identity=['display_name'=>trim((string)($_POST['display_name']??'')),'organization'=>trim((string)($_POST['organization']??'')),'reply_to'=>trim((string)($_POST['reply_to']??'')),'default_bcc'=>trim((string)($_POST['default_bcc']??'')),'view_density'=>(string)($_POST['view_density']??''),'inbox_view'=>(string)($_POST['inbox_view']??'')];foreach(['display_name','organization'] as $field)if(unicode_length($identity[$field])>160)throw new RuntimeException('Los datos personales son demasiado largos.');if($identity['reply_to']!==''&&!filter_var($identity['reply_to'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('La dirección Responder a no es válida.');if(strlen($identity['default_bcc'])>1000)throw new RuntimeException('El Cco predeterminado es demasiado largo.');$repo->savePreferences($email,$signature,($_POST['receipt_default']??'')==='1',$identity);json_response(['ok'=>true]);}json_response(['preferences'=>$repo->preferences($email)]);
    }
    if ($action === 'draft') {
        $repo=new MailPreferenceRepository((array)config('contacts'));if($_SERVER['REQUEST_METHOD']==='DELETE'){parse_str((string)file_get_contents('php://input'),$delete);if(!hash_equals((string)$_SESSION['csrf'],(string)($delete['csrf']??'')))json_response(['error'=>'La sesión expiró.'],419);$repo->deleteDraft($email,(string)($delete['id']??''));json_response(['ok'=>true]);}if($_SERVER['REQUEST_METHOD']==='POST'){api_require_csrf();$priority=(string)($_POST['priority']??'normal');if(!in_array($priority,['low','normal','high'],true))$priority='normal';$draft=['id'=>(string)($_POST['id']??''),'to'=>(string)($_POST['to']??''),'cc'=>(string)($_POST['cc']??''),'bcc'=>(string)($_POST['bcc']??''),'subject'=>substr((string)($_POST['subject']??''),0,300),'body_html'=>clean_composer_html((string)($_POST['body_html']??'')),'receipt_requested'=>($_POST['receipt_requested']??'')==='1','priority'=>$priority];if(strlen($draft['body_html'])>1048576)throw new RuntimeException('El borrador supera 1 MB.');json_response(['ok'=>true,'draft'=>$repo->saveDraft($email,$draft)]);}json_response(['drafts'=>$repo->drafts($email)]);
    }
    if($action==='templates'){
        $repo=new MailPreferenceRepository((array)config('contacts'));if($_SERVER['REQUEST_METHOD']==='POST'){api_require_csrf();$body=clean_composer_html((string)($_POST['body_html']??''));if(strlen($body)>1048576)throw new RuntimeException('La plantilla supera 1 MB.');$template=$repo->saveTemplate($email,['id'=>(string)($_POST['id']??''),'name'=>(string)($_POST['name']??''),'subject'=>(string)($_POST['subject']??''),'body_html'=>$body]);json_response(['ok'=>true,'template'=>$template,'templates'=>$repo->templates($email)]);}if($_SERVER['REQUEST_METHOD']==='DELETE'){parse_str((string)file_get_contents('php://input'),$delete);if(!hash_equals((string)$_SESSION['csrf'],(string)($delete['csrf']??'')))json_response(['error'=>'La sesión expiró.'],419);$repo->deleteTemplate($email,(string)($delete['id']??''));json_response(['ok'=>true,'templates'=>$repo->templates($email)]);}json_response(['templates'=>$repo->templates($email)]);
    }
    if($action==='blocked-senders'){$repo=new MailPreferenceRepository((array)config('contacts'));if($_SERVER['REQUEST_METHOD']==='DELETE'){parse_str((string)file_get_contents('php://input'),$delete);if(!hash_equals((string)$_SESSION['csrf'],(string)($delete['csrf']??'')))json_response(['error'=>'La sesión expiró.'],419);$repo->unblockSender($email,(string)($delete['sender']??''));json_response(['ok'=>true,'senders'=>$repo->blockedSenders($email)]);}json_response(['senders'=>$repo->blockedSenders($email)]);}
    if($action==='block-sender'&&$_SERVER['REQUEST_METHOD']==='POST'){
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$uid=(int)($_POST['uid']??0);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$message=$imap->message($folder,$uid,false);$from=(string)($message['from']??'');$sender=preg_match('/<([^<>]+)>/',$from,$match)?trim($match[1]):trim($from);$junk=null;foreach($imap->folders() as $candidate)if($candidate['special']==='junk'){$junk=$candidate['name'];break;}if($junk===null)throw new RuntimeException('No se encontró la carpeta Spam.');(new MailPreferenceRepository((array)config('contacts')))->blockSender($email,$sender);if($folder!==$junk)$imap->moveMany($folder,[$uid],$junk);$imap->close();json_response(['ok'=>true,'sender'=>strtolower($sender)]);
    }
    if ($action === 'folders') {
        $folders=api_cache_get($email,'folders','all',60);if($folders!==null){header('X-AxerOK-Cache: HIT');json_response(['folders'=>$folders]);}$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$folders=$imap->folders();$imap->close();api_timing('imap',$started);api_cache_set($email,'folders','all',$folders);header('X-AxerOK-Cache: MISS');json_response(['folders'=>$folders]);
    }
    if ($action === 'folder-create' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$name=(string)($_POST['name']??'');$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$imap->createFolder($name);$folders=$imap->folders();$imap->close();api_cache_delete($email,'folders','all');json_response(['ok'=>true,'folders'=>$folders]);
    }
    if ($action === 'folder-rename' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$current=(string)($_POST['current']??'');$next=(string)($_POST['name']??'');$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$candidate=null;foreach($imap->folders() as $item){if($item['name']===$current){$candidate=$item;break;}}if($candidate===null)throw new RuntimeException('La carpeta ya no existe.');if($candidate['special']!==null)throw new RuntimeException('Las carpetas del sistema no se pueden renombrar.');$imap->renameFolder($current,$next);$folders=$imap->folders();$imap->close();api_cache_delete($email,'folders','all');json_response(['ok'=>true,'folders'=>$folders]);
    }
    if ($action === 'folder-delete' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$name=(string)($_POST['name']??'');if(!hash_equals($name,(string)($_POST['confirm']??'')))throw new RuntimeException('La confirmación de la carpeta no coincide.');$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$candidate=null;foreach($imap->folders() as $item){if($item['name']===$name){$candidate=$item;break;}}if($candidate===null)throw new RuntimeException('La carpeta ya no existe.');if($candidate['special']!==null)throw new RuntimeException('Las carpetas del sistema no se pueden eliminar.');$imap->deleteFolder($name);$folders=$imap->folders();$imap->close();api_cache_delete($email,'folders','all');json_response(['ok'=>true,'folders'=>$folders]);
    }
    if ($action === 'labels') { json_response(['labels'=>(new LabelRepository((array)config('contacts')))->all($email)]); }
    if ($action === 'label-create' && $_SERVER['REQUEST_METHOD']==='POST') { api_require_csrf();$repo=new LabelRepository((array)config('contacts'));$repo->create($email,(string)($_POST['name']??''),(string)($_POST['color']??''));json_response(['ok'=>true,'labels'=>$repo->all($email)]); }
    if ($action === 'label-update' && $_SERVER['REQUEST_METHOD']==='POST') { api_require_csrf();$repo=new LabelRepository((array)config('contacts'));$repo->update($email,(int)($_POST['id']??0),(string)($_POST['name']??''),(string)($_POST['color']??''));json_response(['ok'=>true,'labels'=>$repo->all($email)]); }
    if ($action === 'label-delete' && $_SERVER['REQUEST_METHOD']==='POST') { api_require_csrf();$repo=new LabelRepository((array)config('contacts'));$repo->delete($email,(int)($_POST['id']??0));json_response(['ok'=>true,'labels'=>$repo->all($email)]); }
    if ($action === 'label-apply' && $_SERVER['REQUEST_METHOD']==='POST') { api_require_csrf();$repo=new LabelRepository((array)config('contacts'));$label=$repo->find($email,(int)($_POST['id']??0));if($label===null)throw new RuntimeException('La etiqueta no existe.');$folder=(string)($_POST['folder']??'INBOX');$uids=array_map('intval',explode(',',(string)($_POST['uids']??'')));$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$imap->setKeyword($folder,$uids,(string)$label['keyword'],($_POST['enabled']??'')==='1');$imap->close();json_response(['ok'=>true]); }
    if ($action === 'messages') {
        $folder=(string)($_GET['folder']??'INBOX');$page=max(1,(int)($_GET['page']??1));$query=trim((string)($_GET['q']??''));$searchBody=($_GET['body']??'')==='1';$filters=['from'=>(string)($_GET['from']??''),'to'=>(string)($_GET['to']??''),'subject'=>(string)($_GET['subject']??''),'contains'=>(string)($_GET['contains']??''),'exclude'=>(string)($_GET['exclude']??''),'status'=>(string)($_GET['status']??'all'),'size_op'=>(string)($_GET['size_op']??''),'size_bytes'=>max(0,(int)($_GET['size_bytes']??0)),'since'=>(string)($_GET['since']??''),'before'=>(string)($_GET['before']??''),'has_attachment'=>($_GET['has_attachment']??'')==='1'];$cacheKey='v2|'.$folder.'|'.$page.'|'.hash('sha256',json_encode([$query,$searchBody,$filters],JSON_THROW_ON_ERROR));$fresh=($_GET['fresh']??'')==='1';$payload=$fresh?null:api_cache_get($email,'messages',$cacheKey,8);if(is_array($payload)&&isset($payload['messages'],$payload['total'])){header('X-AxerOK-Cache: HIT');json_response($payload+['folder'=>$folder,'page'=>$page,'query'=>$query,'search_body'=>$searchBody]);}$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$messages=$imap->messages($folder,$page,max(10,min(100,(int)config('mail.page_size',40))),$query,$searchBody,$filters);$total=$imap->lastMailboxTotal();$imap->close();api_timing('imap',$started);$payload=['messages'=>$messages,'total'=>$total];api_cache_set($email,'messages',$cacheKey,$payload);header('X-AxerOK-Cache: MISS');json_response($payload+['folder'=>$folder,'page'=>$page,'query'=>$query,'search_body'=>$searchBody]);
    }
    if($action==='thread'){
        $folder=(string)($_GET['folder']??'INBOX');$threadId=(string)($_GET['thread_id']??'');$excludeUid=max(0,(int)($_GET['exclude_uid']??0));$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$uids=$imap->threadUids($folder,$threadId);$items=[];$account='&account='.rawurlencode($email);foreach($uids as $uid){if($uid===$excludeUid)continue;$message=$imap->message($folder,$uid,false);$attachments=[];foreach($message['attachments'] as $item)$attachments[]=['name'=>$item['name'],'type'=>$item['type'],'size'=>$item['size'],'url'=>'api.php?action=attachment&folder='.rawurlencode($folder).'&uid='.$uid.'&section='.rawurlencode((string)$item['section']).$account];$resolved=resolve_email_cids((string)$message['html'],(array)($message['inline']??[]),$folder,$uid,$email);unset($message['attachments'],$message['inline']);$items[]=['message'=>$message,'attachments'=>$attachments,'safeHtml'=>$resolved!==''?safe_email_html($resolved):'','hasRemoteImages'=>email_has_remote_images($resolved),'remoteUrl'=>'api.php?action=message-body&remote=1&folder='.rawurlencode($folder).'&uid='.$uid.$account];}$imap->close();api_timing('imap',$started);json_response(['messages'=>$items]);
    }
    if ($action === 'message') {
        $folder=(string)($_GET['folder']??'INBOX');$uid=(int)($_GET['uid']??0);$peek=($_GET['peek']??'')==='1';$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$message=$imap->message($folder,$uid,!$peek);$imap->close();api_timing('imap',$started);$account='&account='.rawurlencode($email);$attachments=[];foreach($message['attachments'] as $item){$attachments[]=['name'=>$item['name'],'type'=>$item['type'],'size'=>$item['size'],'url'=>'api.php?action=attachment&folder='.rawurlencode($folder).'&uid='.$uid.'&section='.rawurlencode((string)$item['section']).$account];}$resolved=resolve_email_cids((string)$message['html'],(array)($message['inline']??[]),$folder,$uid,$email);unset($message['attachments'],$message['inline']);json_response(['message'=>$message,'attachments'=>$attachments,'safeHtml'=>$resolved!==''?safe_email_html($resolved):'','hasRemoteImages'=>email_has_remote_images($resolved),'remoteUrl'=>'api.php?action=message-body&remote=1&folder='.rawurlencode($folder).'&uid='.$uid.$account]);
    }
    if ($action === 'set-seen' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$uid=(int)($_POST['uid']??0);if($uid<1)throw new RuntimeException('Mensaje inválido.');$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$imap->setSeen($folder,$uid,true);$imap->close();api_timing('imap',$started);json_response(['ok'=>true]);
    }
    if ($action === 'update-flags' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$uids=array_map('intval',explode(',',(string)($_POST['uids']??'')));$flag=(string)($_POST['flag']??'');$enabled=($_POST['enabled']??'')==='1';$started=hrtime(true);$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$imap->setFlags($folder,$uids,$flag,$enabled);$imap->close();api_timing('imap',$started);json_response(['ok'=>true]);
    }
    if ($action === 'delete-message' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$uid=(int)($_POST['uid']??0);if($uid<1)throw new RuntimeException('Mensaje inválido.');
        $imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$trash=null;foreach($imap->folders() as $candidate){if($candidate['special']==='trash'){$trash=$candidate['name'];break;}}if($trash===null)throw new RuntimeException('No se encontró la carpeta Papelera.');if($folder===$trash)$imap->deleteMany($folder,[$uid]);else$imap->move($folder,$uid,$trash);$imap->close();json_response(['ok'=>true,'permanent'=>$folder===$trash]);
    }
    if ($action === 'move-messages' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$destination=(string)($_POST['destination']??'');$uids=array_values(array_filter(array_map('intval',explode(',',(string)($_POST['uids']??'')))));$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$valid=false;foreach($imap->folders() as $candidate){if($candidate['name']===$destination&&!in_array('\\Noselect',$candidate['flags']??[],true)){$valid=true;break;}}if(!$valid)throw new RuntimeException('La carpeta de destino no existe.');$imap->moveMany($folder,$uids,$destination);$imap->close();json_response(['ok'=>true]);
    }
    if ($action === 'delete-messages' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'INBOX');$uids=array_values(array_filter(array_map('intval',explode(',',(string)($_POST['uids']??'')))));$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$trash=null;foreach($imap->folders() as $candidate){if($candidate['special']==='trash'){$trash=$candidate['name'];break;}}if($trash===null)throw new RuntimeException('No se encontró la carpeta Papelera.');if($folder===$trash)$imap->deleteMany($folder,$uids);else$imap->moveMany($folder,$uids,$trash);$imap->close();json_response(['ok'=>true,'permanent'=>$folder===$trash]);
    }
    if ($action === 'empty-folder' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$folder=(string)($_POST['folder']??'');$confirm=(string)($_POST['confirm']??'');$imap=new ImapClient((array)config('mail'));$imap->connect($email,$password);$special=null;foreach($imap->folders() as $candidate){if($candidate['name']===$folder){$special=$candidate['special'];break;}}if(!in_array($special,['junk','trash'],true)||!hash_equals('ELIMINAR TODO',$confirm))throw new RuntimeException('La confirmación para vaciar la carpeta no es válida.');$deleted=$imap->emptyFolder($folder);$imap->close();json_response(['ok'=>true,'deleted'=>$deleted]);
    }
    if ($action === 'contacts-import' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$file=$_FILES['vcard']??null;if(!is_array($file)||(int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)throw new RuntimeException('Seleccioná un archivo vCard válido.');if((int)($file['size']??0)>2*1024*1024)throw new RuntimeException('El archivo vCard supera 2 MB.');$tmp=(string)($file['tmp_name']??'');if(!is_uploaded_file($tmp))throw new RuntimeException('El archivo recibido no es válido.');$contents=file_get_contents($tmp);if($contents===false)throw new RuntimeException('No se pudo leer el archivo vCard.');$cards=VCard::parse($contents);if($cards===[])throw new RuntimeException('El archivo no contiene contactos importables.');if(count($cards)>5000)throw new RuntimeException('El archivo contiene demasiados contactos.');$stats=(new ContactRepository((array)config('contacts')))->import($email,$cards);json_response(['ok'=>true,'stats'=>$stats]);
    }
    if ($action === 'roundcube-status') {
        try{json_response(['available'=>true,'status'=>(new RoundcubeReader($email))->status()]);}catch(Throwable){json_response(['available'=>false,'reason'=>'No se encontró una instalación compatible de Roundcube.']);}
    }
    if ($action === 'roundcube-import' && $_SERVER['REQUEST_METHOD']==='POST') {
        api_require_csrf();$includeCollected=($_POST['collected']??'')==='1';$includeIdentity=($_POST['identity']??'')==='1';$source=(new RoundcubeReader($email))->read($includeCollected,$includeIdentity);$stats=(new ContactRepository((array)config('contacts')))->import($email,$source['contacts']);
        if($includeIdentity&&is_array($source['identity'])){$preferences=new MailPreferenceRepository((array)config('contacts'));$current=$preferences->preferences($email);$identity=$source['identity'];$signature=clean_composer_html((string)$identity['signature_html']);$preferences->savePreferences($email,$signature,(bool)$current['receipt_default'],[...$current,...$identity]);}
        json_response(['ok'=>true,'stats'=>$stats,'identity_imported'=>$includeIdentity&&is_array($source['identity'])]);
    }
    if ($action === 'contacts-export') {
        $contacts=(new ContactRepository((array)config('contacts')))->all($email);json_response(['filename'=>'axerok-contactos.vcf','contents'=>VCard::export($contacts)]);
    }
    if ($action === 'contacts') { json_response(['contacts'=>(new ContactRepository((array)config('contacts')))->all($email)]); }
    json_response(['error'=>'Ruta inexistente.'],404);
} catch(Throwable $e) {
    $incident=bin2hex(random_bytes(6));error_log('[AxerOK Mail API '.$incident.'] '.str_replace(["\r","\n"],' ',$e->getMessage()));json_response(['error'=>'No se pudo completar la operación.','incident'=>$incident],500);
}
