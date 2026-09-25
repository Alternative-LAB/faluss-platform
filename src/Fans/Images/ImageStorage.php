<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

/** Local POSIX storage only; hosting configuration remains a required trust boundary. */
final class ImageStorage
{
    public const INPUT_LIMIT = 2097152;
    public const OUTPUT_LIMIT = 8388608;

    public static function root(): ?string
    {
        if (!defined('FALUSS_FANS_IMAGE_PRIVATE_ROOT') || !defined('FALUSS_FANS_IMAGE_STORAGE_ATTESTED')
            || constant('FALUSS_FANS_IMAGE_STORAGE_ATTESTED') !== true || !function_exists('posix_geteuid')) { return null; }
        $configured = constant('FALUSS_FANS_IMAGE_PRIVATE_ROOT');
        if (!is_string($configured) || !str_starts_with($configured, '/')) { return null; }
        clearstatcache();
        $root = realpath($configured);
        if ($root === false || $root !== rtrim($configured, '/') || $root === '/' || !is_dir($root)
            || is_link($configured) || (fileperms($root) & 0777) !== 0700
            || fileowner($root) !== posix_geteuid() || !is_writable($root)) { return null; }
        foreach ([defined('ABSPATH') ? constant('ABSPATH') : '/', defined('WP_CONTENT_DIR') ? constant('WP_CONTENT_DIR') : '/', $_SERVER['DOCUMENT_ROOT'] ?? ''] as $web) {
            if (!is_string($web) || $web === '') { continue; }
            $web = realpath($web);
            if ($web === false || $root === $web || str_starts_with($root . '/', rtrim($web, '/') . '/')
                || str_starts_with($web . '/', $root . '/')) { return null; }
        }
        // Ancestors must not be replaceable by another unprivileged user (sticky /tmp allowed).
        for ($dir = dirname($root); $dir !== '/'; $dir = dirname($dir)) {
            $mode = fileperms($dir);
            if ($mode === false || (($mode & 0022) !== 0 && ($mode & 01000) === 0)) { return null; }
        }
        return $root;
    }

    public static function validId(string $id): bool
    { return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1; }

    /** Inspect actual bytes; decode and re-encode into a fresh PNG without source metadata. */
    public static function normalize(string $data): ?string
    {
        if (strlen($data) === 0 || strlen($data) > self::INPUT_LIMIT || !function_exists('imagecreatefromstring') || !class_exists(\finfo::class)) { return null; }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($data);
        $size = @getimagesizefromstring($data);
        if (!in_array($mime, ['image/png', 'image/jpeg'], true) || $size === false || $size['mime'] !== $mime
            || $size[0] < 1 || $size[1] < 1 || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 4000000) { return null; }
        // Decoder warnings (including truncation) are rejection, never a best-effort repair.
        $warning = false;
        set_error_handler(static function () use (&$warning): bool { $warning = true; return true; });
        try { $image = imagecreatefromstring($data); } finally { restore_error_handler(); }
        if ($warning || $image === false) { return null; }
        ob_start();
        try { $ok = imagepng($image); $png = ob_get_contents(); } finally { ob_end_clean(); }
        return $ok && is_string($png) && strlen($png) <= self::OUTPUT_LIMIT ? $png : null;
    }

    /** @return list<string>|null Every file, including orphans, counts against physical capacity. */
    public static function files(string $root): ?array
    {
        $names = @scandir($root);
        if ($names === false) { return null; }
        $files = [];
        foreach ($names as $name) {
            if ($name === '.' || $name === '..') { continue; }
            if (!str_ends_with($name, '.bin') || !self::validId(substr($name, 0, -4)) || !self::safeFile($root . '/' . $name)) { return null; }
            $files[] = $name;
        }
        return $files;
    }

    private static function safeFile(string $path): bool
    {
        clearstatcache(true, $path);
        $s = @lstat($path);
        return is_array($s) && ($s['mode'] & 0170000) === 0100000 && ($s['mode'] & 0777) === 0600
            && $s['uid'] === posix_geteuid() && $s['nlink'] === 1;
    }

    public static function put(string $root, string $id, string $bytes): bool
    {
        if (!self::validId($id)) { return false; }
        $path = $root . '/' . $id . '.bin';
        $old = umask(0077);
        try { $handle = @fopen($path, 'xb'); } finally { umask($old); }
        if ($handle === false) { return false; }
        try { $ok = fwrite($handle, $bytes) === strlen($bytes) && fflush($handle); } finally { fclose($handle); }
        if (!$ok || !self::safeFile($path)) { @unlink($path); return false; }
        return true;
    }

    public static function read(string $root, string $id, string $hash): ?string
    {
        if (!self::validId($id)) { return null; }
        $path = $root . '/' . $id . '.bin';
        if (!self::safeFile($path)) { return null; }
        $bytes = @file_get_contents($path, false, null, 0, self::OUTPUT_LIMIT + 1);
        return is_string($bytes) && strlen($bytes) <= self::OUTPUT_LIMIT && hash_equals($hash, hash('sha256', $bytes)) ? $bytes : null;
    }

    public static function remove(string $root, string $id): bool
    {
        if (!self::validId($id)) { return false; }
        $path = $root . '/' . $id . '.bin';
        clearstatcache(true, $path);
        if (@lstat($path) === false) { return true; }
        return self::safeFile($path) && @unlink($path);
    }
}
