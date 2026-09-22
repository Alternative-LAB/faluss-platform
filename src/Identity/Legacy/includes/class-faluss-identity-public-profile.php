<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/** FI-03 public profile data, member editing and root-level public routing. */
final class Faluss_Identity_Public_Profile {

    const QUERY_VAR = 'faluss_public_profile';
    const STYLE_HANDLE = 'faluss-identity-public-profile';
    const MAX_LINKS = 8;
    const OPTION_TEMPLATE_ID = 'faluss_identity_public_profile_template_id';

    public static function register() {
        add_shortcode( 'faluss_identity_profile_editor', array( __CLASS__, 'editor_shortcode' ) );
        add_shortcode( 'faluss_identity_public_profile', array( __CLASS__, 'public_shortcode' ) );
        add_action( 'admin_post_faluss_identity_save_public_profile', array( __CLASS__, 'handle_save' ) );
        add_action( 'admin_post_nopriv_faluss_identity_save_public_profile', array( __CLASS__, 'handle_save_unauthenticated' ) );
        add_action( 'init', array( __CLASS__, 'register_rewrite_rule' ), 20 );
        add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
        add_action( 'parse_request', array( __CLASS__, 'protect_wordpress_routes' ), 5 );
        add_action( 'template_redirect', array( __CLASS__, 'render_routed_profile' ), 0 );
        if ( is_admin() ) {
            add_action( 'admin_menu', array( __CLASS__, 'register_template_settings_page' ) );
            add_action( 'admin_init', array( __CLASS__, 'register_template_setting' ) );
        }
    }

    public static function register_assets() {
        wp_register_style(
            self::STYLE_HANDLE,
            plugins_url( 'assets/css/faluss-identity-public-profile.css', FALUSS_IDENTITY_FILE ),
            array(),
            FALUSS_IDENTITY_VERSION
        );
    }

    public static function register_rewrite_rule() {
        // Static exclusions protect core endpoints before the dynamic root slug.
        add_rewrite_rule( '^(?!(?:wp-admin|wp-json|wp-login\\.php|login|logout|commencer|mon-faluss|mes-decouvertes|list|oauth|api|assets|wp-content|wp-includes|wp-cron\\.php|xmlrpc\\.php|feed|search|author|category|tag|embed|index\\.php)(?:/|$))([a-z0-9][a-z0-9-]{1,39})/?$', 'index.php?' . self::QUERY_VAR . '=$matches[1]', 'top' );
    }

    public static function register_query_var( $query_vars ) {
        $query_vars[] = self::QUERY_VAR;
        return $query_vars;
    }

    public static function editor_shortcode( $attributes = array() ) {
        return self::render_editor( (array) $attributes );
    }

    public static function public_shortcode( $attributes = array() ) {
        $attributes = shortcode_atts( array( 'identifier' => '' ), (array) $attributes, 'faluss_identity_public_profile' );
        $slug = self::resolve_public_slug( $attributes['identifier'] );
        return '' === $slug ? '' : self::render_public_profile( $slug );
    }

    public static function register_template_settings_page() {
        add_options_page( __( 'Profil public Faluss', 'faluss-identity' ), __( 'Profil public Faluss', 'faluss-identity' ), 'manage_options', 'faluss-identity-public-profile', array( __CLASS__, 'render_template_settings_page' ) );
    }

    public static function register_template_setting() {
        register_setting( 'faluss_identity_public_profile', self::OPTION_TEMPLATE_ID, array( 'type' => 'integer', 'sanitize_callback' => array( __CLASS__, 'sanitize_template_id' ), 'default' => 0 ) );
    }

    public static function sanitize_template_id( $template_id ) {
        $template_id = (int) $template_id;
        return self::is_valid_elementor_template( $template_id ) ? $template_id : 0;
    }

    public static function render_template_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        $selected = (int) get_option( self::OPTION_TEMPLATE_ID, 0 );
        $templates = self::get_elementor_templates();
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Profil public Faluss', 'faluss-identity' ); ?></h1>
            <p><?php esc_html_e( 'Choisissez une page Elementor publiée comme modèle pour les profils à la racine. Le widget « Profil public Faluss » sans identifiant affichera le profil de l’URL. Sans modèle valide ou sans Elementor, le rendu autonome reste actif.', 'faluss-identity' ); ?></p>
            <form method="post" action="options.php">
                <?php settings_fields( 'faluss_identity_public_profile' ); ?>
                <table class="form-table" role="presentation"><tr><th scope="row"><label for="faluss-identity-public-profile-template"><?php esc_html_e( 'Modèle Elementor', 'faluss-identity' ); ?></label></th><td>
                    <select id="faluss-identity-public-profile-template" name="<?php echo esc_attr( self::OPTION_TEMPLATE_ID ); ?>">
                        <option value="0"><?php esc_html_e( 'Rendu autonome', 'faluss-identity' ); ?></option>
                        <?php foreach ( $templates as $template ) : ?><option value="<?php echo (int) $template->ID; ?>" <?php selected( $selected, $template->ID ); ?>><?php echo esc_html( $template->post_title ); ?></option><?php endforeach; ?>
                    </select>
                </td></tr></table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public static function handle_save_unauthenticated() {
        wp_safe_redirect( home_url( '/' ) );
        exit;
    }

