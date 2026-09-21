<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

/** Private administration surface; no secret, token or Faluss ID is rendered. */
final class ConnectorAdmin
{
    private const PAGE = 'faluss-platform-token-engine-connector';
    private const CAPABILITY = 'manage_options';
    private const RESULT_TTL = 60;

    private static string $pageHook = '';

    public static function boot(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueueAssets']);
        add_action('admin_post_token_engine_connector_save', [self::class, 'save']);
        add_action('admin_post_token_engine_connector_test', [self::class, 'testCoreConnection']);
        add_action('admin_post_token_engine_connector_test_core', [self::class, 'testCoreConnection']);
        add_action('admin_post_token_engine_connector_test_subject', [self::class, 'testSubject']);
        add_action('admin_post_token_engine_connector_test_daily_reward', [self::class, 'testDailyReward']);
        add_action('admin_post_token_engine_connector_test_entitlements', [self::class, 'testEntitlements']);
    }

    public static function menu(): void
    {
        $pageHook = add_submenu_page(
            'faluss-platform',
            __('Token Engine Connector', 'faluss-platform'),
            __('Token Engine', 'faluss-platform'),
            self::CAPABILITY,
            self::PAGE,
            [self::class, 'page']
        );
        self::$pageHook = is_string($pageHook) ? $pageHook : '';
    }

    public static function enqueueAssets(string $hook): void
    {
        if ($hook !== self::$pageHook || !current_user_can(self::CAPABILITY)) {
            return;
        }

        $pluginFile = dirname(__DIR__, 2) . '/faluss-platform.php';
        wp_enqueue_style(
            'faluss-platform-admin',
            plugins_url('assets/admin.css', $pluginFile),
            [],
            '0.1.0'
        );
        wp_enqueue_style(
            'faluss-platform-token-engine-connector',
            plugins_url('assets/token-engine-connector.css', $pluginFile),
            ['faluss-platform-admin'],
            '0.1.0'
        );
    }

    public static function page(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(esc_html__('Accès non autorisé.', 'faluss-platform'));
        }

