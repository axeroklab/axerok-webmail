<?php
declare(strict_types=1);

// Sanitizadores de HTML de AxerOK Mail. Se extraen de bootstrap.php para poder
// probarlos de forma aislada (sin sesion, headers ni configuracion). El
// comportamiento es identico al que tenian como funciones globales del bootstrap.

function safe_email_html(string $html, bool $allowRemoteImages = false): string
{
    $html = preg_replace('/<base\b[^>]*>/i', '', $html) ?? '';
    $imageSources = $allowRemoteImages ? "'self' data: cid: https:" : "'self' data: cid:";
    $policy = "default-src 'none'; img-src {$imageSources}; style-src 'unsafe-inline'; font-src data:; media-src 'none'; object-src 'none'; base-uri 'none'; form-action 'none'";
    $blockedImageStyle = $allowRemoteImages ? '' : 'img[src^="http" i]{visibility:hidden}';
    return '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="' . $policy . '"><base target="_blank"><style>html{color:#202635;background:#fff;font:16px/1.6 Arial,sans-serif;overflow-wrap:anywhere}img{max-width:100%;height:auto}' . $blockedImageStyle . '</style></head><body>' . $html . '</body></html>';
}

function email_has_remote_images(string $html): bool
{
    return preg_match('/(?:\bsrc\s*=\s*["\']\s*https?:\/\/|\burl\s*\(\s*["\']?\s*https?:\/\/)/i', $html) === 1;
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
