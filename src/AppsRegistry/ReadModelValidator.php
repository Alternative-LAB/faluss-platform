<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use DateTimeImmutable;

/** Closed validator for the member-scoped apps.registry v1 read-model. */
final class ReadModelValidator
{
    private const ROOT_KEYS = [
        'contract_version',
        'namespace',
        'consumer',
        'freshness',
        'source',
        'applications',
        'compatibility',
    ];
    private const INTERFACES = [
        'module_read_model',
        'delegated_action',
        'event_source',
        'content_reference_source',
    ];
    private const SLOTS = [
        'portal.apps.card_action',
        'portal.analytics.dataset',
        'master_profile.module',
        'master_profile.footer_action',
        'me.studio.tab',
        'me.studio.block_source',
        'me.public.tab',
        'me.public.block',
        'analytics.events',
        'quests.events',
        'progression.events',
    ];
    private const EVENT_SLOTS = ['analytics.events', 'quests.events', 'progression.events'];

    public static function validate(mixed $document): bool
    {
        if (!is_array($document)
            || !self::exactKeys($document, self::ROOT_KEYS)
            || self::containsSensitiveContent($document)
            || ($document['contract_version'] ?? null) !== AppsRegistryService::READ_MODEL_VERSION
            || ($document['namespace'] ?? null) !== 'apps.registry'
            || !self::validConsumer($document['consumer'] ?? null)
            || !self::validFreshness($document['freshness'] ?? null, true, true)
            || !self::validSource($document['source'] ?? null)
            || !is_array($document['consumer'])
            || !self::validCompatibility(
                $document['compatibility'] ?? null,
                $document['consumer']['consumer_version'] ?? null
            )
            || !self::isList($document['applications'] ?? null)
            || count($document['applications']) > 128
        ) {
            return false;
        }

        $applications = [];
        foreach ($document['applications'] as $application) {
            if (!is_array($application)
                || !self::validApplication(
                    $application,
                    $document['consumer']['surface'],
                    $document['consumer']['consumer_version']
                )
                || isset($applications[$application['app_key']])
            ) {
                return false;
            }
            $applications[$application['app_key']] = true;
        }

        return true;
    }

    private static function validConsumer(mixed $consumer): bool
    {
        return self::exactKeys($consumer, ['surface', 'consumer_version'])
            && is_array($consumer)
            && in_array($consumer['surface'] ?? null, ['portal', 'master_profile', 'me'], true)
            && self::isSemver($consumer['consumer_version'] ?? null);
    }

    private static function validSource(mixed $source): bool
    {
        return self::exactKeys($source, ['type', 'engine', 'read_model', 'source_version'])
            && is_array($source)
            && ($source['type'] ?? null) === 'registry_read_model'
            && ($source['engine'] ?? null) === 'faluss-apps-registry'
            && ($source['read_model'] ?? null) === 'apps-registry'
            && ($source['source_version'] ?? null) === AppsRegistryService::READ_MODEL_VERSION;
    }

