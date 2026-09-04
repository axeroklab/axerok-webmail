<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class BodyStructure
{
    /** @return array<string,mixed> */
    public static function parseFetchResponse(string $response): array
    {
        $position=stripos($response,'BODYSTRUCTURE');
        if($position===false)throw new MailException('El servidor no devolvió la estructura MIME.');
        $source=substr($response,$position+13);$offset=0;
        self::skipWhitespace($source,$offset);
        $value=self::value($source,$offset);
        if(!is_array($value))throw new MailException('La estructura MIME recibida no es válida.');
        return self::part($value,'1',true);
    }

    /** @return array{body:?array<string,mixed>,attachments:array<int,array<string,mixed>>,inline:array<int,array<string,mixed>>} */
    public static function describe(array $root): array
    {
        $attachments=[];$inline=[];
        self::collectFiles($root,$attachments,$inline);
        return ['body'=>self::chooseBody($root),'attachments'=>$attachments,'inline'=>$inline];
    }

    /** @return mixed */
    private static function value(string $source,int &$offset): mixed
    {
        self::skipWhitespace($source,$offset);
        if($offset>=strlen($source))throw new MailException('Estructura MIME incompleta.');
        if($source[$offset]==='('){$offset++;$values=[];while(true){self::skipWhitespace($source,$offset);if($offset>=strlen($source))throw new MailException('Estructura MIME incompleta.');if($source[$offset]===')'){$offset++;return $values;}$values[]=self::value($source,$offset);}}
        if($source[$offset]==='"')return self::quoted($source,$offset);
        $start=$offset;while($offset<strlen($source)&&!str_contains(" ()\r\n\t",$source[$offset]))$offset++;
        $atom=substr($source,$start,$offset-$start);
        if(strcasecmp($atom,'NIL')===0)return null;
        return ctype_digit($atom)?(int)$atom:$atom;
    }

    private static function quoted(string $source,int &$offset): string
    {
        $offset++;$result='';$length=strlen($source);
        while($offset<$length){$char=$source[$offset++];if($char==='"')return $result;if($char==='\\'&&$offset<$length)$char=$source[$offset++];$result.=$char;}
        throw new MailException('Cadena MIME incompleta.');
    }

    private static function skipWhitespace(string $source,int &$offset): void
    {
        $length=strlen($source);while($offset<$length&&str_contains(" \r\n\t",$source[$offset]))$offset++;
    }

    /** @param array<int,mixed> $raw @return array<string,mixed> */
    private static function part(array $raw,string $section,bool $root=false): array
    {
        if(isset($raw[0])&&is_array($raw[0])){
            $children=[];$index=0;
            while(isset($raw[$index])&&is_array($raw[$index])){$children[]=self::part($raw[$index],$root?(string)($index+1):$section.'.'.($index+1));$index++;}
            $subtype=strtolower((string)($raw[$index]??'mixed'));
            return ['section'=>$section,'type'=>'multipart/'.$subtype,'params'=>self::params($raw[$index+1]??null),'disposition'=>self::disposition($raw[$index+2]??null),'encoding'=>'','size'=>0,'content_id'=>'','children'=>$children];
        }
        $major=strtolower((string)($raw[0]??'application'));$minor=strtolower((string)($raw[1]??'octet-stream'));
        $children=[];
        if($major==='message'&&$minor==='rfc822'&&isset($raw[8])&&is_array($raw[8])){
            // El mensaje encapsulado (message/rfc822) se numera con la sección del
            // rfc822 como prefijo, como si fuera el mensaje de nivel superior: si es
            // multipart sus partes son N.1, N.2 (NO N.1.1/N.1.2); si es una sola
            // parte, es N.1. Numerarlo mal hacía que el fetch de la sección diera
            // vacío -> "No se pudo cargar la parte" (típico en bounces/DSN).
            $encapMultipart=isset($raw[8][0])&&is_array($raw[8][0]);
            $children[]=self::part($raw[8],$encapMultipart?$section:$section.'.1');
        }
        return ['section'=>$section,'type'=>$major.'/'.$minor,'params'=>self::params($raw[2]??null),'content_id'=>trim((string)($raw[3]??''),'<>'),'encoding'=>strtolower((string)($raw[5]??'')),'size'=>(int)($raw[6]??0),'disposition'=>self::findDisposition($raw),'children'=>$children];
    }

    /** @return array<string,string> */
    private static function params(mixed $raw): array
    {
        if(!is_array($raw))return [];$params=[];$continuations=[];
        for($i=0;$i+1<count($raw);$i+=2){$key=strtolower((string)$raw[$i]);$value=(string)$raw[$i+1];if(preg_match('/^(.+)\*(\d+)(\*)?$/',$key,$match)){$continuations[$match[1]][(int)$match[2]]=$value;continue;}$params[$key]=$value;}
        foreach($continuations as $key=>$segments){ksort($segments,SORT_NUMERIC);$params[$key.'*']=implode('',$segments);}
        return $params;
    }

    /** @return array{type:string,params:array<string,string>} */
    private static function disposition(mixed $raw): array
    {
        if(!is_array($raw)||!isset($raw[0]))return ['type'=>'','params'=>[]];
        return ['type'=>strtolower((string)$raw[0]),'params'=>self::params($raw[1]??null)];
    }

    /** @param array<int,mixed> $raw @return array{type:string,params:array<string,string>} */
    private static function findDisposition(array $raw): array
    {
        for($i=7;$i<count($raw);$i++)if(is_array($raw[$i])&&isset($raw[$i][0])&&is_string($raw[$i][0])&&in_array(strtolower($raw[$i][0]),['attachment','inline'],true))return self::disposition($raw[$i]);
        return ['type'=>'','params'=>[]];
    }

    /** @return array<string,mixed>|null */
    private static function chooseBody(array $part): ?array
    {
        return self::findBody($part,'text/html')??self::findBody($part,'text/plain');
    }

    /** @return array<string,mixed>|null */
    private static function findBody(array $part,string $type): ?array
    {
        if($part['type']===$type&&$part['disposition']['type']!=='attachment')return $part;
        foreach($part['children'] as $child)if($body=self::findBody($child,$type))return $body;
        return null;
    }

    /** @param array<int,array<string,mixed>> $attachments @param array<int,array<string,mixed>> $inline */
    private static function collectFiles(array $part,array &$attachments,array &$inline): void
    {
        foreach($part['children'] as $child)self::collectFiles($child,$attachments,$inline);
        if($part['children']!==[])return;
        $filename=$part['disposition']['params']['filename']??$part['disposition']['params']['filename*']??$part['params']['name']??$part['params']['name*']??'';
        $isInline=$part['disposition']['type']==='inline'||$part['content_id']!=='';
        if($isInline&&str_starts_with($part['type'],'image/')){$part['name']=$filename!==''?$filename:'imagen';$inline[]=$part;}
        elseif($part['disposition']['type']==='attachment'||$filename!==''){$part['name']=$filename!==''?$filename:'archivo';$attachments[]=$part;}
    }
}
