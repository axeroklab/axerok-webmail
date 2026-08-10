<?php
declare(strict_types=1);

$vendor=dirname(__DIR__).'/vendor/autoload.php';
if(is_file($vendor))require $vendor;
require dirname(__DIR__) . '/src/Mail/MailException.php';
require dirname(__DIR__) . '/src/Mail/MimeParser.php';
require dirname(__DIR__) . '/src/Mail/BodyStructure.php';
require dirname(__DIR__) . '/src/Mail/ImapClient.php';
require dirname(__DIR__) . '/src/Mail/SmtpClient.php';
require dirname(__DIR__) . '/src/Contacts/VCard.php';
require dirname(__DIR__) . '/src/Contacts/RoundcubeReader.php';
require dirname(__DIR__) . '/src/Security/LoginRateLimiter.php';
require dirname(__DIR__) . '/src/Security/Credentials.php';
require dirname(__DIR__) . '/src/Contacts/ContactRepository.php';
require dirname(__DIR__) . '/src/Labels/LabelRepository.php';
require dirname(__DIR__) . '/src/Preferences/MailPreferenceRepository.php';
require dirname(__DIR__) . '/src/Worker/JobQueue.php';
require dirname(__DIR__) . '/src/Html.php';

use AxerokMail\Contacts\VCard;
use AxerokMail\Contacts\RoundcubeReader;
use AxerokMail\Mail\MailException;
use AxerokMail\Mail\MimeParser;
use AxerokMail\Mail\BodyStructure;
use AxerokMail\Mail\ImapClient;
use AxerokMail\Mail\SmtpClient;
use AxerokMail\Security\LoginRateLimiter;
use AxerokMail\Security\Credentials;
use AxerokMail\Contacts\ContactRepository;
use AxerokMail\Labels\LabelRepository;
use AxerokMail\Preferences\MailPreferenceRepository;
use AxerokMail\Worker\JobQueue;

$tests = 0;
$assert = static function (bool $condition, string $message) use (&$tests): void {
    $tests++;
    if (!$condition) { throw new RuntimeException('FAIL: ' . $message); }
};

$plain = MimeParser::message("From: Alice <alice@example.com>\r\nTo: Bob <bob@example.com>\r\nSubject: Hola\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nMensaje seguro");
$assert($plain['text'] === 'Mensaje seguro', 'plain text MIME');
$assert($plain['subject'] === 'Hola', 'subject parsing');

$multipart = MimeParser::message("From: Alice <alice@example.com>\r\nContent-Type: multipart/alternative; boundary=AxBoundary\r\n\r\n--AxBoundary\r\nContent-Type: text/plain\r\n\r\nTexto\r\n--AxBoundary\r\nContent-Type: text/html\r\n\r\n<p>HTML</p>\r\n--AxBoundary--\r\n");
$assert($multipart['text'] === 'Texto', 'multipart text');
$assert($multipart['html'] === '<p>HTML</p>', 'multipart html');
$latin=MimeParser::decodeBody("Ol=E1 se=F1or",'quoted-printable','ISO-8859-1');
$assert($latin==='Olá señor','MIME body charset conversion');
$assert(MimeParser::decodeParameter("UTF-8''factura%20a%C3%B1o.pdf")==='factura año.pdf','RFC 2231 filename decoding');

$structure=BodyStructure::parseFetchResponse('* 1 FETCH (UID 9 BODYSTRUCTURE (("TEXT" "PLAIN" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 120 4 NIL NIL NIL)("TEXT" "HTML" ("CHARSET" "UTF-8") NIL NIL "QUOTED-PRINTABLE" 240 8 NIL NIL NIL)("IMAGE" "PNG" ("NAME" "logo.png") "logo-1" NIL "BASE64" 900 NIL ("INLINE" ("FILENAME" "logo.png")) NIL NIL)("APPLICATION" "PDF" ("NAME" "informe.pdf") NIL NIL "BASE64" 5000 NIL ("ATTACHMENT" ("FILENAME" "informe.pdf")) NIL NIL) "MIXED" ("BOUNDARY" "x") NIL NIL NIL))');
$description=BodyStructure::describe($structure);
$assert($description['body']['section']==='1','BODYSTRUCTURE preferred plain body in mixed');
$assert($description['inline'][0]['section']==='3','BODYSTRUCTURE inline CID section');
$assert($description['attachments'][0]['section']==='4','BODYSTRUCTURE attachment section');

