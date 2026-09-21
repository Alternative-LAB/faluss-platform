<?php

declare(strict_types=1);

namespace {
    require_once __DIR__ . '/AppsRegistryFixtures.php';

    if (!defined('ABSPATH')) {
        define('ABSPATH', '/tmp/faluss-platform-tests/');
    }

    if (!class_exists('WP_Error')) {
        class WP_Error
        {
            public function __construct(private readonly string $code = '')
            {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }
        }
    }

    if (!class_exists('Faluss_Federation_Crypto')) {
        final class Faluss_Federation_Crypto
        {
            /** @var array<string, string> */
            public static array $identity = [
                'node_id' => 'hub-node',
                'app_key' => 'faluss-hub',
                'origin' => 'https://faluss.com',
                'key_id' => 'hub-key-0001',
            ];
            public static bool $ready = true;

            public static function transport_ready(): bool
            {
                return self::$ready;
            }

            /** @return array<string, string> */
            public static function local_identity(): array
            {
                return self::$identity;
            }
        }
    }

    if (!class_exists('Faluss_Federation_Providers')) {
        final class Faluss_Federation_Providers
        {
            public static int $registrations = 0;

            public static function register_manifest_contract_validator(
                string $documentType,
                string $version,
                callable $validator
            ): bool {
                unset($validator);
                ++self::$registrations;

                return $documentType === 'faluss.app-capability-manifest' && $version === '1.0.0';
            }
        }
    }

    if (!class_exists('Faluss_Federation_Policy')) {
        final class Faluss_Federation_Policy
        {
            public static string $keyId = 'me-key-0001';

            /** @return array<string, string>|WP_Error */
            public static function find_outbound_peer(string $nodeId, string $appKey): array|WP_Error
            {
                return $nodeId === 'me-node' && $appKey === 'faluss-me'
                    ? [
                        'peer_node_id' => $nodeId,
                        'peer_app_key' => $appKey,
                        'key_id' => self::$keyId,
                        'canonical_origin' => 'https://faluss.me',
                        'key_state' => 'active',
                        'valid_from' => '2026-09-21T00:00:00Z',
                        'valid_until' => '2027-09-21T00:00:00Z',
                    ]
                    : new WP_Error('unknown_peer');
            }
        }
    }

    if (!class_exists('Faluss_Federation_Client')) {
        final class Faluss_Federation_Client
        {
            public static int $calls = 0;
            public static bool $fail = false;

            /** @return array<string, mixed>|WP_Error */
            public static function manifest_read(string $nodeId, string $appKey, string $version): array|WP_Error
            {
                ++self::$calls;
                if (self::$fail || $nodeId !== 'me-node' || $appKey !== 'faluss-me' || $version !== '1.0.0') {
                    return new WP_Error('transport_failed');
                }

                return [
                    'status' => 'success',
                    'payload_contract' => [
                        'document_type' => 'faluss.app-capability-manifest',
                        'contract_version' => '1.0.0',
                    ],
                    'payload' => \Faluss\Platform\AppsRegistry\AppsRegistryFixtures::meManifest(),
                    'responder' => [
                        'node_id' => 'me-node',
                        'app_key' => 'faluss-me',
                        'key_id' => \Faluss_Federation_Policy::$keyId,
                    ],
                    'generated_at' => gmdate('Y-m-d\\TH:i:s\\Z'),
                    'expires_at' => gmdate('Y-m-d\\TH:i:s\\Z', time() + 120),
                    'error' => null,
                ];
            }
        }
    }
}

namespace Faluss\Platform\AppsRegistry {
    function apps_registry_test_reset(): void
    {
        $GLOBALS['apps_registry_test_hooks'] = [];
        $GLOBALS['apps_registry_test_transients'] = [];
        $GLOBALS['apps_registry_test_transient_ttls'] = [];
        $GLOBALS['apps_registry_test_plugins_loaded'] = true;
        \Faluss_Federation_Crypto::$identity = [
            'node_id' => 'hub-node',
            'app_key' => 'faluss-hub',
            'origin' => 'https://faluss.com',
            'key_id' => 'hub-key-0001',
        ];
        \Faluss_Federation_Crypto::$ready = true;
        \Faluss_Federation_Providers::$registrations = 0;
        \Faluss_Federation_Policy::$keyId = 'me-key-0001';
        \Faluss_Federation_Client::$calls = 0;
        \Faluss_Federation_Client::$fail = false;
        if (class_exists(AppsRegistryService::class)) {
            $reflection = new \ReflectionClass(AppsRegistryService::class);
            foreach (['sources' => [], 'sourceConflict' => false] as $name => $value) {
                if ($reflection->hasProperty($name)) {
                    $property = $reflection->getProperty($name);
                    $property->setAccessible(true);
                    $property->setValue(null, $value);
                }
            }
        }
    }

    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        $GLOBALS['apps_registry_test_hooks'][] = compact('hook', 'callback', 'priority');
    }

    function did_action(string $hook): int
    {
        return $hook === 'plugins_loaded' && $GLOBALS['apps_registry_test_plugins_loaded'] ? 1 : 0;
    }

    function is_wp_error(mixed $value): bool
    {
        return $value instanceof \WP_Error;
    }

    /** @return array<string, int|string>|false */
    function wp_parse_url(string $url): array|false
    {
        return parse_url($url);
    }

    function get_transient(string $key): mixed
    {
        return $GLOBALS['apps_registry_test_transients'][$key] ?? false;
    }

    function set_transient(string $key, mixed $value, int $expiration): bool
    {
        $GLOBALS['apps_registry_test_transients'][$key] = $value;
        $GLOBALS['apps_registry_test_transient_ttls'][$key] = $expiration;

        return true;
    }

    function delete_transient(string $key): bool
    {
        unset($GLOBALS['apps_registry_test_transients'][$key], $GLOBALS['apps_registry_test_transient_ttls'][$key]);

        return true;
    }
}
