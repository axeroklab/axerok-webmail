<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class InlineImageResolver
{
    /** @param array<int,array<string,mixed>> $parts */
    public static function resolve(string $html, array $parts, callable $urlForPart): string
    {
        $urls = [];
        foreach ($parts as $part) {
            $section = (string)($part['section'] ?? '');
            if (!preg_match('/^\d+(?:\.\d+)*$/', $section)) {
                continue;
            }
            $cid = self::normalizeCid((string)($part['content_id'] ?? ''));
            if ($cid === '') {
                continue;
            }
            $urls[$cid] = (string)$urlForPart($part);
        }

        if ($urls === [] || stripos($html, 'cid:') === false) {
            return $html;
        }

        return preg_replace_callback(
            '/\bcid:([^\s"\'<>\)]+)/i',
            static function (array $match) use ($urls): string {
                $cid = self::normalizeCid($match[1]);
                if ($cid === '' || !isset($urls[$cid])) {
                    return $match[0];
                }
                return htmlspecialchars($urls[$cid], ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
            },
            $html
        ) ?? $html;
    }

    private static function normalizeCid(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decoded = rawurldecode($value);
            if ($decoded === $value) {
                break;
            }
            $value = $decoded;
        }
        $value = trim($value, " \t\r\n\0\x0B<>\"'");
        return strtolower($value);
    }
}
