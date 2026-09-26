<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Images;

/** Images' server contract: freshly rendered JPEG only, never the quarantine file. */
final class ImageDisplayDerivative
{
    public const MAX_EDGE = 1280;
    public const MAX_BYTES = 2097152;

    public static function enabled(): bool
    {
        return defined('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY') && constant('FALUSS_PLATFORM_FANS_IMAGE_DELIVERY') === true
            && ImagesModule::available();
    }

    /** Caller owns the publication/profile locks and transaction. This method locks the image. */
    public static function forPublication(string $id, string $creatorId, int $revision): string|\WP_Error
    {
        if (!self::enabled() || !ImageStorage::validId($id) || $revision < 1) { return ImageService::error('display_not_found', 404); }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT creator_id,state,revision,file_hash FROM `' . ImageSchema::table()
            . '` WHERE image_id=%s FOR UPDATE', $id), 'ARRAY_A');
        if ($wpdb->last_error !== '') { return ImageService::error('display_unavailable'); }
        if (!is_array($row) || $row['creator_id'] !== $creatorId || $row['state'] !== 'approved' || (int) $row['revision'] !== $revision) {
            return ImageService::error('display_not_found', 404);
        }
        $root = ImageStorage::root();
        $source = $root === null ? null : ImageStorage::read($root, $id, $row['file_hash']);
        $derived = $source === null ? null : self::render($source);
        return $derived ?? ImageService::error('display_unavailable');
    }

    /** Bounded fresh raster, opaque white background, no metadata or source-byte fallback. */
    public static function render(string $source): ?string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')
            || strlen($source) > ImageStorage::OUTPUT_LIMIT || !str_starts_with($source, "\x89PNG\r\n\x1a\n")) { return null; }
        $size = @getimagesizefromstring($source);
        if ($size === false || $size['mime'] !== 'image/png' || $size[0] < 1 || $size[1] < 1
            || $size[0] > 4096 || $size[1] > 4096 || $size[0] * $size[1] > 4000000) { return null; }
        $warning = false;
        set_error_handler(static function () use (&$warning): bool { $warning = true; return true; });
        $bufferLevel = ob_get_level();
        try {
            $decoded = imagecreatefromstring($source);
            if ($decoded === false || $warning) { return null; }
            $scale = min(1, self::MAX_EDGE / max($size[0], $size[1]));
            $width = max(1, (int) floor($size[0] * $scale)); $height = max(1, (int) floor($size[1] * $scale));
            $canvas = imagecreatetruecolor($width, $height);
            if ($canvas === false) { return null; }
            $white = imagecolorallocate($canvas, 255, 255, 255);
            if ($white === false) { return null; }
            imagefill($canvas, 0, 0, $white);
            imagecopyresampled($canvas, $decoded, 0, 0, 0, 0, $width, $height, $size[0], $size[1]);
            ob_start();
            $ok = imagejpeg($canvas, null, 82); $jpeg = ob_get_contents();
            /** @var bool $warning Decoder/encoder callbacks can change this flag. */
            return $ok && !$warning && is_string($jpeg) && str_starts_with($jpeg, "\xff\xd8")
                && strlen($jpeg) <= self::MAX_BYTES ? $jpeg : null;
        } finally {
            while (ob_get_level() > $bufferLevel) { ob_end_clean(); }
            restore_error_handler();
        }
    }
}
