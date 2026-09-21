<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** Native administration only; there is no public wallet or frontend surface in this core. */
final class Token_Engine_Admin {
    const PAGE = 'token-engine';
    const CAPABILITY = 'manage_options';

    public static function boot() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
        add_action( 'admin_post_token_engine_save_configuration', array( __CLASS__, 'save_configuration' ) );
        add_action( 'admin_post_token_engine_create_project', array( __CLASS__, 'create_project' ) );
        add_action( 'admin_post_token_engine_update_project', array( __CLASS__, 'update_project' ) );
        add_action( 'admin_post_token_engine_create_rule', array( __CLASS__, 'create_rule' ) );
        add_action( 'admin_post_token_engine_update_rule', array( __CLASS__, 'update_rule' ) );
        add_action( 'admin_post_token_engine_adjust', array( __CLASS__, 'adjust' ) );
        add_action( 'admin_post_token_engine_generate_project_credentials', array( __CLASS__, 'generate_project_credentials' ) );
        add_action( 'admin_post_token_engine_update_project_permissions', array( __CLASS__, 'update_project_permissions' ) );
        add_action( 'admin_post_token_engine_update_project_entitlements_permission', array( __CLASS__, 'update_project_entitlements_permission' ) );
        add_action( 'admin_post_token_engine_create_entitlement_definition', array( __CLASS__, 'create_entitlement_definition' ) );
        add_action( 'admin_post_token_engine_update_entitlement_definition', array( __CLASS__, 'update_entitlement_definition' ) );
        add_action( 'admin_post_token_engine_create_entitlement_grant', array( __CLASS__, 'create_entitlement_grant' ) );
        add_action( 'admin_post_token_engine_revoke_entitlement_grant', array( __CLASS__, 'revoke_entitlement_grant' ) );
    }

    public static function enqueue_assets( $hook = '' ) {
        if ( '' !== $hook && 'toplevel_page_' . self::PAGE !== $hook ) {
            return;
        }
        wp_enqueue_style( 'token-engine-admin', plugins_url( 'assets/css/token-engine-admin.css', TOKEN_ENGINE_FILE ), array(), TOKEN_ENGINE_VERSION );
        wp_enqueue_script( 'token-engine-admin', plugins_url( 'assets/js/token-engine-admin.js', TOKEN_ENGINE_FILE ), array(), TOKEN_ENGINE_VERSION, true );
    }

    public static function menu() {
        add_menu_page( 'Token Engine', 'Token Engine', self::CAPABILITY, self::PAGE, array( __CLASS__, 'page' ), 'dashicons-database', 59 );
    }

    public static function page() {
        if ( ! current_user_can( self::CAPABILITY ) ) {
            return;
        }
        $tab = self::tab( $_GET['tab'] ?? '' );
        ?>
        <div class="wrap token-engine-admin">
            <div class="token-engine-admin__shell">
                <aside class="token-engine-admin__sidebar" aria-label="Navigation Token Engine">
                    <div class="token-engine-admin__brand"><strong>Faluss</strong><span>by Alternative LAB</span></div>
                    <nav class="token-engine-admin__nav" aria-label="Token Engine">
                        <?php foreach ( self::tabs() as $key => $label ) : ?><a class="<?php echo $tab === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>" <?php echo $tab === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?>
                    </nav>
                </aside>
                <section class="token-engine-admin__workspace" aria-labelledby="token-engine-admin-title">
                    <header class="token-engine-admin__header"><p>Token Engine</p><h1 id="token-engine-admin-title"><?php echo esc_html( self::tabs()[ $tab ] ); ?></h1><span>Ledger central et administration des connecteurs.</span></header>
                    <div class="token-engine-admin__panel">
                        <?php self::notice(); ?>
                        <?php if ( 'configuration' === $tab ) { self::configuration_page(); } ?>
                        <?php if ( 'projects' === $tab ) { self::projects_page(); } ?>
                        <?php if ( 'rules' === $tab ) { self::rules_page(); } ?>
                        <?php if ( 'ledger' === $tab ) { self::ledger_page(); } ?>
                        <?php if ( 'adjustment' === $tab ) { self::adjustment_page(); } ?>
                        <?php if ( 'entitlements' === $tab ) { self::entitlements_page(); } ?>
                    </div>
                </section>
            </div>
        </div>
        <?php
    }

    public static function save_configuration() {
        self::guard( 'token_engine_save_configuration' );
        $result = Token_Engine_Service::save_configuration( $_POST );
        self::redirect( 'configuration', is_wp_error( $result ) ? $result->get_error_code() : 'saved' );
    }

    public static function create_project() {
        self::guard( 'token_engine_create_project' );
        $result = Token_Engine_Service::create_project( $_POST );
        self::redirect( 'projects', is_wp_error( $result ) ? $result->get_error_code() : 'project_created' );
    }

    public static function update_project() {
        self::guard( 'token_engine_update_project' );
        $result = Token_Engine_Service::update_project( $_POST['project_id'] ?? 0, $_POST );
        self::redirect( 'projects', is_wp_error( $result ) ? $result->get_error_code() : 'project_saved' );
    }

    public static function update_project_permissions() {
        self::guard( 'token_engine_update_project_permissions' );
        $result = Token_Engine_Connector_Access::update_project_permissions( $_POST['project_id'] ?? 0, $_POST['permissions'] ?? array() );
        self::redirect( 'projects', is_wp_error( $result ) ? $result->get_error_code() : 'connector_permissions_saved' );
    }

    public static function update_project_entitlements_permission() {
        self::guard( 'token_engine_update_project_entitlements_permission' );
        $result = Token_Engine_Connector_Access::update_project_entitlements_permission( $_POST['project_id'] ?? 0, ! empty( $_POST['entitlements_read'] ) );
        self::redirect( 'projects', is_wp_error( $result ) ? $result->get_error_code() : 'connector_permissions_saved' );
    }

    public static function create_entitlement_definition() {
        self::guard( 'token_engine_create_entitlement_definition' );
        $result = Token_Engine_Entitlements::create_definition( $_POST );
        self::redirect( 'entitlements', is_wp_error( $result ) ? $result->get_error_code() : 'entitlement_definition_created' );
    }

    public static function update_entitlement_definition() {
        self::guard( 'token_engine_update_entitlement_definition' );
        $result = Token_Engine_Entitlements::update_definition( $_POST['entitlement_definition_id'] ?? 0, $_POST );
        self::redirect( 'entitlements', is_wp_error( $result ) ? $result->get_error_code() : 'entitlement_definition_saved' );
    }

    public static function create_entitlement_grant() {
        self::guard( 'token_engine_create_entitlement_grant' );
        $result = Token_Engine_Entitlements::create_manual_grant( $_POST );
        self::redirect( 'entitlements', is_wp_error( $result ) ? $result->get_error_code() : 'entitlement_grant_created' );
    }

    public static function revoke_entitlement_grant() {
        self::guard( 'token_engine_revoke_entitlement_grant' );
        $result = Token_Engine_Entitlements::revoke_grant( $_POST['entitlement_grant_id'] ?? 0, $_POST['revoke_reason'] ?? '' );
        self::redirect( 'entitlements', is_wp_error( $result ) ? $result->get_error_code() : 'entitlement_grant_revoked' );
    }

    public static function create_rule() {
        self::guard( 'token_engine_create_rule' );
        $result = Token_Engine_Service::create_rule( $_POST );
        self::redirect( 'rules', is_wp_error( $result ) ? $result->get_error_code() : 'rule_created' );
    }

    public static function update_rule() {
        self::guard( 'token_engine_update_rule' );
        $result = Token_Engine_Service::update_rule( $_POST['rule_id'] ?? 0, $_POST );
        self::redirect( 'rules', is_wp_error( $result ) ? $result->get_error_code() : 'rule_saved' );
    }

    public static function adjust() {
        self::guard( 'token_engine_adjust' );
        $result = Token_Engine_Service::write_transaction( array(
            'transaction_uuid' => wp_unslash( $_POST['operation_uuid'] ?? '' ),
            'idempotency_key' => wp_unslash( $_POST['operation_uuid'] ?? '' ),
            'subject_id' => wp_unslash( $_POST['subject_id'] ?? '' ),
            'project_key' => wp_unslash( $_POST['project_key'] ?? '' ),
            'direction' => wp_unslash( $_POST['direction'] ?? '' ),
            'amount' => wp_unslash( $_POST['amount'] ?? '' ),
            'source_reference' => wp_unslash( $_POST['source_reference'] ?? '' ),
            'metadata' => array( 'manual_adjustment' => true ),
        ) );
        if ( is_wp_error( $result ) ) {
            self::redirect( 'adjustment', $result->get_error_code() );
        }
        set_transient( self::adjustment_transient_key(), array( 'balance' => (int) $result['balance'], 'idempotent' => ! empty( $result['idempotent'] ) ), MINUTE_IN_SECONDS );
        self::redirect( 'adjustment', 'adjusted' );
    }

    public static function generate_project_credentials() {
        self::guard( 'token_engine_generate_project_credentials' );
        $result = Token_Engine_Connector_Access::generate_credentials( $_POST['project_id'] ?? 0 );
        if ( is_wp_error( $result ) ) {
            self::redirect( 'projects', $result->get_error_code() );
        }
        self::enqueue_assets();
        require_once ABSPATH . 'wp-admin/admin-header.php';
        ?>
        <div class="wrap token-engine-admin"><div class="token-engine-admin__shell"><aside class="token-engine-admin__sidebar" aria-label="Navigation Token Engine"><div class="token-engine-admin__brand"><strong>Faluss</strong><span>by Alternative LAB</span></div><nav class="token-engine-admin__nav" aria-label="Token Engine"><?php foreach ( self::tabs() as $key => $label ) : ?><a class="<?php echo 'projects' === $key ? 'is-active' : ''; ?>" href="<?php echo esc_url( self::url( $key ) ); ?>" <?php echo 'projects' === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php endforeach; ?></nav></aside><section class="token-engine-admin__workspace" aria-labelledby="token-engine-secret-title"><header class="token-engine-admin__header"><p>Token Engine</p><h1 id="token-engine-secret-title">Identifiants connecteur</h1><span>Le secret ci-dessous ne sera plus affiché après cette page.</span></header><div class="token-engine-admin__panel"><div class="token-engine-admin__secret"><p><strong>Projet :</strong> <?php echo esc_html( $result['project_key'] ); ?></p><p><strong>Identifiant client :</strong> <code><?php echo esc_html( $result['client_id'] ); ?></code></p><p><strong>Permission :</strong> <?php echo in_array( Token_Engine_Connector_Access::PERMISSION_WALLET_READ, $result['permissions'], true ) ? 'wallet.read accordée' : 'wallet.read non accordée'; ?></p><p><strong>Permission :</strong> <?php echo in_array( Token_Engine_Connector_Access::PERMISSION_REWARD_CLAIM, $result['permissions'], true ) ? 'reward.claim accordée' : 'reward.claim non accordée'; ?></p><label for="token-engine-one-time-secret"><strong>Secret confidentiel — copiez-le maintenant</strong></label><input id="token-engine-one-time-secret" class="large-text code" readonly value="<?php echo esc_attr( $result['secret'] ); ?>"><p class="description">Seule une empreinte vérifiable est conservée. Régénérer le secret invalide immédiatement les jetons précédents, sans modifier les permissions du projet.</p><p><a class="button button-primary" href="<?php echo esc_url( self::url( 'projects' ) ); ?>">Retour aux projets</a></p></div></div></section></div></div>
        <?php
        require_once ABSPATH . 'wp-admin/admin-footer.php';
        exit;
    }

    private static function configuration_page() {
        $settings = Token_Engine_Service::configuration();
        ?>
        <h2>Configuration</h2>
        <p>Aucune unité n’est active tant que ces quatre champs ne sont pas valides. Le code devient immuable après la première écriture.</p>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="token_engine_save_configuration">
            <?php wp_nonce_field( 'token_engine_save_configuration', 'token_engine_nonce' ); ?>
            <table class="form-table" role="presentation"><tbody>
                <tr><th scope="row"><label for="token-engine-unit-code">Code de l’unité</label></th><td><input id="token-engine-unit-code" name="unit_code" class="regular-text" maxlength="16" value="<?php echo esc_attr( $settings['unit_code'] ); ?>" <?php disabled( Token_Engine_Service::has_transactions() ); ?>><p class="description">Lettres capitales, chiffres, tiret ou souligné.</p></td></tr>
                <tr><th scope="row"><label for="token-engine-unit-singular">Libellé singulier</label></th><td><input id="token-engine-unit-singular" name="unit_singular" class="regular-text" maxlength="80" value="<?php echo esc_attr( $settings['unit_singular'] ); ?>"></td></tr>
                <tr><th scope="row"><label for="token-engine-unit-plural">Libellé pluriel</label></th><td><input id="token-engine-unit-plural" name="unit_plural" class="regular-text" maxlength="80" value="<?php echo esc_attr( $settings['unit_plural'] ); ?>"></td></tr>
                <tr><th scope="row"><label for="token-engine-timezone">Fuseau horaire de référence</label></th><td><select id="token-engine-timezone" name="reference_timezone"><option value="">Choisir un fuseau</option><?php foreach ( timezone_identifiers_list() as $timezone ) : ?><option value="<?php echo esc_attr( $timezone ); ?>" <?php selected( $settings['reference_timezone'], $timezone ); ?>><?php echo esc_html( $timezone ); ?></option><?php endforeach; ?></select></td></tr>
            </tbody></table>
            <?php submit_button( 'Enregistrer la configuration' ); ?>
        </form>
        <?php
    }

    private static function projects_page() {
        global $wpdb;
        $projects = (array) $wpdb->get_results( 'SELECT * FROM ' . Token_Engine_Schema::projects_table() . ' ORDER BY name ASC, id ASC', ARRAY_A );
        ?>
        <h2>Projets</h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="token_engine_create_project"><?php wp_nonce_field( 'token_engine_create_project', 'token_engine_nonce' ); ?>
            <table class="form-table" role="presentation"><tbody><tr><th><label for="token-engine-project-key">Clé stable</label></th><td><input id="token-engine-project-key" name="project_key" required maxlength="64" pattern="[a-z0-9][a-z0-9_-]{1,63}"></td></tr><tr><th><label for="token-engine-project-name">Nom</label></th><td><input id="token-engine-project-name" name="name" required maxlength="120" class="regular-text"></td></tr><tr><th>État</th><td><label><input name="active" type="checkbox" value="1" checked> Actif</label></td></tr></tbody></table><?php submit_button( 'Créer le projet', 'secondary' ); ?>
        </form>
        <table class="widefat striped"><thead><tr><th>Projet</th><th>Administration et connecteur</th></tr></thead><tbody>
        <?php foreach ( $projects as $project ) : $connection = Token_Engine_Connector_Access::project_connection_status( $project ); ?><tr><td><code><?php echo esc_html( $project['project_key'] ); ?></code></td><td><div class="token-engine-project-row"><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="token-engine-project-row__settings"><input type="hidden" name="action" value="token_engine_update_project"><input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>"><?php wp_nonce_field( 'token_engine_update_project', 'token_engine_nonce' ); ?><label>Nom <input name="name" maxlength="120" value="<?php echo esc_attr( $project['name'] ); ?>"></label><label><input name="active" type="checkbox" value="1" <?php checked( ! empty( $project['active'] ) ); ?>> Actif</label><button class="button" type="submit">Enregistrer</button></form><div class="token-engine-project-row__connector"><div class="token-engine-project-row__connection"><label for="token-engine-core-url-<?php echo (int) $project['id']; ?>">URL du site Core</label><div class="token-engine-project-row__copy"><input id="token-engine-core-url-<?php echo (int) $project['id']; ?>" class="regular-text code" readonly value="<?php echo esc_attr( Token_Engine_Connector_Access::core_site_url() ); ?>"><button class="button token-engine-copy" type="button" data-copy-target="token-engine-core-url-<?php echo (int) $project['id']; ?>">Copier</button></div><span>À renseigner telle quelle dans le Connector ; la forme REST est détectée automatiquement.</span><span>Identifiant client : <?php echo ! empty( $project['connector_client_id'] ) ? '<code>' . esc_html( $project['connector_client_id'] ) . '</code>' : 'à générer'; ?></span><span>État : <?php echo esc_html( self::connector_status_label( $connection['code'] ) ); ?></span></div><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="token-engine-project-row__permissions"><input type="hidden" name="action" value="token_engine_update_project_permissions"><input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>"><?php wp_nonce_field( 'token_engine_update_project_permissions', 'token_engine_nonce' ); ?><label><input type="checkbox" name="permissions[]" value="wallet.read" <?php checked( in_array( Token_Engine_Connector_Access::PERMISSION_WALLET_READ, $connection['permissions'], true ) ); ?>> Autoriser <code>wallet.read</code></label><label><input type="checkbox" name="permissions[]" value="reward.claim" <?php checked( in_array( Token_Engine_Connector_Access::PERMISSION_REWARD_CLAIM, $connection['permissions'], true ) ); ?>> Autoriser <code>reward.claim</code></label><button class="button" type="submit">Enregistrer les permissions</button></form><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="token_engine_generate_project_credentials"><input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>"><?php wp_nonce_field( 'token_engine_generate_project_credentials', 'token_engine_nonce' ); ?><button class="button" type="submit" <?php disabled( empty( $project['active'] ) ); ?>><?php echo ! empty( $project['connector_secret_hash'] ) ? 'Régénérer le secret' : 'Générer les identifiants'; ?></button></form></div></div></td></tr><?php endforeach; ?>
        <?php if ( ! $projects ) : ?><tr><td colspan="4">Aucun projet.</td></tr><?php endif; ?></tbody></table>
        <section class="token-engine-entitlement-permissions" aria-labelledby="token-engine-entitlement-permissions-title">
            <h3 id="token-engine-entitlement-permissions-title">Lecture des droits par connecteur</h3><p class="description">Autorisez <code>entitlements.read</code> seulement pour les surfaces qui doivent vérifier des thèmes verrouillables.</p>
            <?php foreach ( $projects as $project ) : $connection = Token_Engine_Connector_Access::project_connection_status( $project ); ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="token_engine_update_project_entitlements_permission"><input type="hidden" name="project_id" value="<?php echo (int) $project['id']; ?>"><?php wp_nonce_field( 'token_engine_update_project_entitlements_permission', 'token_engine_nonce' ); ?>
                    <label><input type="checkbox" name="entitlements_read" value="1" <?php checked( in_array( Token_Engine_Connector_Access::PERMISSION_ENTITLEMENTS_READ, $connection['permissions'], true ) ); ?>> Autoriser <code>entitlements.read</code></label><button class="button" type="submit"><?php echo esc_html( $project['name'] ); ?> — enregistrer</button>
                </form>
            <?php endforeach; ?>
        </section>
        <?php
    }

    private static function rules_page() {
        $projects = Token_Engine_Service::active_projects();
        $rules = Token_Engine_Service::rules();
        ?>
        <h2>Règles</h2><p>Les règles sont administrées ici, mais TE-01 ne les exécute jamais automatiquement.</p>
        <?php self::rule_form( array(), $projects, 'token_engine_create_rule', 'Créer la règle' ); ?>
        <table class="widefat striped"><thead><tr><th>Clé</th><th>Portée</th><th>Déclencheur</th><th>Périodicité</th><th>Montant</th><th>État</th><th>Enregistrer</th></tr></thead><tbody>
        <?php foreach ( $rules as $rule ) : ?><tr><td colspan="7"><?php self::rule_form( $rule, $projects, 'token_engine_update_rule', 'Enregistrer la règle' ); ?></td></tr><?php endforeach; ?>
        <?php if ( ! $rules ) : ?><tr><td colspan="7">Aucune règle.</td></tr><?php endif; ?></tbody></table>
        <?php
    }

    private static function rule_form( $rule, $projects, $action, $submit ) {
        $is_update = 'token_engine_update_rule' === $action;
        $scope = $rule['scope'] ?? 'global';
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="token-engine-rule-form" data-token-engine-rule-form style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">
            <input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>"><?php if ( $is_update ) : ?><input type="hidden" name="rule_id" value="<?php echo (int) $rule['id']; ?>"><?php endif; ?><?php wp_nonce_field( $action, 'token_engine_nonce' ); ?>
            <label>Clé<br><input name="rule_key" maxlength="96" required value="<?php echo esc_attr( $rule['rule_key'] ?? '' ); ?>" <?php echo $is_update ? 'readonly' : ''; ?>></label>
            <label>Portée<br><select name="scope" data-token-engine-rule-scope><option value="global" <?php selected( $scope, 'global' ); ?>>Globale</option><option value="project" <?php selected( $scope, 'project' ); ?>>Projet</option></select></label>
            <p class="description" data-token-engine-global-scope <?php echo 'global' === $scope ? '' : 'hidden'; ?>>Toutes les surfaces autorisées</p>
            <label data-token-engine-rule-project <?php echo 'global' === $scope ? 'hidden' : ''; ?>>Projet<br><select name="project_id" data-token-engine-rule-project-select <?php echo 'global' === $scope ? 'disabled' : 'required'; ?>><option value="0">Choisir un projet</option><?php foreach ( $projects as $project ) : ?><option value="<?php echo (int) $project['id']; ?>" <?php selected( (int) ( $rule['project_id'] ?? 0 ), (int) $project['id'] ); ?>><?php echo esc_html( $project['name'] ); ?></option><?php endforeach; ?></select></label>
            <label>Déclencheur<br><select name="trigger_type"><option value="event" <?php selected( $rule['trigger_type'] ?? 'event', 'event' ); ?>>Événement</option><option value="claim" <?php selected( $rule['trigger_type'] ?? '', 'claim' ); ?>>Réclamation</option></select></label>
            <label>Périodicité<br><select name="periodicity"><option value="none" <?php selected( $rule['periodicity'] ?? 'none', 'none' ); ?>>Aucune</option><option value="once" <?php selected( $rule['periodicity'] ?? '', 'once' ); ?>>Une fois</option><option value="daily" <?php selected( $rule['periodicity'] ?? '', 'daily' ); ?>>Quotidienne</option><option value="cooldown" <?php selected( $rule['periodicity'] ?? '', 'cooldown' ); ?>>Cooldown</option></select></label>
            <label>Cooldown (s)<br><input name="cooldown_seconds" type="number" min="1" value="<?php echo (int) ( $rule['cooldown_seconds'] ?? 0 ); ?>"></label>
            <label>Montant<br><input name="amount" type="number" min="1" required value="<?php echo (int) ( $rule['amount'] ?? 1 ); ?>"></label>
            <label><input name="active" type="checkbox" value="1" <?php checked( ! isset( $rule['active'] ) || ! empty( $rule['active'] ) ); ?>> Active</label><button class="button" type="submit"><?php echo esc_html( $submit ); ?></button>
        </form>
        <?php
    }

    private static function ledger_page() {
        $entries = Token_Engine_Service::ledger_entries();
        ?><h2>Ledger</h2><table class="widefat striped"><thead><tr><th>Date</th><th>UUID</th><th>Sujet</th><th>Projet</th><th>Règle</th><th>Sens</th><th>Montant</th><th>Référence</th></tr></thead><tbody><?php foreach ( $entries as $entry ) : ?><tr><td><?php echo esc_html( $entry['created_at'] ); ?></td><td><code><?php echo esc_html( $entry['transaction_uuid'] ); ?></code></td><td><?php echo esc_html( $entry['subject_id'] ); ?></td><td><?php echo esc_html( $entry['project_key'] ); ?></td><td><?php echo esc_html( $entry['rule_key'] ?: '—' ); ?></td><td><?php echo esc_html( $entry['direction'] ); ?></td><td><?php echo (int) $entry['amount']; ?></td><td><?php echo esc_html( $entry['source_reference'] ?: '—' ); ?></td></tr><?php endforeach; ?><?php if ( ! $entries ) : ?><tr><td colspan="8">Aucune transaction.</td></tr><?php endif; ?></tbody></table><?php
    }

    private static function adjustment_page() {
        $projects = Token_Engine_Service::active_projects();
        $result = get_transient( self::adjustment_transient_key() );
        if ( is_array( $result ) ) { delete_transient( self::adjustment_transient_key() ); }
        ?><h2>Ajustement manuel</h2><p>Chaque soumission porte un UUID d’opération : un renvoi du même formulaire retrouve l’écriture existante.</p><?php if ( is_array( $result ) ) : ?><div class="notice notice-success"><p><?php echo ! empty( $result['idempotent'] ) ? 'Opération déjà inscrite.' : 'Ajustement inscrit.'; ?> Solde projeté : <strong><?php echo (int) $result['balance']; ?></strong></p></div><?php endif; ?>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="token_engine_adjust"><input type="hidden" name="operation_uuid" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'token_engine_adjust', 'token_engine_nonce' ); ?>
        <table class="form-table" role="presentation"><tbody><tr><th><label for="token-engine-adjust-project">Projet</label></th><td><select id="token-engine-adjust-project" name="project_key" required><option value="">Choisir un projet</option><?php foreach ( $projects as $project ) : ?><option value="<?php echo esc_attr( $project['project_key'] ); ?>"><?php echo esc_html( $project['name'] ); ?></option><?php endforeach; ?></select></td></tr><tr><th><label for="token-engine-adjust-subject">Subject ID</label></th><td><input id="token-engine-adjust-subject" name="subject_id" maxlength="191" required class="regular-text"></td></tr><tr><th>Sens</th><td><label><input name="direction" type="radio" value="credit" checked> Crédit</label> <label><input name="direction" type="radio" value="debit"> Débit</label></td></tr><tr><th><label for="token-engine-adjust-amount">Montant</label></th><td><input id="token-engine-adjust-amount" name="amount" type="number" min="1" required></td></tr><tr><th><label for="token-engine-adjust-reference">Motif / référence</label></th><td><input id="token-engine-adjust-reference" name="source_reference" maxlength="191" class="regular-text"></td></tr></tbody></table><?php submit_button( 'Inscrire l’ajustement' ); ?></form><?php
    }

    /** EC-02 has its own generic rights surface; grants never touch the ledger. */
    private static function entitlements_page() {
        $projects = Token_Engine_Service::active_projects();
        $definitions = Token_Engine_Entitlements::definitions();
        $history = Token_Engine_Entitlements::grant_history();
        ?>
        <h2>Droits</h2>
        <p>Les droits sont centralisés ici. Ils ne représentent ni unité, ni prix, ni abonnement : chaque attribution vise un sujet opaque et une surface autorisée.</p>
        <section class="token-engine-entitlements" aria-labelledby="token-engine-entitlement-definition-title">
            <h3 id="token-engine-entitlement-definition-title">Créer un droit</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="token_engine_create_entitlement_definition"><?php wp_nonce_field( 'token_engine_create_entitlement_definition', 'token_engine_nonce' ); ?>
                <table class="form-table" role="presentation"><tbody>
                    <tr><th><label for="token-engine-entitlement-code">Code stable</label></th><td><input id="token-engine-entitlement-code" name="entitlement_code" required maxlength="120" pattern="[a-z0-9][a-z0-9_.-]{1,119}"><p class="description">Lettres minuscules, chiffres, point, tiret ou souligné.</p></td></tr>
                    <tr><th><label for="token-engine-entitlement-label">Libellé administrateur</label></th><td><input id="token-engine-entitlement-label" name="label" required maxlength="120" class="regular-text"></td></tr>
                    <tr><th><label for="token-engine-entitlement-project">Projet / surface</label></th><td><select id="token-engine-entitlement-project" name="project_key" required><option value="">Choisir un projet</option><?php foreach ( $projects as $project ) : ?><option value="<?php echo esc_attr( $project['project_key'] ); ?>"><?php echo esc_html( $project['name'] . ' (' . $project['project_key'] . ')' ); ?></option><?php endforeach; ?></select></td></tr>
                    <tr><th>Type</th><td><input type="hidden" name="entitlement_type" value="theme"><span>Thème</span></td></tr>
                    <tr><th>État</th><td><label><input type="checkbox" name="active" value="1" checked> Actif</label></td></tr>
                </tbody></table><?php submit_button( 'Créer le droit', 'secondary' ); ?>
            </form>
        </section>
        <section aria-labelledby="token-engine-entitlement-list-title"><h3 id="token-engine-entitlement-list-title">Définitions</h3>
            <table class="widefat striped"><thead><tr><th>Droit</th><th>Surface</th><th>Type</th><th>État</th><th>Modifier</th></tr></thead><tbody>
            <?php foreach ( $definitions as $definition ) : ?><tr><td><code><?php echo esc_html( $definition['entitlement_code'] ); ?></code><br><?php echo esc_html( $definition['label'] ); ?></td><td><code><?php echo esc_html( $definition['project_key'] ); ?></code></td><td><?php echo esc_html( $definition['entitlement_type'] ); ?></td><td><?php echo 'active' === Token_Engine_Entitlements::definition_state( $definition ) ? 'Actif' : 'Inactif'; ?></td><td><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="token_engine_update_entitlement_definition"><input type="hidden" name="entitlement_definition_id" value="<?php echo (int) $definition['id']; ?>"><input type="hidden" name="entitlement_type" value="theme"><?php wp_nonce_field( 'token_engine_update_entitlement_definition', 'token_engine_nonce' ); ?><label>Libellé <input name="label" maxlength="120" value="<?php echo esc_attr( $definition['label'] ); ?>"></label><label>Projet <select name="project_key"><?php foreach ( $projects as $project ) : ?><option value="<?php echo esc_attr( $project['project_key'] ); ?>" <?php selected( $definition['project_key'], $project['project_key'] ); ?>><?php echo esc_html( $project['name'] ); ?></option><?php endforeach; ?></select></label><label><input name="active" type="checkbox" value="1" <?php checked( ! empty( $definition['active'] ) ); ?>> Actif</label><button class="button" type="submit">Enregistrer</button></form></td></tr><?php endforeach; ?>
            <?php if ( ! $definitions ) : ?><tr><td colspan="5">Aucun droit défini.</td></tr><?php endif; ?></tbody></table>
        </section>
        <section aria-labelledby="token-engine-entitlement-grant-title"><h3 id="token-engine-entitlement-grant-title">Attribuer manuellement</h3>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="token_engine_create_entitlement_grant"><input type="hidden" name="operation_reference" value="<?php echo esc_attr( wp_generate_uuid4() ); ?>"><?php wp_nonce_field( 'token_engine_create_entitlement_grant', 'token_engine_nonce' ); ?>
                <table class="form-table" role="presentation"><tbody><tr><th><label for="token-engine-entitlement-subject">Subject ID</label></th><td><input id="token-engine-entitlement-subject" name="subject_id" required maxlength="191" class="regular-text"></td></tr><tr><th><label for="token-engine-entitlement-definition">Droit</label></th><td><select id="token-engine-entitlement-definition" name="entitlement_code" required><option value="">Choisir un droit actif</option><?php foreach ( $definitions as $definition ) : if ( empty( $definition['active'] ) ) { continue; } ?><option value="<?php echo esc_attr( $definition['entitlement_code'] ); ?>"><?php echo esc_html( $definition['label'] ); ?></option><?php endforeach; ?></select></td></tr><tr><th><label for="token-engine-entitlement-start">Début</label></th><td><input id="token-engine-entitlement-start" name="starts_at" type="datetime-local" value="<?php echo esc_attr( wp_date( 'Y-m-d\TH:i' ) ); ?>"></td></tr><tr><th><label for="token-engine-entitlement-end">Fin facultative</label></th><td><input id="token-engine-entitlement-end" name="ends_at" type="datetime-local"></td></tr></tbody></table><?php submit_button( 'Attribuer le droit' ); ?>
            </form>
        </section>
        <section aria-labelledby="token-engine-entitlement-history-title"><h3 id="token-engine-entitlement-history-title">Historique minimal</h3><table class="widefat striped"><thead><tr><th>Date</th><th>Subject ID</th><th>Droit</th><th>État</th><th>Source</th><th>Action</th></tr></thead><tbody>
            <?php foreach ( $history as $grant ) : ?><tr><td><?php echo esc_html( $grant['created_at'] ); ?></td><td><code><?php echo esc_html( $grant['subject_id'] ); ?></code></td><td><?php echo esc_html( $grant['label'] ); ?></td><td><?php echo esc_html( Token_Engine_Entitlements::grant_state( $grant ) ); ?></td><td><?php echo esc_html( $grant['source'] ); ?></td><td><?php if ( 'active' === Token_Engine_Entitlements::grant_state( $grant ) || 'scheduled' === Token_Engine_Entitlements::grant_state( $grant ) ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="token_engine_revoke_entitlement_grant"><input type="hidden" name="entitlement_grant_id" value="<?php echo (int) $grant['id']; ?>"><?php wp_nonce_field( 'token_engine_revoke_entitlement_grant', 'token_engine_nonce' ); ?><label class="screen-reader-text">Motif de révocation</label><input name="revoke_reason" maxlength="191" placeholder="Motif facultatif"><button class="button-link-delete" type="submit">Révoquer</button></form><?php else : ?>—<?php endif; ?></td></tr><?php endforeach; ?>
            <?php if ( ! $history ) : ?><tr><td colspan="6">Aucune attribution.</td></tr><?php endif; ?></tbody></table></section>
        <?php
    }

    private static function guard( $action ) {
        if ( ! current_user_can( self::CAPABILITY ) || ! isset( $_POST['token_engine_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['token_engine_nonce'] ) ), $action ) ) {
            wp_die( 'Accès refusé.' );
        }
    }

    private static function connector_status_label( $status ) {
        $labels = array(
            'connector_ready' => 'Prêt : wallet.read est autorisée.',
            'connector_project_inactive' => 'Projet inactif.',
            'connector_credentials_missing' => 'Identifiants à générer.',
            'connector_permission_wallet_read_missing' => 'wallet.read est révoquée ou absente.',
        );
        return $labels[ $status ] ?? 'État indisponible.';
    }
    private static function tabs() { return array( 'configuration' => 'Configuration', 'projects' => 'Projets', 'rules' => 'Règles', 'ledger' => 'Ledger', 'adjustment' => 'Ajustement manuel', 'entitlements' => 'Droits' ); }
    private static function tab( $value ) { $value = sanitize_key( wp_unslash( $value ) ); return isset( self::tabs()[ $value ] ) ? $value : 'configuration'; }
    private static function url( $tab ) { return add_query_arg( array( 'page' => self::PAGE, 'tab' => $tab ), admin_url( 'admin.php' ) ); }
    private static function redirect( $tab, $notice ) { wp_safe_redirect( add_query_arg( 'token_engine_notice', sanitize_key( $notice ), self::url( $tab ) ) ); exit; }
    private static function adjustment_transient_key() { return 'token_engine_adjustment_' . get_current_user_id(); }
    private static function notice() { $notice = sanitize_key( wp_unslash( $_GET['token_engine_notice'] ?? '' ) ); $messages = array( 'saved' => 'Configuration enregistrée.', 'project_created' => 'Projet créé.', 'project_saved' => 'Projet enregistré.', 'connector_permissions_saved' => 'Permission connecteur enregistrée.', 'rule_created' => 'Règle créée.', 'rule_saved' => 'Règle enregistrée.', 'entitlement_definition_created' => 'Droit créé.', 'entitlement_definition_saved' => 'Droit enregistré.', 'entitlement_grant_created' => 'Droit attribué.', 'entitlement_grant_revoked' => 'Droit révoqué.' ); if ( isset( $messages[ $notice ] ) ) { echo '<div class="notice notice-success"><p>' . esc_html( $messages[ $notice ] ) . '</p></div>'; } elseif ( '' !== $notice && 'adjusted' !== $notice ) { echo '<div class="notice notice-error"><p>' . esc_html( 'L’opération ne peut pas être enregistrée : ' . $notice ) . '</p></div>'; } }
}