    public static function handle_save() {
        if ( ! is_user_logged_in() || ! isset( $_POST['faluss_identity_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['faluss_identity_profile_nonce'] ) ), 'faluss_identity_save_public_profile' ) ) {
            self::redirect_editor( 'invalid' );
        }

        $faluss_id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $faluss_id ) {
            self::redirect_editor( 'invalid' );
        }

        $result = self::save_profile( $faluss_id, $_POST, $_FILES );
        self::redirect_editor( $result );
    }

    public static function protect_wordpress_routes( $wp ) {
        if ( ! is_object( $wp ) || empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
            return;
        }
        $slug = self::normalize_slug( (string) $wp->query_vars[ self::QUERY_VAR ] );
        $wordpress_post = '' === $slug ? null : self::existing_wordpress_post( $slug );
        if ( '' === $slug || self::is_reserved_slug( $slug ) || $wordpress_post instanceof WP_Post ) {
            unset( $wp->query_vars[ self::QUERY_VAR ] );
            if ( '' !== $slug ) {
                if ( $wordpress_post instanceof WP_Post && 'page' !== $wordpress_post->post_type ) {
                    $wp->query_vars['name'] = $slug;
                    $wp->query_vars['post_type'] = $wordpress_post->post_type;
                } else {
                    $wp->query_vars['pagename'] = $slug;
                }
            }
            return;
        }
        if ( null === self::find_published_by_slug( $slug ) ) {
            unset( $wp->query_vars[ self::QUERY_VAR ] );
            $wp->query_vars['pagename'] = $slug;
        }
    }

    public static function render_routed_profile() {
        $slug = self::normalize_slug( (string) get_query_var( self::QUERY_VAR ) );
        if ( '' === $slug ) {
            return;
        }
        $profile = self::find_published_by_slug( $slug );
        if ( null === $profile ) {
            return;
        }

        global $wp_query;
        if ( $wp_query instanceof WP_Query ) {
            $wp_query->is_404 = false;
            $wp_query->is_singular = true;
        }
        status_header( 200 );
        nocache_headers();
        self::enqueue_style();
        self::render_public_shell( $profile );
        exit;
    }

    private static function render_public_shell( $profile ) {
        show_admin_bar( false );
        ?>
        <!doctype html>
        <html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover"><?php wp_head(); ?></head>
        <body <?php body_class( 'faluss-identity-public-shell faluss-identity-public-route' ); ?>><?php wp_body_open(); ?>
        <?php if ( ! self::render_elementor_template( self::get_template_id() ) ) : ?>
            <main class="faluss-identity-profile-page"><?php echo self::render_profile_markup( $profile ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- safe server-rendered fallback. ?></main>
        <?php endif; ?>
        <div class="faluss-identity-public-header-layer"><?php self::render_elementor_header(); ?></div>
        <?php wp_footer(); ?></body></html>
        <?php
    }

    /**
     * The route shell intentionally avoids the theme chrome, but an Elementor
     * Theme Builder header remains an explicit, designer-owned location.
     */
    private static function render_elementor_header() {
        if ( function_exists( 'elementor_theme_do_location' ) ) {
            elementor_theme_do_location( 'header' );
        }
    }

    /** @return string */
    public static function render_editor( $settings = array() ) {
        self::enqueue_style();
        if ( ! is_user_logged_in() ) {
            return '<p class="faluss-identity-profile-notice">' . esc_html__( 'Connectez-vous pour administrer votre profil Faluss.', 'faluss-identity' ) . '</p>';
        }
        $faluss_id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $faluss_id ) {
            return '<p class="faluss-identity-profile-notice">' . esc_html__( 'Votre identité Faluss doit être vérifiée avant de créer un profil public.', 'faluss-identity' ) . '</p>';
        }
        $profile = self::find_by_faluss_id( $faluss_id );
        $defaults = array( 'heading' => __( 'Mon profil Faluss', 'faluss-identity' ), 'intro' => __( 'Choisissez les informations visibles sur faluss.me.', 'faluss-identity' ) );
        $settings = wp_parse_args( $settings, $defaults );
        $links = null === $profile ? array() : $profile['links'];
        while ( count( $links ) < self::MAX_LINKS ) {
            $links[] = array( 'label' => '', 'url' => '', 'position' => count( $links ) + 1 );
        }

        ob_start();
        ?>
        <section class="faluss-identity-profile-editor">
            <div class="faluss-identity-profile-editor__card">
                <h2><?php echo esc_html( $settings['heading'] ); ?></h2>
                <p class="faluss-identity-profile__intro"><?php echo esc_html( $settings['intro'] ); ?></p>
                <?php self::render_editor_notice(); ?>
                <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="faluss_identity_save_public_profile">
                    <?php wp_nonce_field( 'faluss_identity_save_public_profile', 'faluss_identity_profile_nonce' ); ?>
                    <label for="faluss-public-identifier"><?php esc_html_e( 'Identifiant public', 'faluss-identity' ); ?></label>
                    <input id="faluss-public-identifier" name="public_slug" type="text" value="<?php echo esc_attr( null === $profile ? '' : $profile['public_slug'] ); ?>" pattern="[a-z0-9][a-z0-9-]{1,39}" maxlength="40" <?php echo null !== $profile ? 'readonly' : ''; ?> required>
                    <p class="faluss-identity-profile__hint"><?php esc_html_e( 'Il ne peut plus être modifié après sa création.', 'faluss-identity' ); ?></p>
                    <label for="faluss-public-name"><?php esc_html_e( 'Nom affiché', 'faluss-identity' ); ?></label>
                    <input id="faluss-public-name" name="display_name" type="text" maxlength="80" value="<?php echo esc_attr( null === $profile ? '' : $profile['display_name'] ); ?>" required>
                    <label for="faluss-public-bio"><?php esc_html_e( 'Bio courte', 'faluss-identity' ); ?></label>
                    <textarea id="faluss-public-bio" name="bio" maxlength="280" rows="4"><?php echo esc_textarea( null === $profile ? '' : $profile['bio'] ); ?></textarea>
                    <label for="faluss-public-avatar"><?php esc_html_e( 'Avatar', 'faluss-identity' ); ?></label>
                    <?php if ( null !== $profile && $profile['avatar_attachment_id'] > 0 ) : ?>
                        <div class="faluss-identity-profile-editor__avatar"><?php echo wp_get_attachment_image( $profile['avatar_attachment_id'], 'thumbnail', false, array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress image HTML. ?></div>
                    <?php endif; ?>
                    <input id="faluss-public-avatar" name="faluss_identity_avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                    <fieldset class="faluss-identity-profile-editor__links">
                        <legend><?php esc_html_e( 'Liens externes', 'faluss-identity' ); ?></legend>
                        <p class="faluss-identity-profile__hint"><?php esc_html_e( 'Utilisez la position pour choisir leur ordre de publication.', 'faluss-identity' ); ?></p>
                        <?php foreach ( $links as $index => $link ) : ?>
                            <div class="faluss-identity-profile-editor__link-row">
                                <input name="links[<?php echo (int) $index; ?>][position]" type="number" min="1" max="<?php echo (int) self::MAX_LINKS; ?>" value="<?php echo (int) $link['position']; ?>" aria-label="<?php esc_attr_e( 'Position', 'faluss-identity' ); ?>">
                                <input name="links[<?php echo (int) $index; ?>][label]" type="text" maxlength="80" value="<?php echo esc_attr( $link['label'] ); ?>" placeholder="<?php esc_attr_e( 'Libellé', 'faluss-identity' ); ?>">
                                <input name="links[<?php echo (int) $index; ?>][url]" type="url" maxlength="2048" value="<?php echo esc_attr( $link['url'] ); ?>" placeholder="https://">
                            </div>
                        <?php endforeach; ?>
                    </fieldset>
                    <label class="faluss-identity-profile-editor__publish" for="faluss-public-publish"><input id="faluss-public-publish" name="publication_status" type="checkbox" value="published" <?php checked( null !== $profile && 'published' === $profile['publication_status'] ); ?>> <?php esc_html_e( 'Publier mon profil', 'faluss-identity' ); ?></label>
                    <button type="submit"><?php esc_html_e( 'Enregistrer le profil', 'faluss-identity' ); ?></button>
                </form>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Minimal internal UI contract for Faluss Link Studio.
     * Identity remains the only owner of this form and its persistence route.
     *
     * @return string
     */
    public static function render_studio_fields() {
        self::enqueue_style();
        if ( ! is_user_logged_in() ) {
            return '<p class="faluss-identity-profile-notice">' . esc_html__( 'Connectez-vous pour administrer votre profil Faluss.', 'faluss-identity' ) . '</p>';
        }
        $faluss_id = Faluss_Identity_Registry::get_active_for_wp_user( get_current_user_id() );
        if ( null === $faluss_id ) {
            return '<p class="faluss-identity-profile-notice">' . esc_html__( 'Votre identité Faluss doit être vérifiée avant de créer un profil public.', 'faluss-identity' ) . '</p>';
        }
        $profile = self::find_by_faluss_id( $faluss_id );
        $links = null === $profile ? array() : $profile['links'];
        while ( count( $links ) < self::MAX_LINKS ) {
            $links[] = array( 'label' => '', 'url' => '', 'position' => count( $links ) + 1 );
        }

        ob_start();
        ?>
        <form class="faluss-link-studio__identity" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
            <input type="hidden" name="action" value="faluss_identity_save_public_profile">
            <?php wp_nonce_field( 'faluss_identity_save_public_profile', 'faluss_identity_profile_nonce' ); ?>
            <?php self::render_editor_notice(); ?>
            <div data-fl-panel="profile">
                <label for="faluss-public-identifier"><?php esc_html_e( 'Identifiant public', 'faluss-identity' ); ?></label>
                <input id="faluss-public-identifier" name="public_slug" type="text" value="<?php echo esc_attr( null === $profile ? '' : $profile['public_slug'] ); ?>" pattern="[a-z0-9][a-z0-9-]{1,39}" maxlength="40" <?php echo null !== $profile ? 'readonly' : ''; ?> required>
                <p class="faluss-identity-profile__hint"><?php esc_html_e( 'Il ne peut plus être modifié après sa création.', 'faluss-identity' ); ?></p>
                <label for="faluss-public-name"><?php esc_html_e( 'Nom affiché', 'faluss-identity' ); ?></label>
                <input id="faluss-public-name" name="display_name" type="text" maxlength="80" value="<?php echo esc_attr( null === $profile ? '' : $profile['display_name'] ); ?>" required>
                <label for="faluss-public-bio"><?php esc_html_e( 'Bio courte', 'faluss-identity' ); ?></label>
                <textarea id="faluss-public-bio" name="bio" maxlength="280" rows="4"><?php echo esc_textarea( null === $profile ? '' : $profile['bio'] ); ?></textarea>
                <label for="faluss-public-avatar"><?php esc_html_e( 'Avatar', 'faluss-identity' ); ?></label>
                <?php if ( null !== $profile && $profile['avatar_attachment_id'] > 0 ) : ?><div class="faluss-identity-profile-editor__avatar"><?php echo wp_get_attachment_image( $profile['avatar_attachment_id'], 'thumbnail', false, array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped. ?></div><?php endif; ?>
                <input id="faluss-public-avatar" name="faluss_identity_avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif">
                <label class="faluss-identity-profile-editor__publish" for="faluss-public-publish"><input id="faluss-public-publish" name="publication_status" type="checkbox" value="published" <?php checked( null !== $profile && 'published' === $profile['publication_status'] ); ?>> <?php esc_html_e( 'Publier mon profil', 'faluss-identity' ); ?></label>
            </div>
            <div data-fl-panel="links" hidden>
                <fieldset class="faluss-identity-profile-editor__links"><legend><?php esc_html_e( 'Liens publics', 'faluss-identity' ); ?></legend>
                    <?php foreach ( $links as $index => $link ) : ?><div class="faluss-identity-profile-editor__link-row"><input name="links[<?php echo (int) $index; ?>][position]" type="number" min="1" max="<?php echo (int) self::MAX_LINKS; ?>" value="<?php echo (int) $link['position']; ?>" aria-label="<?php esc_attr_e( 'Position', 'faluss-identity' ); ?>"><input name="links[<?php echo (int) $index; ?>][label]" type="text" maxlength="80" value="<?php echo esc_attr( $link['label'] ); ?>" placeholder="<?php esc_attr_e( 'Libellé', 'faluss-identity' ); ?>"><input name="links[<?php echo (int) $index; ?>][url]" type="url" maxlength="2048" value="<?php echo esc_attr( $link['url'] ); ?>" placeholder="https://"></div><?php endforeach; ?>
                </fieldset>
            </div>
            <button type="submit"><?php esc_html_e( 'Enregistrer le profil', 'faluss-identity' ); ?></button>
        </form>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Minimal data contract for Faluss Link Studio. No identity data is copied
     * into Faluss Link and persistence keeps this class as the sole owner.
     *
     * @return array<string, mixed>
     */
    public static function studio_profile( $faluss_id ) {
        $profile = self::find_by_faluss_id( $faluss_id );
        return null === $profile ? array(
            'faluss_id' => $faluss_id, 'public_slug' => '', 'display_name' => '', 'bio' => '',
            'avatar_attachment_id' => 0, 'publication_status' => 'draft', 'links' => array(),
        ) : $profile;
    }

    /**
     * Minimal server-owned projection used by the first-party SSO client.
     * Profile content and the public slug remain private to Identity here.
     *
     * @return array{contract_version: string, publication_status: string, canonical_url: string}|null
     */
    public static function member_app_projection( $faluss_id ) {
        $profile = self::find_by_faluss_id( $faluss_id );
        if ( ! is_array( $profile )
            || 'published' !== ( $profile['publication_status'] ?? '' )
            || '' === self::normalize_slug( $profile['public_slug'] ?? '' ) ) {
            return null;
        }
        return array(
            'contract_version'   => '1',
            'publication_status' => 'published',
            'canonical_url'      => home_url( '/mon-faluss' ),
        );
    }

    /**
     * Answers only whether the existing FI-03 registry contains a profile for
     * this identity. It never exposes the technical identifier in markup.
     */
    public static function has_profile_for_faluss_id( $faluss_id ) {
        return null !== self::find_by_faluss_id( $faluss_id );
    }

    /**
     * Checks the same route, reserved-word and registry constraints as final
     * reservation. The response reveals availability, never the current owner.
     */
    public static function public_slug_availability( $slug, $faluss_id = null ) {
        global $wpdb;
        $slug = self::normalize_slug( $slug );
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( '' === $slug || self::is_reserved_slug( $slug ) || '' === $table || ! self::schema_ready() ) {
            return 'invalid';
        }
        $owner = $wpdb->get_var( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE public_slug = %s', $slug ) );
        if ( ! is_string( $owner ) || '' === $owner ) {
            return 'available';
        }
        return is_string( $faluss_id ) && hash_equals( $owner, $faluss_id ) ? 'claimed' : 'taken';
    }

    /**
     * Atomically reserves a permanent public slug by inserting the minimal
     * draft FI-03 record. Its content remains owned by the normal profile/
     * Studio save path and is never duplicated into onboarding state.
     *
     * @return string claimed|taken|invalid|immutable
     */
    public static function reserve_public_slug( $faluss_id, $slug ) {
        global $wpdb;
        $slug = self::normalize_slug( $slug );
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || '' === $slug || self::is_reserved_slug( $slug ) || '' === $table || ! self::schema_ready() || false === $wpdb->query( 'START TRANSACTION' ) ) {
            return 'invalid';
        }
        try {
            $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT faluss_id, public_slug FROM ' . self::quote_identifier( $table ) . ' WHERE faluss_id = %s FOR UPDATE', $faluss_id ), ARRAY_A );
            if ( is_array( $existing ) ) {
                $wpdb->query( 'COMMIT' );
                return hash_equals( (string) $existing['public_slug'], $slug ) ? 'claimed' : 'immutable';
            }
            $owner = $wpdb->get_var( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE public_slug = %s FOR UPDATE', $slug ) );
            if ( is_string( $owner ) && '' !== $owner ) {
                $wpdb->query( 'ROLLBACK' );
                return 'taken';
            }
            $now = current_time( 'mysql', true );
            $saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $table ) . ' (faluss_id, public_slug, display_name, bio, avatar_attachment_id, publication_status, external_links, created_at, updated_at, published_at) VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s, %s)', $faluss_id, $slug, '', '', 0, 'draft', '[]', $now, $now, null ) );
            if ( 1 !== $saved || false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return 'invalid';
            }
            return 'claimed';
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return 'invalid';
        }
    }

    /**
     * A member can never change a claimed slug. This narrow primitive is kept
     * for a future privileged support screen and requires manage_options.
     */
    public static function admin_override_public_slug( $faluss_id, $slug ) {
        global $wpdb;
        $slug = self::normalize_slug( $slug );
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( ! current_user_can( 'manage_options' ) || ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || '' === $slug || self::is_reserved_slug( $slug ) || '' === $table || ! self::schema_ready() || false === $wpdb->query( 'START TRANSACTION' ) ) {
            return 'invalid';
        }
        try {
            $profile = $wpdb->get_row( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE faluss_id = %s FOR UPDATE', $faluss_id ), ARRAY_A );
            $owner = $wpdb->get_var( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE public_slug = %s FOR UPDATE', $slug ) );
            if ( ! is_array( $profile ) || ( is_string( $owner ) && '' !== $owner && ! hash_equals( $owner, $faluss_id ) ) ) {
                $wpdb->query( 'ROLLBACK' );
                return 'taken';
            }
            $updated = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET public_slug = %s, updated_at = %s WHERE faluss_id = %s', $slug, current_time( 'mysql', true ), $faluss_id ) );
            if ( false === $updated || false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return 'invalid'; }
            return 'claimed';
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return 'invalid';
        }
    }

    /** @return string saved|taken|invalid */
    public static function save_studio_profile( $faluss_id, $post, $files ) {
        return self::save_profile( $faluss_id, $post, $files );
    }

    /**
     * Locks and returns the Identity-owned Studio row inside a transaction
     * already opened by Faluss Link. This primitive never starts, commits or
     * rolls back a transaction.
     *
     * @return array<string,mixed>|false
     */
    public static function lock_studio_profile_in_transaction( $faluss_id ) {
        global $wpdb;
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( '' === $table || ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || ! self::schema_ready() ) {
            return false;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT faluss_id,public_slug,display_name,bio,avatar_attachment_id,publication_status,external_links,published_at FROM ' . self::quote_identifier( $table ) . ' WHERE faluss_id=%s FOR UPDATE', $faluss_id ), ARRAY_A );
        return is_array( $row ) ? $row : false;
    }

    /**
     * Validates and persists only Identity's public-link projection while the
     * caller owns the surrounding transaction.
     */
    public static function persist_external_links_in_transaction( $faluss_id, $links ) {
        global $wpdb;
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        $links = self::sanitize_links( is_array( $links ) ? $links : array() );
        if ( '' === $table || null === $links || ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || ! self::schema_ready() ) {
            return false;
        }
        return false !== $wpdb->update(
            $table,
            array( 'external_links' => wp_json_encode( $links ), 'updated_at' => current_time( 'mysql', true ) ),
            array( 'faluss_id' => $faluss_id ),
            array( '%s', '%s' ),
            array( '%s' )
        );
    }

    /**
     * Persists only the closed profile fields supplied by Faluss Link. The
     * surrounding aggregate transaction is owned by the caller.
     */
    public static function persist_studio_profile_in_transaction( $faluss_id, $fields ) {
        global $wpdb;
        $allowed = array( 'display_name', 'bio', 'avatar_attachment_id', 'publication_status' );
        if ( ! is_array( $fields ) || ! $fields || array_diff( array_keys( $fields ), $allowed ) ) {
            return false;
        }
        $row = self::lock_studio_profile_in_transaction( $faluss_id );
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( false === $row || '' === $table ) {
            return false;
        }

        $values = array();
        $formats = array();
        if ( array_key_exists( 'display_name', $fields ) ) {
            $name = self::limit_text( sanitize_text_field( wp_unslash( (string) $fields['display_name'] ) ), 80 );
            if ( '' === $name ) { return false; }
            $values['display_name'] = $name;
            $formats[] = '%s';
        }
        if ( array_key_exists( 'bio', $fields ) ) {
            $values['bio'] = self::limit_text( sanitize_textarea_field( wp_unslash( (string) $fields['bio'] ) ), 280 );
            $formats[] = '%s';
        }
        if ( array_key_exists( 'avatar_attachment_id', $fields ) ) {
            $attachment_id = absint( $fields['avatar_attachment_id'] );
            if ( $attachment_id ) {
                $attachment = get_post( $attachment_id );
                if ( ! $attachment instanceof WP_Post || (int) $attachment->post_author !== (int) get_current_user_id() || ! wp_attachment_is_image( $attachment_id ) ) {
                    return false;
                }
            }
            $values['avatar_attachment_id'] = $attachment_id;
            $formats[] = '%d';
        }
        if ( array_key_exists( 'publication_status', $fields ) ) {
            $status = sanitize_key( (string) $fields['publication_status'] );
            if ( ! in_array( $status, array( 'draft', 'published' ), true ) ) { return false; }
            $values['publication_status'] = $status;
            $formats[] = '%s';
            $values['published_at'] = 'published' === $status ? ( 'published' === ( $row['publication_status'] ?? '' ) && ! empty( $row['published_at'] ) ? $row['published_at'] : current_time( 'mysql', true ) ) : null;
            $formats[] = '%s';
        }
        $values['updated_at'] = current_time( 'mysql', true );
        $formats[] = '%s';
        return false !== $wpdb->update( $table, $values, array( 'faluss_id' => $faluss_id ), $formats, array( '%s' ) );
    }

    /** @return string */
    public static function render_public_profile( $slug ) {
        self::enqueue_style();
        $slug = self::resolve_public_slug( $slug );
        $profile = self::find_published_by_slug( $slug );
        return null === $profile ? '' : self::render_profile_markup( $profile );
    }

    /** @return array<string, mixed>|null */
    public static function find_published_by_slug( $slug ) {
        global $wpdb;
        $slug = self::normalize_slug( $slug );
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( '' === $slug || '' === $table || ! self::schema_ready() ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT faluss_id, public_slug, display_name, bio, avatar_attachment_id, publication_status, external_links, published_at FROM ' . self::quote_identifier( $table ) . ' WHERE public_slug = %s AND publication_status = %s', $slug, 'published' ), ARRAY_A );
        return self::hydrate_profile( $row );
    }

    /** @return array<string, mixed>|null */
    private static function find_by_faluss_id( $faluss_id ) {
        global $wpdb;
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( '' === $table || ! Faluss_Identity_Registry::is_valid_faluss_id( $faluss_id ) || ! self::schema_ready() ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT faluss_id, public_slug, display_name, bio, avatar_attachment_id, publication_status, external_links, published_at FROM ' . self::quote_identifier( $table ) . ' WHERE faluss_id = %s', $faluss_id ), ARRAY_A );
        return self::hydrate_profile( $row );
    }

    private static function save_profile( $faluss_id, $post, $files ) {
        global $wpdb;
        if ( ! self::schema_ready() ) {
            return 'invalid';
        }
        $existing = self::find_by_faluss_id( $faluss_id );
        $slug = null === $existing ? self::normalize_slug( isset( $post['public_slug'] ) ? wp_unslash( $post['public_slug'] ) : '' ) : $existing['public_slug'];
        $display_name = self::limit_text( isset( $post['display_name'] ) ? sanitize_text_field( wp_unslash( $post['display_name'] ) ) : '', 80 );
        $bio = self::limit_text( isset( $post['bio'] ) ? sanitize_textarea_field( wp_unslash( $post['bio'] ) ) : '', 280 );
        $links = self::sanitize_links( isset( $post['links'] ) && is_array( $post['links'] ) ? wp_unslash( $post['links'] ) : array() );
        if ( '' === $slug || self::is_reserved_slug( $slug ) || '' === $display_name || null === $links ) {
            return 'invalid';
        }

        $avatar_id = self::handle_avatar_upload( $files, null === $existing ? 0 : $existing['avatar_attachment_id'] );
        if ( false === $avatar_id ) {
            return 'invalid';
        }
        // ONB-02 reuses Faluss Link's member-owned image upload. The Identity
        // profile remains the only owner of the avatar reference and accepts
        // that attachment only after the same image/author validation.
        if ( empty( $files['faluss_identity_avatar']['name'] ) && isset( $post['faluss_identity_avatar_id'] ) ) {
            $requested_avatar = absint( $post['faluss_identity_avatar_id'] );
            $attachment = $requested_avatar ? get_post( $requested_avatar ) : null;
            if ( ! $attachment instanceof WP_Post || (int) $attachment->post_author !== (int) get_current_user_id() || ! wp_attachment_is_image( $requested_avatar ) ) {
                return 'invalid';
            }
            $avatar_id = $requested_avatar;
        }
        $status = isset( $post['publication_status'] ) && 'published' === $post['publication_status'] ? 'published' : 'draft';
        $now = current_time( 'mysql', true );
        $published_at = 'published' === $status ? ( null !== $existing && 'published' === $existing['publication_status'] ? $existing['published_at'] : $now ) : null;
        $table = Faluss_Identity_Schema::get_public_profiles_table();
        if ( '' === $table || false === $wpdb->query( 'START TRANSACTION' ) ) {
            return 'invalid';
        }
        try {
            if ( null === $existing ) {
                $owner = $wpdb->get_var( $wpdb->prepare( 'SELECT faluss_id FROM ' . self::quote_identifier( $table ) . ' WHERE public_slug = %s FOR UPDATE', $slug ) );
                if ( is_string( $owner ) && '' !== $owner ) {
                    $wpdb->query( 'ROLLBACK' );
                    return 'taken';
                }
                $saved = $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . self::quote_identifier( $table ) . ' (faluss_id, public_slug, display_name, bio, avatar_attachment_id, publication_status, external_links, created_at, updated_at, published_at) VALUES (%s, %s, %s, %s, %d, %s, %s, %s, %s, %s)', $faluss_id, $slug, $display_name, $bio, $avatar_id, $status, wp_json_encode( $links ), $now, $now, $published_at ) );
            } else {
                $saved = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::quote_identifier( $table ) . ' SET display_name = %s, bio = %s, avatar_attachment_id = %d, publication_status = %s, external_links = %s, updated_at = %s, published_at = %s WHERE faluss_id = %s', $display_name, $bio, $avatar_id, $status, wp_json_encode( $links ), $now, $published_at, $faluss_id ) );
            }
            if ( false === $saved || false === $wpdb->query( 'COMMIT' ) ) {
                $wpdb->query( 'ROLLBACK' );
                return 'taken';
            }
        } catch ( Exception $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return 'invalid';
        }
        return 'saved';
    }

    private static function handle_avatar_upload( $files, $current_id ) {
        if ( empty( $files['faluss_identity_avatar']['name'] ) ) {
            return (int) $current_id;
        }
        if ( empty( $files['faluss_identity_avatar']['tmp_name'] ) || ! is_uploaded_file( $files['faluss_identity_avatar']['tmp_name'] ) ) {
            return false;
        }
        $type = wp_check_filetype_and_ext( $files['faluss_identity_avatar']['tmp_name'], $files['faluss_identity_avatar']['name'] );
        if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) {
            return false;
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $attachment_id = media_handle_upload( 'faluss_identity_avatar', 0, array(), array( 'test_form' => false ) );
        return is_wp_error( $attachment_id ) || ! wp_attachment_is_image( $attachment_id ) ? false : (int) $attachment_id;
    }

    /** @return array<int, array{label: string, url: string, position: int}>|null */
    private static function sanitize_links( $links ) {
        $clean = array();
        foreach ( array_slice( $links, 0, self::MAX_LINKS, true ) as $link ) {
            if ( ! is_array( $link ) ) {
                return null;
            }
            $url = isset( $link['url'] ) ? trim( (string) $link['url'] ) : '';
            $label = self::limit_text( isset( $link['label'] ) ? sanitize_text_field( (string) $link['label'] ) : '', 80 );
            if ( '' === $url && '' === $label ) {
                continue;
            }
            $safe_url = self::validate_external_url( $url );
            if ( null === $safe_url ) {
                return null;
            }
            if ( '' === $label ) {
                $parts = wp_parse_url( $safe_url );
                $label = isset( $parts['host'] ) ? $parts['host'] : $safe_url;
            }
            $clean[] = array( 'label' => $label, 'url' => $safe_url, 'position' => max( 1, min( self::MAX_LINKS, isset( $link['position'] ) ? (int) $link['position'] : count( $clean ) + 1 ) ) );
        }
        usort( $clean, static function ( $left, $right ) { return $left['position'] <=> $right['position']; } );
        foreach ( $clean as $index => $link ) {
            $clean[ $index ]['position'] = $index + 1;
        }
        return $clean;
    }

    private static function validate_external_url( $url ) {
        $url = esc_url_raw( $url, array( 'https' ) );
        $parts = wp_parse_url( $url );
        if ( '' === $url || ! is_array( $parts ) || ! isset( $parts['scheme'], $parts['host'] ) || 'https' !== strtolower( $parts['scheme'] ) || isset( $parts['user'], $parts['pass'] ) ) {
            return null;
        }
        return $url;
    }

    /** Uses the current root route when the shortcode or Elementor field is empty. */
    private static function resolve_public_slug( $slug ) {
        $slug = self::normalize_slug( $slug );
        return '' !== $slug ? $slug : self::normalize_slug( (string) get_query_var( self::QUERY_VAR ) );
    }

    private static function get_template_id() {
        return self::sanitize_template_id( get_option( self::OPTION_TEMPLATE_ID, 0 ) );
    }

    private static function is_valid_elementor_template( $template_id ) {
        if ( $template_id < 1 || ! class_exists( 'Elementor\\Plugin' ) ) {
            return false;
        }
        $post = get_post( $template_id );
        return $post instanceof WP_Post && 'page' === $post->post_type && 'publish' === $post->post_status && '' !== (string) get_post_meta( $template_id, '_elementor_data', true );
    }

    /** @return array<int, WP_Post> */
    private static function get_elementor_templates() {
        if ( ! class_exists( 'Elementor\\Plugin' ) ) {
            return array();
        }
        return get_posts( array( 'post_type' => 'page', 'post_status' => 'publish', 'posts_per_page' => 100, 'orderby' => 'title', 'order' => 'ASC', 'meta_key' => '_elementor_data', 'meta_compare' => 'EXISTS' ) );
    }

    /**
     * Renders a selected Elementor page while preserving QUERY_VAR for its
     * profile widget. A missing, invalid or empty builder result returns false
     * so the standalone profile remains a reliable fallback.
     */
    private static function render_elementor_template( $template_id ) {
        if ( ! self::is_valid_elementor_template( $template_id ) ) {
            return false;
        }
        $content = \Elementor\Plugin::instance()->frontend->get_builder_content_for_display( $template_id );
        if ( ! is_string( $content ) || '' === trim( $content ) ) {
            return false;
        }
        echo '<main class="faluss-identity-profile-page faluss-identity-profile-page--elementor">' . $content . '</main>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Elementor returns its own escaped builder markup.
        return true;
    }

    private static function normalize_slug( $slug ) {
        $slug = sanitize_title( (string) $slug );
        return 1 === preg_match( '/^[a-z0-9][a-z0-9-]{1,39}$/D', $slug ) ? $slug : '';
    }

    private static function is_reserved_slug( $slug ) {
        static $reserved = array( 'admin', 'api', 'assets', 'author', 'category', 'commencer', 'embed', 'feed', 'index', 'index.php', 'list', 'login', 'logout', 'mes-decouvertes', 'mon-faluss', 'oauth', 'profile', 'profiles', 'search', 'tag', 'wp-admin', 'wp-content', 'wp-includes', 'wp-json', 'wp-login.php', 'wp-cron.php', 'xmlrpc.php' );
        return in_array( $slug, $reserved, true ) || self::existing_wordpress_post( $slug ) instanceof WP_Post;
    }

    private static function existing_wordpress_post( $slug ) {
        $post_types = get_post_types( array( 'public' => true ), 'names' );
        return get_page_by_path( $slug, OBJECT, $post_types );
    }

    private static function schema_ready() {
        $version = function_exists( 'get_option' ) ? (string) get_option( Faluss_Identity_Schema::OPTION_VERSION, '' ) : '';
        if ( ! ctype_digit( $version ) || (int) $version < (int) Faluss_Identity_Schema::FI03_VERSION ) {
            return false;
        }
        $status = Faluss_Identity_Schema::get_status();
        return ! empty( $status['ready'] );
    }

    private static function hydrate_profile( $row ) {
        if ( ! is_array( $row ) || ! Faluss_Identity_Registry::is_valid_faluss_id( $row['faluss_id'] ) || '' === self::normalize_slug( $row['public_slug'] ) ) {
            return null;
        }
        $links = json_decode( $row['external_links'], true );
        $links = is_array( $links ) ? self::sanitize_links( $links ) : array();
        return array( 'faluss_id' => $row['faluss_id'], 'public_slug' => $row['public_slug'], 'display_name' => $row['display_name'], 'bio' => (string) $row['bio'], 'avatar_attachment_id' => (int) $row['avatar_attachment_id'], 'publication_status' => $row['publication_status'], 'links' => is_array( $links ) ? $links : array(), 'published_at' => $row['published_at'] );
    }

    private static function render_profile_markup( $profile ) {
        ob_start();
        ?>
        <article class="faluss-identity-public-profile">
            <div class="faluss-identity-public-profile__card">
                <?php if ( $profile['avatar_attachment_id'] > 0 ) : ?>
                    <div class="faluss-identity-public-profile__avatar"><?php echo wp_get_attachment_image( $profile['avatar_attachment_id'], 'medium', false, array( 'alt' => '' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- WordPress image HTML. ?></div>
                <?php endif; ?>
                <h1><?php echo esc_html( $profile['display_name'] ); ?></h1>
                <p class="faluss-identity-public-profile__identifier">@<?php echo esc_html( $profile['public_slug'] ); ?></p>
                <?php if ( '' !== $profile['bio'] ) : ?><p class="faluss-identity-public-profile__bio"><?php echo nl2br( esc_html( $profile['bio'] ) ); ?></p><?php endif; ?>
                <?php if ( ! empty( $profile['links'] ) ) : ?>
                    <ul class="faluss-identity-public-profile__links">
                        <?php foreach ( $profile['links'] as $link ) : ?><li><a href="<?php echo esc_url( $link['url'] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $link['label'] ); ?></a></li><?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    private static function render_editor_notice() {
        $notice = isset( $_GET['faluss_identity_profile_notice'] ) ? sanitize_key( wp_unslash( $_GET['faluss_identity_profile_notice'] ) ) : '';
        $messages = array( 'saved' => __( 'Votre profil a été enregistré.', 'faluss-identity' ), 'taken' => __( 'Cet identifiant public n’est pas disponible.', 'faluss-identity' ), 'invalid' => __( 'Nous ne pouvons pas enregistrer ce profil.', 'faluss-identity' ) );
        if ( isset( $messages[ $notice ] ) ) {
            echo '<p class="faluss-identity-profile-notice" role="status">' . esc_html( $messages[ $notice ] ) . '</p>';
        }
    }

    private static function redirect_editor( $notice ) {
        $url = wp_validate_redirect( wp_get_referer(), home_url( '/' ) );
        wp_safe_redirect( add_query_arg( 'faluss_identity_profile_notice', sanitize_key( $notice ), $url ) );
        exit;
    }

    private static function enqueue_style() {
        if ( ! wp_style_is( self::STYLE_HANDLE, 'registered' ) ) {
            self::register_assets();
        }
        wp_enqueue_style( self::STYLE_HANDLE );
    }

    private static function limit_text( $value, $length ) {
        $value = trim( (string) $value );
        return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length );
    }

    private static function quote_identifier( $identifier ) {
        return chr( 96 ) . str_replace( chr( 96 ), chr( 96 ) . chr( 96 ), $identifier ) . chr( 96 );
    }
}
