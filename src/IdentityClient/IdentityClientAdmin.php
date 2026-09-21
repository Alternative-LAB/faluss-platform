<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

final class IdentityClientAdmin
{
    private const PAGE = 'faluss-identity-client';

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_init', [self::class, 'settings']);
    }

    public static function menu(): void
    {
        add_submenu_page(
            'faluss-platform',
            __('Faluss Identity Client', 'faluss-platform'),
            __('Identity Client', 'faluss-platform'),
            'manage_options',
            self::PAGE,
            [self::class, 'page']
        );
    }

    public static function settings(): void
    {
        register_setting('faluss_identity_client', IdentityClientService::SETTINGS, [
            'sanitize_callback' => [self::class, 'sanitize'],
        ]);
    }

    /** @return array{enabled:bool,authority:string,client_id:string,return_urls:list<string>} */
    public static function sanitize(mixed $value): array
    {
        $value = is_array($value) ? $value : [];
        $rawUrls = is_scalar($value['return_urls'] ?? null) ? (string) $value['return_urls'] : '';
        $lines = preg_split('/\r\n|\r|\n/', trim($rawUrls));
        $urls = [];
        foreach (is_array($lines) ? $lines : [] as $url) {
            $url = trim($url);
            if ($url !== '' && self::local($url)) {
                $urls[] = $url;
            }
        }

        return [
            'enabled' => !empty($value['enabled']),
            'authority' => 'https://faluss.me',
            'client_id' => sanitize_text_field($value['client_id'] ?? ''),
            'return_urls' => array_values(array_unique($urls)),
        ];
    }

    public static function page(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        $config = IdentityClientService::config();
        ?>
        <div class="wrap faluss-admin faluss-identity-client-admin">
            <div class="faluss-admin__hero">
                <span class="faluss-admin__eyebrow"><?php echo esc_html__('Connexion fédérée', 'faluss-platform'); ?></span>
                <h1><?php echo esc_html__('Faluss Identity Client', 'faluss-platform'); ?></h1>
                <p><?php echo esc_html__('Le client reste désactivé tant que la recette de préproduction et la bascule du plugin historique ne sont pas validées.', 'faluss-platform'); ?></p>
            </div>
            <section class="faluss-admin__card">
                <form method="post" action="options.php">
                    <?php settings_fields('faluss_identity_client'); ?>
                    <table class="form-table" role="presentation">
                        <tr>
                            <th scope="row"><?php echo esc_html__('Activer le SSO Faluss', 'faluss-platform'); ?></th>
                            <td><label><input type="checkbox" name="<?php echo esc_attr(IdentityClientService::SETTINGS); ?>[enabled]" value="1" <?php checked($config['enabled']); ?>> <?php echo esc_html__('Activer après recette', 'faluss-platform'); ?></label></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="faluss-identity-authority"><?php echo esc_html__('Autorité', 'faluss-platform'); ?></label></th>
                            <td><input id="faluss-identity-authority" class="regular-text" readonly value="https://faluss.me"></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="faluss-identity-client-id"><?php echo esc_html__('Client ID', 'faluss-platform'); ?></label></th>
                            <td><input id="faluss-identity-client-id" class="regular-text" name="<?php echo esc_attr(IdentityClientService::SETTINGS); ?>[client_id]" value="<?php echo esc_attr($config['client_id']); ?>"></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Secret client', 'faluss-platform'); ?></th>
                            <td><code>FALUSS_IDENTITY_CLIENT_SECRET</code><p class="description"><?php echo esc_html__('À définir côté serveur dans wp-config.php ou l’environnement ; jamais dans les options WordPress.', 'faluss-platform'); ?></p></td>
                        </tr>
                        <tr>
                            <th scope="row"><?php echo esc_html__('Callback à déclarer', 'faluss-platform'); ?></th>
                            <td><code><?php echo esc_html(IdentityClientService::callbackUrl()); ?></code></td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="faluss-identity-return-urls"><?php echo esc_html__('Retours locaux autorisés', 'faluss-platform'); ?></label></th>
                            <td><textarea id="faluss-identity-return-urls" class="large-text code" rows="4" name="<?php echo esc_attr(IdentityClientService::SETTINGS); ?>[return_urls]"><?php echo esc_textarea(implode("\n", $config['return_urls'])); ?></textarea></td>
                        </tr>
                    </table>
                    <?php submit_button(); ?>
                </form>
            </section>
        </div>
        <?php
    }

    private static function local(string $url): bool
    {
        $candidate = wp_parse_url($url);
        $home = wp_parse_url(home_url('/'));
        if (!is_array($candidate)
            || !is_array($home)
            || !isset($candidate['scheme'], $candidate['host'], $home['scheme'], $home['host'])
            || isset($candidate['user'])
            || isset($candidate['pass'])
            || isset($candidate['fragment'])
        ) {
            return false;
        }
        $candidatePort = isset($candidate['port'])
            ? (int) $candidate['port']
            : (strtolower((string) $candidate['scheme']) === 'https' ? 443 : 80);
        $homePort = isset($home['port'])
            ? (int) $home['port']
            : (strtolower((string) $home['scheme']) === 'https' ? 443 : 80);

        return strcasecmp((string) $candidate['scheme'], (string) $home['scheme']) === 0
            && strcasecmp((string) $candidate['host'], (string) $home['host']) === 0
            && $candidatePort === $homePort;
    }
}
