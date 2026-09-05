<?php
declare(strict_types=1);

namespace AxerokMail\Filters;

use AxerokMail\Mail\ManageSieveClient;

/**
 * Filtros de correo del usuario (estilo Gmail) sobre SIEVE.
 *
 * Las definiciones se guardan normalizadas en sqlite (para listarlas/editarlas)
 * y en cada cambio se REGENERA el script sieve del usuario y se sube por
 * ManageSieve, de modo que los filtros corren en el servidor al entregar el
 * correo (aplican a cualquier cliente, no solo al webmail).
 */
final class FilterService
{
    private const SCRIPT_NAME = 'axerok';
    private const MAX_FILTERS = 60;
    private const FIELDS = ['from', 'to', 'cc', 'subject', 'body'];

    private \PDO $db;

    /** @param array<string,mixed> $contacts @param array<string,mixed> $mail */
    public function __construct(array $contacts, private array $mail)
    {
        $this->db = new \PDO((string) $contacts['dsn'], $contacts['username'] ?? null, $contacts['password'] ?? null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
        ]);
        if ($this->db->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->db->exec('PRAGMA busy_timeout=5000');
            $this->db->exec('PRAGMA journal_mode=WAL');
        }
        $this->db->exec("CREATE TABLE IF NOT EXISTS mail_filters (owner VARCHAR(255) NOT NULL, id VARCHAR(64) NOT NULL, name VARCHAR(160) NOT NULL, definition TEXT NOT NULL, position INTEGER NOT NULL DEFAULT 0, updated_at VARCHAR(32) NOT NULL, PRIMARY KEY(owner,id))");
    }

    /** @return list<array<string,mixed>> */
    public function all(string $owner): array
    {
        $s = $this->db->prepare('SELECT id,name,definition FROM mail_filters WHERE owner=? ORDER BY position,name');
        $s->execute([$owner]);
        $out = [];
        foreach ($s->fetchAll() ?: [] as $row) {
            $def = json_decode((string) $row['definition'], true);
            if (!is_array($def)) continue;
            $def['id'] = (string) $row['id'];
            $def['name'] = (string) $row['name'];
            $out[] = $def;
        }
        return $out;
    }

    /** Valida + guarda un filtro y regenera el sieve. Devuelve el filtro normalizado. */
    public function save(string $owner, array $input, string $imapUser, string $password): array
    {
        $filter = $this->normalize($input);
        $existing = $this->all($owner);
        $isNew = true;
        foreach ($existing as $f) if ($f['id'] === $filter['id']) { $isNew = false; break; }
        if ($isNew && count($existing) >= self::MAX_FILTERS) {
            throw new \RuntimeException('Alcanzaste el máximo de filtros (' . self::MAX_FILTERS . ').');
        }
        $now = date(DATE_ATOM);
        $pos = count($existing);
        $up = $this->db->prepare('UPDATE mail_filters SET name=?,definition=?,updated_at=? WHERE owner=? AND id=?');
        $up->execute([$filter['name'], json_encode($filter, JSON_UNESCAPED_UNICODE), $now, $owner, $filter['id']]);
        if ($up->rowCount() === 0) {
            $ins = $this->db->prepare('INSERT INTO mail_filters(owner,id,name,definition,position,updated_at) VALUES(?,?,?,?,?,?)');
            $ins->execute([$owner, $filter['id'], $filter['name'], json_encode($filter, JSON_UNESCAPED_UNICODE), $pos, $now]);
        }
        $this->syncSieve($owner, $imapUser, $password);
        return $filter;
    }

    public function delete(string $owner, string $id, string $imapUser, string $password): void
    {
        $s = $this->db->prepare('DELETE FROM mail_filters WHERE owner=? AND id=?');
        $s->execute([$owner, $id]);
        $this->syncSieve($owner, $imapUser, $password);
    }

    /** Regenera el sieve del usuario a partir de todos sus filtros y lo sube. */
    public function syncSieve(string $owner, string $imapUser, string $password): void
    {
        $filters = $this->all($owner);
        $script = $this->buildSieve($filters);
        $client = new ManageSieveClient(
            (string) ($this->mail['imap_host'] ?? '127.0.0.1'),
            4190,
            10,
            (bool) ($this->mail['allow_self_signed'] ?? true),
        );
        try {
            $client->connect($imapUser, $password);
            if (trim($script) === '') {
                // sin filtros: desactivar y borrar nuestro script (deja el sieve_before de spam intacto)
                $client->setActive('');
                $client->deleteScript(self::SCRIPT_NAME);
            } else {
                $client->putScript(self::SCRIPT_NAME, $script);
                $client->setActive(self::SCRIPT_NAME);
            }
        } finally {
            $client->close();
        }
    }

    // ── Normalización / validación ────────────────────────────────────────

    /** @return array<string,mixed> */
    private function normalize(array $in): array
    {
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 160) throw new \RuntimeException('El filtro necesita un nombre (máx 160).');
        $id = (string) ($in['id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) $id = 'f_' . bin2hex(random_bytes(8));
        $match = (($in['match'] ?? 'all') === 'any') ? 'any' : 'all';

        $conditions = [];
        foreach ((array) ($in['conditions'] ?? []) as $c) {
            $field = (string) ($c['field'] ?? '');
            $value = trim((string) ($c['value'] ?? ''));
            if (!in_array($field, self::FIELDS, true) || $value === '') continue;
            if (mb_strlen($value) > 500) throw new \RuntimeException('Un valor de condición es demasiado largo.');
            $op = (($c['op'] ?? 'contains') === 'is') ? 'is' : 'contains';
            $conditions[] = ['field' => $field, 'op' => $op, 'value' => $value];
            if (count($conditions) >= 8) break;
        }
        if ($conditions === []) throw new \RuntimeException('Agregá al menos una condición.');

        $a = (array) ($in['actions'] ?? []);
        $folder = trim((string) ($a['folder'] ?? ''));
        if (mb_strlen($folder) > 200) throw new \RuntimeException('El nombre de carpeta es demasiado largo.');
        $forward = trim((string) ($a['forward'] ?? ''));
        if ($forward !== '' && !filter_var($forward, FILTER_VALIDATE_EMAIL)) throw new \RuntimeException('La dirección de reenvío no es válida.');
        $actions = [
            'folder' => $folder,
            'read' => !empty($a['read']),
            'star' => !empty($a['star']),
            'forward' => $forward,
            'delete' => !empty($a['delete']),
            'stop' => !empty($a['stop']),
        ];
        if ($folder === '' && !$actions['read'] && !$actions['star'] && $forward === '' && !$actions['delete']) {
            throw new \RuntimeException('Elegí al menos una acción.');
        }
        return ['id' => $id, 'name' => $name, 'match' => $match, 'conditions' => $conditions, 'actions' => $actions];
    }

    // ── Generación del script sieve ───────────────────────────────────────

    /** @param list<array<string,mixed>> $filters */
    private function buildSieve(array $filters): string
    {
        if ($filters === []) return '';
        $req = ['fileinto', 'mailbox'];
        $needFlags = false; $needBody = false;
        foreach ($filters as $f) {
            if (!empty($f['actions']['read']) || !empty($f['actions']['star'])) $needFlags = true;
            foreach ($f['conditions'] as $c) if ($c['field'] === 'body') $needBody = true;
        }
        if ($needFlags) $req[] = 'imap4flags';
        if ($needBody) $req[] = 'body';

        $out = "# AxerOK Mail - filtros del usuario (generado automáticamente)\n";
        $out .= 'require [' . implode(', ', array_map(fn($r) => '"' . $r . '"', array_unique($req))) . "];\n\n";

        foreach ($filters as $f) {
            $out .= '# ' . str_replace(["\r", "\n"], ' ', (string) $f['name']) . "\n";
            $tests = [];
            foreach ($f['conditions'] as $c) {
                $tests[] = $this->conditionTest($c);
            }
            $joiner = ($f['match'] === 'any') ? 'anyof' : 'allof';
            $out .= 'if ' . $joiner . ' (' . implode(', ', $tests) . ") {\n";
            $out .= $this->actionsBlock((array) $f['actions']);
            $out .= "}\n\n";
        }
        return $out;
    }

    /** @param array<string,mixed> $c */
    private function conditionTest(array $c): string
    {
        $value = $this->q((string) $c['value']);
        $matchType = ($c['op'] === 'is') ? ':is' : ':contains';
        if ($c['field'] === 'body') {
            return 'body :text ' . $matchType . ' ' . $value;
        }
        return 'header ' . $matchType . ' "' . $c['field'] . '" ' . $value;
    }

    /** @param array<string,mixed> $a */
    private function actionsBlock(array $a): string
    {
        $b = '';
        if (!empty($a['read'])) $b .= "  setflag \"\\\\Seen\";\n";
        if (!empty($a['star'])) $b .= "  addflag \"\\\\Flagged\";\n";
        if (($a['forward'] ?? '') !== '') $b .= '  redirect ' . $this->q((string) $a['forward']) . ";\n";
        if (!empty($a['delete'])) {
            $b .= "  discard;\n";
        } elseif (($a['folder'] ?? '') !== '') {
            $b .= '  fileinto :create ' . $this->q((string) $a['folder']) . ";\n";
        }
        if (!empty($a['stop'])) $b .= "  stop;\n";
        return $b;
    }

    /** Escapa un string para sieve (comillas dobles). */
    private function q(string $s): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $s) . '"';
    }
}