    /** @param array<string, mixed> $application */
    private static function validApplication(array $application, string $surface, string $consumerVersion): bool
    {
        if (!self::exactKeys($application, ['app_key', 'availability', 'member_relationship', 'capabilities'])
            || !self::isAppKey($application['app_key'] ?? null)
            || !in_array($application['availability'] ?? null, ['available', 'unavailable', 'retired'], true)
            || !in_array($application['member_relationship'] ?? null, ['active', 'inactive', 'not_linked'], true)
            || !self::isList($application['capabilities'] ?? null)
            || count($application['capabilities']) > 64
        ) {
            return false;
        }
        $capabilities = [];
        foreach ($application['capabilities'] as $capability) {
            if (!is_array($capability)
                || !self::validCapability($capability, $application, $surface, $consumerVersion)
                || isset($capabilities[$capability['capability_key']])
            ) {
                return false;
            }
            $capabilities[$capability['capability_key']] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $capability
     *  @param array<string, mixed> $application
     */
    private static function validCapability(
        array $capability,
        array $application,
        string $surface,
        string $consumerVersion
    ): bool {
        if (!self::exactKeys($capability, [
            'capability_key',
            'owner',
            'interfaces',
            'state',
            'specialized_read_model',
            'surface_compatibility',
            'active_bindings',
            'allowed_actions',
        ]) || !self::isNamespacedKey($capability['capability_key'] ?? null, $application['app_key'])
            || ($capability['owner'] ?? null) !== $application['app_key']
            || !self::validInterfaces($capability['interfaces'] ?? null)
            || !in_array($capability['state'] ?? null, ['enabled', 'disabled', 'temporarily_unavailable', 'not_supported'], true)
            || !self::validSpecializedReadModel($capability['specialized_read_model'] ?? null, $capability['owner'])
            || !self::validSurfaceCompatibility($capability['surface_compatibility'] ?? null, $consumerVersion)
            || !self::validBindings($capability['active_bindings'] ?? null, $capability['interfaces'], $surface)
            || !self::validActions(
                $capability['allowed_actions'] ?? null,
                $capability['owner'],
                $capability['interfaces'],
                $capability['active_bindings']
            )
        ) {
            return false;
        }

        $outputsAllowed = $application['availability'] === 'available'
            && $application['member_relationship'] === 'active'
            && $capability['state'] === 'enabled'
            && $capability['specialized_read_model']['status'] === 'available'
            && $capability['surface_compatibility']['status'] === 'compatible';

        return $outputsAllowed || ($capability['active_bindings'] === [] && $capability['allowed_actions'] === []);
    }

    private static function validInterfaces(mixed $interfaces): bool
    {
        return self::isList($interfaces)
            && count($interfaces) >= 1
            && count($interfaces) <= 4
            && count($interfaces) === count(array_unique($interfaces, SORT_STRING))
            && array_diff($interfaces, self::INTERFACES) === [];
    }

    private static function validSpecializedReadModel(mixed $model, string $owner): bool
    {
        if (!self::exactKeys($model, ['status', 'source', 'freshness'])
            || !is_array($model)
            || !in_array($model['status'] ?? null, ['available', 'unavailable', 'expired', 'not_supported'], true)
            || !self::exactKeys($model['source'] ?? null, ['engine', 'read_model', 'source_version'])
            || !is_array($model['source'])
            || ($model['source']['engine'] ?? null) !== $owner
            || !self::isAppKey($model['source']['engine'])
            || !is_string($model['source']['read_model'] ?? null)
            || preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63})*$/D', $model['source']['read_model']) !== 1
            || !self::isSemver($model['source']['source_version'] ?? null)
        ) {
            return false;
        }

        return self::validFreshness($model['freshness'] ?? null, $model['status'] === 'available');
    }

    private static function validSurfaceCompatibility(mixed $compatibility, string $consumerVersion): bool
    {
        return self::exactKeys($compatibility, ['status', 'consumer_version'])
            && is_array($compatibility)
            && in_array($compatibility['status'] ?? null, ['compatible', 'incompatible', 'unsupported'], true)
            && ($compatibility['consumer_version'] ?? null) === $consumerVersion;
    }

