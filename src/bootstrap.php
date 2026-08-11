<?php
declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'AxerokMail\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$vendorAutoload=dirname(__DIR__).'/vendor/autoload.php';
if(is_file($vendorAutoload))require_once $vendorAutoload;

$configFile = dirname(__DIR__) . '/config/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit("AxerOK Mail is not configured. Copy config/config.example.php to config/config.php and edit it.\n");
}

$config = require $configFile;
if (!is_array($config)) {
    throw new RuntimeException('Invalid configuration file.');
}

$key = (string)($config['app']['key'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/i', $key)) {
    throw new RuntimeException('app.key must contain exactly 64 hexadecimal characters.');
}

$mode=(string)($config['app']['mode']??'standalone');
$baseUrl = rtrim((string)($config['app']['base_url'] ?? ''), '/');
if ($mode!=='cpanel'&&(!str_starts_with($baseUrl, 'https://') || filter_var($baseUrl, FILTER_VALIDATE_URL) === false)) {
    throw new RuntimeException('app.base_url must be a valid HTTPS URL.');
}
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (int)($_SERVER['SERVER_PORT'] ?? 0) === 443;
if (!$isHttps&&$mode!=='cpanel') {
    $requestPath = '/' . ltrim((string)($_SERVER['REQUEST_URI'] ?? '/'), '/');
    header('Location: ' . $baseUrl . $requestPath, true, 302);
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Strict-Transport-Security: max-age=31536000');
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; font-src 'self' data:; frame-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'");

ini_set('session.use_strict_mode', '1');
$sessionLifetime=max(1800,(int)($config['app']['session_lifetime']??28800));
$sessionIdle=max(900,(int)($config['app']['session_idle_timeout']??1800));
ini_set('session.gc_maxlifetime',(string)$sessionLifetime);
$runtimeClass='AxerokMail\\Runtime';
$sessionDirectory=$runtimeClass::storage('sessions');
if((is_dir($sessionDirectory)||@mkdir($sessionDirectory,0700,true))&&is_writable($sessionDirectory)){@chmod($sessionDirectory,0700);session_save_path($sessionDirectory);}
session_name((string)($config['app']['session_name'] ?? 'axerok_mail'));
session_set_cookie_params([
    'lifetime' => $sessionLifetime,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

if($runtimeClass::isCpanel()&&isset($_SESSION['cpanel_token_hash'])){
    $cpanelToken=(string)($_SERVER['cp_security_token']??getenv('cp_security_token')?:'');
    if(!preg_match('#^/cpsess[0-9]+$#',$cpanelToken)||!hash_equals((string)$_SESSION['cpanel_token_hash'],hash('sha256',$cpanelToken))){$_SESSION=[];session_regenerate_id(true);}
}

if($runtimeClass::isCpanel()){
    $database=$runtimeClass::storage('axerok.sqlite');
    $config['contacts']=['dsn'=>'sqlite:'.$database,'username'=>null,'password'=>null];
}

$now = time();
if(isset($_SESSION['last_activity'])&&$now-(int)$_SESSION['last_activity']>$sessionIdle){
    $_SESSION=[];session_regenerate_id(true);
}
if (!isset($_SESSION['rotated_at']) || $now - (int)$_SESSION['rotated_at'] > 86400) {
    session_regenerate_id(true);
    $_SESSION['rotated_at']=$now;
}
$_SESSION['last_activity'] = $now;
$_SESSION['started_at'] ??= $now;

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

function config(string $path, mixed $default = null): mixed
{
    global $config;
    $value = $config;
    foreach (explode('.', $path) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function runtime_storage_path(string $path=''): string
{
    return \AxerokMail\Runtime::storage($path);
}

function app_credential_key(): string
{
    return \AxerokMail\Runtime::credentialKey((string)config('app.key'));
}

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function safe_email_html(string $html, bool $allowRemoteImages = false): string
{
    $html = preg_replace('/<base\b[^>]*>/i', '', $html) ?? '';
    $html = preg_replace_callback('/<a\b([^>]*)>/i', static function (array $match): string {
        $attributes = $match[1];
        if (!preg_match('/\bhref\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/i', $attributes, $href)) {
            return $match[0];
        }
        $url = trim(html_entity_decode($href[2] !== '' ? $href[2] : ($href[3] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if (!preg_match('/^(?:https?:\/\/|mailto:|tel:)/i', $url)) {
            return $match[0];
        }
        $attributes = preg_replace('/\s+(?:target|rel)\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $attributes) ?? $attributes;
        return '<a' . $attributes . ' target="_blank" rel="noopener noreferrer">';
    }, $html) ?? '';
    $imageSources = $allowRemoteImages ? "'self' data: cid: https:" : "'self' data: cid:";
    $policy = "default-src 'none'; img-src {$imageSources}; style-src 'unsafe-inline'; font-src data:; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    $blockedImageStyle = $allowRemoteImages ? '' : 'img[src^="http" i]{visibility:hidden}';
    return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $policy . '"><style>html{color:#202635;background:#fff;font:16px/1.6 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}' . $blockedImageStyle . '</style></head><body>' . $html . '</body></html>';
}

function email_has_remote_images(string $html): bool
{
    return preg_match('/(?:\bsrc\s*=\s*["\']\s*https?:\/\/|\burl\s*\(\s*["\']?\s*https?:\/\/)/i', $html) === 1;
}

/** @param array<int,array<string,mixed>> $inline */
function resolve_email_cids(string $html,array $inline,string $folder,int $uid,string $account=''): string
{
    foreach($inline as $part){$cid=trim((string)($part['content_id']??''),'<>');$section=(string)($part['section']??'');if($cid===''||!preg_match('/^\d+(?:\.\d+)*$/',$section))continue;$url='api.php?action=inline&folder='.rawurlencode($folder).'&uid='.$uid.'&section='.rawurlencode($section).($account!==''?'&account='.rawurlencode($account):'');$html=preg_replace('/\bcid:'.preg_quote($cid,'/').'/i',$url,$html)??$html;}
    return $html;
}

function mail_date(string $value, bool $compact = false): string
{
    try {
        $date = new DateTimeImmutable($value);
        $date = $date->setTimezone(new DateTimeZone(date_default_timezone_get()));
    } catch (Throwable) {
        return $value;
    }

    $months = [1 => 'ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
    if ($compact && $date->format('Y-m-d') === date('Y-m-d')) {
        return $date->format('H:i');
    }
    if ($compact && $date->format('Y') === date('Y')) {
        return (int)$date->format('j') . ' ' . $months[(int)$date->format('n')];
    }
    return (int)$date->format('j') . ' ' . $months[(int)$date->format('n')] . ' ' . $date->format('Y, H:i');
}

function unicode_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }
    $matches = preg_match_all('/./us', $value);
    return $matches === false ? strlen($value) : $matches;
}

function asset_url(string $path): string
{
    $path = ltrim($path, '/');
    $file = dirname(__DIR__) . '/public/' . $path;
    $version = is_file($file) ? substr((string)sha1_file($file), 0, 12) : 'missing';
    return $path . '?v=' . $version;
}

function clean_composer_html(string $html): string
{
    $html = preg_replace('/<(script|style|iframe|object|embed|form)\b[^>]*>.*?<\/\1>/is', '', $html) ?? '';
    $html = strip_tags($html, '<p><div><br><b><strong><i><em><u><s><ul><ol><li><blockquote><a>');
    return preg_replace_callback('/<([a-z0-9]+)\b([^>]*)>/i', static function(array $match): string {
        $tag=strtolower($match[1]);if($tag!=='a')return '<'.$tag.'>';
        if(!preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/i',$match[2],$href))return '<a>';
        $url=trim(html_entity_decode($href[2],ENT_QUOTES|ENT_HTML5,'UTF-8'));if(!preg_match('/^(https?:\/\/|mailto:)/i',$url))return '<a>';
        return '<a href="'.htmlspecialchars($url,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'" target="_blank" rel="noopener noreferrer">';
    },$html) ?? '';
}

function composer_plain_text(string $html): string
{
    $html = preg_replace('/<br\s*\/?>|<\/(p|div|li|blockquote)>/i', "\n", $html) ?? $html;
    return trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e((string)$_SESSION['csrf']) . '">';
}

function require_csrf(): void
{
    if (!hash_equals((string)($_SESSION['csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        http_response_code(419);
        exit('La sesión expiró. Volvé atrás y reintentá.');
    }
}