$tooMany = "From: a@example.com\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\n" . str_repeat("--x\r\nContent-Type: text/plain\r\n\r\nx\r\n", 201) . '--x--';
try { MimeParser::message($tooMany); $assert(false, 'MIME part limit'); } catch (MailException) { $assert(true, 'MIME part limit'); }

$cards = VCard::parse("BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ana Pérez\r\nEMAIL:ANA@example.com\r\nTEL:+54 11 5555-0101\r\nEND:VCARD\r\n");
$assert(count($cards) === 1, 'vCard count');
$assert($cards[0]['name'] === 'Ana Pérez', 'vCard UTF-8 name');
$assert(str_contains(VCard::export($cards), 'EMAIL:ANA@example.com'), 'vCard export');

if(class_exists(SQLite3::class)){
    $roundcubeFile=tempnam(sys_get_temp_dir(),'axerok-roundcube-');
    $roundcubeDb=new SQLite3($roundcubeFile);
    $roundcubeDb->exec('CREATE TABLE contacts (name TEXT,email TEXT,vcard TEXT,del INTEGER)');
    $roundcubeDb->exec('CREATE TABLE collected_addresses (name TEXT,email TEXT)');
    $roundcubeDb->exec('CREATE TABLE carddav_contacts (vcard TEXT)');
    $roundcubeDb->exec('CREATE TABLE identities (identity_id INTEGER,name TEXT,organization TEXT,"reply-to" TEXT,bcc TEXT,signature TEXT,html_signature INTEGER,standard INTEGER,del INTEGER)');
    $statement=$roundcubeDb->prepare('INSERT INTO contacts VALUES (:name,:email,:vcard,0)');$statement->bindValue(':name','Ana');$statement->bindValue(':email','ana@example.com');$statement->bindValue(':vcard',"BEGIN:VCARD\r\nVERSION:3.0\r\nFN:Ana\r\nEMAIL:ana@example.com\r\nEND:VCARD\r\n");$statement->execute();
    $roundcubeDb->exec("INSERT INTO collected_addresses VALUES ('Luis','luis@example.com')");
    $roundcubeDb->exec("INSERT INTO identities VALUES (1,'Juan','Pronexo','respuesta@example.com','archivo@example.com','Saludos',0,1,0)");
    $roundcubeDb->close();
    $reflection=new ReflectionClass(RoundcubeReader::class);$reader=$reflection->newInstanceWithoutConstructor();$pathProperty=$reflection->getProperty('path');$pathProperty->setValue($reader,$roundcubeFile);
    $status=$reader->status();$assert($status['personal']===1&&$status['collected']===1&&$status['identity'],'Roundcube SQLite status');
    $roundcubeData=$reader->read(true,true);$assert(count($roundcubeData['contacts'])===2,'Roundcube personal and collected contacts');
    $assert(($roundcubeData['identity']['display_name']??'')==='Juan'&&str_contains((string)($roundcubeData['identity']['signature_html']??''),'Saludos'),'Roundcube identity import');
    unlink($roundcubeFile);

    $appDatabase=tempnam(sys_get_temp_dir(),'axerok-app-');
    $sqliteConfig=['dsn'=>'sqlite:'.$appDatabase,'username'=>null,'password'=>null];
    $contactRepository=new ContactRepository($sqliteConfig);
    $assert($contactRepository->upsert('owner@example.com',['name'=>'Ana','email'=>'ana@example.com'])==='created','SQLite contact create');
    $assert($contactRepository->upsert('owner@example.com',['name'=>'Ana actualizada','email'=>'ana@example.com'])==='updated','SQLite contact native upsert');
    $assert($contactRepository->upsert('owner@example.com',['name'=>str_repeat('a',256),'email'=>'larga@example.com'])==='invalid','contact field length limit');
    $assert(count($contactRepository->all('owner@example.com'))===1,'SQLite contact isolation');
    $importStats=$contactRepository->import('owner@example.com',[['name'=>'Luis','email'=>'luis@example.com'],['name'=>'Inválido','email'=>'no-es-email']]);
    $assert($importStats===['created'=>1,'updated'=>0,'invalid'=>1],'transactional contact import stats');
    $labelRepository=new LabelRepository($sqliteConfig);
    $label=$labelRepository->create('owner@example.com','Clientes','#2563eb');
    $assert($label['id']>0&&count($labelRepository->all('owner@example.com'))===1,'SQLite label create');
    $preferenceRepository=new MailPreferenceRepository($sqliteConfig);
    $preferenceRepository->savePreferences('owner@example.com','<b>Firma</b>',true,['display_name'=>'Ana']);
    $assert($preferenceRepository->preferences('owner@example.com')['display_name']==='Ana','SQLite preferences save');
    $firstDraft=$preferenceRepository->saveDraft('owner@example.com',['id'=>'11111111-1111-4111-8111-111111111111','to'=>'one@example.com','subject'=>'Uno']);
    $secondDraft=$preferenceRepository->saveDraft('owner@example.com',['id'=>'22222222-2222-4222-8222-222222222222','to'=>'two@example.com','subject'=>'Dos']);
    $assert(count($preferenceRepository->drafts('owner@example.com'))===2&&$firstDraft['id']!==$secondDraft['id'],'multiple persistent drafts');
    $preferenceRepository->deleteDraft('owner@example.com',(string)$firstDraft['id']);
    $assert(count($preferenceRepository->drafts('owner@example.com'))===1,'delete one draft only');
    $template=$preferenceRepository->saveTemplate('owner@example.com',['name'=>'Respuesta comercial','subject'=>'Propuesta','body_html'=>'<p>Hola</p>']);
    $assert(count($preferenceRepository->templates('owner@example.com'))===1&&$template['name']==='Respuesta comercial','persistent mail template');
    $preferenceRepository->deleteTemplate('owner@example.com',(string)$template['id']);
    $assert($preferenceRepository->templates('owner@example.com')===[],'delete mail template');
    $preferenceRepository->blockSender('owner@example.com','BLOCKED@example.com');
    $assert($preferenceRepository->blockedSenders('owner@example.com')===['blocked@example.com'],'blocked sender normalization');
    $preferenceRepository->unblockSender('owner@example.com','blocked@example.com');
    $assert($preferenceRepository->blockedSenders('owner@example.com')===[],'unblock sender');
    $queue=new JobQueue(new PDO($sqliteConfig['dsn']));
    $jobId=$queue->enqueue('owner@example.com','mail.send','idempotency-001',['message_id'=>'draft-1'],time());
    $claimed=$queue->claim('worker-test');
    $assert(($claimed['id']??'')===$jobId&&($claimed['payload']['message_id']??'')==='draft-1','worker job claim');
    $queue->finish($jobId,true);
    unlink($appDatabase);
}

