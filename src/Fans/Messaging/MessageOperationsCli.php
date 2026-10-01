<?php
declare(strict_types=1);
namespace Faluss\Platform\Fans\Messaging;

/** WP-CLI adapter. No flag, capability or human attestation is written. */
final class MessageOperationsCli
{
    /**
     * Prepare schemas/scheduling, inspect technical status or run a bounded purge.
     *
     * ## OPTIONS
     *
     * <operation>
     * : prepare, status or purge.
     *
     * [--require-healthy]
     * : Fail status unless schemas, hourly event and recent successful purge are verified.
     *
     * @param list<string> $args
     * @param array<string,mixed> $assoc
     */
    public function __invoke(array $args, array $assoc): void
    {
        if (count($args) !== 1 || array_diff(array_keys($assoc), ['require-healthy']) !== []
            || (isset($assoc['require-healthy']) && $args[0] !== 'status')) { \WP_CLI::error('Usage: wp faluss fans-messaging prepare|status|purge [--require-healthy pour status].'); }
        $result = match ($args[0]) {
            'prepare' => MessageOperations::prepare(), 'status' => MessageOperations::status(), 'purge' => MessageOperations::purge(),
            default => MessagePolicy::error('invalid_message_operation', 400),
        };
        if ($result instanceof \WP_Error) { \WP_CLI::error((string) $result->get_error_code()); }
        \WP_CLI::log((string) wp_json_encode($result));
        if (($args[0] === 'purge' && (($result['error'] ?? null) !== '' || ($result['more'] ?? null) !== false))
            || (isset($assoc['require-healthy']) && ($result['retention_healthy'] ?? false) !== true)) {
            \WP_CLI::error('message_retention_not_healthy');
        }
    }
}