    /** @param list<mixed> $interfaces */
    private static function validBindings(mixed $bindings, array $interfaces, string $surface): bool
    {
        if (!self::isList($bindings) || count($bindings) > 16) {
            return false;
        }
        $seen = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding)
                || !self::exactKeys($binding, ['slot', 'interface', 'binding_state'])
                || ($binding['binding_state'] ?? null) !== 'active'
                || !in_array($binding['interface'] ?? null, $interfaces, true)
                || !in_array($binding['slot'] ?? null, self::SLOTS, true)
                || !self::surfaceAcceptsSlot($surface, $binding['slot'])
                || (in_array($binding['slot'], self::EVENT_SLOTS, true) !== ($binding['interface'] === 'event_source'))
            ) {
                return false;
            }
            $key = $binding['slot'] . "\x1F" . $binding['interface'];
            if (isset($seen[$key])) {
                return false;
            }
            $seen[$key] = true;
        }

        return true;
    }

    /** @param list<mixed> $interfaces
     *  @param list<mixed> $bindings
     */
    private static function validActions(mixed $actions, string $owner, array $interfaces, array $bindings): bool
    {
        if (!self::isList($actions) || count($actions) > 16) {
            return false;
        }
        if ($actions !== []) {
            $delegatedBinding = false;
            foreach ($bindings as $binding) {
                if (is_array($binding) && ($binding['interface'] ?? null) === 'delegated_action') {
                    $delegatedBinding = true;
                }
            }
            if (!in_array('delegated_action', $interfaces, true) || !$delegatedBinding) {
                return false;
            }
        }
        $seen = [];
        foreach ($actions as $action) {
            if (!is_array($action)
                || !self::exactKeys($action, ['action_key', 'owner', 'delegation'])
                || !self::isNamespacedKey($action['action_key'] ?? null, $owner)
                || ($action['owner'] ?? null) !== $owner
                || !self::exactKeys($action['delegation'] ?? null, ['type', 'target'])
                || !is_array($action['delegation'])
                || ($action['delegation']['type'] ?? null) !== 'owner_delegated_action'
                || ($action['delegation']['target'] ?? null) !== $action['action_key']
                || isset($seen[$action['action_key']])
            ) {
                return false;
            }
            $seen[$action['action_key']] = true;
        }

        return true;
    }

    private static function validFreshness(mixed $freshness, bool $mustBeCurrent, bool $strictUtc = false): bool
    {
        if (!self::exactKeys($freshness, ['generated_at', 'max_age_seconds', 'stale_behavior'])
            || !is_array($freshness)
            || self::timestamp($freshness['generated_at'] ?? null) === false
            || !is_int($freshness['max_age_seconds'] ?? null)
            || $freshness['max_age_seconds'] < 1
            || $freshness['max_age_seconds'] > 86400
            || !in_array($freshness['stale_behavior'] ?? null, ['omit', 'refresh_from_owner'], true)
            || ($strictUtc && (!is_string($freshness['generated_at']) || !str_ends_with($freshness['generated_at'], 'Z')))
        ) {
            return false;
        }
        $generated = self::timestamp($freshness['generated_at']);

        return is_int($generated)
            && (!$mustBeCurrent
                || ($generated <= time() + 60 && $generated + $freshness['max_age_seconds'] >= time()));
    }

    private static function validCompatibility(mixed $compatibility, mixed $consumerVersion): bool
    {
        if (!is_string($consumerVersion)
            || !self::exactKeys($compatibility, ['minimum_consumer_version', 'compatible_with', 'deprecated', 'sunset_at'])
            || !is_array($compatibility)
            || !self::isSemver($compatibility['minimum_consumer_version'] ?? null)
            || !self::isList($compatibility['compatible_with'] ?? null)
            || count($compatibility['compatible_with']) > 32
            || count($compatibility['compatible_with']) !== count(array_unique($compatibility['compatible_with'], SORT_STRING))
            || !is_bool($compatibility['deprecated'] ?? null)
            || ($compatibility['sunset_at'] !== null && !is_int(self::timestamp($compatibility['sunset_at'])))
        ) {
            return false;
        }
        foreach ($compatibility['compatible_with'] as $version) {
            if (!self::isSemver($version)) {
                return false;
            }
        }
        $sunset = $compatibility['sunset_at'] === null ? null : self::timestamp($compatibility['sunset_at']);

        return version_compare($consumerVersion, $compatibility['minimum_consumer_version'], '>=')
            && in_array($consumerVersion, $compatibility['compatible_with'], true)
            && ($sunset === null || (is_int($sunset) && $sunset > time()));
    }

    private static function containsSensitiveContent(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key)
                    && preg_match('/faluss[_-]?id|wp[_-]?user[_-]?id|e[_-]?mail|email|login|session|cookie|balance|solde|payment|paiement|stripe|history|historique|profile[_-]?url|member[_-]?content|raw[_-]?manifest|callback|callable|executable/i', $key) === 1
                ) {
                    return true;
                }
                if (self::containsSensitiveContent($child)) {
                    return true;
                }
            }

            return false;
        }

        return is_object($value)
            || is_resource($value)
            || (is_string($value)
                && preg_match('/<\?(?:php)?|<(?:script|iframe|style)\b|javascript:|data:text\/html|on[a-z]+\s*=/i', $value) === 1);
    }

    private static function surfaceAcceptsSlot(string $surface, mixed $slot): bool
    {
        $slots = [
            'portal' => ['portal.apps.card_action', 'portal.analytics.dataset'],
            'master_profile' => ['master_profile.module', 'master_profile.footer_action'],
            'me' => ['me.studio.tab', 'me.studio.block_source', 'me.public.tab', 'me.public.block'],
        ];

        return is_string($slot) && isset($slots[$surface]) && in_array($slot, $slots[$surface], true);
    }

    /** @param list<string> $keys */
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

    private static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    private static function isAppKey(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[a-z][a-z0-9-]{1,63}$/D', $value) === 1;
    }

    private static function isSemver(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[1-9][0-9]*\.[0-9]+\.[0-9]+$/D', $value) === 1;
    }

    private static function isNamespacedKey(mixed $value, string $namespace): bool
    {
        return is_string($value)
            && preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9_.-]{1,127})+$/D', $value) === 1
            && str_starts_with($value, $namespace . '.');
    }

    private static function timestamp(mixed $value): int|false
    {
        if (!is_string($value)
            || preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:Z|[+-][0-9]{2}:[0-9]{2})$/D', $value) !== 1
        ) {
            return false;
        }
        $normalized = str_ends_with($value, 'Z') ? substr($value, 0, -1) . '+00:00' : $value;
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:sP', $normalized);
        $errors = DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $normalized === $date->format('Y-m-d\\TH:i:sP')
                ? $date->getTimestamp()
                : false;
    }
}
