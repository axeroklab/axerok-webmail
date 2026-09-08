<?php
declare(strict_types=1);

namespace AxerokMail\Contacts;

/**
 * Lee contactos e identidades directamente de una base Roundcube en MySQL
 * (deployments no-cPanel donde Roundcube usa MySQL en vez del sqlite per-cuenta).
 * Asi AxerOK usa LA MISMA fuente de contactos/firmas que Roundcube.
 * El usuario de Roundcube se ubica por username = email o email*nxsso (login SSO).
 */
final class RoundcubeMysqlReader
{
    private \PDO $db;
    /** @var list<int> */
    private array $userIds;

    /** @param array{dsn:string,user?:string,pass?:string} $config */
    public function __construct(string $email, array $config)
    {
        $this->db = new \PDO(
            (string)$config['dsn'],
            (string)($config['user'] ?? ''),
            (string)($config['pass'] ?? ''),
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]
        );
        $email = strtolower(trim($email));
        $stmt = $this->db->prepare('SELECT user_id FROM users WHERE username = ? OR username = ?');
        $stmt->execute([$email, $email . '*nxsso']);
        $this->userIds = array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    /** @return list<array{id:int,email:string,name:string,phone:string,organization:string}> */
    public function contacts(): array
    {
        if ($this->userIds === []) return [];
        $in = implode(',', array_fill(0, count($this->userIds), '?'));
        $stmt = $this->db->prepare("SELECT name,email,vcard FROM contacts WHERE del=0 AND user_id IN ($in) LIMIT 5000");
        $stmt->execute($this->userIds);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $cards = VCard::parse((string)($row['vcard'] ?? ''));
            $entries = $cards !== [] ? $cards : [[
                'name' => (string)($row['name'] ?? ''),
                'email' => $this->firstEmail((string)($row['email'] ?? '')),
                'phone' => '', 'organization' => '',
            ]];
            foreach ($entries as $card) {
                $mail = strtolower(trim((string)($card['email'] ?? '')));
                if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || isset($items[$mail])) continue;
                $items[$mail] = [
                    'id' => count($items) + 1,
                    'email' => $mail,
                    'name' => (string)($card['name'] ?? ''),
                    'phone' => (string)($card['phone'] ?? ''),
                    'organization' => (string)($card['organization'] ?? ''),
                ];
            }
        }
        return array_values($items);
    }

    /** @return list<array<string,mixed>> */
    public function identities(): array
    {
        if ($this->userIds === []) return [];
        $in = implode(',', array_fill(0, count($this->userIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT identity_id,email,name,organization,`reply-to` AS reply_to,bcc,signature,html_signature "
            . "FROM identities WHERE del=0 AND user_id IN ($in) ORDER BY standard DESC, identity_id ASC"
        );
        $stmt->execute($this->userIds);
        $items = [];
        foreach ($stmt->fetchAll() as $row) {
            $mail = strtolower(trim((string)($row['email'] ?? '')));
            if (!filter_var($mail, FILTER_VALIDATE_EMAIL) || isset($items[$mail])) continue;
            $signature = (string)($row['signature'] ?? '');
            $items[$mail] = [
                'id' => 'roundcube-' . (string)$row['identity_id'],
                'email' => $mail,
                'display_name' => (string)($row['name'] ?? ''),
                'reply_to' => (string)($row['reply_to'] ?? ''),
                'default_bcc' => (string)($row['bcc'] ?? ''),
                'signature_html' => (int)($row['html_signature'] ?? 0) === 1
                    ? $signature
                    : nl2br(htmlspecialchars($signature, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')),
            ];
        }
        return array_values($items);
    }

    private function firstEmail(string $value): string
    {
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $value, $match)) return strtolower($match[0]);
        return trim($value);
    }
}
