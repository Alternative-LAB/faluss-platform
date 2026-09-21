<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use DateTimeImmutable;

/** Trusted owner registry and member-scoped apps.registry resolver. */
final class AppsRegistryService
{
    public const DOCUMENT_TYPE = 'faluss.app-capability-manifest';
    public const CONTRACT_VERSION = '1.0.0';
    public const READ_MODEL_VERSION = '1.0.0';

    /** @var array<string, array<string, mixed>> */
    private static array $sources = [];
    private static bool $sourceConflict = false;

    public static function boot(): void
    {
        add_action('plugins_loaded', [self::class, 'registerFederationValidator'], 20);
        if (did_action('plugins_loaded')) {
            self::registerFederationValidator();
        }
    }

    public static function registerFederationValidator(): bool|\WP_Error
    {
        if (!class_exists('Faluss_Federation_Providers')
            || !class_exists('Faluss_Federation_Crypto')
            || !is_callable(['Faluss_Federation_Providers', 'register_manifest_contract_validator'])
            || !is_callable(['Faluss_Federation_Crypto', 'transport_ready'])
            || call_user_func(['Faluss_Federation_Crypto', 'transport_ready']) !== true
        ) {
            return false;
        }

        $result = call_user_func(
            ['Faluss_Federation_Providers', 'register_manifest_contract_validator'],
            self::DOCUMENT_TYPE,
            self::CONTRACT_VERSION,
            ['Faluss_Apps_Registry_Manifest_Validator', 'validate']
        );

        return is_bool($result) || $result instanceof \WP_Error ? $result : false;
    }

    /** @return array{document_type:string,contract_version:string} */
    public static function manifestContract(): array
    {
        return [
            'document_type' => self::DOCUMENT_TYPE,
            'contract_version' => self::CONTRACT_VERSION,
        ];
    }

    public static function validateManifest(
        mixed $manifest,
        mixed $payloadContract,
        mixed $requestContext
    ): bool {
        return ManifestValidator::validate($manifest, $payloadContract, $requestContext);
    }

    /** Register one closed, server-owned application source. */
    public static function registerSource(mixed $descriptor): bool|\WP_Error
    {
        if (!self::validSourceDescriptor($descriptor)) {
            self::$sourceConflict = true;

            return self::failure();
        }

        $appKey = $descriptor['app_key'];
        if (isset(self::$sources[$appKey])) {
            self::$sourceConflict = true;

            return self::failure();
        }

        self::$sources[$appKey] = $descriptor;

        return true;
    }

