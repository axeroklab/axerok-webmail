<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

/**
 * Cliente ManageSieve mínimo (RFC 5804) para subir/activar/listar/borrar el
 * script sieve del usuario. Se usa para los filtros de correo del webmail:
 * el usuario define filtros, se genera un script sieve y se sube acá, así los
 * filtros corren en el SERVIDOR al entregar el correo (como Gmail).
 */
final class ManageSieveClient
{
    /** @var resource|null */
    private $stream = null;

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 4190,
        private readonly int $timeout = 10,
        private readonly bool $allowSelfSigned = true,
    ) {}

    public function connect(string $user, string $password): void
    {
        $ctx = stream_context_create(['ssl' => [
            'verify_peer' => !$this->allowSelfSigned,
            'verify_peer_name' => !$this->allowSelfSigned,
            'allow_self_signed' => $this->allowSelfSigned,
        ]]);
        $this->stream = @stream_socket_client(
            "tcp://{$this->host}:{$this->port}", $errno, $errstr, $this->timeout,
            STREAM_CLIENT_CONNECT, $ctx
        );
        if (!$this->stream) {
            throw new MailException('No se pudo conectar al servicio de filtros (ManageSieve).');
        }
        stream_set_timeout($this->stream, $this->timeout);

        $greeting = $this->readResponse();
        if (stripos($greeting['raw'], 'STARTTLS') !== false) {
            $this->send('STARTTLS');
            if ($this->readResponse()['status'] !== 'OK') {
                throw new MailException('STARTTLS rechazado por ManageSieve.');
            }
            if (!@stream_socket_enable_crypto($this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT)) {
                throw new MailException('No se pudo negociar TLS con ManageSieve.');
            }
            $this->readResponse(); // el server reenvía capacidades + OK tras TLS
        }

        // SASL PLAIN con initial-response: base64(authzid \0 authcid \0 password)
        $ir = base64_encode("\0" . $user . "\0" . $password);
        $this->send('AUTHENTICATE "PLAIN" "' . $ir . '"');
        if ($this->readResponse()['status'] !== 'OK') {
            throw new MailException('El servidor de filtros rechazó la autenticación.');
        }
    }

    /** Sube un script (reemplaza si existe). El nombre no lleva comillas ni saltos. */
    public function putScript(string $name, string $script): void
    {
        $bytes = strlen($script);
        // Literal no-sincronizante {N+}: N bytes exactos del script y luego el
        // comando se termina con CRLF (si falta, el server queda esperando).
        $this->send('PUTSCRIPT "' . $this->quote($name) . '" {' . $bytes . "+}\r\n" . $script . "\r\n", false);
        $r = $this->readResponse();
        if ($r['status'] !== 'OK') {
            throw new MailException('El servidor rechazó el filtro: ' . $r['message']);
        }
    }

    public function setActive(string $name): void
    {
        $this->send('SETACTIVE "' . $this->quote($name) . '"');
        if ($this->readResponse()['status'] !== 'OK') {
            throw new MailException('No se pudo activar el script de filtros.');
        }
    }

    public function deleteScript(string $name): void
    {
        $this->send('DELETESCRIPT "' . $this->quote($name) . '"');
        $this->readResponse(); // si no existe, NO; lo ignoramos
    }

    /** @return list<string> nombres de scripts existentes */
    public function listScripts(): array
    {
        $this->send('LISTSCRIPTS');
        $r = $this->readResponse();
        $names = [];
        foreach (explode("\n", $r['raw']) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, 'OK') || str_starts_with($line, 'NO')) continue;
            if (preg_match('/^"((?:[^"\\\\]|\\\\.)*)"/', $line, $m)) {
                $names[] = stripcslashes($m[1]);
            }
        }
        return $names;
    }

    public function close(): void
    {
        if ($this->stream) {
            @fwrite($this->stream, "LOGOUT\r\n");
            @fclose($this->stream);
            $this->stream = null;
        }
    }

    private function quote(string $s): string
    {
        return str_replace(['\\', '"'], ['\\\\', '\\"'], $s);
    }

    private function send(string $line, bool $appendCrlf = true): void
    {
        if (!$this->stream) throw new MailException('ManageSieve no está conectado.');
        $data = $line . ($appendCrlf ? "\r\n" : '');
        // fwrite sobre TLS puede escribir parcial; hay que reintentar hasta
        // mandar TODO o el server queda esperando y la conexión se corta.
        for ($off = 0, $len = strlen($data); $off < $len;) {
            $n = @fwrite($this->stream, substr($data, $off));
            if ($n === false || $n === 0) throw new MailException('No se pudo escribir a ManageSieve.');
            $off += $n;
        }
    }

    /**
     * Lee hasta una línea de estado (OK/NO/BYE), resolviendo literales {n}.
     * @return array{status:string,message:string,raw:string}
     */
    private function readResponse(): array
    {
        $raw = '';
        while (true) {
            $line = fgets($this->stream);
            if ($line === false) {
                throw new MailException('Conexión ManageSieve interrumpida.');
            }
            $raw .= $line;
            // ¿es una línea de estado (OK/NO/BYE)? Puede además traer un literal
            // como mensaje (típico en errores de validación multilínea).
            $status = null; $inline = '';
            if (preg_match('/^(OK|NO|BYE)\b(.*)$/i', ltrim($line), $sm)) {
                $status = strtoupper($sm[1]); $inline = trim($sm[2]);
            }
            if (preg_match('/\{(\d+)\+?\}\r?\n$/', $line, $lm)) {
                $need = (int) $lm[1]; $data = '';
                while ($need > 0 && !feof($this->stream)) {
                    $chunk = fread($this->stream, $need);
                    if ($chunk === false || $chunk === '') break;
                    $data .= $chunk; $need -= strlen($chunk);
                }
                $raw .= $data;
                if ($status !== null) {
                    @fgets($this->stream); // consumir el CRLF que cierra el literal del estado
                    return ['status' => $status, 'message' => trim($data), 'raw' => $raw];
                }
                continue; // literal de datos (capabilities / listscripts): seguir
            }
            if ($status !== null) {
                return ['status' => $status, 'message' => $inline, 'raw' => $raw];
            }
        }
    }
}