if(session_status()!==PHP_SESSION_ACTIVE)session_start();
$credentialKey=bin2hex(random_bytes(32));
Credentials::store('uno@example.com','secreto-uno',$credentialKey);
Credentials::store('dos@example.com','secreto-dos',$credentialKey);
$assert(Credentials::accounts()===['uno@example.com','dos@example.com'],'multiple accounts retained');
$assert(Credentials::password($credentialKey,'uno@example.com')==='secreto-uno','password selected by account');
$assert(Credentials::has('uno@example.com')&&!Credentials::has('ausente@example.com'),'strict account existence check');
$assert(Credentials::setActive('uno@example.com')&&Credentials::email()==='uno@example.com','active account switch');
Credentials::remove('uno@example.com');
$assert(Credentials::email()==='dos@example.com','account removal selects remaining account');

$smtp = new SmtpClient([]);
$builder = (new ReflectionClass($smtp))->getMethod('buildMessage');
$rawMail = $builder->invoke($smtp, 'from@example.com', ['to@example.com'], ['cc@example.com'], 'Prueba', 'Texto', '<p><b>Texto</b></p>', true, 'high', [['name'=>'informe.pdf','type'=>'application/pdf','data'=>'PDF']]);
$assert(str_contains($rawMail, 'Cc: cc@example.com'), 'SMTP Cc header');
$assert(str_contains($rawMail, 'multipart/alternative'), 'SMTP alternative body');
$assert(str_contains($rawMail, 'Content-Type: text/html; charset=UTF-8'), 'SMTP HTML body');
$assert(str_contains($rawMail, 'filename="informe.pdf"'), 'SMTP attachment');
$assert(str_contains($rawMail, 'Disposition-Notification-To: <from@example.com>'), 'SMTP receipt request');
$assert(str_contains($rawMail, 'X-Priority: 1 (Highest)'), 'SMTP high priority');
$recipientParser=(new ReflectionClass($smtp))->getMethod('recipients');
$assert($recipientParser->invoke($smtp,'Ana <ana@example.com>, BOB@example.com')===['ana@example.com','bob@example.com'],'SMTP contact display-name recipient');