    /** @return array<string, mixed>|\WP_Error */
    public static function readForMember(
        mixed $falussId,
        mixed $surface,
        mixed $consumerVersion
    ): array|\WP_Error {
        if (self::$sourceConflict
            || !self::isUuidV4($falussId)
            || !in_array($surface, ['portal', 'master_profile', 'me'], true)
            || $consumerVersion !== self::READ_MODEL_VERSION
            || !self::isHubAuthority()
        ) {
            return self::failure();
        }

        $sources = self::$sources;
        ksort($sources, SORT_STRING);
        $applications = [];
        foreach ($sources as $source) {
            $application = self::resolveApplication($source, $falussId, $surface, $consumerVersion);
            if ($application !== null) {
                $applications[] = $application;
            }
        }

        $document = [
            'contract_version' => self::READ_MODEL_VERSION,
            'namespace' => 'apps.registry',
            'consumer' => [
                'surface' => $surface,
                'consumer_version' => $consumerVersion,
            ],
            'freshness' => [
                'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'max_age_seconds' => 60,
                'stale_behavior' => 'omit',
            ],
            'source' => [
                'type' => 'registry_read_model',
                'engine' => 'faluss-apps-registry',
                'read_model' => 'apps-registry',
                'source_version' => self::READ_MODEL_VERSION,
            ],
            'applications' => $applications,
            'compatibility' => [
                'minimum_consumer_version' => self::READ_MODEL_VERSION,
                'compatible_with' => [self::READ_MODEL_VERSION],
                'deprecated' => false,
                'sunset_at' => null,
            ],
        ];

        return ReadModelValidator::validate($document) ? $document : self::failure();
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private static function resolveApplication(
        array $source,
        string $falussId,
        string $surface,
        string $consumerVersion
    ): ?array {
        $manifest = $source['source_type'] === 'local_owner'
            ? self::localManifest($source)
            : self::federatedManifest($source);
        if ($manifest === null) {
            return null;
        }

        try {
            $relationship = call_user_func($source['relationship_resolver'], $falussId);
        } catch (\Throwable) {
            return null;
        }
        if (is_wp_error($relationship)
            || !in_array($relationship, ['active', 'inactive', 'not_linked'], true)
        ) {
            return null;
        }

        $availability = match ($manifest['product_state']) {
            'active' => 'available',
            'retired' => 'retired',
            default => 'unavailable',
        };
        $manifestCompatible = self::compatible($manifest['compatibility'], $consumerVersion);
        $capabilities = [];
        foreach ($manifest['capabilities'] as $declared) {
            $resolved = self::resolveCapability(
                $source,
                $declared,
                $falussId,
                $surface,
                $consumerVersion,
                $availability,
                $relationship,
                $manifestCompatible
            );
            if ($resolved !== null) {
                $capabilities[] = $resolved;
            }
        }
        usort(
            $capabilities,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['capability_key'],
                (string) $right['capability_key']
            )
        );

        return [
            'app_key' => $source['app_key'],
            'availability' => $availability,
            'member_relationship' => $relationship,
            'capabilities' => $capabilities,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @param array<string, mixed> $declared
     * @return array<string, mixed>|null
     */
    private static function resolveCapability(
        array $source,
        array $declared,
        string $falussId,
        string $surface,
        string $consumerVersion,
        string $availability,
        string $relationship,
        bool $manifestCompatible
    ): ?array {
        if ($source['capability_resolver'] === null) {
            return null;
        }

        try {
            $ownerResult = call_user_func(
                $source['capability_resolver'],
                $falussId,
                $declared['capability_key']
            );
        } catch (\Throwable) {
            return null;
        }
        if (is_wp_error($ownerResult)
            || !self::validOwnerCapabilityResult(
                $ownerResult,
                $declared['capability_key'],
                $source['owner']
            )
        ) {
            return null;
        }

        $surfaceStatus = $manifestCompatible
            && self::compatible($declared['compatibility'], $consumerVersion)
                ? 'compatible'
                : 'incompatible';
        $active = $availability === 'available'
            && $relationship === 'active'
            && $ownerResult['state'] === 'enabled'
            && $ownerResult['specialized_read_model']['status'] === 'available'
            && $surfaceStatus === 'compatible'
            && self::freshNow($ownerResult['specialized_read_model']['freshness']);

        $bindings = [];
        if ($active) {
            foreach ($declared['requested_bindings'] as $binding) {
                if (self::surfaceAcceptsSlot($surface, $binding['slot'])
                    && in_array($binding['interface'], $declared['interfaces'], true)
                ) {
                    $bindings[] = [
                        'slot' => $binding['slot'],
                        'interface' => $binding['interface'],
                        'binding_state' => 'active',
                    ];
                }
            }
        }
        usort(
            $bindings,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['slot'] . "\x1F" . (string) $left['interface'],
                (string) $right['slot'] . "\x1F" . (string) $right['interface']
            )
        );

        $hasDelegatedBinding = false;
        foreach ($bindings as $binding) {
            $hasDelegatedBinding = $hasDelegatedBinding || $binding['interface'] === 'delegated_action';
        }

        $actions = [];
        if ($active && $hasDelegatedBinding) {
            $declaredActions = array_column($declared['symbolic_actions'], null, 'action_key');
            foreach ($ownerResult['allowed_action_keys'] as $actionKey) {
                if (isset($declaredActions[$actionKey])
                    && $declaredActions[$actionKey]['kind'] === 'delegated_action'
                    && in_array('delegated_action', $declared['interfaces'], true)
                ) {
                    $actions[] = [
                        'action_key' => $actionKey,
                        'owner' => $source['owner'],
                        'delegation' => [
                            'type' => 'owner_delegated_action',
                            'target' => $actionKey,
                        ],
                    ];
                }
            }
        }
        usort(
            $actions,
            static fn (array $left, array $right): int => strcmp(
                (string) $left['action_key'],
                (string) $right['action_key']
            )
        );

        return [
            'capability_key' => $declared['capability_key'],
            'owner' => $source['owner'],
            'interfaces' => array_values($declared['interfaces']),
            'state' => $ownerResult['state'],
            'specialized_read_model' => $ownerResult['specialized_read_model'],
            'surface_compatibility' => [
                'status' => $surfaceStatus,
                'consumer_version' => $consumerVersion,
            ],
            'active_bindings' => $bindings,
            'allowed_actions' => $actions,
        ];
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private static function localManifest(array $source): ?array
    {
        try {
            $manifest = call_user_func($source['manifest_resolver']);
        } catch (\Throwable) {
            return null;
        }

        return self::manifestIsValid($manifest, $source) ? $manifest : null;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>|null
     */
    private static function federatedManifest(array $source): ?array
    {
        if (!class_exists('Faluss_Federation_Client')
            || !class_exists('Faluss_Federation_Policy')
            || !is_callable(['Faluss_Federation_Client', 'manifest_read'])
            || !is_callable(['Faluss_Federation_Policy', 'find_outbound_peer'])
        ) {
            return null;
        }

        $peer = call_user_func(
            ['Faluss_Federation_Policy', 'find_outbound_peer'],
            $source['peer_node_id'],
            $source['peer_app_key']
        );
        if (is_wp_error($peer)
            || !is_array($peer)
            || !is_string($peer['key_id'] ?? null)
            || $peer['key_id'] === ''
        ) {
            return null;
        }

        $cacheKey = self::manifestCacheKey($source, $peer);
        $cached = get_transient($cacheKey);
        if (self::cachedManifestIsValid($cached, $source, $peer)) {
            return $cached['manifest'];
        }
        if ($cached !== false) {
            delete_transient($cacheKey);
        }

        $response = call_user_func(
            ['Faluss_Federation_Client', 'manifest_read'],
            $source['peer_node_id'],
            $source['peer_app_key'],
            $source['requested_manifest_version']
        );
        if (!self::federatedResponseIsValid($response, $source, $peer)) {
            return null;
        }

        $expires = self::timestamp($response['expires_at']);
        if ($expires === false) {
            return null;
        }
        $ttl = min(300, $expires - time());
        if ($ttl < 1) {
            return null;
        }

        $value = [
            'cached_at' => time(),
            'contract_version' => $source['contract_version'],
            'document_type' => $source['document_type'],
            'expires_at' => $response['expires_at'],
            'manifest' => $response['payload'],
            'manifest_version' => $source['requested_manifest_version'],
            'responder_key_id' => $response['responder']['key_id'],
        ];
        set_transient($cacheKey, $value, $ttl);

        return $response['payload'];
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $peer
     */
    private static function federatedResponseIsValid(mixed $response, array $source, array $peer): bool
    {
        if (is_wp_error($response)
            || !is_array($response)
            || ($response['status'] ?? null) !== 'success'
            || !array_key_exists('error', $response)
            || $response['error'] !== null
            || !self::exactKeys(
                $response['payload_contract'] ?? null,
                ['document_type', 'contract_version']
            )
            || $source['document_type'] !== $response['payload_contract']['document_type']
            || $source['contract_version'] !== $response['payload_contract']['contract_version']
            || !is_array($response['payload'] ?? null)
            || !is_array($response['responder'] ?? null)
            || $source['peer_node_id'] !== ($response['responder']['node_id'] ?? null)
            || $source['peer_app_key'] !== ($response['responder']['app_key'] ?? null)
            || $peer['key_id'] !== ($response['responder']['key_id'] ?? null)
        ) {
            return false;
        }

        $generated = self::timestamp($response['generated_at'] ?? null);
        $expires = self::timestamp($response['expires_at'] ?? null);

        return $generated !== false
            && $expires !== false
            && $generated <= time() + 60
            && $expires > time()
            && $expires > $generated
            && $expires - $generated <= 300
            && self::manifestIsValid($response['payload'], $source);
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $peer
     */
    private static function cachedManifestIsValid(mixed $cached, array $source, array $peer): bool
    {
        if (!self::exactKeys($cached, [
            'cached_at',
            'contract_version',
            'document_type',
            'expires_at',
            'manifest',
            'manifest_version',
            'responder_key_id',
        ])
            || !is_int($cached['cached_at'])
            || $cached['cached_at'] > time() + 60
            || $cached['cached_at'] + 300 < time()
            || $cached['document_type'] !== $source['document_type']
            || $cached['contract_version'] !== $source['contract_version']
            || $cached['manifest_version'] !== $source['requested_manifest_version']
            || $cached['responder_key_id'] !== $peer['key_id']
        ) {
            return false;
        }

        $expires = self::timestamp($cached['expires_at']);

        return $expires !== false
            && $expires > time()
            && self::manifestIsValid($cached['manifest'], $source);
    }

    /** @param array<string, mixed> $source */
    private static function manifestIsValid(mixed $manifest, array $source): bool
    {
        $context = [
            'operation' => 'manifest.read',
            'parameters' => [
                'app_key' => $source['app_key'],
                'requested_manifest_version' => $source['requested_manifest_version'],
            ],
            'subject_context' => null,
            'sender' => ['node_id' => 'hub-node', 'app_key' => 'faluss-hub'],
            'recipient' => ['node_id' => 'owner-node', 'app_key' => $source['app_key']],
        ];

        return self::validateManifest(
            $manifest,
            [
                'document_type' => $source['document_type'],
                'contract_version' => $source['contract_version'],
            ],
            $context
        );
    }

    /** @phpstan-assert-if-true array<string, mixed> $source */
    private static function validSourceDescriptor(mixed $source): bool
    {
        if (!is_array($source)
            || !in_array($source['source_type'] ?? null, ['local_owner', 'federated_peer'], true)
        ) {
            return false;
        }

        $common = [
            'app_key',
            'source_type',
            'document_type',
            'contract_version',
            'requested_manifest_version',
            'owner',
            'relationship_resolver',
            'capability_resolver',
        ];
        $expected = $source['source_type'] === 'local_owner'
            ? [...$common, 'manifest_resolver']
            : [...$common, 'peer_node_id', 'peer_app_key'];
        if (!self::exactKeys($source, $expected)
            || !self::isAppKey($source['app_key'])
            || $source['owner'] !== $source['app_key']
            || $source['document_type'] !== self::DOCUMENT_TYPE
            || $source['contract_version'] !== self::CONTRACT_VERSION
            || $source['requested_manifest_version'] !== self::CONTRACT_VERSION
            || !is_callable($source['relationship_resolver'])
            || ($source['capability_resolver'] !== null && !is_callable($source['capability_resolver']))
        ) {
            return false;
        }

        if ($source['source_type'] === 'local_owner') {
            return is_callable($source['manifest_resolver']);
        }

        return self::isAppKey($source['peer_node_id'])
            && $source['app_key'] === $source['peer_app_key'];
    }

    private static function validOwnerCapabilityResult(
        mixed $result,
        mixed $capabilityKey,
        mixed $owner
    ): bool {
        if (!self::exactKeys($result, [
            'capability_key',
            'state',
            'specialized_read_model',
            'allowed_action_keys',
        ])
            || $capabilityKey !== $result['capability_key']
            || !in_array(
                $result['state'],
                ['enabled', 'disabled', 'temporarily_unavailable', 'not_supported'],
                true
            )
            || !self::isList($result['allowed_action_keys'])
            || count($result['allowed_action_keys'])
                !== count(array_unique($result['allowed_action_keys'], SORT_STRING))
        ) {
            return false;
        }

        foreach ($result['allowed_action_keys'] as $actionKey) {
            if (!self::isNamespacedKey($actionKey, $owner)) {
                return false;
            }
        }

        $model = $result['specialized_read_model'];

        return self::exactKeys($model, ['status', 'source', 'freshness'])
            && in_array($model['status'], ['available', 'unavailable', 'expired', 'not_supported'], true)
            && self::exactKeys($model['source'], ['engine', 'read_model', 'source_version'])
            && $owner === $model['source']['engine']
            && is_string($model['source']['read_model'])
            && self::isSemver($model['source']['source_version'])
            && self::freshnessShape($model['freshness'])
            && ($result['state'] === 'enabled' || $result['allowed_action_keys'] === []);
    }

    private static function compatible(mixed $compatibility, string $version): bool
    {
        if (!is_array($compatibility)
            || !is_string($compatibility['minimum_consumer_version'] ?? null)
            || !is_array($compatibility['compatible_with'] ?? null)
            || version_compare($version, $compatibility['minimum_consumer_version'], '<')
            || !in_array($version, $compatibility['compatible_with'], true)
        ) {
            return false;
        }

        $sunset = $compatibility['sunset_at'] ?? null;
        $sunsetTimestamp = self::timestamp($sunset);

        return $sunset === null || ($sunsetTimestamp !== false && $sunsetTimestamp > time());
    }

    private static function surfaceAcceptsSlot(string $surface, mixed $slot): bool
    {
        $slots = [
            'portal' => ['portal.apps.card_action', 'portal.analytics.dataset'],
            'master_profile' => ['master_profile.module', 'master_profile.footer_action'],
            'me' => ['me.studio.tab', 'me.studio.block_source', 'me.public.tab', 'me.public.block'],
        ];

        return isset($slots[$surface]) && in_array($slot, $slots[$surface], true);
    }

    private static function freshnessShape(mixed $freshness): bool
    {
        return self::exactKeys($freshness, ['generated_at', 'max_age_seconds', 'stale_behavior'])
            && self::timestamp($freshness['generated_at']) !== false
            && is_int($freshness['max_age_seconds'])
            && $freshness['max_age_seconds'] >= 1
            && $freshness['max_age_seconds'] <= 86400
            && in_array($freshness['stale_behavior'], ['omit', 'refresh_from_owner'], true);
    }

    private static function freshNow(mixed $freshness): bool
    {
        if (!self::freshnessShape($freshness)) {
            return false;
        }

        $generated = self::timestamp($freshness['generated_at']);

        return $generated !== false
            && $generated <= time() + 60
            && $generated + $freshness['max_age_seconds'] >= time();
    }

    /** @param array<string, mixed> $source
     *  @param array<string, mixed> $peer
     */
    private static function manifestCacheKey(array $source, array $peer): string
    {
        $peerBinding = [
            $peer['peer_node_id'] ?? '',
            $peer['peer_app_key'] ?? '',
            $peer['key_id'] ?? '',
            $peer['canonical_origin'] ?? '',
            $peer['key_state'] ?? '',
            $peer['valid_from'] ?? '',
            $peer['valid_until'] ?? '',
        ];

        return '_faluss_apps_registry_manifest_v1_' . hash(
            'sha256',
            $source['peer_node_id'] . "\x1F"
                . $source['peer_app_key'] . "\x1F"
                . $source['requested_manifest_version'] . "\x1F"
                . $source['contract_version'] . "\x1F"
                . implode("\x1F", $peerBinding)
        );
    }

    private static function isHubAuthority(): bool
    {
        if (!class_exists('Faluss_Federation_Crypto')
            || !is_callable(['Faluss_Federation_Crypto', 'local_identity'])
        ) {
            return false;
        }

        $identity = call_user_func(['Faluss_Federation_Crypto', 'local_identity']);

        return !is_wp_error($identity)
            && is_array($identity)
            && ($identity['node_id'] ?? null) === 'hub-node'
            && ($identity['app_key'] ?? null) === 'faluss-hub'
            && ($identity['origin'] ?? null) === 'https://faluss.com';
    }

    /**
     * @param list<string> $keys
     * @phpstan-assert-if-true array<string, mixed> $value
     */
    private static function exactKeys(mixed $value, array $keys): bool
    {
        if (!is_array($value)) {
            return false;
        }

        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($keys, SORT_STRING);

        return $actual === $keys;
    }

    /** @phpstan-assert-if-true list<mixed> $value */
    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function isAppKey(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) === 1;
    }

    private static function isSemver(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value) === 1;
    }

    private static function isNamespacedKey(mixed $value, mixed $namespace): bool
    {
        return is_string($value)
            && is_string($namespace)
            && preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9_.-]{1,127})+$/D', $value) === 1
            && str_starts_with($value, $namespace . '.');
    }

    private static function isUuidV4(mixed $value): bool
    {
        return is_string($value)
            && preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-4[a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',
                $value
            ) === 1;
    }

    private static function timestamp(mixed $value): int|false
    {
        if (!is_string($value)
            || preg_match(
                '/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D',
                $value
            ) !== 1
        ) {
            return false;
        }

        $normalized = str_ends_with($value, 'Z')
            ? substr($value, 0, -1) . '+00:00'
            : $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $normalized === $date->format('Y-m-d\TH:i:sP')
                ? $date->getTimestamp()
                : false;
    }

    private static function failure(): \WP_Error
    {
        return new \WP_Error('faluss_apps_registry_unavailable');
    }
}
