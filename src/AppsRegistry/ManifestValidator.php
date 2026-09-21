<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use DateTimeImmutable;

/** Closed runtime validator for the CAP v1 application manifest. */
final class ManifestValidator
{
    private const ROOT_KEYS = [
        'manifest_version',
        'app_key',
        'capability_namespace',
        'owner',
        'product_state',
        'canonical_origins',
        'public_presentation',
        'official_asset',
        'capabilities',
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

    public static function validate(mixed $manifest, mixed $payloadContract, mixed $requestContext): bool
    {
        if (!is_array($manifest)
            || !is_array($requestContext)
            || !self::validContractAndContext($payloadContract, $requestContext)
            || !self::exactKeys($manifest, self::ROOT_KEYS)
            || self::containsForbiddenContent($manifest)
        ) {
            return false;
        }
        if (($manifest['manifest_version'] ?? null) !== '1.0.0'
            || !self::isAppKey($manifest['app_key'] ?? null)
            || $manifest['app_key'] !== ($requestContext['parameters']['app_key'] ?? null)
            || !self::isAppKey($manifest['capability_namespace'] ?? null)
            || $manifest['app_key'] !== $manifest['capability_namespace']
            || !in_array($manifest['product_state'] ?? null, ['planned', 'active', 'maintenance', 'retired'], true)
        ) {
            return false;
        }

        return self::validOwner($manifest['owner'] ?? null, $manifest['capability_namespace'])
            && self::validOrigins($manifest['canonical_origins'] ?? null, $manifest['owner']['authority'] ?? null)
            && self::validPresentation($manifest['public_presentation'] ?? null)
            && self::validAsset($manifest['official_asset'] ?? null, $manifest['capability_namespace'])
            && self::validCapabilities($manifest['capabilities'] ?? null, $manifest['capability_namespace'])
            && self::validCompatibility($manifest['compatibility'] ?? null, $manifest['capability_namespace']);
    }

    /** @param array<string, mixed> $context */
    private static function validContractAndContext(mixed $contract, array $context): bool
    {
        if (!self::exactKeys($contract, ['document_type', 'contract_version'])
            || $contract['document_type'] !== AppsRegistryService::DOCUMENT_TYPE
            || $contract['contract_version'] !== AppsRegistryService::CONTRACT_VERSION
            || !self::exactKeys($context, ['operation', 'parameters', 'subject_context', 'sender', 'recipient'])
            || $context['operation'] !== 'manifest.read'
            || $context['subject_context'] !== null
            || !is_array($context['parameters'])
            || !is_array($context['sender'])
            || !is_array($context['recipient'])
        ) {
            return false;
        }
        $parameters = $context['parameters'];

        return self::exactKeys($parameters, ['app_key', 'requested_manifest_version'])
            && self::isAppKey($parameters['app_key'] ?? null)
            && ($parameters['requested_manifest_version'] ?? null) === AppsRegistryService::CONTRACT_VERSION
            && ($context['recipient']['app_key'] ?? null) === $parameters['app_key'];
    }

    private static function validOwner(mixed $owner, string $namespace): bool
    {
        return self::exactKeys($owner, ['engine', 'authority'])
            && is_array($owner)
            && ($owner['engine'] ?? null) === $namespace
            && is_string($owner['authority'] ?? null)
            && preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63})*$/D', $owner['authority']) === 1;
    }

    private static function validOrigins(mixed $origins, mixed $authority): bool
    {
        if (!self::isList($origins)
            || $origins === []
            || count($origins) > 16
            || count($origins) !== count(array_unique($origins, SORT_STRING))
            || !is_string($authority)
        ) {
            return false;
        }
        foreach ($origins as $origin) {
            $parts = is_string($origin) ? wp_parse_url($origin) : false;
            $port = is_array($parts) && isset($parts['port']) ? $parts['port'] : null;
            $canonical = is_array($parts) && isset($parts['host'])
                ? 'https://' . $parts['host'] . ($port === null ? '' : ':' . $port)
                : '';
            if (!is_array($parts)
                || ($parts['scheme'] ?? null) !== 'https'
                || !is_string($parts['host'] ?? null)
                || ($port !== null && $port < 1)
                || isset($parts['path'], $parts['query'], $parts['fragment'], $parts['user'], $parts['pass'])
                || $origin !== $canonical
                || $authority !== strtolower($parts['host'])
            ) {
                return false;
            }
        }

        return true;
    }

    private static function validPresentation(mixed $presentation): bool
    {
        return self::exactKeys($presentation, ['display_name', 'summary'])
            && is_array($presentation)
            && self::boundedText($presentation['display_name'] ?? null, 80)
            && self::boundedText($presentation['summary'] ?? null, 280);
    }

    private static function validAsset(mixed $asset, string $namespace): bool
    {
        if ($asset === null) {
            return true;
        }
        if (!self::exactKeys($asset, ['asset_key', 'mime_type', 'sha256', 'intrinsic_dimensions', 'distribution'])
            || !is_array($asset)
            || !self::isNamespacedKey($asset['asset_key'] ?? null, $namespace)
            || !in_array($asset['mime_type'] ?? null, ['image/svg+xml', 'image/png', 'image/jpeg', 'image/webp'], true)
            || !is_string($asset['sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $asset['sha256']) !== 1
            || !self::exactKeys($asset['intrinsic_dimensions'] ?? null, ['width', 'height'])
            || !is_array($asset['intrinsic_dimensions'])
            || !in_array($asset['distribution'] ?? null, ['owner_hosted', 'immutable_embedded'], true)
        ) {
            return false;
        }
        $width = $asset['intrinsic_dimensions']['width'] ?? null;
        $height = $asset['intrinsic_dimensions']['height'] ?? null;

        return is_int($width) && is_int($height)
            && $width >= 1 && $width <= 32768
            && $height >= 1 && $height <= 32768;
    }

    private static function validCapabilities(mixed $capabilities, string $namespace): bool
    {
        if (!self::isList($capabilities) || count($capabilities) > 64) {
            return false;
        }
        $seen = [];
        foreach ($capabilities as $capability) {
            if (!is_array($capability)
                || !self::exactKeys($capability, [
                    'capability_key',
                    'interfaces',
                    'requested_bindings',
                    'read_model_contract',
                    'symbolic_actions',
                    'compatibility',
                ])
                || !self::isNamespacedKey($capability['capability_key'] ?? null, $namespace)
                || isset($seen[$capability['capability_key']])
                || !self::validInterfaces($capability['interfaces'] ?? null)
                || !self::validReadModelContract($capability['read_model_contract'] ?? null, $capability['interfaces'])
                || !self::validBindings($capability['requested_bindings'] ?? null, $capability['interfaces'])
                || !self::validActions($capability['symbolic_actions'] ?? null, $capability['interfaces'], $namespace)
                || !self::validCompatibility($capability['compatibility'] ?? null, $namespace)
            ) {
                return false;
            }
            $seen[$capability['capability_key']] = true;
        }

        return true;
    }

    private static function validInterfaces(mixed $interfaces): bool
    {
        return self::isList($interfaces)
            && count($interfaces) >= 1
            && count($interfaces) <= 4
            && count($interfaces) === count(array_unique($interfaces, SORT_STRING))
            && array_diff($interfaces, self::INTERFACES) === [];
    }

    /** @param list<mixed> $interfaces */
    private static function validReadModelContract(mixed $contract, array $interfaces): bool
    {
        if ($contract === null) {
            return !in_array('module_read_model', $interfaces, true);
        }

        return self::exactKeys($contract, ['document_type', 'contract_version'])
            && is_array($contract)
            && is_string($contract['document_type'] ?? null)
            && preg_match('/^[a-z][a-z0-9-]{1,63}(?:\.[a-z][a-z0-9-]{1,63})+$/D', $contract['document_type']) === 1
            && self::isSemver($contract['contract_version'] ?? null);
    }

    /** @param list<mixed> $interfaces */
    private static function validBindings(mixed $bindings, array $interfaces): bool
    {
        if (!self::isList($bindings) || count($bindings) > 16) {
            return false;
        }
        $seen = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding)
                || !self::exactKeys($binding, ['interface', 'slot'])
                || !in_array($binding['interface'] ?? null, $interfaces, true)
                || !in_array($binding['slot'] ?? null, self::SLOTS, true)
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

    /** @param list<mixed> $interfaces */
    private static function validActions(mixed $actions, array $interfaces, string $namespace): bool
    {
        if (!self::isList($actions)
            || count($actions) > 16
            || ($actions !== [] && !in_array('delegated_action', $interfaces, true))
        ) {
            return false;
        }
        $seen = [];
        foreach ($actions as $action) {
            if (!is_array($action)
                || !self::exactKeys($action, ['action_key', 'kind'])
                || !self::isNamespacedKey($action['action_key'] ?? null, $namespace)
                || ($action['kind'] ?? null) !== 'delegated_action'
                || isset($seen[$action['action_key']])
            ) {
                return false;
            }
            $seen[$action['action_key']] = true;
        }

        return true;
    }

    private static function validCompatibility(mixed $compatibility, string $namespace): bool
    {
        if (!self::exactKeys($compatibility, [
            'minimum_consumer_version',
            'compatible_with',
            'deprecated',
            'sunset_at',
            'replacement_capability_key',
        ]) || !is_array($compatibility)
            || !self::isSemver($compatibility['minimum_consumer_version'] ?? null)
            || !self::isList($compatibility['compatible_with'] ?? null)
            || count($compatibility['compatible_with']) > 32
            || count($compatibility['compatible_with']) !== count(array_unique($compatibility['compatible_with'], SORT_STRING))
            || !is_bool($compatibility['deprecated'] ?? null)
        ) {
            return false;
        }
        foreach ($compatibility['compatible_with'] as $version) {
            if (!self::isSemver($version)) {
                return false;
            }
        }
        if ($compatibility['sunset_at'] !== null && !self::isDateTime($compatibility['sunset_at'])) {
            return false;
        }
        if ($compatibility['replacement_capability_key'] !== null
            && !self::isNamespacedKey($compatibility['replacement_capability_key'], $namespace)
        ) {
            return false;
        }

        return $compatibility['deprecated']
            || ($compatibility['sunset_at'] === null && $compatibility['replacement_capability_key'] === null);
    }

    private static function containsForbiddenContent(mixed $value): bool
    {
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                if (is_string($key)
                    && preg_match('/faluss[_-]?id|wp[_-]?user[_-]?id|e[_-]?mail|email|login|session|cookie|balance|solde|payment|paiement|stripe|history|historique|subscription|abonnement|private[_-]?content|contenu[_-]?prive|callback|callable|executable|javascript|php|html|css|sql/i', $key) === 1
                ) {
                    return true;
                }
                if (self::containsForbiddenContent($child)) {
                    return true;
                }
            }

            return false;
        }
        if (!is_string($value)) {
            return false;
        }

        return preg_match('/faluss[_-]?id|wp[_-]?user[_-]?id|e-?mail|login|session|cookie|balance|solde|payment|paiement|stripe|history|historique|subscription|abonnement/i', $value) === 1
            || preg_match('/<\?(?:php)?|<(?:script|iframe|style)\b|javascript:|data:text\/html|on[a-z]+\s*=|\b(?:SELECT|INSERT|UPDATE|DELETE|DROP)\s+(?:FROM|INTO|TABLE|SET|WHERE)\b/i', $value) === 1;
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

    private static function boundedText(mixed $value, int $maximum): bool
    {
        return is_string($value)
            && trim($value) !== ''
            && !str_contains($value, "\0")
            && preg_match('//u', $value) === 1
            && preg_match_all('/./us', $value) <= $maximum;
    }

    private static function isDateTime(mixed $value): bool
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
            && $normalized === $date->format('Y-m-d\\TH:i:sP');
    }
}
