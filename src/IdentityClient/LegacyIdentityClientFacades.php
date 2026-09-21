<?php

declare(strict_types=1);

use Faluss\Platform\IdentityClient\IdentityClientAdmin;
use Faluss\Platform\IdentityClient\IdentityClientAppsRegistryAdapter;
use Faluss\Platform\IdentityClient\IdentityClientModule;
use Faluss\Platform\IdentityClient\IdentityClientSchema;
use Faluss\Platform\IdentityClient\IdentityClientService;

final class Faluss_Identity_Client
{
    public const SETTINGS = IdentityClientService::SETTINGS;
    public const COOKIE = IdentityClientService::COOKIE;
    public const TTL = IdentityClientService::TTL;
    public const MEMBER_SESSION_TTL = IdentityClientService::MEMBER_SESSION_TTL;
    public const STYLE = IdentityClientService::STYLE;
    public const START_ACTION = IdentityClientService::START_ACTION;
    public const CONTINUE_ACTION = IdentityClientService::CONTINUE_ACTION;
    public const MEMBER_APPS_META = IdentityClientService::MEMBER_APPS_META;

    public static function register(): void
    {
        IdentityClientService::register();
    }

    /** @param list<string> $variables
     *  @return list<string>
     */
    public static function query_vars(array $variables): array
    {
        return IdentityClientService::queryVars($variables);
    }

    public static function rewrite(): void
    {
        IdentityClientService::rewrite();
    }

    public static function assets(): void
    {
        IdentityClientService::assets();
    }

    /** @return array{enabled:bool,authority:string,client_id:string,return_urls:list<string>} */
    public static function config(): array
    {
        return IdentityClientService::config();
    }

    public static function enabled(): bool
    {
        return IdentityClientService::enabled();
    }

    public static function shortcode(mixed $attributes = []): string
    {
        return IdentityClientService::shortcode($attributes);
    }

    /** @param array<string, mixed> $attributes */
    public static function button(array $attributes = [], bool $inline = true): string
    {
        return IdentityClientService::button($attributes, $inline);
    }

    public static function start(): never
    {
        IdentityClientService::start();
    }

    public static function continue_session(): never
    {
        IdentityClientService::continueSession();
    }

    public static function callback(): void
    {
        IdentityClientService::callback();
    }

    public static function member_cookie_expiration(int $expiration, int $userId, bool $remember): int
    {
        return IdentityClientService::memberCookieExpiration($expiration, $userId, $remember);
    }

    /** @return array{contract_version:string,publication_status:string,canonical_url:string}|null */
    public static function member_app_projection(mixed $falussId, mixed $appKey): ?array
    {
        return IdentityClientService::memberAppProjection($falussId, $appKey);
    }

    public static function callback_url(): string
    {
        return IdentityClientService::callbackUrl();
    }

    /** @return array{faluss_id:string,created_at:string,last_proved_at:string}|null */
    public static function current_linked_subject(): ?array
    {
        return IdentityClientService::currentLinkedSubject();
    }
}

final class Faluss_Identity_Client_Schema
{
    public const VERSION = IdentityClientSchema::VERSION;
    public const OPTION = IdentityClientSchema::OPTION;
    public const LOCK_TIMEOUT = 10;

    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        return IdentityClientSchema::schema();
    }

    /** @return array{links?:string,states?:string} */
    public static function tables(): array
    {
        return IdentityClientSchema::tables();
    }

    public static function install_or_verify(): bool
    {
        return IdentityClientSchema::installOrVerify();
    }
}

final class Faluss_Identity_Client_Admin
{
    public static function register(): void
    {
        IdentityClientAdmin::register();
    }

    public static function menu(): void
    {
        IdentityClientAdmin::menu();
    }

    public static function settings(): void
    {
        IdentityClientAdmin::settings();
    }

    /** @return array{enabled:bool,authority:string,client_id:string,return_urls:list<string>} */
    public static function sanitize(mixed $value): array
    {
        return IdentityClientAdmin::sanitize($value);
    }

    public static function page(): void
    {
        IdentityClientAdmin::page();
    }
}

final class Faluss_Identity_Client_Apps_Registry_Adapter
{
    public static function boot(): void
    {
        IdentityClientAppsRegistryAdapter::boot();
    }

    public static function register_source(): bool|WP_Error
    {
        return IdentityClientAppsRegistryAdapter::registerSource();
    }

    public static function relationship(mixed $falussId): string|WP_Error
    {
        return IdentityClientAppsRegistryAdapter::relationship($falussId);
    }

    public static function canonical_destination(mixed $falussId): ?string
    {
        return IdentityClientAppsRegistryAdapter::canonicalDestination($falussId);
    }
}

final class Faluss_Identity_Client_Plugin
{
    public static function boot(): void
    {
        if (self::is_identity_host()) {
            return;
        }

        add_action('plugins_loaded', [self::class, 'load_textdomain']);
        add_action('init', [Faluss_Identity_Client::class, 'register']);
        IdentityClientAppsRegistryAdapter::boot();
        add_action('wp_enqueue_scripts', [Faluss_Identity_Client::class, 'assets']);
        add_action('elementor/widgets/register', [self::class, 'widget']);
        if (is_admin()) {
            IdentityClientAdmin::register();
        }
    }

    public static function activate(): bool
    {
        if (self::is_identity_host()) {
            wp_die('Faluss Identity Client ne peut pas être activé sur faluss.me.');
        }

        return IdentityClientModule::activate();
    }

    public static function deactivate(): void
    {
        IdentityClientModule::deactivate();
    }

    public static function load_textdomain(): void
    {
        IdentityClientModule::loadTextdomain();
    }

    public static function widget(mixed $manager): void
    {
        IdentityClientModule::widget($manager);
    }

    private static function is_identity_host(): bool
    {
        $parts = wp_parse_url(home_url('/'));

        return is_array($parts)
            && isset($parts['host'])
            && in_array(strtolower((string) $parts['host']), ['faluss.me', 'www.faluss.me'], true);
    }
}
