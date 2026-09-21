<?php

declare(strict_types=1);

namespace {
    if (!class_exists('WP_Error')) {
        final class WP_Error
        {
            /** @param array<string, mixed> $data */
            public function __construct(
                private readonly string $code,
                private readonly string $message = '',
                private readonly array $data = []
            ) {
            }

            public function get_error_code(): string
            {
                return $this->code;
            }

            /** @return array<string, mixed> */
            public function get_error_data(): array
            {
                return $this->data;
            }
        }
    }
}

namespace Faluss\Platform\TokenEngineConnector {
    function connector_test_reset(): void
    {
        $GLOBALS['connector_test_options'] = [];
        $GLOBALS['connector_test_updates'] = [];
        $GLOBALS['connector_test_remote_queue'] = [];
        $GLOBALS['connector_test_remote_calls'] = [];
        $GLOBALS['connector_test_cache'] = [];
        $GLOBALS['connector_test_time'] = 1_800_000_000;
        $GLOBALS['connector_test_did_plugins_loaded'] = true;
        $GLOBALS['connector_test_user'] = null;
        $GLOBALS['connector_test_filtered_subject'] = null;
        $GLOBALS['connector_test_hooks'] = [];
        $GLOBALS['connector_test_can_manage'] = true;
        $GLOBALS['connector_test_nonce_valid'] = true;
    }

    /** @param array<string, mixed> $body
     *  @return array{code:int,body:string}
     */
    function connector_test_response(array $body, int $code = 200): array
    {
        return ['code' => $code, 'body' => (string) json_encode($body)];
    }

    function get_option(string $name, mixed $default = false): mixed
    {
        return $GLOBALS['connector_test_options'][$name] ?? $default;
    }

    function update_option(string $name, mixed $value, bool $autoload = false): bool
    {
        $GLOBALS['connector_test_options'][$name] = $value;
        $GLOBALS['connector_test_updates'][] = $name;

        return true;
    }

    function wp_salt(string $scheme = 'auth'): string
    {
        return 'connector-test-salt-' . $scheme;
    }

    function is_wp_error(mixed $value): bool
    {
        return $value instanceof \WP_Error;
    }

    function wp_unslash(mixed $value): mixed
    {
        return $value;
    }

    function sanitize_text_field(mixed $value): string
    {
        return is_scalar($value) ? trim(strip_tags((string) $value)) : '';
    }

    function esc_url_raw(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** @return array<string, int|string>|false */
    function wp_parse_url(string $value): array|false
    {
        return parse_url($value);
    }

    function trailingslashit(string $value): string
    {
        return rtrim($value, '/') . '/';
    }

    function add_query_arg(string $key, string $value, string $url): string
    {
        return $url . (str_contains($url, '?') ? '&' : '?') . rawurlencode($key) . '=' . rawurlencode($value);
    }

    function __(string $message, string $domain = ''): string
    {
        return $message;
    }

    function wp_generate_uuid4(): string
    {
        return 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    }

    function wp_remote_retrieve_response_code(mixed $response): int
    {
        return is_array($response) ? (int) ($response['code'] ?? 0) : 0;
    }

    function wp_remote_retrieve_body(mixed $response): string
    {
        return is_array($response) ? (string) ($response['body'] ?? '') : '';
    }

    /** @param array<string, mixed> $args */
    function wp_safe_remote_post(string $url, array $args = []): mixed
    {
        return connector_test_remote('POST', $url, $args);
    }

    /** @param array<string, mixed> $args */
    function wp_safe_remote_get(string $url, array $args = []): mixed
    {
        return connector_test_remote('GET', $url, $args);
    }

    /** @param array<string, mixed> $args */
    function connector_test_remote(string $method, string $url, array $args): mixed
    {
        $GLOBALS['connector_test_remote_calls'][] = compact('method', 'url', 'args');
        $response = array_shift($GLOBALS['connector_test_remote_queue']);

        return is_callable($response) ? $response($method, $url, $args) : $response;
    }

    function wp_cache_get(string $key, string $group = ''): mixed
    {
        $entry = $GLOBALS['connector_test_cache'][$group][$key] ?? null;
        if (!is_array($entry) || ($entry['expires_at'] ?? 0) <= time()) {
            return false;
        }

        return $entry['value'];
    }

    function wp_cache_set(string $key, mixed $value, string $group = '', int $expire = 0): bool
    {
        $GLOBALS['connector_test_cache'][$group][$key] = [
            'value' => $value,
            'expires_at' => time() + $expire,
        ];

        return true;
    }

    function wp_cache_delete(string $key, string $group = ''): bool
    {
        unset($GLOBALS['connector_test_cache'][$group][$key]);

        return true;
    }

    function time(): int
    {
        return (int) ($GLOBALS['connector_test_time'] ?? 1_800_000_000);
    }

    function did_action(string $hook): int
    {
        return $hook === 'plugins_loaded' && !empty($GLOBALS['connector_test_did_plugins_loaded']) ? 1 : 0;
    }

    function wp_get_current_user(): mixed
    {
        return $GLOBALS['connector_test_user'];
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
    {
        return $GLOBALS['connector_test_filtered_subject'] ?? $value;
    }

    function add_action(string $hook, callable $callback, int $priority = 10): void
    {
        $GLOBALS['connector_test_hooks'][$hook] = $callback;
    }

    function current_user_can(string $capability): bool
    {
        return $capability === 'manage_options' && !empty($GLOBALS['connector_test_can_manage']);
    }

    function wp_verify_nonce(string $nonce, string $action): int|false
    {
        return !empty($GLOBALS['connector_test_nonce_valid']) ? 1 : false;
    }

    function wp_die(string $message = ''): never
    {
        throw new \RuntimeException($message);
    }
}
