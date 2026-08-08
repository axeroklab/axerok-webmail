<?php
declare(strict_types=1);

namespace AxerokMail\Contacts;

final class VCard
{
    public static function parse(string $contents): array
    {
        $contents=preg_replace("/\r?\n[ \t]/",'', $contents)??$contents;$cards=[];
        if(!preg_match_all('/BEGIN:VCARD\s*(.*?)END:VCARD/is',$contents,$matches)){return [];}
        foreach($matches[1] as $block){$c=['name'=>'','email'=>'','phone'=>'','organization'=>''];foreach(preg_split('/\r?\n/',$block)?:[] as $line){if(!str_contains($line,':'))continue;[$key,$value]=explode(':',$line,2);$key=strtoupper(strtok($key,';'));$value=str_replace(['\\n','\\,','\\;'],["\n",',',';'],trim($value));if($key==='FN')$c['name']=$value;if($key==='EMAIL'&&$c['email']==='')$c['email']=$value;if($key==='TEL'&&$c['phone']==='')$c['phone']=$value;if($key==='ORG')$c['organization']=str_replace(';',' · ',$value);}if($c['email']!=='')$cards[]=$c;}return $cards;
    }
    public static function export(array $contacts): string
    {
        $out='';foreach($contacts as $c){$escape=static fn($v)=>str_replace(["\r","\n",',',';'],['','\\n','\\,','\\;'],(string)$v);$out.="BEGIN:VCARD\r\nVERSION:3.0\r\nFN:".$escape($c['name'])."\r\nEMAIL:".$escape($c['email'])."\r\n";if(!empty($c['phone']))$out.='TEL:'.$escape($c['phone'])."\r\n";if(!empty($c['organization']))$out.='ORG:'.$escape($c['organization'])."\r\n";$out.="END:VCARD\r\n";}return $out;
    }
}

