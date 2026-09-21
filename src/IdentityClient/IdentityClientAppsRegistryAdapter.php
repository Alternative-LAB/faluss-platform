<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

/** AP-02A relation adapter for the federated Faluss Me application source. */
final class IdentityClientAppsRegistryAdapter
{
    /** @var array<string, array{contract_version:string,publication_status:string,canonical_url:string}|null|\WP_Error> */
    private static array $relationshipCache = [];

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'registerSource'], 40);
        if (did_action('plugins_loaded')) {
            self::registerSource();
        }
    }

    public static function registerSource(): bool|\WP_Error
    {
        if (!class_exists('Faluss_Apps_Registry')
            || !is_callable(['Faluss_Apps_Registry', 'register_source'])
        ) {
            return false;
        }

        $result = \Faluss_Apps_Registry::register_source([
            'app_key' => 'faluss-me',
            'source_type' => 'federated_peer',
            'document_type' => 'faluss.app-capability-manifest',
            'contract_version' => '1.0.0',
            'requested_manifest_version' => '1.0.0',
            'owner' => 'faluss-me',
            'relationship_resolver' => [self::class, 'relationship'],
            'capability_resolver' => null,
            'peer_node_id' => 'me-node',
            'peer_app_key' => 'faluss-me',
        ]);

        return is_bool($result) || $result instanceof \WP_Error ? $result : false;
    }

    public static function relationship(mixed $falussId): string|\WP_Error
    {
        if (!self::isUuidV4($falussId) || !self::authorityAvailable()) {
            return new \WP_Error('faluss_apps_registry_unavailable');
        }
        if (!array_key_exists($falussId, self::$relationshipCache)) {
            try {
                global $wpdb;
                $projection = IdentityClientService::memberAppProjection($falussId, 'me');
                self::$relationshipCache[$falussId] = !empty($wpdb->last_error)
                    ? new \WP_Error('faluss_apps_registry_unavailable')
                    : $projection;
            } catch (\Throwable) {
                self::$relationshipCache[$falussId] = new \WP_Error('faluss_apps_registry_unavailable');
            }
        }

        $projection = self::$relationshipCache[$falussId];
        if (is_wp_error($projection)) {
            return $projection;
        }
        if ($projection === null) {
            return 'not_linked';
        }

        return self::validProjection($projection)
            ? 'active'
            : new \WP_Error('faluss_apps_registry_unavailable');
    }

    public static function canonicalDestination(mixed $falussId): ?string
    {
        if (self::relationship($falussId) !== 'active' || !is_string($falussId)) {
            return null;
        }

        $projection = self::$relationshipCache[$falussId] ?? null;

        return is_array($projection) ? $projection['canonical_url'] : null;
    }

    /** @param array<string, mixed> $projection */
    private static function validProjection(array $projection): bool
    {
        if (array_keys($projection) !== ['contract_version', 'publication_status', 'canonical_url']
            || $projection['contract_version'] !== '1'
            || $projection['publication_status'] !== 'published'
            || !is_string($projection['canonical_url'])
            || !in_array($projection['canonical_url'], [
                'https://faluss.me/mon-faluss',
                'https://www.faluss.me/mon-faluss',
            ], true)
        ) {
            return false;
        }

        $parts = wp_parse_url($projection['canonical_url']);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && in_array(strtolower((string) ($parts['host'] ?? '')), ['faluss.me', 'www.faluss.me'], true)
            && ($parts['path'] ?? null) === '/mon-faluss'
            && !isset($parts['query'], $parts['fragment'], $parts['user'], $parts['pass'], $parts['port']);
    }

    private static function authorityAvailable(): bool
    {
        global $wpdb;

        if (!is_object($wpdb)
            || !is_callable([$wpdb, 'prepare'])
            || !is_callable([$wpdb, 'get_var'])
        ) {
            return false;
        }
        $tables = IdentityClientSchema::tables();

        return is_string($tables['links'] ?? null) && $tables['links'] !== '';
    }

    private static function isUuidV4(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D', $value) === 1;
    }
}
