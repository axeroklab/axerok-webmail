<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class ImapClient
{
    /** @var resource|null */
    private $socket = null;
    private int $tag = 0;
    private int $lastMailboxTotal = 0;

    public function __construct(private readonly array $options) {}

    public function connect(string $username, string $password): void
    {
        $host = (string)$this->options['imap_host'];
        $port = (int)$this->options['imap_port'];
        $encryption = (string)($this->options['imap_encryption'] ?? 'ssl');
        if(!in_array($encryption,['ssl','tls'],true))throw new MailException('IMAP requiere una conexión TLS segura.');
        $transport = $encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create(['ssl' => [
            'verify_peer' => !($this->options['allow_self_signed'] ?? false),
            'verify_peer_name' => !($this->options['allow_self_signed'] ?? false),
            'allow_self_signed' => (bool)($this->options['allow_self_signed'] ?? false),
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $error, 15, STREAM_CLIENT_CONNECT, $context);
        if (!is_resource($socket)) {
            throw new MailException("No se pudo conectar con IMAP: {$error} ({$errno})");
        }
        $this->socket = $socket;
        stream_set_timeout($this->socket, 20);
        $greeting = $this->readLine();
        if (!str_starts_with($greeting, '* OK')) {
            throw new MailException('El servidor IMAP rechazó la conexión.');
        }
        if ($encryption === 'tls') {
            $this->command('STARTTLS');
            if (!stream_socket_enable_crypto($this->socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new MailException('No se pudo activar TLS para IMAP.');
            }
        }
        try {
            $this->command('LOGIN ' . $this->quote($username) . ' ' . $this->quote($password));
        } catch (MailException $exception) {
            if (is_resource($this->socket)) {
                fclose($this->socket);
            }
            $this->socket = null;
            throw new AuthenticationException('El servidor de correo rechazó la autenticación.', 0, $exception);
        }
    }

    public function ping(): void
    {
        $this->command('NOOP');
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            try { $this->command('LOGOUT'); } catch (\Throwable) {}
            fclose($this->socket);
        }
        $this->socket = null;
    }

    /** @return array<int,array{name:string,delimiter:string,flags:array<int,string>,special:?string}> */
    public function folders(): array
    {
        try { $response = $this->command('LIST "" "*" RETURN (SPECIAL-USE SUBSCRIBED)'); }
        catch (MailException) { $response = $this->command('LIST "" "*"'); }
        $folders = [];
        foreach ($response as $line) {
            if (!preg_match('/^\* LIST \(([^)]*)\) (?:"([^"]*)"|NIL) (.+)$/i', $line, $m)) {
                continue;
            }
            $flags = preg_split('/\s+/', trim($m[1])) ?: [];
            $name = $this->decodeMailbox(trim($m[3], '"'));
            $special = null;
            foreach (['\\Inbox','\\Sent','\\Drafts','\\Junk','\\Trash','\\Archive','\\All','\\Flagged'] as $candidate) {
                if (in_array($candidate, $flags, true)) { $special = strtolower(substr($candidate, 1)); break; }
            }
            if (strcasecmp($name, 'INBOX') === 0) { $special = 'inbox'; }
            $folders[] = ['name' => $name, 'delimiter' => $m[2] ?? '/', 'flags' => $flags, 'special' => $special];
        }
        return $folders;
    }

    public function createFolder(string $name): void
    {
        $name=$this->validatedFolderName($name);
        $encoded=$this->quote($this->encodeMailbox($name));
        $this->command('CREATE '.$encoded);
        try{$this->command('SUBSCRIBE '.$encoded);}catch(MailException){}
    }

    public function renameFolder(string $current,string $next): void
    {
        $current=$this->validatedFolderName($current);$next=$this->validatedFolderName($next);
        if(strcasecmp($current,'INBOX')===0)throw new MailException('La carpeta Recibidos no se puede renombrar.');
        $this->command('RENAME '.$this->quote($this->encodeMailbox($current)).' '.$this->quote($this->encodeMailbox($next)));
    }

    public function deleteFolder(string $name): void
    {
        $name=$this->validatedFolderName($name);
        if(strcasecmp($name,'INBOX')===0)throw new MailException('La carpeta Recibidos no se puede eliminar.');
        $encoded=$this->quote($this->encodeMailbox($name));
        try{$this->command('UNSUBSCRIBE '.$encoded);}catch(MailException){}
        $this->command('DELETE '.$encoded);
    }

    private function validatedFolderName(string $name): string
    {
        $name=trim($name);
        if($name===''||strlen($name)>190||preg_match('/[\x00-\x1F\x7F]/',$name))throw new MailException('El nombre de la carpeta no es válido.');
        return $name;
    }

    /** @return array{exists:int,unseen:int,uidvalidity:?int} */
    public function select(string $folder): array
    {
        $folder = $this->validatedFolderName($folder);
        $response = $this->command('SELECT ' . $this->quote($this->encodeMailbox($folder)));
        $status = ['exists' => 0, 'unseen' => 0, 'uidvalidity' => null];
        foreach ($response as $line) {
            if (preg_match('/^\* (\d+) EXISTS$/i', $line, $m)) { $status['exists'] = (int)$m[1]; }
            if (preg_match('/\[UNSEEN (\d+)\]/i', $line, $m)) { $status['unseen'] = (int)$m[1]; }
            if (preg_match('/\[UIDVALIDITY (\d+)\]/i', $line, $m)) { $status['uidvalidity'] = (int)$m[1]; }
        }
        return $status;
    }

    public function unreadCount(string $folder = 'INBOX'): int
    {
        $folder = $this->validatedFolderName($folder);
        $response = $this->command('STATUS ' . $this->quote($this->encodeMailbox($folder)) . ' (UNSEEN)');
        foreach ($response as $line) {
            if (preg_match('/^\* STATUS .+ \([^)]*\bUNSEEN (\d+)\b[^)]*\)$/i', $line, $match)) {
                return (int)$match[1];
            }
        }
        throw new MailException('El servidor IMAP no informó la cantidad de mensajes no leídos.');
    }

    /** @return array<int,array<string,mixed>> */
    public function messages(string $folder, int $page = 1, int $pageSize = 40, string $query = '', bool $searchBody = false, array $filters = []): array
    {
        $status = $this->select($folder);
        $this->lastMailboxTotal = (int)$status['exists'];
        $query = trim($query);
        if (strlen($query) > 200 || preg_match('/[\x00-\x1F\x7F]/', $query)) {
            throw new MailException('La búsqueda no es válida o supera 200 caracteres.');
        }
        $uids = [];
        $criteria=self::searchCriteriaFromFilters($query,$searchBody,$filters);
        if ($criteria === '') {
            $range = self::pageSequenceRange((int)$status['exists'], $page, $pageSize);
            if ($range === null) { return []; }
            [$start, $end] = $range;
            $parts = $this->command("FETCH {$start}:{$end} (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES AUTO-SUBMITTED PRECEDENCE LIST-ID LIST-UNSUBSCRIBE)])");
        } else {
            $search = $this->command('UID SEARCH CHARSET UTF-8 ' . $criteria);
            foreach ($search as $line) {
                if (str_starts_with($line, '* SEARCH')) {
                    $uids = array_values(array_filter(array_map('intval', preg_split('/\s+/', trim(substr($line, 8))) ?: [])));
                }
            }
            if(count($uids)>10000)throw new MailException('La búsqueda devuelve demasiados resultados. Agregá más filtros.');
            rsort($uids, SORT_NUMERIC);
            $this->lastMailboxTotal = count($uids);
            $uids = array_slice($uids, max(0, ($page - 1) * $pageSize), $pageSize);
            if ($uids === []) { return []; }
            $parts = $this->command('UID FETCH ' . implode(',', $uids) . ' (UID FLAGS INTERNALDATE RFC822.SIZE BODY.PEEK[HEADER.FIELDS (FROM TO SUBJECT DATE MESSAGE-ID IN-REPLY-TO REFERENCES AUTO-SUBMITTED PRECEDENCE LIST-ID LIST-UNSUBSCRIBE)])');
        }
        $byUid = [];
        for ($index = 0, $count = count($parts); $index < $count; $index++) {
            $meta = $parts[$index];
            if (!preg_match('/^\* \d+ FETCH .*\bUID (\d+)\b/i', $meta, $uidMatch)) { continue; }
            $header = isset($parts[$index + 1]) && (str_contains($parts[$index + 1], "\r\n") || preg_match('/^[\w-]+:/', $parts[$index + 1])) ? $parts[++$index] : '';
            $headers = MimeParser::headers($header);
            $uid = (int)$uidMatch[1];
            $byUid[$uid] = [
                'uid' => $uid,
                'folder' => $folder,
                'from' => MimeParser::decodeHeader($headers['from'] ?? ''),
                'to' => MimeParser::decodeHeader($headers['to'] ?? ''),
                'cc' => MimeParser::decodeHeader($headers['cc'] ?? ''),
                'subject' => MimeParser::decodeHeader($headers['subject'] ?? '(Sin asunto)'),
                'date' => $headers['date'] ?? '',
                'message_id' => $headers['message-id'] ?? '',
                'thread_id' => self::threadIdFromHeaders($headers),
                'seen' => str_contains($meta, '\\Seen'),
                'flagged' => str_contains($meta, '\\Flagged'),
                'keywords' => self::keywordsFromFetch($meta),
                'category' => isset($headers['list-id'])||isset($headers['list-unsubscribe'])||(isset($headers['auto-submitted'])&&strcasecmp($headers['auto-submitted'],'no')!==0)||in_array(strtolower($headers['precedence']??''),['bulk','list','junk'],true)?'notification':'principal',
                'size' => preg_match('/RFC822\.SIZE (\d+)/i', $meta, $sizeMatch) ? (int)$sizeMatch[1] : 0,
            ];
        }
        krsort($byUid, SORT_NUMERIC);
        return array_values($byUid);
    }

    public function lastMailboxTotal(): int
    {
        return $this->lastMailboxTotal;
    }

    /** @param array<int,array{name:string,flags?:array<int,string>}> $folders */
    public function messagesAcrossFolders(array $folders, int $page, int $pageSize, string $query = '', bool $searchBody = false, array $filters = [], int $perFolderLimit = 100): array
    {
        $candidates = array_values(array_filter($folders, static fn(array $item): bool => !in_array('\\Noselect', $item['flags'] ?? [], true)));
        if (count($candidates) > 50) throw new MailException('Hay demasiadas carpetas para una búsqueda global.');
        $rows = [];$total=0;$needed=max($perFolderLimit,$page*$pageSize);if($needed>1000)throw new MailException('La página solicitada supera el límite de búsqueda global.');
        foreach ($candidates as $item) {
            $rows = array_merge($rows, $this->messages($item['name'], 1, $needed, $query, $searchBody, $filters));$total+=$this->lastMailboxTotal();
        }
        usort($rows, static function(array $a,array $b): int {$left=strtotime((string)$a['date']);$right=strtotime((string)$b['date']);return ($right?:0)<=>($left?:0);});
        $this->lastMailboxTotal = $total;
        return array_slice($rows, max(0, ($page - 1) * $pageSize), $pageSize);
    }

    /** @param array<string,string> $headers */
    public static function threadIdFromHeaders(array $headers): string
    {
        $references=(string)($headers['references']??'');
        if(preg_match('/<[^<>\s]+>/', $references, $match))return strtolower($match[0]);
        $reply=(string)($headers['in-reply-to']??'');
        if(preg_match('/<[^<>\s]+>/', $reply, $match))return strtolower($match[0]);
        $message=(string)($headers['message-id']??'');
        return preg_match('/<[^<>\s]+>/', $message, $match)?strtolower($match[0]):'';
    }

    /** @return array<int,int> */
    public function threadUids(string $folder,string $threadId,int $limit=30): array
    {
        if(!preg_match('/^<[^<>\s]{1,998}>$/',$threadId))throw new MailException('Identificador de conversación inválido.');
        $this->select($folder);$quoted='"'.str_replace(['\\','"'],['\\\\','\\"'],$threadId).'"';
        $response=$this->command('UID SEARCH OR OR HEADER Message-ID '.$quoted.' HEADER In-Reply-To '.$quoted.' HEADER References '.$quoted);$uids=[];
        foreach($response as $line)if(str_starts_with($line,'* SEARCH'))$uids=array_values(array_filter(array_map('intval',preg_split('/\s+/',trim(substr($line,8)))?:[])));
        sort($uids,SORT_NUMERIC);return array_slice(array_values(array_unique($uids)),max(0,count($uids)-max(1,min(50,$limit))));
    }

    public static function searchCriteria(string $query, bool $searchBody = false): string
    {
        $quoted = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $query) . '"';
        if ($searchBody) { return 'TEXT ' . $quoted; }
        return 'OR FROM ' . $quoted . ' OR TO ' . $quoted . ' SUBJECT ' . $quoted;
    }

    public static function searchCriteriaFromFilters(string $query, bool $searchBody, array $filters): string
    {
        $criteria=[];
        $quote=static fn(string $value):string=>'"'.str_replace(['\\','"'],['\\\\','\\"'],trim($value)).'"';
        foreach(['from','to','subject','contains','exclude'] as $key){$value=(string)($filters[$key]??'');if(strlen($value)>200||preg_match('/[\x00-\x1F\x7F]/',$value))throw new MailException('Uno de los filtros de búsqueda no es válido.');}
        foreach(['from'=>'FROM','to'=>'TO','subject'=>'SUBJECT'] as $key=>$imap){$value=trim((string)($filters[$key]??''));if($value!=='')$criteria[]=$imap.' '.$quote($value);}
        $contains=trim((string)($filters['contains']??''));if($contains!=='')$criteria[]='TEXT '.$quote($contains);
        $exclude=trim((string)($filters['exclude']??''));if($exclude!=='')$criteria[]='NOT TEXT '.$quote($exclude);
        $status=(string)($filters['status']??'all');if($status==='unread')$criteria[]='UNSEEN';elseif($status==='read')$criteria[]='SEEN';elseif($status==='flagged')$criteria[]='FLAGGED';
        $size=max(0,(int)($filters['size_bytes']??0));$sizeOperator=(string)($filters['size_op']??'');if($size>0&&$sizeOperator==='larger')$criteria[]='LARGER '.$size;elseif($size>0&&$sizeOperator==='smaller')$criteria[]='SMALLER '.$size;
        foreach(['since'=>'SINCE','before'=>'BEFORE'] as $key=>$imap){$value=(string)($filters[$key]??'');$date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value);if($date&&$date->format('Y-m-d')===$value)$criteria[]=$imap.' '.$date->format('d-M-Y');}
        if(!empty($filters['has_attachment']))$criteria[]='HEADER Content-Type "multipart/mixed"';
        $query=trim($query);if($query!=='')$criteria[]=self::searchCriteria($query,$searchBody);
        return implode(' ',$criteria);
    }

    /** @return array{0:int,1:int}|null */
    public static function pageSequenceRange(int $exists, int $page, int $pageSize): ?array
    {
        if ($exists < 1 || $page < 1 || $pageSize < 1) { return null; }
        $end = $exists - (($page - 1) * $pageSize);
        if ($end < 1) { return null; }
        return [max(1, $end - $pageSize + 1), $end];
    }

    /** @return array<string,mixed> */
    public function message(string $folder, int $uid, bool $markSeen = true): array
    {
        $this->select($folder);
        if ($uid < 1) { throw new MailException('Mensaje inválido.'); }
        $overview=$this->command("UID FETCH {$uid} (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER] BODYSTRUCTURE)");
        $header=$this->firstLiteral($overview);if($header==='')throw new MailException('El mensaje ya no existe.');
        $headers=MimeParser::headers($header);$structure=BodyStructure::parseFetchResponse($this->responseSyntax($overview));$description=BodyStructure::describe($structure);
        $body=$description['body'];$html='';$text='';
        if(is_array($body)){
            $rawBody=$this->fetchSection($uid,(string)$body['section'],!$markSeen);
            $decoded=MimeParser::decodeBody($rawBody,(string)$body['encoding'],(string)($body['params']['charset']??'UTF-8'));
            if($body['type']==='text/html')$html=$decoded;else$text=$decoded;
        } elseif($markSeen) {
            $this->command("UID STORE {$uid} +FLAGS.SILENT (\\Seen)");
        }
        $attachments=array_map(static fn(array $part):array=>['name'=>MimeParser::decodeParameter((string)$part['name']),'type'=>$part['type'],'size'=>$part['size'],'section'=>$part['section']],$description['attachments']);
        $inline=array_map(static fn(array $part):array=>['name'=>MimeParser::decodeParameter((string)$part['name']),'type'=>$part['type'],'size'=>$part['size'],'section'=>$part['section'],'content_id'=>$part['content_id']],$description['inline']);
        return ['uid'=>$uid,'from'=>MimeParser::decodeHeader($headers['from']??''),'to'=>MimeParser::decodeHeader($headers['to']??''),'cc'=>MimeParser::decodeHeader($headers['cc']??''),'subject'=>MimeParser::decodeHeader($headers['subject']??'(Sin asunto)'),'date'=>$headers['date']??'','message_id'=>$headers['message-id']??'','thread_id'=>self::threadIdFromHeaders($headers),'html'=>$html,'text'=>$text,'attachments'=>$attachments,'inline'=>$inline];
    }

    public function messageHeaders(string $folder, int $uid): string
    {
        $this->select($folder);
        if ($uid < 1) { throw new MailException('Mensaje inválido.'); }
        $headers=$this->firstLiteral($this->command("UID FETCH {$uid} (BODY.PEEK[HEADER])"));
        if($headers==='')throw new MailException('El mensaje ya no existe.');
        return $headers;
    }

    public function rawMessage(string $folder, int $uid): string
    {
        $this->select($folder);
        if ($uid < 1) { throw new MailException('Mensaje inválido.'); }
        $raw=$this->firstLiteral($this->command("UID FETCH {$uid} (BODY.PEEK[])"));
        if($raw==='')throw new MailException('El mensaje ya no existe.');
        return $raw;
    }

    /** @return array{name:string,type:string,size:int,section:string,data:string} */
    public function attachment(string $folder,int $uid,string $section): array
    {
        $this->select($folder);if($uid<1||!preg_match('/^\d+(?:\.\d+)*$/',$section))throw new MailException('Adjunto inválido.');
        $overview=$this->command("UID FETCH {$uid} (BODYSTRUCTURE)");$structure=BodyStructure::parseFetchResponse($this->responseSyntax($overview));$description=BodyStructure::describe($structure);
        foreach(array_merge($description['attachments'],$description['inline']) as $part)if(hash_equals((string)$part['section'],$section))return ['name'=>MimeParser::decodeParameter((string)($part['name']??'archivo')),'type'=>(string)$part['type'],'size'=>(int)$part['size'],'section'=>$section,'data'=>MimeParser::decodeBody($this->fetchSection($uid,$section,true),(string)$part['encoding'])];
        throw new MailException('El adjunto no existe.');
    }

    private function fetchSection(int $uid,string $section,bool $peek=true): string
    {
        if(!preg_match('/^\d+(?:\.\d+)*$/',$section))throw new MailException('Sección MIME inválida.');
        $item=$peek?'BODY.PEEK['.$section.']':'BODY['.$section.']';$parts=$this->command("UID FETCH {$uid} ({$item})");$literal=$this->firstLiteral($parts);
        if($literal==='')throw new MailException('No se pudo cargar la parte solicitada.');return $literal;
    }

    public function setSeen(string $folder, int $uid, bool $seen): void
    {
        $this->setFlags($folder,[$uid],'seen',$seen);
    }

    /** @param array<int,int> $uids */
    public function setFlags(string $folder,array $uids,string $flag,bool $enabled): void
    {
        $uids=array_values(array_unique(array_filter(array_map('intval',$uids),static fn(int $uid):bool=>$uid>0)));
        if($uids===[]||count($uids)>100)throw new MailException('La selección de mensajes no es válida.');
        $imapFlag=match($flag){'seen'=>'\\Seen','flagged'=>'\\Flagged',default=>throw new MailException('La bandera solicitada no es válida.')};
        $this->select($folder);
        $this->command('UID STORE '.implode(',',$uids).($enabled?' +FLAGS.SILENT (':' -FLAGS.SILENT (').$imapFlag.')');
    }

    public function setKeyword(string $folder,array $uids,string $keyword,bool $enabled): void
    {
        $uids=array_values(array_unique(array_filter(array_map('intval',$uids),static fn(int $uid):bool=>$uid>0)));
        if($uids===[]||count($uids)>100||!preg_match('/^[A-Za-z0-9_-]{1,64}$/',$keyword))throw new MailException('La selección o etiqueta no es válida.');
        $this->select($folder);$this->command('UID STORE '.implode(',',$uids).($enabled?' +FLAGS.SILENT (':' -FLAGS.SILENT (').$keyword.')');
    }

    private static function keywordsFromFetch(string $meta): array
    {
        if(!preg_match('/FLAGS \(([^)]*)\)/i',$meta,$match))return [];$flags=preg_split('/\s+/',trim($match[1]))?:[];return array_values(array_filter($flags,static fn(string $flag):bool=>$flag!==''&&!str_starts_with($flag,'\\')));
    }

    public function move(string $folder, int $uid, string $destination): void
    {
        $destination = $this->validatedFolderName($destination);
        $this->select($folder);
        $this->command('UID MOVE ' . $uid . ' ' . $this->quote($this->encodeMailbox($destination)));
    }

    /** @param array<int,int> $uids */
    public function moveMany(string $folder,array $uids,string $destination): void
    {
        $destination = $this->validatedFolderName($destination);
        $uids=array_values(array_unique(array_filter(array_map('intval',$uids),static fn(int $uid):bool=>$uid>0)));
        if($uids===[]||count($uids)>100)throw new MailException('La selección de mensajes no es válida.');
        $this->select($folder);$this->command('UID MOVE '.implode(',',$uids).' '.$this->quote($this->encodeMailbox($destination)));
    }

    /** @param array<int,int> $uids */
    public function copyMany(string $folder,array $uids,string $destination): void
    {
        $destination = $this->validatedFolderName($destination);
        $uids=array_values(array_unique(array_filter(array_map('intval',$uids),static fn(int $uid):bool=>$uid>0)));
        if($uids===[]||count($uids)>100)throw new MailException('La selección de mensajes no es válida.');
        $this->select($folder);$this->command('UID COPY '.implode(',',$uids).' '.$this->quote($this->encodeMailbox($destination)));
    }

    /** @param array<int,int> $uids */
    public function deleteMany(string $folder,array $uids): void
    {
        $uids=array_values(array_unique(array_filter(array_map('intval',$uids),static fn(int $uid):bool=>$uid>0)));
        if($uids===[]||count($uids)>100)throw new MailException('La selección de mensajes no es válida.');
        $this->select($folder);
        $this->command('UID STORE '.implode(',',$uids).' +FLAGS.SILENT (\\Deleted)');
        $this->command('UID EXPUNGE '.implode(',',$uids));
    }

    public function emptyFolder(string $folder): int
    {
        $this->select($folder);$search=$this->command('UID SEARCH ALL');$uids=[];
        foreach($search as $line)if(str_starts_with($line,'* SEARCH'))$uids=array_values(array_filter(array_map('intval',preg_split('/\s+/',trim(substr($line,8)))?:[])));
        if($uids===[])return 0;
        foreach(array_chunk($uids,100) as $chunk){$set=implode(',',$chunk);$this->command('UID STORE '.$set.' +FLAGS.SILENT (\\Deleted)');$this->command('UID EXPUNGE '.$set);}
        return count($uids);
    }

    public function append(string $folder, string $rawMessage): void
    {
        $folder = $this->validatedFolderName($folder);
        if (!is_resource($this->socket)) { throw new MailException('No hay conexión IMAP.'); }
        $tag='A'.str_pad((string)++$this->tag,4,'0',STR_PAD_LEFT);
        fwrite($this->socket,$tag.' APPEND '.$this->quote($this->encodeMailbox($folder)).' (\\Seen) {'.strlen($rawMessage)."}\r\n");
        $continue=$this->readLine(); if(!str_starts_with($continue,'+')){throw new MailException('El servidor no aceptó guardar el mensaje enviado.');}
        fwrite($this->socket,$rawMessage."\r\n");
        while(true){$line=$this->readLine();if(str_starts_with($line,$tag.' ')){if(!str_starts_with($line,$tag.' OK'))throw new MailException('No se pudo guardar el mensaje en Enviados.');return;}}
    }

    /** @return array<int,string> */
    private function command(string $command): array
    {
        if (!is_resource($this->socket)) { throw new MailException('No hay conexión IMAP.'); }
        $tag = 'A' . str_pad((string)++$this->tag, 4, '0', STR_PAD_LEFT);
        fwrite($this->socket, $tag . ' ' . $command . "\r\n");
        $parts = [];
        while (true) {
            $line = $this->readLine();
            if (preg_match('/\{(\d+)\}$/', $line, $m)) {
                $parts[] = $line;
                $parts[] = $this->readBytes((int)$m[1]);
                continue;
            }
            $parts[] = $line;
            if (str_starts_with($line, $tag . ' ')) {
                if (!str_starts_with($line, $tag . ' OK')) {
                    throw new MailException('IMAP: ' . preg_replace('/^' . preg_quote($tag, '/') . '\s+(NO|BAD)\s*/', '', $line));
                }
                return $parts;
            }
        }
    }

    private function readLine(): string
    {
        if (!is_resource($this->socket)) { throw new MailException('Conexión IMAP cerrada.'); }
        $line = fgets($this->socket, 8 * 1024 * 1024);
        if ($line === false) { throw new MailException('El servidor IMAP cerró la conexión.'); }
        if(!str_ends_with($line,"\n")&&!feof($this->socket))throw new MailException('El servidor IMAP devolvió una línea demasiado grande.');
        return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string
    {
        $maximum = (int)($this->options['max_message_bytes'] ?? 26214400);
        if ($length < 0 || $length > $maximum) { throw new MailException('El servidor intentó enviar un bloque demasiado grande.'); }
        $data = '';
        while (strlen($data) < $length) {
            $chunk = fread($this->socket, $length - strlen($data));
            if ($chunk === false || $chunk === '') { throw new MailException('Respuesta IMAP incompleta.'); }
            $data .= $chunk;
        }
        return $data;
    }

    /** @param array<int,string> $parts */
    private function firstLiteral(array $parts): string
    {
        foreach($parts as $index=>$part){if($index>0&&preg_match('/\{'.strlen($part).'\}$/',$parts[$index-1]))return $part;}
        return '';
    }

    /** @param array<int,string> $parts */
    private function responseSyntax(array $parts): string
    {
        $syntax=[];foreach($parts as $index=>$part){if($index>0&&preg_match('/\{'.strlen($part).'\}$/',$parts[$index-1]))continue;$syntax[]=$part;}return implode(' ',$syntax);
    }

    private function quote(string $value): string
    {
        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new MailException('El comando IMAP contiene caracteres no válidos.');
        }
        return '"' . addcslashes($value, "\\\"") . '"';
    }
    private function encodeMailbox(string $name): string
    {
        if(function_exists('mb_convert_encoding')){try{$converted=@mb_convert_encoding($name,'UTF7-IMAP','UTF-8');if(is_string($converted))return $converted;}catch(\Throwable){}}
        return self::encodeModifiedUtf7($name);
    }

    private static function encodeModifiedUtf7(string $name): string
    {
        $result='';$buffer='';$flush=static function()use(&$result,&$buffer):void{if($buffer==='')return;$utf16=function_exists('mb_convert_encoding')?@mb_convert_encoding($buffer,'UTF-16BE','UTF-8'):false;if(!is_string($utf16)&&function_exists('iconv'))$utf16=@iconv('UTF-8','UTF-16BE',$buffer);if(!is_string($utf16))throw new MailException('No se pudo codificar el nombre de la carpeta.');$result.='&'.rtrim(str_replace('/',',',base64_encode($utf16)),'=').'-';$buffer='';};
        foreach(preg_split('//u',$name,-1,PREG_SPLIT_NO_EMPTY)?:[] as $char){$byte=ord($char[0]);if(strlen($char)===1&&$byte>=0x20&&$byte<=0x7e){$flush();$result.=$char==='&'?'&-':$char;}else{$buffer.=$char;}}$flush();return $result;
    }

    private function decodeMailbox(string $name): string
    {
        if(function_exists('mb_convert_encoding')){try{$converted=@mb_convert_encoding($name,'UTF-8','UTF7-IMAP');if(is_string($converted))return $converted;}catch(\Throwable){}}
        return self::decodeModifiedUtf7($name);
    }

    private static function decodeModifiedUtf7(string $name): string
    {
        return preg_replace_callback('/&([^-]*)-/',static function(array $match):string{if($match[1]==='')return '&';$encoded=str_replace(',','/',$match[1]);$encoded.=str_repeat('=',(4-strlen($encoded)%4)%4);$utf16=base64_decode($encoded,true);if($utf16===false)throw new MailException('El servidor devolvió una carpeta IMAP inválida.');$utf8=function_exists('mb_convert_encoding')?@mb_convert_encoding($utf16,'UTF-8','UTF-16BE'):false;if(!is_string($utf8)&&function_exists('iconv'))$utf8=@iconv('UTF-16BE','UTF-8',$utf16);if(!is_string($utf8))throw new MailException('No se pudo decodificar el nombre de la carpeta.');return $utf8;},$name)??$name;
    }
}
