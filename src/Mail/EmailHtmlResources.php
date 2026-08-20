<?php
declare(strict_types=1);

namespace AxerokMail\Mail;

final class EmailHtmlResources
{
    public static function normalize(string $html): string
    {
        $base = self::baseUrl($html);
        if ($base === null) {
            return $html;
        }

        $html = preg_replace_callback(
            '/\b(src|background)\s*=\s*(?:(["\'])(.*?)\2|([^\s>]+))/is',
            static function (array $match) use ($base): string {
                $attribute = $match[1];
                $quote = $match[2] ?? '';
                $raw = ($match[3] ?? '') !== '' ? $match[3] : ($match[4] ?? '');
                $url = self::absoluteUrl($raw, $base);
                if ($url === null) {
                    return $match[0];
                }
                $escaped = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $attribute . '=' . ($quote !== '' ? $quote . $escaped . $quote : '"' . $escaped . '"');
            },
            $html
        ) ?? $html;

        return preg_replace_callback(
            '/\bsrcset\s*=\s*(["\'])(.*?)\1/is',
            static function (array $match) use ($base): string {
                $value = $match[2];
                if (preg_match('/^\s*data:/i', $value)) {
                    return $match[0];
                }
                $candidates = preg_split('/\s*,\s*/', $value) ?: [];
                foreach ($candidates as &$candidate) {
                    if (!preg_match('/^\s*(\S+)(\s+.*)?$/s', $candidate, $parts)) {
                        continue;
                    }
                    $url = self::absoluteUrl($parts[1], $base);
                    if ($url !== null) {
                        $candidate = $url . ($parts[2] ?? '');
                    }
                }
                unset($candidate);
                $escaped = htmlspecialchars(implode(', ', $candidates), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return 'srcset=' . $match[1] . $escaped . $match[1];
            },
            $html
        ) ?? $html;
    }

    public static function hasRemoteImages(string $html): bool
    {
        $html = self::normalize($html);
        return preg_match(
            '/(?:\b(?:src|background)\s*=\s*(?:["\']\s*)?(?:https?:)?\/\/|\bsrcset\s*=\s*(?:["\'][^"\']*|[^\s>]*)(?:https?:)?\/\/|\burl\s*\(\s*(?:["\']\s*)?(?:https?:)?\/\/)/i',
            $html
        ) === 1;
    }

    private static function baseUrl(string $html): ?string
    {
        if (!preg_match('/<base\b[^>]*\bhref\s*=\s*(?:(["\'])(.*?)\1|([^\s>]+))/is', $html, $match)) {
            return null;
        }
        $raw = ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '');
        $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $parts = parse_url($url);
        if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true) || empty($parts['host'])) {
            return null;
        }
        return $url;
    }

    private static function absoluteUrl(string $raw, string $base): ?string
    {
        $url = trim(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === '' || str_starts_with($url, '#') || preg_match('/^(?:cid|data|blob):/i', $url)) {
            return null;
        }
        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $baseParts = parse_url($base);
        if (!is_array($baseParts) || empty($baseParts['scheme']) || empty($baseParts['host'])) {
            return null;
        }
        $scheme = strtolower((string)$baseParts['scheme']);
        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $url)) {
            return null;
        }

        $origin = $scheme . '://' . $baseParts['host'];
        if (isset($baseParts['port'])) {
            $origin .= ':' . (int)$baseParts['port'];
        }

        $fragment = '';
        if (($position = strpos($url, '#')) !== false) {
            $fragment = substr($url, $position);
            $url = substr($url, 0, $position);
        }
        $query = '';
        if (($position = strpos($url, '?')) !== false) {
            $query = substr($url, $position);
            $url = substr($url, 0, $position);
        }

        $basePath = (string)($baseParts['path'] ?? '/');
        if ($url === '') {
            $path = $basePath === '' ? '/' : $basePath;
        } elseif (str_starts_with($url, '/')) {
            $path = $url;
        } else {
            $directory = str_ends_with($basePath, '/') ? $basePath : dirname($basePath) . '/';
            $path = $directory . $url;
        }

        return $origin . self::normalizePath($path) . $query . $fragment;
    }

    private static function normalizePath(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        return '/' . implode('/', $segments);
    }
}
