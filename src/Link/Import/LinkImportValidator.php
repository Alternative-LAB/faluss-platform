<?php

declare(strict_types=1);

namespace Faluss\Platform\Link\Import;

/** Local syntax validation and deliberately conservative duplicate matching. */
final class LinkImportValidator
{
    /**
     * @param list<array<string,mixed>> $rows
     * @param list<string> $existingUrls
     * @return list<array{label:string,url:string,order:int,status:string,message:string,selected:bool}>
     */
    public function review(array $rows, array $existingUrls = []): array
    {
        if (count($rows) > LinkImportParser::MAX_ROWS) {
            throw new \InvalidArgumentException('too_many_rows');
        }
        $seen = [];
        foreach ($existingUrls as $existing) {
            $seen[$this->key($existing)] = true;
        }
        $reviewed = [];
        foreach ($rows as $index => $row) {
            $url = $this->normalizeUrl(is_string($row['url'] ?? null) ? $row['url'] : '');
            $label = is_string($row['label'] ?? null) ? strip_tags($row['label']) : '';
            // Match the existing Studio plain-text storage boundary before preview.
            do {
                $previousLabel = $label;
                $label = (string) preg_replace('/%[a-f0-9]{2}/i', '', $label);
            } while ($previousLabel !== $label);
            $label = trim((string) preg_replace('/\s+/u', ' ', $label));
            if ($label === '') {
                $host = parse_url($url, PHP_URL_HOST);
                $label = is_string($host) ? $host : '';
            }
            $message = $this->validationMessage($url, $label);
            $status = $message === '' ? 'ready' : 'invalid';
            $key = $this->key($url);
            if ($status === 'ready') {
                if (isset($seen[$key])) {
                    $status = 'duplicate';
                    $message = 'Ce lien existe déjà ou apparaît plusieurs fois dans cet import.';
                }
                $seen[$key] = true;
            }
            $reviewed[] = [
                'label' => $label,
                'url' => $url,
                'order' => is_int($row['order'] ?? null) ? $row['order'] : $index,
                'status' => $status,
                'message' => $message,
                'selected' => $status === 'ready',
            ];
        }
        return $reviewed;
    }

    public function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (preg_match('~^http://~i', $url) === 1) {
            $authority = (string) preg_replace('~^http://([^/?#]+):80(?=[/?#]|$)~i', 'http://$1', $url);
            $url = 'https://' . substr($authority, 7);
        } elseif ($url !== '' && preg_match('~^[a-z][a-z0-9+.-]*:~i', $url) !== 1) {
            $url = 'https://' . $url;
        }
        // WordPress escapes brackets outside the authority when storing URLs.
        if (preg_match('~^(https?://[^/?#]*)(.*)$~is', $url, $parts) === 1) {
            $url = $parts[1] . str_replace(['[', ']'], ['%5B', '%5D'], $parts[2]);
        }
        return $url;
    }

    public function key(string $url): string
    {
        $url = $this->normalizeUrl($url);
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            return $url;
        }
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host = strtolower($parts['host']);
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':' . $parts['port'] : '';
        $path = $this->decodeUnreserved($parts['path'] ?? '/');
        $path = $path === '' ? '/' : $path;
        $query = [];
        foreach (explode('&', $parts['query'] ?? '') as $parameter) {
            if ($parameter === '') {
                continue;
            }
            $name = strtolower(rawurldecode(explode('=', $parameter, 2)[0]));
            if (str_starts_with($name, 'utm_') || in_array($name, ['fbclid', 'gclid'], true)) {
                continue;
            }
            $query[] = $this->decodeUnreserved($parameter);
        }
        $fragment = isset($parts['fragment']) && $parts['fragment'] !== '' ? '#' . $this->decodeUnreserved($parts['fragment']) : '';
        return $scheme . '://' . $host . $port . $path . ($query === [] ? '' : '?' . implode('&', $query)) . $fragment;
    }

    private function decodeUnreserved(string $value): string
    {
        return (string) preg_replace_callback('/%[0-9a-f]{2}/i', static function (array $match): string {
            $character = chr((int) hexdec(substr($match[0], 1)));
            return preg_match('/[a-z0-9._~-]/i', $character) === 1 ? $character : strtoupper($match[0]);
        }, $value);
    }

    private function publicIp(string $ip): bool
    {
        $packed = inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        if (strlen($packed) === 16) {
            // Admit globally routed unicast only, excluding documentation space.
            return (ord($packed[0]) & 0xe0) === 0x20
                && substr($packed, 0, 4) !== "\x20\x01\x0d\xb8";
        }
        $address = ip2long($ip);
        if ($address === false) {
            return false;
        }
        foreach ([['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24], ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24], ['224.0.0.0', 4]] as [$network, $bits]) {
            $mask = (0xffffffff << (32 - $bits)) & 0xffffffff;
            if (($address & $mask) === (ip2long($network) & $mask)) {
                return false;
            }
        }
        return true;
    }

    private function validationMessage(string $url, string $label): string
    {
        if (str_contains($label, '\\') || str_contains($label, '<')) {
            return 'Remplace les caractères « \\ » et « < » dans le titre pour conserver exactement le texte à l’enregistrement.';
        }
        if ($label === '' || preg_match('//u', $label) !== 1 || preg_match_all('/./us', $label) > 80 || preg_match('/[\x00-\x1f\x7f]/', $label) === 1) {
            return 'Le titre doit contenir entre 1 et 80 caractères, sans caractères de contrôle.';
        }
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f\\\\<>"`{}|^]/', $url) === 1 || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) === 1) {
            return 'L’URL est vide, trop longue ou contient des caractères interdits.';
        }
        $parts = parse_url($url);
        if ($parts === false || strtolower($parts['scheme'] ?? '') !== 'https' || !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            return 'Saisissez une URL HTTPS publique sans identifiants.';
        }
        $host = strtolower($parts['host']);
        $ip = trim($host, '[]');
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false || !$this->publicIp($ip)) {
                return 'Les adresses locales, privées ou réservées ne sont pas acceptées.';
            }
        } elseif (strlen($host) > 253 || preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host) !== 1 || preg_match('/\.(?:localhost|local|internal|test|invalid|example)$/D', $host) === 1) {
            return 'Le nom de domaine doit être une adresse publique valide.';
        }
        if (preg_match('/%(?![0-9a-f]{2})/i', $url) === 1) {
            return 'L’URL contient un encodage invalide.';
        }
        return '';
    }
}