$imapValidation = new ImapClient([]);
$imapReflection=new ReflectionClass($imapValidation);$encodeMailbox=$imapReflection->getMethod('encodeMailbox');$decodeMailbox=$imapReflection->getMethod('decodeMailbox');$mailboxName='Enviados & Más';$encodedMailbox=$encodeMailbox->invoke($imapValidation,$mailboxName);
$assert(is_string($encodedMailbox)&&$encodedMailbox!==''&&str_contains($encodedMailbox,'&-'),'IMAP modified UTF-7 encoding');
$assert($decodeMailbox->invoke($imapValidation,$encodedMailbox)===$mailboxName,'IMAP modified UTF-7 round trip');
$fallbackEncode=$imapReflection->getMethod('encodeModifiedUtf7');$fallbackDecode=$imapReflection->getMethod('decodeModifiedUtf7');$fallbackMailbox=$fallbackEncode->invoke(null,$mailboxName);
$assert($fallbackMailbox==='Enviados &- M&AOE-s','IMAP fallback canonical modified UTF-7');
$assert($fallbackDecode->invoke(null,$fallbackMailbox)===$mailboxName,'IMAP fallback modified UTF-7 round trip');
$assert(ImapClient::pageSequenceRange(30000,1,40)===[29961,30000],'IMAP first page sequence range');
$assert(ImapClient::pageSequenceRange(30000,2,40)===[29921,29960],'IMAP second page sequence range');
$assert(ImapClient::pageSequenceRange(17,1,40)===[1,17],'IMAP short mailbox sequence range');
$assert(ImapClient::pageSequenceRange(17,2,40)===null,'IMAP page beyond mailbox');
$assert(ImapClient::searchCriteria('factura')==='OR FROM "factura" OR TO "factura" SUBJECT "factura"','IMAP fast header search');
$assert(ImapClient::searchCriteria('factura',true)==='TEXT "factura"','IMAP optional body search');
$assert(ImapClient::searchCriteria('a"b')==='OR FROM "a\\"b" OR TO "a\\"b" SUBJECT "a\\"b"','IMAP search escaping');
$assert(ImapClient::threadIdFromHeaders(['message-id'=>'<root@example.com>'])==='<root@example.com>','thread root message id');
$assert(ImapClient::threadIdFromHeaders(['message-id'=>'<reply@example.com>','in-reply-to'=>'<root@example.com>','references'=>'<root@example.com> <reply@example.com>'])==='<root@example.com>','thread first reference');
$advanced=ImapClient::searchCriteriaFromFilters('',false,['from'=>'ventas@example.com','subject'=>'factura','status'=>'unread','size_op'=>'larger','size_bytes'=>1048576,'since'=>'2026-07-01','before'=>'2026-08-01','has_attachment'=>true]);
$assert($advanced==='FROM "ventas@example.com" SUBJECT "factura" UNSEEN LARGER 1048576 SINCE 01-Jul-2026 BEFORE 01-Aug-2026 HEADER Content-Type "multipart/mixed"','IMAP advanced search criteria');
$assert(ImapClient::searchCriteriaFromFilters('cliente',false,['exclude'=>'spam','status'=>'flagged'])==='NOT TEXT "spam" FLAGGED OR FROM "cliente" OR TO "cliente" SUBJECT "cliente"','IMAP combined search criteria');
$keywordsMethod=$imapReflection->getMethod('keywordsFromFetch');
$assert($keywordsMethod->invoke(null,'* 1 FETCH (UID 7 FLAGS (\\Seen \\Flagged AxerOK_a1b2 custom))')===['AxerOK_a1b2','custom'],'IMAP keyword extraction');
$folderValidation=$imapReflection->getMethod('validatedFolderName');
try{$folderValidation->invoke($imapValidation,"Carpeta\ninyectada");$assert(false,'IMAP folder control characters');}catch(Throwable $e){$assert($e->getPrevious() instanceof MailException||$e instanceof MailException,'IMAP folder control characters');}
$imapQuote=$imapReflection->getMethod('quote');
try{$imapQuote->invoke($imapValidation,"INBOX\r\nA0002 NOOP");$assert(false,'IMAP command injection control characters');}catch(Throwable $e){$assert($e->getPrevious() instanceof MailException||$e instanceof MailException,'IMAP command injection control characters');}
try { $imapValidation->setFlags('INBOX',[1],'invalid',true);$assert(false,'IMAP flag allowlist'); } catch (MailException) { $assert(true,'IMAP flag allowlist'); }
try { $imapValidation->setFlags('INBOX',range(1,101),'seen',true);$assert(false,'IMAP bulk limit'); } catch (MailException) { $assert(true,'IMAP bulk limit'); }

