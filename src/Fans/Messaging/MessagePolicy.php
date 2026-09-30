<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

final class MessagePolicy
{
    public static function uuid(mixed $id): bool
    { return is_string($id) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) === 1; }
    public static function text(mixed $text, int $limit = 2000): bool
    {
        return is_string($text) && strlen($text) <= $limit * 4 && trim($text) !== '' && preg_match('//u', $text) === 1
            && preg_match_all('/./us', $text) <= $limit && preg_match('/[<>\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', $text) === 0;
    }
    /** No Fans transport currently attests Max or creator subscriptions. Never trust client fields. */
    public static function directOpeningAvailable(): bool { return false; }
    public static function error(string $code, int $status = 503): \WP_Error
    { return new \WP_Error($code, 'Messagerie indisponible.', ['status' => $status]); }
}