        $tab = self::tab($_GET['tab'] ?? '');
        ?>
        <div class="wrap faluss-admin faluss-connector-admin">
            <div class="faluss-admin__hero">
                <span class="faluss-admin__eyebrow"><?php echo esc_html__('Faluss Platform · faluss.me', 'faluss-platform'); ?></span>
                <h1><?php echo esc_html__('Token Engine Connector', 'faluss-platform'); ?></h1>
                <p><?php echo esc_html__('Client serveur vers le Token Engine de faluss.com. Aucun ledger, solde, droit ou Faluss ID n’est stocké localement.', 'faluss-platform'); ?></p>
            </div>
            <nav class="nav-tab-wrapper" aria-label="<?php echo esc_attr__('Token Engine Connector', 'faluss-platform'); ?>">
                <?php foreach (self::tabs() as $key => $label): ?>
                    <a class="nav-tab <?php echo $tab === $key ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url(self::url($key)); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <section class="faluss-admin__card faluss-connector-admin__panel">
                <?php self::notice(); ?>
                <?php if ($tab === 'configuration'): ?>
                    <?php self::configurationPage(); ?>
                <?php elseif ($tab === 'entitlements'): ?>
                    <?php self::entitlementsPage(); ?>
                <?php else: ?>
                    <?php self::diagnosticPage(); ?>
                <?php endif; ?>
            </section>
        </div>
        <?php
    }

    public static function save(): void
    {
        self::guard('token_engine_connector_save');
        $result = ConnectorService::saveConfiguration($_POST);
        self::redirect(
            'configuration',
            $result instanceof \WP_Error ? (string) $result->get_error_code() : 'saved'
        );
    }

    /** Authenticates the Core without resolving a local subject. */
    public static function testCoreConnection(): void
    {
        self::guard('token_engine_connector_test_core');
        $connection = ConnectorService::coreConnectionTest();
        $failed = $connection instanceof \WP_Error;
        $errorData = $failed ? $connection->get_error_data() : [];
        $errorData = is_array($errorData) ? $errorData : [];
        $result = [
            'connected' => !$failed,
            'code' => $failed ? (string) $connection->get_error_code() : 'connector_core_valid',
            'project_key' => !$failed ? $connection['project_key'] : '',
            'permissions' => !$failed ? $connection['permissions'] : [],
            'protocol_version' => !$failed ? (string) ($connection['protocol_version'] ?? '') : '',
            'steps' => !$failed && is_array($connection['steps'] ?? null)
                ? $connection['steps']
                : [],
            'stage' => $failed ? (string) ($errorData['stage'] ?? '') : '',
            'diagnostic_id' => $failed
                ? (string) ($errorData['diagnostic_id'] ?? '')
                : (string) ($connection['diagnostic_id'] ?? ''),
        ];
        set_transient(self::resultKey('core'), $result, self::RESULT_TTL);
        if ($result['connected']) {
            set_transient(self::coreValidKey(), true, 5 * self::RESULT_TTL);
        } else {
            delete_transient(self::coreValidKey());
        }
        self::redirect('diagnostic', $result['connected'] ? 'test_ok' : 'test_failed');
    }

    public static function testSubject(): void
    {
        self::guard('token_engine_connector_test_subject');
        if (!get_transient(self::coreValidKey())) {
            self::redirect('diagnostic', 'subject_core_required');
        }

        $subject = ConnectorService::subjectDiagnostic();
        $subject['diagnostic_id'] = wp_generate_uuid4();
        set_transient(self::resultKey('subject'), $subject, self::RESULT_TTL);
        self::redirect('diagnostic', 'subject_tested');
    }

    public static function testDailyReward(): void
    {
        self::guard('token_engine_connector_test_daily_reward');
        set_transient(
            self::resultKey('daily_reward'),
            ConnectorService::dailyRewardDiagnostic(),
            self::RESULT_TTL
        );
        self::redirect('diagnostic', 'daily_reward_tested');
    }

    public static function testEntitlements(): void
    {
        self::guard('token_engine_connector_test_entitlements');
        set_transient(
            self::resultKey('entitlements'),
            ConnectorService::entitlementsDiagnostic(),
            self::RESULT_TTL
        );
        self::redirect('entitlements', 'entitlements_tested');
    }

    private static function configurationPage(): void
    {
        $settings = ConnectorService::configuration();
        ?>
        <h2><?php echo esc_html__('Configuration du Core', 'faluss-platform'); ?></h2>
        <p><?php echo esc_html__('Utilisez l’URL canonique HTTPS du site Core. Le secret est protégé localement et ne sera jamais réaffiché.', 'faluss-platform'); ?></p>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="token_engine_connector_save">
            <?php wp_nonce_field('token_engine_connector_save', 'token_engine_connector_nonce'); ?>
            <div class="faluss-admin__fields">
                <label class="faluss-admin__field" for="token-engine-connector-url">
                    <?php echo esc_html__('URL du site Core', 'faluss-platform'); ?>
                    <input id="token-engine-connector-url" class="regular-text code" type="url" name="core_site_url" required placeholder="https://www.faluss.com" value="<?php echo esc_attr($settings['core_site_url']); ?>">
                    <span class="description"><?php echo esc_html__('Sans /wp-json, rest_route ni endpoint Token Engine.', 'faluss-platform'); ?></span>
                </label>
                <label class="faluss-admin__field" for="token-engine-connector-client">
                    <?php echo esc_html__('Identifiant client', 'faluss-platform'); ?>
                    <input id="token-engine-connector-client" class="regular-text code" name="client_id" required value="<?php echo esc_attr($settings['client_id']); ?>">
                </label>
                <label class="faluss-admin__field" for="token-engine-connector-secret">
                    <?php echo esc_html__('Secret client', 'faluss-platform'); ?>
                    <input id="token-engine-connector-secret" class="regular-text" type="password" name="client_secret" autocomplete="new-password" placeholder="<?php echo esc_attr($settings['secret_configured'] ? __('Laisser vide pour conserver le secret', 'faluss-platform') : __('Coller le secret une seule fois', 'faluss-platform')); ?>">
                    <span class="description"><?php echo esc_html($settings['secret_state'] === 'saved' ? __('Secret enregistré.', 'faluss-platform') : __('Secret requis.', 'faluss-platform')); ?></span>
                </label>
                <label class="faluss-admin__field" for="token-engine-connector-project">
                    <?php echo esc_html__('Clé projet attendue', 'faluss-platform'); ?>
                    <input id="token-engine-connector-project" class="regular-text code" name="project_key" required value="<?php echo esc_attr($settings['project_key']); ?>">
                </label>
            </div>
            <?php submit_button(__('Enregistrer la configuration', 'faluss-platform')); ?>
        </form>
        <?php
    }

    private static function diagnosticPage(): void
    {
        $core = self::takeResult('core');
        $subject = self::takeResult('subject');
        $reward = self::takeResult('daily_reward');
        ?>
        <h2><?php echo esc_html__('Diagnostic non mutatif', 'faluss-platform'); ?></h2>
        <p><?php echo esc_html__('Aucun secret, jeton, en-tête d’autorisation, Faluss ID ou corps distant brut n’est affiché.', 'faluss-platform'); ?></p>
        <?php self::diagnosticSection('core', __('Connexion au Core', 'faluss-platform'), __('Valide la route, le protocole, les credentials et wallet.read.', 'faluss-platform'), 'token_engine_connector_test_core', __('Tester la connexion', 'faluss-platform'), $core); ?>
        <?php self::diagnosticSection('daily_reward', __('Gain quotidien', 'faluss-platform'), __('Vérifie reward.claim et la règle globale sans réclamer de gain.', 'faluss-platform'), 'token_engine_connector_test_daily_reward', __('Diagnostiquer le gain', 'faluss-platform'), $reward); ?>
        <?php if (get_transient(self::coreValidKey())): ?>
            <?php self::diagnosticSection('subject', __('Sujet Faluss', 'faluss-platform'), __('Résout le profil Identity actif de la session courante sans afficher son identifiant.', 'faluss-platform'), 'token_engine_connector_test_subject', __('Diagnostiquer le sujet', 'faluss-platform'), $subject); ?>
        <?php else: ?>
            <section class="faluss-connector-admin__diagnostic"><h3><?php echo esc_html__('Sujet Faluss', 'faluss-platform'); ?></h3><p><?php echo esc_html__('Validez d’abord la connexion au Core.', 'faluss-platform'); ?></p></section>
        <?php endif; ?>
        <?php
    }

    private static function entitlementsPage(): void
    {
        $result = self::takeResult('entitlements');
        ?>
        <h2><?php echo esc_html__('Diagnostic des droits', 'faluss-platform'); ?></h2>
        <p><?php echo esc_html__('Cette lecture ne crée ni droit local, ni attribution, ni écriture de ledger.', 'faluss-platform'); ?></p>
        <?php self::diagnosticSection('entitlements', __('Droits de thème', 'faluss-platform'), __('Vérifie entitlements.read, les définitions du projet et la disponibilité du sujet courant.', 'faluss-platform'), 'token_engine_connector_test_entitlements', __('Diagnostiquer les droits', 'faluss-platform'), $result); ?>
        <?php
    }

    /** @param array<string, mixed>|null $result */
    private static function diagnosticSection(
        string $kind,
        string $title,
        string $description,
        string $action,
        string $button,
        ?array $result
    ): void {
        ?>
        <section class="faluss-connector-admin__diagnostic" aria-labelledby="faluss-connector-<?php echo esc_attr($kind); ?>">
            <h3 id="faluss-connector-<?php echo esc_attr($kind); ?>"><?php echo esc_html($title); ?></h3>
            <p><?php echo esc_html($description); ?></p>
            <?php if ($result !== null): ?>
                <dl class="faluss-connector-admin__result">
                    <?php foreach (self::resultLabels($kind, $result) as $label => $value): ?>
                        <dt><?php echo esc_html($label); ?></dt><dd><?php echo esc_html($value); ?></dd>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
                <?php wp_nonce_field($action, 'token_engine_connector_nonce'); ?>
                <?php submit_button($button, 'secondary', 'submit', false); ?>
            </form>
        </section>
        <?php
    }

    /** @param array<string, mixed> $result
     *  @return array<string, string>
     */
    private static function resultLabels(string $kind, array $result): array
    {
        if ($kind === 'core') {
            return [
                __('État', 'faluss-platform') => !empty($result['connected']) ? __('Connecté', 'faluss-platform') : self::coreMessage((string) ($result['code'] ?? '')),
                __('Projet', 'faluss-platform') => (string) ($result['project_key'] ?? '—'),
                __('Diagnostic', 'faluss-platform') => (string) ($result['diagnostic_id'] ?? '—'),
            ];
        }
        if ($kind === 'subject') {
            return [
                __('Session WordPress', 'faluss-platform') => !empty($result['signed_in']) ? __('Connectée', 'faluss-platform') : __('Absente', 'faluss-platform'),
                __('Profil Identity actif', 'faluss-platform') => !empty($result['active_identity_profile']) ? __('Détecté', 'faluss-platform') : __('Absent', 'faluss-platform'),
                __('Sujet Faluss', 'faluss-platform') => !empty($result['subject_available']) ? __('Résolu sans affichage', 'faluss-platform') : __('Indisponible', 'faluss-platform'),
                __('Empreinte', 'faluss-platform') => (string) ($result['subject_fingerprint'] ?? '—'),
            ];
        }
        if ($kind === 'daily_reward') {
            return [
                __('Connexion Core', 'faluss-platform') => !empty($result['core_connected']) ? __('Validée', 'faluss-platform') : __('Non validée', 'faluss-platform'),
                __('Permission reward.claim', 'faluss-platform') => !empty($result['reward_claim_authorized']) ? __('Accordée', 'faluss-platform') : __('Absente', 'faluss-platform'),
                __('Règle daily_reward', 'faluss-platform') => (string) ($result['rule_state'] ?? 'transient_error'),
                __('Portée globale', 'faluss-platform') => !empty($result['global_scope_accepted']) ? __('Validée', 'faluss-platform') : __('Non validée', 'faluss-platform'),
            ];
        }

        return [
            __('Connexion Core', 'faluss-platform') => !empty($result['core_connected']) ? __('Validée', 'faluss-platform') : __('Non validée', 'faluss-platform'),
            __('Permission entitlements.read', 'faluss-platform') => !empty($result['entitlements_read_authorized']) ? __('Accordée', 'faluss-platform') : __('Absente', 'faluss-platform'),
            __('Définitions', 'faluss-platform') => !empty($result['definitions_readable']) ? __('Lisibles', 'faluss-platform') : __('Indisponibles', 'faluss-platform'),
        ];
    }

    private static function guard(string $action): void
    {
        $nonce = isset($_POST['token_engine_connector_nonce'])
            ? sanitize_text_field((string) wp_unslash($_POST['token_engine_connector_nonce']))
            : '';
        if (!current_user_can(self::CAPABILITY) || $nonce === '' || !wp_verify_nonce($nonce, $action)) {
            wp_die('Accès refusé.');
        }
    }

    /** @return array<string, string> */
    private static function tabs(): array
    {
        return [
            'configuration' => __('Configuration', 'faluss-platform'),
            'diagnostic' => __('Diagnostic', 'faluss-platform'),
            'entitlements' => __('Droits', 'faluss-platform'),
        ];
    }

    private static function tab(mixed $value): string
    {
        $value = sanitize_key((string) wp_unslash($value));

        return isset(self::tabs()[$value]) ? $value : 'configuration';
    }

    private static function url(string $tab): string
    {
        return add_query_arg(['page' => self::PAGE, 'tab' => $tab], admin_url('admin.php'));
    }

    private static function redirect(string $tab, string $notice): never
    {
        wp_safe_redirect(add_query_arg(
            'token_engine_connector_notice',
            sanitize_key($notice),
            self::url($tab)
        ));
        exit;
    }

    /** @return array<string, mixed>|null */
    private static function takeResult(string $kind): ?array
    {
        $result = get_transient(self::resultKey($kind));
        if (!is_array($result)) {
            return null;
        }
        delete_transient(self::resultKey($kind));

        return $result;
    }

    private static function resultKey(string $kind): string
    {
        return 'token_engine_connector_' . sanitize_key($kind) . '_' . get_current_user_id();
    }

    private static function coreValidKey(): string
    {
        return 'token_engine_connector_core_valid_' . get_current_user_id();
    }

    private static function notice(): void
    {
        $notice = sanitize_key((string) wp_unslash($_GET['token_engine_connector_notice'] ?? ''));
        if ($notice === '') {
            return;
        }

        $messages = [
            'saved' => __('Configuration enregistrée.', 'faluss-platform'),
            'connector_secret_protection_unavailable' => __('Le stockage protégé du secret est indisponible.', 'faluss-platform'),
            'connector_secret_persistence_failed' => __('Le secret protégé ne peut pas être relu ; la configuration précédente est conservée.', 'faluss-platform'),
            'connector_secret_required' => __('Un secret client est requis.', 'faluss-platform'),
            'connector_core_url_invalid' => __('L’URL HTTPS du Core est invalide.', 'faluss-platform'),
            'connector_client_invalid' => __('L’identifiant client est invalide.', 'faluss-platform'),
            'connector_project_invalid' => __('La clé projet est invalide.', 'faluss-platform'),
            'subject_core_required' => __('Validez d’abord la connexion au Core.', 'faluss-platform'),
        ];
        if (!isset($messages[$notice])) {
            return;
        }
        $class = $notice === 'saved' ? 'notice-success' : 'notice-error';
        echo '<div class="notice ' . esc_attr($class) . '"><p>' . esc_html($messages[$notice]) . '</p></div>';
    }

    private static function coreMessage(string $code): string
    {
        $messages = [
            'connector_core_valid' => __('Connexion validée.', 'faluss-platform'),
            'connector_core_inaccessible' => __('Core inaccessible.', 'faluss-platform'),
            'connector_route_missing' => __('Route Token Engine introuvable.', 'faluss-platform'),
            'connector_protocol_incompatible' => __('Protocole incompatible.', 'faluss-platform'),
            'connector_project_rejected' => __('Projet refusé.', 'faluss-platform'),
            'connector_client_rejected' => __('Client refusé.', 'faluss-platform'),
            'connector_secret_rejected' => __('Secret refusé.', 'faluss-platform'),
            'connector_permission_wallet_read_missing' => __('Permission wallet.read absente.', 'faluss-platform'),
            'connector_token_rejected' => __('Jeton court refusé ou expiré.', 'faluss-platform'),
        ];

        return $messages[$code] ?? __('Connexion non validée.', 'faluss-platform');
    }
}
