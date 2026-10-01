<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

/** Explicit operator operations, available before admission or policy attestation. */
final class MessageOperations
{
    public static function allowed(): bool
    {
        return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) === SiteRole::Fans
            && current_user_can('manage_options') && current_user_can('moderate_faluss_fans_messages');
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function status(): array|\WP_Error
    {
        if (!self::allowed()) { return MessagePolicy::error('message_operations_forbidden', 403); }
        $event = wp_get_scheduled_event(MessageRetention::HOOK);
        $raw = get_option(MessageRetention::STATUS, '');
        $purge = is_string($raw) ? json_decode($raw, true) : null;
        // Project only technical fields; never expose table contents or client configuration.
        $purge = is_array($purge) ? array_intersect_key($purge, array_flip(['checked_at', 'ordinary_purged', 'reports_purged', 'more', 'error'])) : null;
        $schemas = MessageSchema::ready() && ReportSchema::ready();
        $scheduled = $event !== false && $event->schedule === 'hourly' && $event->interval === 3600 && $event->args === [];
        $fresh = is_array($purge) && is_int($purge['checked_at'] ?? null) && $purge['checked_at'] <= time()
            && $purge['checked_at'] > time() - 7200 && ($purge['error'] ?? null) === '' && ($purge['more'] ?? null) === false;
        return ['version' => (string) constant('FALUSS_PLATFORM_VERSION'), 'schemas_ready' => $schemas,
            'sso_ready' => FansSsoSchema::ready() && FansSsoService::configured(), 'profiles_ready' => CreatorProfileSchema::ready(),
            'hourly_event' => $scheduled, 'next_run_utc' => $event !== false ? gmdate('Y-m-d H:i:s', $event->timestamp) : null,
            'purge' => $purge, 'retention_healthy' => $schemas && $scheduled && $fresh,
            'policy_attested' => defined('FALUSS_FANS_MESSAGING_POLICY_ATTESTED') && constant('FALUSS_FANS_MESSAGING_POLICY_ATTESTED') === true,
            'admission_open' => MessageModule::available(), 'private_access' => MessageModule::privateAccessAvailable()];
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function prepare(): array|\WP_Error
    {
        if (!self::allowed()) { return MessagePolicy::error('message_operations_forbidden', 403); }
        if (!FansSsoSchema::ready() || !FansSsoService::configured() || !CreatorProfileSchema::ready()) {
            return MessagePolicy::error('message_operations_prerequisites');
        }
        if (!MessageSchema::installOrVerify() || !ReportSchema::installOrVerify()) {
            return MessagePolicy::error('message_operations_schema_failed');
        }
        $event = wp_get_scheduled_event(MessageRetention::HOOK);
        if ($event === false && wp_schedule_event(time() + 60, 'hourly', MessageRetention::HOOK, [], true) !== true) {
            return MessagePolicy::error('message_operations_schedule_failed');
        }
        $status = self::status();
        if ($status instanceof \WP_Error || !$status['hourly_event']) {
            return MessagePolicy::error('message_operations_schedule_conflict');
        }
        return $status;
    }

    /** @return array<string,mixed>|\WP_Error */
    public static function purge(): array|\WP_Error
    {
        if (!self::allowed()) { return MessagePolicy::error('message_operations_forbidden', 403); }
        if (!MessageSchema::ready() || !ReportSchema::ready()) { return MessagePolicy::error('message_operations_schema_failed'); }
        return MessageRetention::run();
    }
}