$rateDirectory = sys_get_temp_dir() . '/axerok-rate-' . bin2hex(random_bytes(4));
$limiter = new LoginRateLimiter($rateDirectory);
for ($i = 0; $i < 8; $i++) { $limiter->failure('192.0.2.10', 'victim@example.com'); }
try { $limiter->assertAllowed('198.51.100.20', 'victim@example.com'); $assert(false, 'account rate limit across IPs'); } catch (RuntimeException) { $assert(true, 'account rate limit across IPs'); }

$otherDirectory = sys_get_temp_dir() . '/axerok-rate-' . bin2hex(random_bytes(4));
$ipLimiter = new LoginRateLimiter($otherDirectory);
for ($i = 0; $i < 30; $i++) { $ipLimiter->failure('192.0.2.11', 'user' . $i . '@example.com'); }
try { $ipLimiter->assertAllowed('192.0.2.11', 'new@example.com'); $assert(false, 'IP rate limit across accounts'); } catch (RuntimeException) { $assert(true, 'IP rate limit across accounts'); }

// --- Sanitizadores de HTML (src/Html.php) — defensa anti-XSS ---
$assert(!str_contains(clean_composer_html('<script>alert(1)</script><p>hola</p>'), 'alert'), 'clean_composer_html elimina <script>');
$assert(clean_composer_html('<iframe src="//evil"></iframe><b>ok</b>') === '<b>ok</b>', 'clean_composer_html elimina <iframe>');
$assert(clean_composer_html('<p onclick="x()">hola</p>') === '<p>hola</p>', 'clean_composer_html quita atributos de eventos');
$assert(clean_composer_html('<img src=x onerror=alert(1)>hola') === 'hola', 'clean_composer_html elimina tags no permitidos');
$assert(clean_composer_html('<a href="javascript:alert(1)">x</a>') === '<a>x</a>', 'clean_composer_html rechaza href javascript:');
$linkClean = clean_composer_html('<a href="https://ok.com">x</a>');
$assert(str_contains($linkClean, 'href="https://ok.com"') && str_contains($linkClean, 'rel="noopener noreferrer"'), 'clean_composer_html conserva href http y agrega rel noopener');
$assert(composer_plain_text('<p>uno</p><p>dos</p>') === "uno\ndos", 'composer_plain_text convierte parrafos en saltos de linea');
$assert(composer_plain_text('a<br>b') === "a\nb", 'composer_plain_text convierte <br>');
$assert(email_has_remote_images('<img src="https://x/a.png">') === true, 'email_has_remote_images detecta imagen remota');
$assert(email_has_remote_images('<img src="cid:logo">') === false, 'email_has_remote_images ignora imagenes cid/inline');
$assert(email_has_remote_images('<div style="background:url(https://x/a.png)">') === true, 'email_has_remote_images detecta url() remota');
$safeDefault = safe_email_html('<script>alert(1)</script><b>hola</b>');
$assert(str_contains($safeDefault, "default-src 'none'") && str_contains($safeDefault, 'img[src^="http" i]{visibility:hidden}'), 'safe_email_html aplica CSP y bloquea imagenes remotas por defecto');
$assert(!str_contains(safe_email_html('<base href="//evil/">x'), '<base href'), 'safe_email_html elimina <base> del contenido');
$assert(str_contains(safe_email_html('x', true), "img-src 'self' data: cid: https:"), 'safe_email_html permite imagenes remotas cuando se habilita');

echo "PASS: {$tests} assertions\n";
