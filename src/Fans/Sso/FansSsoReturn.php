<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Sso;

/** A navigation hint, never an identity or an authorization grant. */
final class FansSsoReturn
{
    public const COOKIE = 'faluss_fans_sso_return';

    public static function path(mixed $candidate): ?string
    {
        if (!is_string($candidate) || strlen($candidate) > 512) {
            return null;
        }
        $entry = parse_url(home_url('/app'), PHP_URL_PATH);
        if (is_string($entry) && ($candidate === $entry || $candidate === $entry . '/')) { return $entry; }
        $prefix = parse_url(home_url('/app/'), PHP_URL_PATH);
        $legacy = parse_url(home_url('/faluss-fans/'), PHP_URL_PATH);
        if (is_string($legacy) && str_starts_with($candidate, $legacy)) { $prefix = $legacy; }
        if (!is_string($prefix) || !str_starts_with($candidate, $prefix)) {
            return null;
        }
        $relative = substr($candidate, strlen($prefix));
        if (preg_match('~^(fan|creator)/messages/?\?(creator|thread)=([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$~D', $relative, $selection) === 1) {
            return $prefix.$selection[1].'/messages?'.$selection[2].'='.$selection[3];
        }
        if (preg_match('~^creator/creer/?\?publication=([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})$~D', $relative, $selection) === 1) {
            return $prefix . 'creator/creer?publication=' . $selection[1];
        }
        $fan = 'fan/(?:accueil|explorer|hof(?:/session)?|classements|classement-fans|messages|espace)';
        $creator = 'creator/(?:accueil|explorer|hof(?:/session)?|classements|messages|progression|creer|boutique|mon-profil)';
        $public = 'creators/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

        return preg_match('~^(?:' . $fan . '|' . $creator . '|' . $public . ')/?$~D', $relative) === 1
            ? rtrim($candidate, '/') : null;
    }

    public static function seal(mixed $candidate, string $state): ?string
    {
        $path = self::path($candidate);
        if ($path === null || preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) !== 1) {
            return null;
        }
        $payload = rtrim(strtr(base64_encode($path), '+/', '-_'), '=');

        return $payload . '.' . self::signature($payload, $state);
    }

    public static function open(mixed $cookie, string $state): ?string
    {
        if (!is_string($cookie) || strlen($cookie) > 800
            || preg_match('/^[A-Za-z0-9_-]{43}$/D', $state) !== 1
            || preg_match('/^([A-Za-z0-9_-]+)\.([a-f0-9]{64})$/D', $cookie, $parts) !== 1
            || !hash_equals(self::signature($parts[1], $state), $parts[2])
        ) {
            return null;
        }
        $path = base64_decode(strtr($parts[1], '-_', '+/'), true);

        return self::path($path);
    }

    private static function signature(string $payload, string $state): string
    {
        return hash_hmac('sha256', $state . '.' . $payload, wp_salt('faluss_fans_sso_return'));
    }
}
