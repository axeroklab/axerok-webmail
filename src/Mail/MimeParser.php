<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class MimeParser
{
    /** @return array<string,string> */
    public static function headers(string $raw): array
    {
        $raw = preg_replace("/\r?\n[ \t]+/", ' ', $raw) ?? $raw;
        $headers = [];
        foreach (preg_split("/\r?\n/", $raw) ?: [] as $line) {
            if (!str_contains($line, ':')) { continue; }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        return $headers;
    }

    public static function decodeHeader(string $value): string
    {
        if (function_exists('iconv_mime_decode')) {
            $decoded = @iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decoded !== false) { return $decoded; }
        }
        return $value;
    }

    public static function decodeBody(string $body,string $encoding,string $charset='UTF-8'): string
    {
        $decoded=match(strtolower($encoding)){
            'base64'=>base64_decode(preg_replace('/\s+/','',$body)??$body,true)?:'',
            'quoted-printable'=>quoted_printable_decode($body),
            default=>$body,
        };
        $charset=trim($charset," \t\n\r\0\x0B\"");
        if($decoded!==''&&$charset!==''&&strcasecmp($charset,'utf-8')!==0){
            if(function_exists('mb_convert_encoding')){$converted=@mb_convert_encoding($decoded,'UTF-8',$charset);if(is_string($converted))return $converted;}
            if(function_exists('iconv')){$converted=@iconv($charset,'UTF-8//TRANSLIT',$decoded);if($converted!==false)return $converted;}
        }
        return $decoded;
    }

    public static function decodeParameter(string $value): string
    {
        if(preg_match("/^([^']*)'[^']*'(.*)$/",$value,$match)){
            $decoded=rawurldecode($match[2]);$charset=$match[1]!==''?$match[1]:'UTF-8';
            if(strcasecmp($charset,'UTF-8')!==0&&function_exists('iconv')){$converted=@iconv($charset,'UTF-8//TRANSLIT',$decoded);if($converted!==false)$decoded=$converted;}
            return $decoded;
        }
        return self::decodeHeader($value);
    }

    /** @return array<string,mixed> */
    public static function message(string $raw): array
    {
        if(class_exists(\ZBateson\MailMimeParser\Message::class))return self::libraryMessage($raw);
        [$headerRaw, $bodyRaw] = array_pad(preg_split("/\r?\n\r?\n/", $raw, 2) ?: [], 2, '');
        $headers = self::headers($headerRaw);
        $content = self::part($headers, $bodyRaw, 0);
        return [
            'from' => self::decodeHeader($headers['from'] ?? ''),
            'to' => self::decodeHeader($headers['to'] ?? ''),
            'cc' => self::decodeHeader($headers['cc'] ?? ''),
            'subject' => self::decodeHeader($headers['subject'] ?? '(Sin asunto)'),
            'date' => $headers['date'] ?? '',
            'message_id' => $headers['message-id'] ?? '',
            'html' => $content['html'],
            'text' => $content['text'],
            'attachments' => $content['attachments'],
        ];
    }

    /** @return array<string,mixed> */
    private static function libraryMessage(string $raw): array
    {
        $message=\ZBateson\MailMimeParser\Message::from($raw,false);if(count($message->getAllParts())>200)throw new MailException('El mensaje contiene demasiadas partes MIME.');$attachments=[];
        foreach($message->getAllAttachmentParts() as $part){$data=$part->getContent();$attachments[]=['name'=>$part->getFilename()??'archivo','type'=>$part->getContentType()??'application/octet-stream','size'=>strlen($data),'data'=>$data];}
        return ['from'=>(string)$message->getHeaderValue('From'),'to'=>(string)$message->getHeaderValue('To'),'cc'=>(string)$message->getHeaderValue('Cc'),'subject'=>$message->getSubject()?:'(Sin asunto)','date'=>(string)$message->getHeaderValue('Date'),'message_id'=>(string)$message->getHeaderValue('Message-ID'),'html'=>$message->getHtmlContent()??'','text'=>$message->getTextContent()??'','attachments'=>$attachments];
    }

    /** @return array{html:string,text:string,attachments:array<int,array<string,mixed>>} */
    private static function part(array $headers, string $body, int $depth): array
    {
        if ($depth > 12) { throw new MailException('El mensaje tiene una estructura MIME demasiado compleja.'); }
        $typeRaw = $headers['content-type'] ?? 'text/plain';
        $type = strtolower($typeRaw);
        $encoding = strtolower($headers['content-transfer-encoding'] ?? '');
        if (str_starts_with($type, 'multipart/') && preg_match('/boundary=(?:"([^"]+)"|([^;\s]+))/i', $typeRaw, $m)) {
            $boundary = $m[1] ?: $m[2];
            $result = ['html' => '', 'text' => '', 'attachments' => []];
            $rawParts = explode('--' . $boundary, $body);
            if (count($rawParts) > 200) { throw new MailException('El mensaje contiene demasiadas partes MIME.'); }
            foreach ($rawParts as $rawPart) {
                $rawPart = trim($rawPart, "\r\n-");
                if ($rawPart === '') { continue; }
                [$partHeaders, $partBody] = array_pad(preg_split("/\r?\n\r?\n/", $rawPart, 2) ?: [], 2, '');
                $parsed = self::part(self::headers($partHeaders), $partBody, $depth + 1);
                if ($result['html'] === '' && $parsed['html'] !== '') { $result['html'] = $parsed['html']; }
                if ($result['text'] === '' && $parsed['text'] !== '') { $result['text'] = $parsed['text']; }
                $result['attachments'] = array_merge($result['attachments'], $parsed['attachments']);
            }
            return $result;
        }
        $decoded = match ($encoding) { 'base64' => base64_decode(preg_replace('/\s+/', '', $body) ?? $body, true) ?: '', 'quoted-printable' => quoted_printable_decode($body), default => $body };
        $disposition = $headers['content-disposition'] ?? '';
        $name = '';
        if (preg_match('/(?:filename|name)=(?:"([^"]+)"|([^;\s]+))/i', $disposition . ';' . $typeRaw, $m)) { $name = self::decodeHeader($m[1] ?: $m[2]); }
        if (str_contains(strtolower($disposition), 'attachment') || $name !== '') {
            return ['html' => '', 'text' => '', 'attachments' => [['name' => $name ?: 'archivo', 'type' => strtok($type, ';'), 'size' => strlen($decoded), 'data' => $decoded]]];
        }
        if (str_starts_with($type, 'text/html')) { return ['html' => $decoded, 'text' => '', 'attachments' => []]; }
        if (str_starts_with($type, 'text/plain')) { return ['html' => '', 'text' => $decoded, 'attachments' => []]; }
        return ['html' => '', 'text' => '', 'attachments' => []];
    }
}
