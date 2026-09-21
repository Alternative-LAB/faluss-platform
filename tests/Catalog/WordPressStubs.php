<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use RuntimeException;

function sanitize_title(string $value): string
{
    return strtolower(trim(preg_replace('/[^a-z0-9-]+/i', '-', $value) ?? '', '-'));
}

function sanitize_text_field(string $value): string
{
    return trim(strip_tags($value));
}

function sanitize_hex_color(string $value): string
{
    return preg_match('/^#[a-f0-9]{6}$/i', $value) ? $value : '';
}

function absint(mixed $value): int
{
    return abs((int) $value);
}

function wp_attachment_is_image(int $id): bool
{
    return $id === 17;
}

function add_action(string $hook, callable $callback): void
{
    $GLOBALS['catalog_test_hooks'][$hook] = $callback;
}

function current_user_can(string $capability): bool
{
    return $capability === 'manage_options' && ($GLOBALS['catalog_test_admin'] ?? true);
}

function wp_verify_nonce(string $nonce, string $action): bool
{
    return $nonce === 'valid-nonce' && str_starts_with($action, 'faluss_catalog_');
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function __(string $text, string $domain): string
{
    return $text;
}

function esc_html__(string $text, string $domain): string
{
    return $text;
}

function esc_html(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_attr(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function esc_url(string $url): string
{
    return htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
}

function wp_die(string $message): never
{
    throw new RuntimeException($message);
}

function get_option(string $name, mixed $default): mixed
{
    return $GLOBALS['catalog_test_option'] ?? $default;
}

function update_option(string $name, mixed $value, bool $autoload): void
{
    $GLOBALS['catalog_test_option'] = $value;
    $GLOBALS['catalog_test_writes'][] = $name;
}

function do_action(string $hook, string $slug): void
{
    $GLOBALS['catalog_test_events'][] = [$hook, $slug];
}

function sanitize_key(string $value): string
{
    return $value;
}

function admin_url(string $path): string
{
    return '/wp-admin/' . $path;
}

function add_query_arg(string $name, string $value, string $url): string
{
    return $url . '&' . $name . '=' . $value;
}

function wp_safe_redirect(string $url): never
{
    throw new RuntimeException($url);
}

function wp_nonce_field(string $action, string $field): void
{
    echo '<input type="hidden" name="' . esc_attr($field) . '" value="valid-nonce">';
}

function selected(string $value, string $option): void
{
    if ($value === $option) {
        echo 'selected="selected"';
    }
}

function submit_button(string $label, string $type, string $name, bool $wrap): void
{
    echo '<button name="' . esc_attr($name) . '">' . esc_html($label) . '</button>';
}

function wp_get_attachment_image_url(int $id, string $size): string|false
{
    return false;
}

function add_submenu_page(string $parent, string $title, string $menu, string $capability, string $slug, callable $callback): string
{
    $GLOBALS['catalog_test_menu'] = [$parent, $capability, $slug];

    return 'faluss-catalog-test-hook';
}

function plugins_url(string $path, string $pluginFile): string
{
    return '/wp-content/plugins/faluss-platform/' . $path;
}

function wp_enqueue_style(string $handle, string $url, array $dependencies, string $version): void
{
    $GLOBALS['catalog_test_styles'][] = $handle;
}

function wp_enqueue_media(): void
{
    $GLOBALS['catalog_test_media'] = true;
}

function wp_enqueue_script(string $handle, string $url, array $dependencies, string $version, bool $footer): void
{
    $GLOBALS['catalog_test_scripts'][] = $handle;
}
