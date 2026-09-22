<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Faluss_Link {
    const STYLE = 'faluss-link-card';
    const IMMERSIVE_STYLE = 'faluss-link-immersive';
    const STUDIO_STYLE = 'faluss-link-studio';
    const SCRIPT = 'faluss-link-editor';
    const CARD_SCRIPT = 'faluss-link-card';
    const IMMERSIVE_SCRIPT = 'faluss-link-immersive';
    const REWARD_STYLE = 'faluss-link-reward';
    const REWARD_SCRIPT = 'faluss-link-reward';
    const DISCOVERIES_STYLE = 'faluss-link-discoveries';
    const ONBOARDING_STYLE = 'faluss-link-onboarding';
    const ONBOARDING_SCRIPT = 'faluss-link-onboarding';
    const NETWORKS = array( 'instagram', 'tiktok', 'youtube', 'x', 'linkedin', 'github' );
    const ANNOUNCEMENTS = array( 'accent' => 'Accent / ink', 'ink' => 'Ink / white' );
    const NAME_TREATMENTS = array( 'editorial' => 'Éditorial', 'strong' => 'Fort' );
    const LAYOUTS = array( 'bubbles' => 'Bulles', 'inline' => 'Ligne' );
    /* Storage values are kept for existing cards; their member-facing names
     * describe the shared presentation variants used by Studio, onboarding and
     * the public renderer. */
    const LINK_STYLES = array( 'solid' => 'Visuel', 'light' => 'Minutieux', 'outline' => 'Formel' );
    const NAME_COLORS = array( '#BE79FF' => 'Rose', '#FFFFFF' => 'Blanc', '#000000' => 'Noir', '#82206B' => 'Prune' );
    const SOCIAL_VARIANTS = array( 'outline' => 'Icônes contour', 'full' => 'Logos pleins' );
    const BLOCK_TYPES = array( 'section_title' => 'Titre de section', 'text' => 'Texte', 'link' => 'Lien', 'media_teaser' => 'Teaser média' );
    const TEASER_FORMATS = array( 'landscape' => 'Paysage', 'portrait' => 'Portrait', 'square' => 'Carré' );
    const TEASER_ACCESS_MODES = array( 'public' => 'Public', 'member' => 'Membre Faluss', 'entitlement' => 'Droit requis' );
    private static $public_profile_request = false;
    private static $teaser_entitlement_choices = null;

    public static function boot() {
        add_action( 'plugins_loaded', array( 'Faluss_Link_Schema', 'maybe_install' ), 1 );
        add_shortcode( 'faluss_link_card', array( __CLASS__, 'card_shortcode' ) );
        add_shortcode( 'faluss_link_appearance', array( __CLASS__, 'appearance_shortcode' ) );
        add_shortcode( 'faluss_link_studio', array( __CLASS__, 'studio_shortcode' ) );
        add_shortcode( 'faluss_link_daily_reward', array( __CLASS__, 'daily_reward_shortcode' ) );
        add_shortcode( 'faluss_link_discoveries', array( __CLASS__, 'discoveries_shortcode' ) );
        add_action( 'admin_post_faluss_link_save', array( __CLASS__, 'save' ) );
        add_action( 'admin_post_faluss_link_save_studio', array( __CLASS__, 'save_studio' ) );
        add_action( 'admin_post_faluss_link_save_discovery_settings', array( __CLASS__, 'save_discovery_settings' ) );
        add_action( 'admin_post_faluss_link_delete_discovery', array( __CLASS__, 'delete_discovery' ) );
        add_action( 'admin_post_faluss_link_clear_discoveries', array( __CLASS__, 'clear_discoveries' ) );
        add_action( 'wp_ajax_faluss_link_upload_cover', array( __CLASS__, 'upload_cover' ) );
        add_action( 'wp_ajax_faluss_link_upload_avatar', array( __CLASS__, 'upload_avatar' ) );
        add_action( 'wp_ajax_faluss_link_upload_teaser', array( __CLASS__, 'upload_teaser' ) );
        add_action( 'wp_ajax_faluss_link_onboarding_save', array( __CLASS__, 'save_onboarding_wizard' ) );
        add_action( 'wp_ajax_faluss_link_onboarding_preview', array( __CLASS__, 'preview_onboarding_wizard' ) );
        add_action( 'wp_ajax_faluss_link_onboarding_finish', array( __CLASS__, 'finish_onboarding_wizard' ) );
        add_action( 'wp_ajax_faluss_link_onboarding_upload_avatar', array( __CLASS__, 'upload_onboarding_avatar' ) );
        add_action( 'wp_ajax_faluss_link_daily_reward_claim', array( __CLASS__, 'claim_daily_reward' ) );
        add_action( 'parse_request', array( __CLASS__, 'exclude_public_profile_from_cache' ), 1 );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_public_profile_assets' ), 6 );
        add_action( 'template_redirect', array( __CLASS__, 'send_public_profile_no_cache_headers' ), 0 );
        add_filter( 'body_class', array( __CLASS__, 'onboarding_body_class' ), 99 );
        add_action( 'elementor/frontend/after_register_scripts', array( __CLASS__, 'assets' ), 5 );
        add_action( 'elementor/frontend/after_register_styles', array( __CLASS__, 'assets' ), 5 );
        add_action( 'elementor/widgets/register', array( __CLASS__, 'widgets' ) );
    }

    public static function activate() { return Faluss_Link_Schema::install(); }
    public static function card_shortcode( $attributes = array() ) { return self::render_card( (array) $attributes ); }
    /** Keep historical Elementor/shortcode placements on the canonical Studio surface. */
    public static function appearance_shortcode() { return self::studio_shortcode(); }
    public static function studio_shortcode() { return self::render_studio(); }
    public static function daily_reward_shortcode( $attributes = array() ) { return self::render_daily_reward( (array) $attributes ); }
    public static function discoveries_shortcode( $attributes = array() ) { return self::render_discoveries( (array) $attributes ); }

    /**
     * Gives the selected Elementor Canvas onboarding page a route-scoped shell.
     * The browser then marks only the ancestors that actually wrap this widget,
     * so no other Elementor page inherits the viewport reset.
     */
    public static function onboarding_body_class( $classes ) {
        $classes = is_array( $classes ) ? $classes : array();
        if ( self::is_onboarding_surface_request() ) {
            $classes[] = 'faluss-link-onboarding-route';
        }
        return array_values( array_unique( $classes ) );
    }

    public static function assets() {
        wp_register_style( self::STYLE, plugins_url( 'assets/link/css/faluss-link.css', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION );
        wp_register_style( self::IMMERSIVE_STYLE, plugins_url( 'assets/link/css/faluss-link-immersive.css', FALUSS_LINK_FILE ), array( self::STYLE ), FALUSS_LINK_VERSION );
        wp_register_style( self::STUDIO_STYLE, plugins_url( 'assets/link/css/faluss-link-studio.css', FALUSS_LINK_FILE ), array( self::STYLE, self::IMMERSIVE_STYLE ), FALUSS_LINK_VERSION );
        wp_register_style( self::REWARD_STYLE, plugins_url( 'assets/link/css/faluss-link-reward.css', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION );
        wp_register_style( self::DISCOVERIES_STYLE, plugins_url( 'assets/link/css/faluss-link-discoveries.css', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION );
        wp_register_style( self::ONBOARDING_STYLE, plugins_url( 'assets/link/css/faluss-link-onboarding.css', FALUSS_LINK_FILE ), array( self::STYLE ), FALUSS_LINK_VERSION );
        wp_register_script( self::CARD_SCRIPT, plugins_url( 'assets/link/js/faluss-link-card.js', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION, true );
        wp_register_script( self::SCRIPT, plugins_url( 'assets/link/js/faluss-link-editor.js', FALUSS_LINK_FILE ), array( 'jquery', self::CARD_SCRIPT ), FALUSS_LINK_VERSION, true );
        wp_register_script( self::IMMERSIVE_SCRIPT, plugins_url( 'assets/link/js/faluss-link-immersive.js', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION, true );
        wp_register_script( self::REWARD_SCRIPT, plugins_url( 'assets/link/js/faluss-link-reward.js', FALUSS_LINK_FILE ), array(), FALUSS_LINK_VERSION, true );
        wp_register_script( self::ONBOARDING_SCRIPT, plugins_url( 'assets/link/js/faluss-link-onboarding.js', FALUSS_LINK_FILE ), array( self::CARD_SCRIPT ), FALUSS_LINK_VERSION, true );
    }

    /**
     * Public Faluss profile routes render mutable member preferences. Mark only
     * those rewritten routes as dynamic before WordPress emits its headers.
     */
    public static function exclude_public_profile_from_cache( $request ) {
        if ( is_admin() || ! self::is_public_profile_request( $request ) ) {
            return;
        }
        self::$public_profile_request = true;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) {
            define( 'DONOTCACHEPAGE', true );
        }
        add_filter( 'wp_headers', array( __CLASS__, 'public_profile_no_cache_headers' ), 99 );
        do_action( 'litespeed_control_set_nocache' );
    }

    /** @param array<string, string> $headers @return array<string, string> */
    public static function public_profile_no_cache_headers( $headers ) {
        if ( ! self::$public_profile_request ) {
            return $headers;
        }
        return array_merge( (array) $headers, array(
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => 'Wed, 11 Jan 1984 05:00:00 GMT',
            'X-LiteSpeed-Cache-Control' => 'no-cache',
        ) );
    }

    public static function send_public_profile_no_cache_headers() {
        if ( ! self::$public_profile_request && ! self::is_public_profile_request() ) {
            return;
        }
        self::$public_profile_request = true;
        if ( function_exists( 'nocache_headers' ) ) {
            nocache_headers();
        }
        do_action( 'litespeed_control_set_nocache' );
    }

    /** Enqueue the isolated public shell CSS before the manual route shell prints wp_head(). */
    public static function enqueue_public_profile_assets() {
        if ( ! self::$public_profile_request && ! self::is_public_profile_request() ) {
            return;
        }
        if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
            self::assets();
        }
        wp_enqueue_style( self::STYLE );
        wp_enqueue_style( self::IMMERSIVE_STYLE );
    }

    public static function render_card( $attributes = array() ) {
        if ( ! self::identity_ready() ) { return self::empty_card( __( 'Carte Faluss indisponible.', 'faluss-link' ) ); }
        $attributes = wp_parse_args( $attributes, array( 'identifier' => '', 'align' => '', 'presentation' => 'compact' ) );
        $slug = '' !== $attributes['identifier'] ? sanitize_title( $attributes['identifier'] ) : (string) get_query_var( 'faluss_public_profile' );
        $profile = self::published_profile( $slug );
        if ( ! $profile ) { return self::empty_card( __( 'Cette carte Faluss n’est pas disponible.', 'faluss-link' ) ); }
        self::record_discovery_for_current_visitor( $profile['faluss_id'] );
        $preferences = self::prefs( $profile['faluss_id'] );
        $alignment = '' === (string) $attributes['align'] ? $preferences['alignment'] : self::align( $attributes['align'] );
        $blocks = self::content_blocks( $profile['faluss_id'], $profile['links'] );
        self::enqueue_assets();
        $markup = self::card_markup( $profile, $preferences, $alignment, false, $blocks );
        if ( 'immersive' === $attributes['presentation'] ) {
            wp_enqueue_script( self::IMMERSIVE_SCRIPT );
            return str_replace( 'faluss-link-card ', 'faluss-link-card faluss-link-card--presentation-immersive ', $markup );
        }
        return $markup;
    }

    /** Public, viewer-scoped reward component. It never accepts a profile owner or subject attribute. */
    public static function render_daily_reward( $attributes = array() ) {
        $attributes = wp_parse_args( $attributes, array(
            'align' => 'left',
            'presentation' => 'immersive',
            'show_balance' => 'no',
            'hide_unavailable' => 'no',
            'login_label' => __( 'Réclamer mes %1$s %2$s', 'faluss-link' ),
            'login_microcopy' => __( 'et débloquer le teaser gratuitement', 'faluss-link' ),
            'claim_label' => __( 'Réclamer %1$s %2$s', 'faluss-link' ),
            'claimed_label' => __( 'Récompense quotidienne déjà réclamée.', 'faluss-link' ),
            'unavailable_label' => __( 'Récompense quotidienne indisponible.', 'faluss-link' ),
        ) );
        $attributes['align'] = in_array( $attributes['align'], array( 'left', 'center', 'right' ), true ) ? $attributes['align'] : 'left';
        $attributes['presentation'] = 'compact' === $attributes['presentation'] ? 'compact' : 'immersive';
        $attributes['show_balance'] = in_array( $attributes['show_balance'], array( true, 1, '1', 'yes' ), true );
        $attributes['hide_unavailable'] = in_array( $attributes['hide_unavailable'], array( true, 1, '1', 'yes' ), true );
        foreach ( array( 'login_label', 'login_microcopy', 'claim_label', 'claimed_label', 'unavailable_label' ) as $label ) {
            $attributes[ $label ] = sanitize_text_field( (string) $attributes[ $label ] );
        }
        self::reward_assets();
        if ( ! is_user_logged_in() ) {
            $offer = \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::dailyRewardOffer();
            if ( is_wp_error( $offer ) ) {
                return self::daily_reward_markup( self::daily_reward_error_state( $offer ), $attributes );
            }
            if ( 'available' !== ( $offer['state'] ?? '' ) ) {
                return self::daily_reward_markup( $offer, $attributes );
            }
            return self::daily_reward_markup( array( 'state' => 'login', 'amount' => $offer['amount'], 'unit' => $offer['unit'] ), $attributes );
        }
        $result = \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::dailyRewardStatusForCurrentSubject();
        return self::daily_reward_markup( is_wp_error( $result ) ? self::daily_reward_error_state( $result ) : $result, $attributes );
    }

    /** WordPress AJAX protection is local; all eligibility and credits remain on the Core. */
    public static function claim_daily_reward() {
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( array( 'message' => __( 'Connectez-vous pour réclamer cette récompense.', 'faluss-link' ) ), 403 );
        }
        if ( ! check_ajax_referer( 'faluss_link_daily_reward_claim', 'nonce', false ) ) {
            wp_send_json_error( array( 'message' => __( 'Votre session a expiré. Rechargez la page avant de réessayer.', 'faluss-link' ) ), 403 );
        }
        if ( ! \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::available() ) {
            wp_send_json_error( array( 'message' => __( 'La connexion au Core est indisponible.', 'faluss-link' ) ), 503 );
        }
        $result = \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::claimDailyRewardForCurrentSubject();
        if ( is_wp_error( $result ) ) {
            wp_send_json_success( self::daily_reward_error_payload( $result ) );
        }
        wp_send_json_success( self::daily_reward_response_payload( $result ) );
    }

    /**
     * Private, viewer-scoped library. It intentionally has no identifier
     * attribute: a member can only ever read their own discoveries.
     */
    public static function render_discoveries( $attributes = array() ) {
        if ( ! self::identity_ready() || ! is_user_logged_in() ) { return ''; }
        $viewer = self::current_faluss_id();
        if ( ! self::valid_faluss_id( $viewer ) ) { return ''; }
        $attributes = wp_parse_args( (array) $attributes, array(
            'title' => __( 'Mes découvertes', 'faluss-link' ),
            'empty_label' => __( 'Aucune découverte pour le moment.', 'faluss-link' ),
            'per_page' => 24,
            'layout' => 'list',
        ) );
        $title = sanitize_text_field( (string) $attributes['title'] );
        $empty_label = sanitize_text_field( (string) $attributes['empty_label'] );
        $limit = min( 250, max( 1, absint( $attributes['per_page'] ) ) );
        $layout = in_array( $attributes['layout'], array( 'list', 'grid' ), true ) ? $attributes['layout'] : 'list';
        self::discoveries_assets();
        $enabled = self::discovery_recording_enabled( $viewer );
        $page = max( 1, absint( $_GET['faluss_discoveries_page'] ?? 1 ) );
        $total = self::discoveries_count_for_viewer( $viewer );
        $pages = max( 1, (int) ceil( $total / $limit ) );
        $page = min( $page, $pages );
        $discoveries = self::discoveries_for_viewer( $viewer, $limit, ( $page - 1 ) * $limit );
        $instance = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'faluss-link-discoveries-' ) : 'faluss-link-discoveries';
        ob_start();
        ?>
        <section class="faluss-link-discoveries faluss-link-discoveries--<?php echo esc_attr( $layout ); ?>"<?php echo '' !== $title ? ' aria-labelledby="' . esc_attr( $instance ) . '-title"' : ' aria-label="' . esc_attr__( 'Mes découvertes', 'faluss-link' ) . '"'; ?>>
            <?php if ( '' !== $title ) : ?><h2 id="<?php echo esc_attr( $instance ); ?>-title" class="faluss-link-discoveries__title"><?php echo esc_html( $title ); ?></h2><?php endif; ?>
            <form class="faluss-link-discoveries__setting" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="faluss_link_save_discovery_settings">
                <?php wp_nonce_field( 'faluss_link_save_discovery_settings', 'faluss_link_discovery_settings_nonce' ); ?>
                <label for="<?php echo esc_attr( $instance ); ?>-recording"><input id="<?php echo esc_attr( $instance ); ?>-recording" name="recording_enabled" type="checkbox" role="switch" value="1" <?php checked( $enabled ); ?>> <?php esc_html_e( 'Enregistrer mes découvertes', 'faluss-link' ); ?></label>
                <p><?php esc_html_e( 'Votre liste reste privée et n’est jamais visible des créateurs.', 'faluss-link' ); ?></p>
                <button class="faluss-link-discoveries__save" type="submit"><?php esc_html_e( 'Enregistrer ce réglage', 'faluss-link' ); ?></button>
            </form>
            <div class="faluss-link-discoveries__notice" role="status" aria-live="polite"><?php echo self::discovery_notice(); ?></div>
            <?php if ( $discoveries ) : ?>
                <ul class="faluss-link-discoveries__items">
                    <?php foreach ( $discoveries as $discovery ) : ?>
                        <li class="faluss-link-discoveries__item">
                            <a class="faluss-link-discoveries__profile" href="<?php echo esc_url( home_url( '/' . $discovery['public_slug'] ) ); ?>">
                                <?php if ( ! empty( $discovery['avatar_attachment_id'] ) && wp_attachment_is_image( (int) $discovery['avatar_attachment_id'] ) ) { echo wp_get_attachment_image( (int) $discovery['avatar_attachment_id'], 'thumbnail', false, array( 'alt' => '' ) ); } ?>
                                <span class="faluss-link-discoveries__identity"><strong><?php echo esc_html( $discovery['display_name'] ); ?></strong><span><?php echo esc_html( '@' . $discovery['public_slug'] ); ?></span><time datetime="<?php echo esc_attr( self::discovery_datetime( $discovery['last_seen_at'] ) ); ?>"><?php echo esc_html( self::discovery_last_seen_label( $discovery['last_seen_at'] ) ); ?></time></span>
                            </a>
                            <form class="faluss-link-discoveries__remove" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                <input type="hidden" name="action" value="faluss_link_delete_discovery"><input type="hidden" name="discovery_id" value="<?php echo (int) $discovery['id']; ?>">
                                <?php wp_nonce_field( 'faluss_link_delete_discovery', 'faluss_link_discovery_delete_nonce' ); ?>
                                <button type="submit"><?php esc_html_e( 'Retirer', 'faluss-link' ); ?></button>
                            </form>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if ( $pages > 1 ) : ?><nav class="faluss-link-discoveries__pages" aria-label="<?php esc_attr_e( 'Pages des découvertes', 'faluss-link' ); ?>"><?php if ( $page > 1 ) : ?><a href="<?php echo esc_url( self::discoveries_page_url( $page - 1 ) ); ?>"><?php esc_html_e( 'Précédent', 'faluss-link' ); ?></a><?php endif; ?><span><?php echo esc_html( sprintf( __( 'Page %1$d sur %2$d', 'faluss-link' ), $page, $pages ) ); ?></span><?php if ( $page < $pages ) : ?><a href="<?php echo esc_url( self::discoveries_page_url( $page + 1 ) ); ?>"><?php esc_html_e( 'Suivant', 'faluss-link' ); ?></a><?php endif; ?></nav><?php endif; ?>
                <form class="faluss-link-discoveries__clear" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="faluss_link_clear_discoveries"><?php wp_nonce_field( 'faluss_link_clear_discoveries', 'faluss_link_discovery_clear_nonce' ); ?>
                    <button type="submit"><?php esc_html_e( 'Tout effacer', 'faluss-link' ); ?></button>
                </form>
            <?php else : ?>
                <p class="faluss-link-discoveries__empty"><?php echo esc_html( '' !== $empty_label ? $empty_label : __( 'Aucune découverte pour le moment.', 'faluss-link' ) ); ?></p>
            <?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function save_discovery_settings() {
        if ( ! self::verify( 'faluss_link_discovery_settings_nonce', 'faluss_link_save_discovery_settings' ) ) { wp_die( 'Accès refusé.' ); }
        $viewer = self::current_faluss_id();
        self::discovery_redirect( self::valid_faluss_id( $viewer ) && self::set_discovery_recording_enabled( $viewer, ! empty( $_POST['recording_enabled'] ) ) ? 'saved' : 'invalid' );
    }

    public static function delete_discovery() {
        if ( ! self::verify( 'faluss_link_discovery_delete_nonce', 'faluss_link_delete_discovery' ) ) { wp_die( 'Accès refusé.' ); }
        $viewer = self::current_faluss_id(); $id = absint( $_POST['discovery_id'] ?? 0 );
        self::discovery_redirect( self::valid_faluss_id( $viewer ) && $id && self::delete_discovery_for_viewer( $viewer, $id ) ? 'deleted' : 'invalid' );
    }

    public static function clear_discoveries() {
        if ( ! self::verify( 'faluss_link_discovery_clear_nonce', 'faluss_link_clear_discoveries' ) ) { wp_die( 'Accès refusé.' ); }
        $viewer = self::current_faluss_id();
        self::discovery_redirect( self::valid_faluss_id( $viewer ) && self::clear_discoveries_for_viewer( $viewer ) ? 'cleared' : 'invalid' );
    }

    public static function render_editor() {
        if ( ! is_user_logged_in() || ! self::identity_ready() ) { return self::empty_card( __( 'Connectez-vous pour personnaliser votre carte.', 'faluss-link' ) ); }
        $faluss_id = \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId();
        if ( ! $faluss_id ) { return self::empty_card( __( 'Votre identité Faluss est indisponible.', 'faluss-link' ) ); }
        self::editor_assets(); $preferences = self::valid_prefs( $faluss_id ); ob_start();
        ?><section class="faluss-link-editor"><h2><?php esc_html_e( 'Apparence de ma carte Faluss', 'faluss-link' ); ?></h2><?php echo self::notice( 'faluss_link_notice' ); ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="faluss_link_save"><?php wp_nonce_field( 'faluss_link_save', 'faluss_link_nonce' ); self::page_background_field( $preferences, false ); self::preference_fields( $preferences, false ); self::social_editor( $preferences ); ?><button class="faluss-link-action" type="submit"><?php esc_html_e( 'Enregistrer', 'faluss-link' ); ?></button></form></section><?php
        return (string) ob_get_clean();
    }

    public static function render_studio() {
        if ( ! is_user_logged_in() || ! self::identity_ready() || ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) { return self::empty_card( __( 'Connectez-vous pour ouvrir Studio Faluss.', 'faluss-link' ) ); }
        $faluss_id = \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId();
        if ( ! $faluss_id ) { return self::empty_card( __( 'Votre identité Faluss est indisponible.', 'faluss-link' ) ); }
        self::editor_assets();
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        $preferences = self::valid_prefs( $faluss_id );
        $blocks = self::content_blocks( $faluss_id, $profile['links'], false );
        $aggregate_version = self::studio_aggregate_version( $faluss_id );
        $collections = self::studio_collections( $blocks );
        $active_tab = self::studio_tab( $_GET['faluss_studio_tab'] ?? 'links' );
        $active_tab = 'profile' === $active_tab ? 'style' : $active_tab;
        $active_section = self::studio_section( $_GET['faluss_studio_section'] ?? ( 'style' === $active_tab ? 'appearance' : 'all' ), $active_tab );
        $active_collection = self::studio_collection_id( $_GET['faluss_studio_collection'] ?? '', $collections );
        if ( 'collection' === $active_section && '' === $active_collection ) { $active_section = 'collections'; }
        $public_url = '' !== $profile['public_slug'] && 'published' === $profile['publication_status'] ? home_url( '/' . $profile['public_slug'] ) : '';
        ob_start();
        ?>
        <section class="faluss-link-studio" data-faluss-studio="v1" data-faluss-studio-tab="<?php echo esc_attr( $active_tab ); ?>" data-faluss-studio-section="<?php echo esc_attr( $active_section ); ?>" data-faluss-studio-collection="<?php echo esc_attr( $active_collection ); ?>" data-faluss-studio-version="<?php echo esc_attr( $aggregate_version ); ?>" data-faluss-studio-back-url="<?php echo esc_url( home_url( '/' ) ); ?>">
            <form class="faluss-link-studio__form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
                <input type="hidden" name="action" value="faluss_link_save_studio">
                <input type="hidden" name="faluss_studio_response" value="json">
                <input type="hidden" name="faluss_studio_tab" data-fl-active-tab value="<?php echo esc_attr( $active_tab ); ?>">
                <input type="hidden" name="faluss_studio_section" data-fl-active-section value="<?php echo esc_attr( $active_section ); ?>">
                <input type="hidden" name="faluss_studio_collection" data-fl-active-collection value="<?php echo esc_attr( $active_collection ); ?>">
                <input type="hidden" name="aggregate_version" data-fl-aggregate-version value="<?php echo esc_attr( $aggregate_version ); ?>">
                <?php wp_nonce_field( 'faluss_link_save_studio', 'faluss_link_studio_nonce' ); ?>
                <?php self::studio_block_store( $blocks ); ?>
                <input type="hidden" name="bio_mode" value="<?php echo esc_attr( $preferences['bio_mode'] ); ?>">
                <input type="hidden" name="announcement" value="<?php echo esc_attr( $preferences['announcement'] ); ?>">
                <input type="hidden" name="announcement_variant" value="<?php echo esc_attr( $preferences['announcement_variant'] ); ?>">

                <header class="faluss-link-studio__topbar">
                    <button class="faluss-link-studio__round-action" type="button" data-fl-studio-back aria-label="<?php esc_attr_e( 'Revenir en arrière', 'faluss-link' ); ?>"><span aria-hidden="true">←</span></button>
                    <div class="faluss-link-studio__header-actions">
                        <button class="faluss-link-studio__round-action" type="button" data-fl-studio-ecosystem aria-label="<?php esc_attr_e( 'Découvrir l’écosystème Faluss', 'faluss-link' ); ?>"><span aria-hidden="true">•••</span></button>
                        <?php if ( '' !== $public_url ) : ?><button class="faluss-link-studio__round-action" type="button" data-fl-studio-share data-public-url="<?php echo esc_url( $public_url ); ?>" aria-label="<?php esc_attr_e( 'Partager mon Faluss public', 'faluss-link' ); ?>"><span aria-hidden="true">↗</span></button><?php else : ?><button class="faluss-link-studio__round-action" type="button" disabled aria-label="<?php esc_attr_e( 'Publiez votre Faluss pour pouvoir le partager', 'faluss-link' ); ?>"><span aria-hidden="true">↗</span></button><?php endif; ?>
                    </div>
                </header>

                <?php self::studio_member_header( $profile, $preferences ); ?>

                <div class="faluss-link-studio__notice" role="status" aria-live="polite"></div>

                <div class="faluss-link-studio__main" data-fl-studio-screen="main">
                    <section class="faluss-link-studio__main-panel" data-fl-main-panel="links"<?php echo 'links' === $active_tab ? '' : ' hidden inert'; ?>>
                        <nav class="faluss-link-studio__context-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Navigation des liens', 'faluss-link' ); ?>">
                            <span class="faluss-link-studio__context-indicator" aria-hidden="true"></span>
                            <button id="faluss-studio-tab-all" type="button" role="tab" data-fl-context-tab="all" aria-controls="faluss-studio-panel-all" aria-selected="<?php echo 'all' === $active_section ? 'true' : 'false'; ?>" tabindex="<?php echo 'all' === $active_section ? '0' : '-1'; ?>"><?php esc_html_e( 'Tous', 'faluss-link' ); ?></button>
                            <button id="faluss-studio-tab-collections" type="button" role="tab" data-fl-context-tab="collections" aria-controls="faluss-studio-panel-collections" aria-selected="<?php echo in_array( $active_section, array( 'collections', 'collection' ), true ) ? 'true' : 'false'; ?>" tabindex="<?php echo in_array( $active_section, array( 'collections', 'collection' ), true ) ? '0' : '-1'; ?>"><?php esc_html_e( 'Collections', 'faluss-link' ); ?></button>
                        </nav>
                        <div class="faluss-link-studio__content">
                            <section id="faluss-studio-panel-all" role="tabpanel" aria-labelledby="faluss-studio-tab-all" data-fl-section-panel="all"<?php echo 'all' === $active_section ? '' : ' hidden inert'; ?>><?php self::studio_links_panel( $blocks ); ?></section>
                            <section id="faluss-studio-panel-collections" role="tabpanel" aria-labelledby="faluss-studio-tab-collections" data-fl-section-panel="collections"<?php echo 'collections' === $active_section && '' === $active_collection ? '' : ' hidden inert'; ?>><?php self::studio_collections_panel( $collections ); ?></section>
                            <?php if ( '' !== $active_collection ) : ?><section id="faluss-studio-panel-collection" role="tabpanel" aria-labelledby="faluss-studio-tab-collections" data-fl-section-panel="collection"><?php self::studio_collection_panel( $collections[ $active_collection ] ); ?></section><?php endif; ?>
                        </div>
                    </section>

                    <section class="faluss-link-studio__main-panel" data-fl-main-panel="style"<?php echo 'style' === $active_tab ? '' : ' hidden inert'; ?>>
                        <nav class="faluss-link-studio__context-tabs faluss-link-studio__context-tabs--design" role="tablist" aria-label="<?php esc_attr_e( 'Navigation du design', 'faluss-link' ); ?>">
                            <span class="faluss-link-studio__context-indicator" aria-hidden="true"></span>
                            <button id="faluss-studio-tab-appearance" type="button" role="tab" data-fl-context-tab="appearance" aria-controls="faluss-studio-panel-appearance" aria-selected="<?php echo 'appearance' === $active_section ? 'true' : 'false'; ?>" tabindex="<?php echo 'appearance' === $active_section ? '0' : '-1'; ?>"><?php esc_html_e( 'Apparence', 'faluss-link' ); ?></button>
                            <button id="faluss-studio-tab-header" type="button" role="tab" data-fl-context-tab="header" aria-controls="faluss-studio-panel-header" aria-selected="<?php echo 'header' === $active_section ? 'true' : 'false'; ?>" tabindex="<?php echo 'header' === $active_section ? '0' : '-1'; ?>"><?php esc_html_e( 'En-tête', 'faluss-link' ); ?></button>
                            <button id="faluss-studio-tab-link-style" type="button" role="tab" data-fl-context-tab="link-style" aria-controls="faluss-studio-panel-link-style" aria-selected="<?php echo 'link-style' === $active_section ? 'true' : 'false'; ?>" tabindex="<?php echo 'link-style' === $active_section ? '0' : '-1'; ?>"><?php esc_html_e( 'Liens', 'faluss-link' ); ?></button>
                        </nav>
                        <div class="faluss-link-studio__content faluss-link-studio__design-content">
                            <section id="faluss-studio-panel-appearance" role="tabpanel" aria-labelledby="faluss-studio-tab-appearance" data-fl-section-panel="appearance"<?php echo 'appearance' === $active_section ? '' : ' hidden inert'; ?>><?php self::studio_appearance_panel( $preferences ); ?></section>
                            <section id="faluss-studio-panel-header" role="tabpanel" aria-labelledby="faluss-studio-tab-header" data-fl-section-panel="header"<?php echo 'header' === $active_section ? '' : ' hidden inert'; ?>><?php self::studio_header_panel( $profile, $preferences ); ?></section>
                            <section id="faluss-studio-panel-link-style" role="tabpanel" aria-labelledby="faluss-studio-tab-link-style" data-fl-section-panel="link-style"<?php echo 'link-style' === $active_section ? '' : ' hidden inert'; ?>><?php self::studio_link_style_panel( $preferences ); ?></section>
                        </div>
                    </section>
                </div>

                <?php self::studio_create_views(); ?>

                <aside id="faluss-studio-preview" class="faluss-link-studio__preview" data-fl-preview aria-label="<?php esc_attr_e( 'Aperçu vivant de ma carte Faluss', 'faluss-link' ); ?>" tabindex="-1" hidden>
                    <div class="faluss-link-studio__preview-bar"><h2><?php esc_html_e( 'Aperçu', 'faluss-link' ); ?></h2><button type="button" data-fl-preview-close aria-label="<?php esc_attr_e( 'Fermer l’aperçu', 'faluss-link' ); ?>">×</button></div>
                    <?php echo self::card_preview_markup( $profile, $preferences, $preferences['alignment'], $blocks, false, 'studio-preview' ); ?>
                </aside>

                <nav class="faluss-link-studio__dock" aria-label="<?php esc_attr_e( 'Navigation principale du Studio', 'faluss-link' ); ?>">
                    <button class="faluss-link-studio__create" type="button" data-fl-create aria-label="<?php esc_attr_e( 'Créer', 'faluss-link' ); ?>"><span aria-hidden="true">+</span></button>
                    <button class="faluss-link-studio__preview-toggle" type="button" data-fl-preview-toggle aria-controls="faluss-studio-preview" aria-expanded="false"><span class="faluss-link-studio__eyes" aria-hidden="true"><i></i><i></i></span><span class="screen-reader-text"><?php esc_html_e( 'Ouvrir le véritable aperçu Faluss', 'faluss-link' ); ?></span><span class="faluss-link-studio__dirty-count" data-fl-dirty-count hidden aria-live="polite">0</span></button>
                    <div class="faluss-link-studio__dock-tabs">
                        <span class="faluss-link-studio__dock-indicator" aria-hidden="true"></span>
                        <button type="button" data-fl-tab="links" aria-pressed="<?php echo 'links' === $active_tab ? 'true' : 'false'; ?>"><?php esc_html_e( 'Liens', 'faluss-link' ); ?></button>
                        <button type="button" disabled aria-disabled="true"><?php esc_html_e( 'Shop', 'faluss-link' ); ?></button>
                        <button type="button" data-fl-tab="style" aria-pressed="<?php echo 'style' === $active_tab ? 'true' : 'false'; ?>"><?php esc_html_e( 'Design', 'faluss-link' ); ?></button>
                        <button type="button" disabled aria-disabled="true"><?php esc_html_e( 'Profil', 'faluss-link' ); ?></button>
                    </div>
                </nav>
            </form>
        </section>
        <?php return (string) ob_get_clean();
    }

    public static function save() {
        if ( ! self::verify( 'faluss_link_nonce', 'faluss_link_save' ) ) { wp_die( 'Accès refusé.' ); }
        $faluss_id = \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId();
        self::redirect( 'faluss_link_notice', $faluss_id && self::save_preferences( $faluss_id, $_POST ) ? 'saved' : 'invalid' );
    }

    public static function save_studio() {
        if ( ! self::verify( 'faluss_link_studio_nonce', 'faluss_link_save_studio' ) ) { wp_die( 'Accès refusé.' ); }
        $faluss_id = \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId();
        $request = self::studio_mutation_request( $_POST );
        $result = $faluss_id && ! is_wp_error( $request ) ? self::run_studio_mutation( $faluss_id, $request ) : array( 'ok' => false, 'status' => 422, 'code' => 'invalid', 'message' => __( 'Mutation Studio invalide.', 'faluss-link' ) );
        if ( 'json' === sanitize_key( (string) ( $_POST['faluss_studio_response'] ?? '' ) ) ) {
            $payload = array( 'code' => $result['code'] ?? 'invalid', 'message' => $result['message'] ?? __( 'Mutation Studio impossible.', 'faluss-link' ) );
            if ( isset( $result['state'] ) ) { $payload['state'] = $result['state']; }
            if ( ! empty( $result['ok'] ) ) { wp_send_json_success( $payload ); }
            wp_send_json_error( $payload, (int) ( $result['status'] ?? 422 ) );
        }
        self::redirect( 'faluss_studio_notice', ! empty( $result['ok'] ) ? 'saved' : 'invalid', self::studio_tab( $_POST['faluss_studio_tab'] ?? 'links' ) );
    }

    /** Closed request contracts: browser state outside the selected mutation is rejected. */
    private static function studio_mutation_request( $post ) {
        $contracts = array(
            'save_appearance'      => array( 'selected_theme', 'cover_attachment_id', 'page_background', 'hero_transition_color', 'hero_transition_intensity', 'hero_transition_position' ),
            'save_header'          => array( 'available', 'avatar_visible', 'avatar_border', 'name_font', 'name_treatment', 'name_color', 'alignment', 'social_networks', 'social_layout', 'social_variant' ),
            'save_link_style'      => array( 'button_color', 'link_style' ),
            'save_profile'         => array( 'display_name', 'bio', 'avatar_attachment_id', 'publication_status' ),
            'create_link'          => array( 'block_id', 'label', 'url', 'collection_id' ),
            'update_link'          => array( 'block_id', 'label', 'url' ),
            'delete_link'          => array( 'block_id' ),
            'create_collection'    => array( 'block_id', 'description_block_id', 'name', 'description' ),
            'update_collection'    => array( 'block_id', 'name', 'description' ),
            'dissolve_collection'  => array( 'block_id' ),
            'reorder_blocks'       => array( 'block_ids' ),
        );
        $mutation = sanitize_key( (string) ( $post['mutation'] ?? '' ) );
        $version = strtolower( sanitize_text_field( (string) ( $post['aggregate_version'] ?? '' ) ) );
        if ( ! isset( $contracts[ $mutation ] ) || 1 !== preg_match( '/^[0-9a-f]{64}$/D', $version ) ) {
            return new WP_Error( 'invalid_mutation' );
        }
        $system = array( 'action', 'mutation', 'aggregate_version', 'faluss_link_studio_nonce', 'faluss_studio_response', 'faluss_studio_tab', 'faluss_studio_section', 'faluss_studio_collection' );
        if ( array_diff( array_keys( (array) $post ), array_merge( $system, $contracts[ $mutation ] ) ) ) {
            return new WP_Error( 'unexpected_mutation_field' );
        }
        $payload = array();
        foreach ( $contracts[ $mutation ] as $field ) {
            if ( array_key_exists( $field, $post ) ) { $payload[ $field ] = wp_unslash( $post[ $field ] ); }
        }
        return array( 'mutation' => $mutation, 'version' => $version, 'payload' => $payload, 'active_collection' => (string) ( $post['faluss_studio_collection'] ?? '' ) );
    }

    /** One member aggregate, one MariaDB transaction, one commit owner. */
    private static function run_studio_mutation( $faluss_id, $request ) {
        global $wpdb;
        $block_mutations = array( 'create_link', 'update_link', 'delete_link', 'create_collection', 'update_collection', 'dissolve_collection', 'reorder_blocks' );
        if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() || false === $wpdb->query( 'START TRANSACTION' ) ) {
            return array( 'ok' => false, 'status' => 503, 'code' => 'transaction_unavailable', 'message' => __( 'La sauvegarde transactionnelle est indisponible.', 'faluss-link' ) );
        }
        try {
            if ( ! self::mutation_checkpoint( 'transaction_started' ) ) { throw new RuntimeException( 'checkpoint' ); }
            $identity_row = \Faluss\Platform\Link\LinkIdentityAdapter::lockStudioProfileInTransaction( $faluss_id );
            $card_row = self::lock_studio_card_in_transaction( $faluss_id );
            $block_rows = self::lock_studio_blocks_in_transaction( $faluss_id );
            if ( false === $identity_row || false === $card_row || false === $block_rows ) { throw new RuntimeException( 'lock' ); }
            $profile = self::profile_from_identity_row( $identity_row );
            $preferences = self::prefs( $faluss_id, false );
            $legacy_blocks_only = ! $block_rows && ! empty( $profile['links'] );
            $blocks = $legacy_blocks_only ? self::legacy_blocks( $faluss_id, $profile['links'] ) : self::blocks_from_rows( $block_rows );
            $current_version = self::aggregate_version_from_state( $profile, $preferences, $blocks );
            if ( ! hash_equals( $current_version, $request['version'] ) ) {
                $wpdb->query( 'ROLLBACK' );
                return array( 'ok' => false, 'status' => 409, 'code' => 'stale_version', 'message' => __( 'Conflit détecté : le Studio a été réhydraté depuis la version canonique.', 'faluss-link' ), 'state' => self::canonical_studio_state( $faluss_id, $request['active_collection'] ) );
            }

            $mutation = $request['mutation'];
            if ( $legacy_blocks_only && in_array( $mutation, $block_mutations, true ) ) {
                $wpdb->query( 'ROLLBACK' );
                return array( 'ok' => false, 'status' => 409, 'code' => 'legacy_blocks_not_initialized', 'message' => __( 'Les liens historiques sont affichés en lecture seule. Leur import explicite doit être traité séparément.', 'faluss-link' ), 'state' => self::canonical_studio_state( $faluss_id, $request['active_collection'] ) );
            }
            if ( 'save_profile' === $mutation ) {
                if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::persistStudioProfileInTransaction( $faluss_id, $request['payload'] ) ) { throw new RuntimeException( 'profile' ); }
            } elseif ( in_array( $mutation, array( 'save_appearance', 'save_header', 'save_link_style' ), true ) ) {
                $saved = self::persist_studio_preferences_in_transaction( $faluss_id, $request['payload'] );
                if ( is_wp_error( $saved ) ) {
                    $wpdb->query( 'ROLLBACK' );
                    $code = $saved->get_error_code();
                    return array( 'ok' => false, 'status' => 422, 'code' => $code, 'message' => 'theme_locked' === $code ? __( 'Ce thème est verrouillé pour ce compte.', 'faluss-link' ) : __( 'Ce thème est indisponible.', 'faluss-link' ), 'state' => self::canonical_studio_state( $faluss_id, $request['active_collection'] ) );
                }
                if ( ! $saved ) { throw new RuntimeException( 'preferences' ); }
            } elseif ( in_array( $mutation, $block_mutations, true ) ) {
                if ( ! self::mutate_blocks_in_transaction( $faluss_id, $mutation, $request['payload'] ) ) { throw new RuntimeException( 'blocks' ); }
                if ( ! self::mutation_checkpoint( 'before_projection' ) ) { throw new RuntimeException( 'projection_checkpoint' ); }
                $canonical_blocks = self::stored_blocks( $faluss_id )['blocks'];
                if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::persistExternalLinksInTransaction( $faluss_id, self::identity_links( $canonical_blocks ) ) ) { throw new RuntimeException( 'projection' ); }
                if ( ! self::mutation_checkpoint( 'after_projection' ) ) { throw new RuntimeException( 'projection_checkpoint' ); }
            } else {
                throw new RuntimeException( 'mutation' );
            }
            if ( ! self::mutation_checkpoint( 'before_commit' ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'commit' ); }
            return array( 'ok' => true, 'status' => 200, 'code' => 'saved', 'message' => __( 'Studio enregistré.', 'faluss-link' ), 'state' => self::canonical_studio_state( $faluss_id, $request['active_collection'] ) );
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return array( 'ok' => false, 'status' => 422, 'code' => 'mutation_failed', 'message' => __( 'La mutation a été annulée intégralement.', 'faluss-link' ), 'state' => self::canonical_studio_state( $faluss_id, $request['active_collection'] ) );
        }
    }

    private static function mutation_checkpoint( $stage ) {
        return ! function_exists( 'apply_filters' ) || false !== apply_filters( 'faluss_link_studio_mutation_checkpoint', true, sanitize_key( $stage ) );
    }

    /** @return array<string,mixed>|false Empty array means a valid absent card row. */
    private static function lock_studio_card_in_transaction( $faluss_id ) {
        global $wpdb; $table = Faluss_Link_Schema::table();
        if ( '' === $table ) { return false; }
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $table . ' WHERE faluss_id=%s FOR UPDATE', $faluss_id ), ARRAY_A );
        return is_array( $row ) ? $row : array();
    }

    /** @return array<int,array<string,mixed>>|false */
    private static function lock_studio_blocks_in_transaction( $faluss_id ) {
        global $wpdb; $table = Faluss_Link_Schema::blocks_table();
        if ( '' === $table ) { return false; }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT block_id,sort_order,block_type,payload FROM ' . $table . ' WHERE faluss_id=%s ORDER BY sort_order ASC,id ASC FOR UPDATE', $faluss_id ), ARRAY_A );
        return is_array( $rows ) ? $rows : false;
    }

    private static function profile_from_identity_row( $row ) {
        $links = json_decode( (string) ( $row['external_links'] ?? '[]' ), true );
        return array( 'public_slug' => (string) ( $row['public_slug'] ?? '' ), 'display_name' => (string) ( $row['display_name'] ?? '' ), 'bio' => (string) ( $row['bio'] ?? '' ), 'avatar_attachment_id' => (int) ( $row['avatar_attachment_id'] ?? 0 ), 'publication_status' => 'published' === ( $row['publication_status'] ?? '' ) ? 'published' : 'draft', 'links' => is_array( $links ) ? $links : array(), 'published_at' => $row['published_at'] ?? null );
    }

    private static function blocks_from_rows( $rows ) {
        $blocks = array(); $seen = array();
        foreach ( (array) $rows as $row ) { $block = self::hydrate_stored_block( $row ); if ( $block && ! isset( $seen[ $block['block_id'] ] ) ) { $seen[ $block['block_id'] ] = true; $blocks[] = $block; } }
        return $blocks;
    }

    private static function aggregate_version_from_state( $profile, $preferences, $blocks ) {
        $profile_state = array();
        foreach ( array( 'public_slug', 'display_name', 'bio', 'avatar_attachment_id', 'publication_status', 'links', 'published_at' ) as $key ) { $profile_state[ $key ] = $profile[ $key ] ?? null; }
        $preference_state = array();
        foreach ( array( 'cover_attachment_id', 'avatar_visible', 'avatar_border', 'name_font', 'name_treatment', 'available', 'bio_mode', 'announcement', 'announcement_variant', 'social_links', 'social_layout', 'link_style', 'alignment', 'page_background', 'button_color', 'hero_transition_color', 'hero_transition_intensity', 'hero_transition_position', 'name_color', 'social_variant', 'theme_reference', 'theme_overrides' ) as $key ) { $preference_state[ $key ] = $preferences[ $key ] ?? null; }
        return hash( 'sha256', wp_json_encode( array( 'profile' => $profile_state, 'preferences' => $preference_state, 'blocks' => array_values( (array) $blocks ) ) ) );
    }

    private static function studio_aggregate_version( $faluss_id ) {
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        $preferences = self::prefs( $faluss_id, false );
        $blocks = self::content_blocks( $faluss_id, $profile['links'] ?? array(), false );
        return self::aggregate_version_from_state( $profile, $preferences, $blocks );
    }

    /** Complete owner-only read model returned after success and conflict. */
    private static function canonical_studio_state( $faluss_id, $requested_collection = '' ) {
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        $stored_preferences = self::prefs( $faluss_id, false );
        $preferences = self::resolve_card_presentation( $stored_preferences, true );
        $blocks = self::content_blocks( $faluss_id, $profile['links'] ?? array(), false );
        $collections = self::studio_collections( $blocks );
        $active_collection = self::studio_collection_id( $requested_collection, $collections );
        ob_start(); self::studio_collections_panel( $collections ); $collections_html = (string) ob_get_clean();
        $collection_html = '';
        if ( '' !== $active_collection ) { ob_start(); self::studio_collection_panel( $collections[ $active_collection ] ); $collection_html = (string) ob_get_clean(); }
        $client_preferences = $preferences;
        unset( $client_preferences['faluss_id'], $client_preferences['_has_row'] );
        $client_preferences['social_links'] = self::socials( $stored_preferences['social_links'] ?? array() );
        $client_preferences['cover_url'] = (int) $preferences['cover_attachment_id'] ? (string) wp_get_attachment_image_url( (int) $preferences['cover_attachment_id'], 'medium' ) : '';
        return array(
            'version' => self::aggregate_version_from_state( $profile, $stored_preferences, $blocks ),
            'profile' => array( 'public_slug' => (string) ( $profile['public_slug'] ?? '' ), 'display_name' => (string) ( $profile['display_name'] ?? '' ), 'bio' => (string) ( $profile['bio'] ?? '' ), 'avatar_attachment_id' => (int) ( $profile['avatar_attachment_id'] ?? 0 ), 'publication_status' => (string) ( $profile['publication_status'] ?? 'draft' ) ),
            'preferences' => $client_preferences,
            'blocks' => $blocks,
            'links_html' => self::studio_links_panel_html( $blocks ),
            'collections_html' => $collections_html,
            'collection_html' => $collection_html,
            'active_collection' => $active_collection,
            'preview_html' => self::card_preview_markup( $profile, $preferences, $preferences['alignment'], $blocks, false, 'studio-preview' ),
        );
    }

    /** Merge only the fields owned by one targeted preference mutation. */
    private static function persist_studio_preferences_in_transaction( $faluss_id, $fields ) {
        global $wpdb;
        if ( ! is_array( $fields ) || ! $fields ) { return false; }
        $old = self::prefs( $faluss_id, false );
        $payload = json_decode( (string) ( $old['social_links'] ?? '[]' ), true );
        if ( ! is_array( $payload ) ) { $payload = array(); }
        $payload['networks'] = self::socials( $payload['networks'] ?? array() );
        $payload['social_selected'] = self::onboarding_network_selection( $payload['social_selected'] ?? $payload['networks'] );
        $payload['avatar_border'] = ! empty( $payload['avatar_border'] ) ? 1 : 0;
        $payload['name_font'] = self::onboarding_name_font( $payload['name_font'] ?? 'outfit' );
        $payload['selected_theme'] = self::theme_reference( $payload['selected_theme'] ?? 'faluss-default' );
        $payload['theme_overrides'] = self::theme_overrides( $payload['theme_overrides'] ?? array() );
        $updates = array();
        $formats = array();
        $payload_changed = false;

        foreach ( $fields as $key => $value ) {
            if ( 'selected_theme' === $key ) {
                $reference = self::theme_reference( $value );
                $theme = self::catalog_theme( $reference );
                if ( ! $theme ) { return new WP_Error( 'theme_unavailable' ); }
                if ( ! self::theme_available_to_subject( $theme, $faluss_id ) ) { return new WP_Error( 'theme_locked' ); }
                $payload['selected_theme'] = $reference;
                $payload['theme_overrides'] = array();
                $old['theme_reference'] = $reference;
                $old['theme_overrides'] = array();
                $payload_changed = true;
                continue;
            }
            if ( in_array( $key, array( 'page_background', 'button_color', 'hero_transition_color' ), true ) ) {
                $color = self::valid_hex( $value );
                if ( '' === $color ) { return false; }
                $payload[ $key ] = $color; $old[ $key ] = $color; $payload_changed = true;
                if ( in_array( $key, self::theme_setting_keys(), true ) && ! in_array( $key, $payload['theme_overrides'], true ) ) { $payload['theme_overrides'][] = $key; }
                continue;
            }
            if ( in_array( $key, array( 'hero_transition_intensity', 'hero_transition_position' ), true ) ) {
                $minimum = 'hero_transition_position' === $key ? 35 : 0;
                $number = min( 100, max( $minimum, (int) $value ) );
                $payload[ $key ] = $number; $old[ $key ] = $number; $payload_changed = true;
                continue;
            }
            if ( 'name_color' === $key ) {
                $color = self::name_color( $value ); $payload['name_color'] = $color; $old['name_color'] = $color; $payload_changed = true;
                if ( ! in_array( 'name_color', $payload['theme_overrides'], true ) ) { $payload['theme_overrides'][] = 'name_color'; }
                continue;
            }
            if ( 'alignment' === $key ) {
                $alignment = self::align( $value ); $payload['alignment'] = $alignment; $old['alignment'] = $alignment; $payload_changed = true;
                if ( ! in_array( 'alignment', $payload['theme_overrides'], true ) ) { $payload['theme_overrides'][] = 'alignment'; }
                continue;
            }
            if ( 'social_variant' === $key ) {
                $variant = self::social_variant( $value ); $payload['social_variant'] = $variant; $old['social_variant'] = $variant; $payload_changed = true;
                if ( ! in_array( 'social_variant', $payload['theme_overrides'], true ) ) { $payload['theme_overrides'][] = 'social_variant'; }
                continue;
            }
            if ( 'avatar_border' === $key ) {
                $boolean = ! empty( $value ) ? 1 : 0; $payload['avatar_border'] = $boolean; $old['avatar_border'] = $boolean; $payload_changed = true;
                continue;
            }
            if ( 'name_font' === $key ) {
                $font = self::onboarding_name_font( $value ); $payload['name_font'] = $font; $old['name_font'] = $font; $payload_changed = true;
                continue;
            }
            if ( 'social_networks' === $key ) {
                $networks = self::socials( is_array( $value ) ? $value : array() );
                $provided = 0;
                foreach ( (array) $value as $network ) { if ( is_array( $network ) && ( '' !== trim( (string) ( $network['network'] ?? '' ) ) || '' !== trim( (string) ( $network['url'] ?? '' ) ) ) ) { ++$provided; } }
                if ( $provided !== count( $networks ) ) { return false; }
                $payload['networks'] = $networks; $old['social_links'] = wp_json_encode( $payload ); $payload_changed = true;
                continue;
            }
            if ( 'social_selected' === $key ) {
                $payload['social_selected'] = self::onboarding_network_selection( $value ); $payload_changed = true;
                continue;
            }

            $column = '';
            $clean = null;
            if ( 'cover_attachment_id' === $key ) {
                $clean = absint( $value );
                if ( $clean && ! self::owned_image( $clean, get_current_user_id() ) ) { return false; }
                $column = $key; $formats[] = '%d';
            } elseif ( in_array( $key, array( 'available', 'avatar_visible' ), true ) ) {
                $clean = ! empty( $value ) ? 1 : 0; $column = $key; $formats[] = '%d';
            } elseif ( 'name_treatment' === $key ) {
                $clean = sanitize_key( (string) $value );
                if ( ! isset( self::NAME_TREATMENTS[ $clean ] ) ) { return false; }
                $column = $key; $formats[] = '%s';
            } elseif ( 'social_layout' === $key ) {
                $clean = sanitize_key( (string) $value );
                if ( ! isset( self::LAYOUTS[ $clean ] ) ) { return false; }
                $column = $key; $formats[] = '%s';
            } elseif ( 'link_style' === $key ) {
                $clean = sanitize_key( (string) $value );
                if ( ! isset( self::LINK_STYLES[ $clean ] ) ) { return false; }
                $column = $key; $formats[] = '%s'; $payload['link_style'] = $clean; $payload_changed = true;
                if ( ! in_array( 'link_style', $payload['theme_overrides'], true ) ) { $payload['theme_overrides'][] = 'link_style'; }
            } else {
                return false;
            }
            $updates[ $column ] = $clean; $old[ $column ] = $clean;
        }

        if ( $payload_changed ) {
            $updates['social_links'] = wp_json_encode( $payload );
            $formats[] = '%s';
            $old['social_links'] = $updates['social_links'];
        }
        if ( ! self::mutation_checkpoint( 'before_preferences_write' ) ) { return false; }
        $now = current_time( 'mysql', true );
        $table = Faluss_Link_Schema::table();
        if ( empty( $old['_has_row'] ) ) {
            $values = array(
                'faluss_id' => $faluss_id, 'cover_attachment_id' => (int) $old['cover_attachment_id'], 'avatar_visible' => (int) $old['avatar_visible'], 'name_weight' => 'bold', 'name_treatment' => (string) $old['name_treatment'], 'available' => (int) $old['available'], 'bio_mode' => (string) $old['bio_mode'], 'announcement' => (string) $old['announcement'], 'announcement_variant' => (string) $old['announcement_variant'], 'social_links' => (string) $old['social_links'], 'social_layout' => (string) $old['social_layout'], 'link_style' => (string) $old['link_style'], 'created_at' => $now, 'updated_at' => $now,
            );
            return false !== $wpdb->insert( $table, $values, array( '%s', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' ) );
        }
        $updates['updated_at'] = $now; $formats[] = '%s';
        return false !== $wpdb->update( $table, $updates, array( 'faluss_id' => $faluss_id ), $formats, array( '%s' ) );
    }

    /** Precise block operations; absence from a payload can never delete a row. */
    private static function mutate_blocks_in_transaction( $faluss_id, $mutation, $payload ) {
        global $wpdb;
        $table = Faluss_Link_Schema::blocks_table();
        $blocks = self::stored_blocks( $faluss_id )['blocks'];
        $by_id = array(); foreach ( $blocks as $index => $block ) { $by_id[ $block['block_id'] ] = $index; }
        $id = strtolower( (string) ( $payload['block_id'] ?? '' ) );
        if ( 'create_link' === $mutation ) {
            if ( count( $blocks ) >= 32 || ! self::valid_block_id( $id ) || isset( $by_id[ $id ] ) ) { return false; }
            $block = self::normalise_block( array( 'block_id' => $id, 'type' => 'link', 'label' => $payload['label'] ?? '', 'url' => $payload['url'] ?? '' ), true );
            if ( ! $block ) { return false; }
            $insert = count( $blocks ); $collection_id = strtolower( (string) ( $payload['collection_id'] ?? '' ) );
            if ( '' !== $collection_id ) {
                if ( ! isset( $by_id[ $collection_id ] ) || 'section_title' !== $blocks[ $by_id[ $collection_id ] ]['type'] ) { return false; }
                $insert = $by_id[ $collection_id ] + 1;
                while ( $insert < count( $blocks ) && 'section_title' !== $blocks[ $insert ]['type'] ) { ++$insert; }
            } else {
                foreach ( $blocks as $index => $candidate ) { if ( 'section_title' === $candidate['type'] ) { $insert = $index; break; } }
            }
            return self::insert_block_in_transaction( $faluss_id, $block, $insert + 1 );
        }
        if ( 'update_link' === $mutation ) {
            if ( ! isset( $by_id[ $id ] ) || 'link' !== $blocks[ $by_id[ $id ] ]['type'] ) { return false; }
            $block = self::normalise_block( array( 'block_id' => $id, 'type' => 'link', 'label' => $payload['label'] ?? '', 'url' => $payload['url'] ?? '' ), true );
            return $block ? self::update_block_payload_in_transaction( $faluss_id, $block ) : false;
        }
        if ( 'delete_link' === $mutation ) {
            if ( ! isset( $by_id[ $id ] ) || 'link' !== $blocks[ $by_id[ $id ] ]['type'] || ! self::mutation_checkpoint( 'before_block_delete' ) ) { return false; }
            $order = $by_id[ $id ] + 1;
            if ( 1 !== (int) $wpdb->delete( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $id, 'block_type' => 'link' ), array( '%s', '%s', '%s' ) ) ) { return false; }
            return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET sort_order=sort_order-1,updated_at=%s WHERE faluss_id=%s AND sort_order>%d ORDER BY sort_order ASC', current_time( 'mysql', true ), $faluss_id, $order ) );
        }
        if ( 'create_collection' === $mutation ) {
            $description = self::block_text( $payload['description'] ?? '', 480, true );
            $description_id = strtolower( (string) ( $payload['description_block_id'] ?? '' ) );
            $needed = '' === $description ? 1 : 2;
            if ( count( $blocks ) + $needed > 32 || ! self::valid_block_id( $id ) || isset( $by_id[ $id ] ) || ( $description && ( ! self::valid_block_id( $description_id ) || isset( $by_id[ $description_id ] ) || hash_equals( $id, $description_id ) ) ) ) { return false; }
            $collection = self::normalise_block( array( 'block_id' => $id, 'type' => 'section_title', 'value' => $payload['name'] ?? '' ), true );
            if ( ! $collection || ! self::insert_block_in_transaction( $faluss_id, $collection, count( $blocks ) + 1 ) ) { return false; }
            return '' === $description || self::insert_block_in_transaction( $faluss_id, array( 'block_id' => $description_id, 'type' => 'text', 'value' => $description ), count( $blocks ) + 2 );
        }
        if ( 'update_collection' === $mutation ) {
            if ( ! isset( $by_id[ $id ] ) || 'section_title' !== $blocks[ $by_id[ $id ] ]['type'] ) { return false; }
            $collection = self::normalise_block( array( 'block_id' => $id, 'type' => 'section_title', 'value' => $payload['name'] ?? '' ), true );
            if ( ! $collection || ! self::update_block_payload_in_transaction( $faluss_id, $collection ) ) { return false; }
            $index = $by_id[ $id ]; $description = self::block_text( $payload['description'] ?? '', 480, true );
            $description_block = isset( $blocks[ $index + 1 ] ) && 'text' === $blocks[ $index + 1 ]['type'] ? $blocks[ $index + 1 ] : null;
            if ( '' !== $description && $description_block ) { $description_block['value'] = $description; return self::update_block_payload_in_transaction( $faluss_id, $description_block ); }
            if ( '' !== $description ) { return count( $blocks ) < 32 && self::insert_block_in_transaction( $faluss_id, array( 'block_id' => wp_generate_uuid4(), 'type' => 'text', 'value' => $description ), $index + 2 ); }
            if ( $description_block ) {
                if ( 1 !== (int) $wpdb->delete( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $description_block['block_id'], 'block_type' => 'text' ), array( '%s', '%s', '%s' ) ) ) { return false; }
                return false !== $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET sort_order=sort_order-1,updated_at=%s WHERE faluss_id=%s AND sort_order>%d ORDER BY sort_order ASC', current_time( 'mysql', true ), $faluss_id, $index + 2 ) );
            }
            return true;
        }
        if ( 'dissolve_collection' === $mutation ) {
            if ( ! isset( $by_id[ $id ] ) || 'section_title' !== $blocks[ $by_id[ $id ] ]['type'] ) { return false; }
            $index = $by_id[ $id ]; $end = $index + 1;
            while ( $end < count( $blocks ) && 'section_title' !== $blocks[ $end ]['type'] ) { ++$end; }
            $description_id = isset( $blocks[ $index + 1 ] ) && 'text' === $blocks[ $index + 1 ]['type'] ? $blocks[ $index + 1 ]['block_id'] : '';
            $moving = array_slice( $blocks, $index + 1 + ( '' !== $description_id ? 1 : 0 ), $end - $index - 1 - ( '' !== $description_id ? 1 : 0 ) );
            $moving_ids = array_column( $moving, 'block_id' );
            $remaining = array_values( array_filter( $blocks, static function( $block ) use ( $id, $description_id, $moving_ids ) { return $block['block_id'] !== $id && $block['block_id'] !== $description_id && ! in_array( $block['block_id'], $moving_ids, true ); } ) );
            $insert = count( $remaining ); foreach ( $remaining as $position => $block ) { if ( 'section_title' === $block['type'] ) { $insert = $position; break; } }
            array_splice( $remaining, $insert, 0, $moving );
            if ( ! self::mutation_checkpoint( 'before_collection_dissolve' ) || 1 !== (int) $wpdb->delete( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $id, 'block_type' => 'section_title' ), array( '%s', '%s', '%s' ) ) ) { return false; }
            if ( '' !== $description_id && 1 !== (int) $wpdb->delete( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $description_id, 'block_type' => 'text' ), array( '%s', '%s', '%s' ) ) ) { return false; }
            return self::write_block_order_in_transaction( $faluss_id, array_column( $remaining, 'block_id' ) );
        }
        if ( 'reorder_blocks' === $mutation ) {
            $ids = array();
            if ( is_array( $payload['block_ids'] ?? null ) ) {
                foreach ( $payload['block_ids'] as $candidate_id ) {
                    if ( ! is_string( $candidate_id ) || ! self::valid_block_id( strtolower( $candidate_id ) ) ) { return false; }
                    $ids[] = strtolower( $candidate_id );
                }
            }
            $canonical_ids = array_column( $blocks, 'block_id' );
            if ( count( $ids ) !== count( $canonical_ids ) || count( array_unique( $ids ) ) !== count( $ids ) || array_diff( $ids, $canonical_ids ) || array_diff( $canonical_ids, $ids ) ) { return false; }
            return self::write_block_order_in_transaction( $faluss_id, $ids );
        }
        return false;
    }

    private static function insert_block_in_transaction( $faluss_id, $block, $position ) {
        global $wpdb; $table = Faluss_Link_Schema::blocks_table();
        if ( ! self::mutation_checkpoint( 'before_block_insert' ) ) { return false; }
        $now = current_time( 'mysql', true );
        if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET sort_order=sort_order+100 WHERE faluss_id=%s AND sort_order>=%d ORDER BY sort_order DESC', $faluss_id, $position ) ) ) { return false; }
        if ( false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET sort_order=sort_order-99,updated_at=%s WHERE faluss_id=%s AND sort_order>=%d ORDER BY sort_order ASC', $now, $faluss_id, $position + 100 ) ) ) { return false; }
        $payload = $block; unset( $payload['block_id'], $payload['type'] );
        return false !== $wpdb->insert( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $block['block_id'], 'sort_order' => $position, 'block_type' => $block['type'], 'payload' => wp_json_encode( $payload ), 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) );
    }

    private static function update_block_payload_in_transaction( $faluss_id, $block ) {
        global $wpdb; if ( ! self::mutation_checkpoint( 'before_block_update' ) ) { return false; }
        $payload = $block; unset( $payload['block_id'], $payload['type'] );
        return false !== $wpdb->update( Faluss_Link_Schema::blocks_table(), array( 'payload' => wp_json_encode( $payload ), 'updated_at' => current_time( 'mysql', true ) ), array( 'faluss_id' => $faluss_id, 'block_id' => $block['block_id'], 'block_type' => $block['type'] ), array( '%s', '%s' ), array( '%s', '%s', '%s' ) );
    }

    private static function write_block_order_in_transaction( $faluss_id, $ids ) {
        global $wpdb; $table = Faluss_Link_Schema::blocks_table(); $now = current_time( 'mysql', true );
        if ( ! self::mutation_checkpoint( 'before_block_reorder' ) || false === $wpdb->query( $wpdb->prepare( 'UPDATE ' . $table . ' SET sort_order=sort_order+100 WHERE faluss_id=%s', $faluss_id ) ) ) { return false; }
        foreach ( array_values( $ids ) as $index => $id ) {
            if ( false === $wpdb->update( $table, array( 'sort_order' => $index + 1, 'updated_at' => $now ), array( 'faluss_id' => $faluss_id, 'block_id' => $id ), array( '%d', '%s' ), array( '%s', '%s' ) ) ) { return false; }
        }
        return true;
    }

    /**
     * Collections are a Studio projection of the existing ordered block stream.
     * A section title starts a collection; its first adjacent text block is the
     * optional description. No collection table, meta, or duplicate link exists.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function studio_collections( $blocks ) {
        $collections = array();
        $current = '';
        foreach ( (array) $blocks as $block ) {
            if ( 'section_title' === ( $block['type'] ?? '' ) ) {
                $current = (string) $block['block_id'];
                $collections[ $current ] = array(
                    'block_id' => $current,
                    'name' => (string) $block['value'],
                    'description' => '',
                    'description_block_id' => '',
                    'links' => array(),
                );
                continue;
            }
            if ( '' === $current || ! isset( $collections[ $current ] ) ) { continue; }
            if ( 'text' === ( $block['type'] ?? '' ) && '' === $collections[ $current ]['description'] && ! $collections[ $current ]['links'] ) {
                $collections[ $current ]['description'] = (string) $block['value'];
                $collections[ $current ]['description_block_id'] = (string) $block['block_id'];
                continue;
            }
            if ( 'link' === ( $block['type'] ?? '' ) ) { $collections[ $current ]['links'][] = $block; }
        }
        return $collections;
    }

    private static function studio_collection_id( $value, $collections ) {
        $value = strtolower( sanitize_text_field( (string) wp_unslash( $value ) ) );
        return self::valid_block_id( $value ) && isset( $collections[ $value ] ) ? $value : '';
    }

    private static function studio_section( $value, $tab ) {
        $value = sanitize_key( (string) wp_unslash( $value ) );
        $allowed = 'style' === $tab ? array( 'appearance', 'header', 'link-style' ) : array( 'all', 'collections', 'collection' );
        return in_array( $value, $allowed, true ) ? $value : $allowed[0];
    }

    private static function studio_block_store( $blocks ) {
        $fields = array(
            'section_title' => array( 'value' ),
            'text' => array( 'value' ),
            'link' => array( 'label', 'url' ),
            'media_teaser' => array( 'attachment_id', 'title', 'text', 'format', 'access_mode', 'entitlement_code' ),
        );
        ?><div class="faluss-link-studio__block-store" data-fl-block-store hidden aria-hidden="true"><?php foreach ( array_values( (array) $blocks ) as $index => $block ) : $type = (string) $block['type']; $prefix = 'content_blocks[' . (int) $index . ']'; ?><div data-fl-stored-block data-block-id="<?php echo esc_attr( $block['block_id'] ); ?>" data-block-type="<?php echo esc_attr( $type ); ?>"><input data-fl-block-field="block_id" name="<?php echo esc_attr( $prefix ); ?>[block_id]" type="hidden" value="<?php echo esc_attr( $block['block_id'] ); ?>"><input data-fl-block-field="type" name="<?php echo esc_attr( $prefix ); ?>[type]" type="hidden" value="<?php echo esc_attr( $type ); ?>"><?php foreach ( $fields[ $type ] ?? array() as $field ) : ?><input data-fl-block-field="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $prefix ); ?>[<?php echo esc_attr( $field ); ?>]" type="hidden" value="<?php echo esc_attr( $block[ $field ] ?? '' ); ?>"><?php endforeach; ?></div><?php endforeach; ?></div><?php
    }

    private static function studio_member_header( $profile, $preferences ) {
        $name = $profile['display_name'] ?: __( 'Mon Faluss', 'faluss-link' );
        $socials = array_slice( self::socials( $preferences['social_links'] ), 0, 4 );
        ?><section class="faluss-link-studio__identity" aria-label="<?php esc_attr_e( 'Identité du membre', 'faluss-link' ); ?>"><span class="faluss-link-studio__avatar"><?php if ( (int) $profile['avatar_attachment_id'] ) { echo wp_get_attachment_image( (int) $profile['avatar_attachment_id'], 'medium', false, array( 'alt' => '' ) ); } ?></span><div class="faluss-link-studio__identity-copy"><strong data-studio-member-name><?php echo esc_html( $name ); ?></strong><span data-studio-member-handle><?php echo '' === $profile['public_slug'] ? '@—' : '@' . esc_html( $profile['public_slug'] ); ?></span><div class="faluss-link-studio__social-shortcuts"><?php foreach ( $socials as $social ) : $asset = self::social_asset_markup( $social['network'], $preferences['social_variant'] ); if ( '' === $asset ) { continue; } ?><a href="<?php echo esc_url( $social['url'] ); ?>" target="_blank" rel="noopener noreferrer nofollow" aria-label="<?php echo esc_attr( self::network_label( $social['network'] ) ); ?>"><?php echo $asset; ?></a><?php endforeach; ?><button class="faluss-link-studio__social-add" type="button" data-fl-open-social-manager aria-label="<?php esc_attr_e( 'Ajouter un réseau social', 'faluss-link' ); ?>"><span aria-hidden="true">+</span></button></div></div></section><?php
    }

    private static function studio_links_panel( $blocks ) {
        $links = array_values( array_filter( (array) $blocks, static function( $block ) { return 'link' === ( $block['type'] ?? '' ); } ) );
        if ( ! $links ) { self::studio_empty_state( 'links' ); return; }
        ?><div class="faluss-link-studio__cards" data-fl-link-list><?php foreach ( $links as $link ) { self::studio_link_card( $link ); } ?></div><?php
    }

    /** The JSON Studio save returns this owner-only fragment after persistence. */
    private static function studio_links_panel_html( $blocks ) {
        ob_start();
        self::studio_links_panel( $blocks );
        return (string) ob_get_clean();
    }

    private static function studio_collections_panel( $collections ) {
        if ( ! $collections ) { self::studio_empty_state( 'collections' ); return; }
        ?><div class="faluss-link-studio__collection-grid"><?php foreach ( $collections as $collection ) : ?><a class="faluss-link-studio__collection-card" href="<?php echo esc_url( self::studio_collection_url( $collection['block_id'] ) ); ?>" data-fl-open-collection="<?php echo esc_attr( $collection['block_id'] ); ?>"><strong><?php echo esc_html( $collection['name'] ); ?></strong><span><?php echo esc_html( sprintf( _n( '%d lien actif', '%d liens actifs', count( $collection['links'] ), 'faluss-link' ), count( $collection['links'] ) ) ); ?></span><i aria-hidden="true"></i></a><?php endforeach; ?></div><?php
    }

    private static function studio_collection_panel( $collection ) {
        ?><div class="faluss-link-studio__collection-heading"><button type="button" data-fl-rename-collection aria-expanded="false"><?php echo esc_html( $collection['name'] ); ?></button><span><?php echo esc_html( sprintf( _n( '%d lien', '%d liens', count( $collection['links'] ), 'faluss-link' ), count( $collection['links'] ) ) ); ?></span></div><div class="faluss-link-studio__collection-editor" data-fl-collection-editor hidden><label><?php esc_html_e( 'Nom de la collection', 'faluss-link' ); ?><input type="text" maxlength="80" data-fl-collection-name value="<?php echo esc_attr( $collection['name'] ); ?>"></label><label><?php esc_html_e( 'Description facultative', 'faluss-link' ); ?><textarea maxlength="480" rows="3" data-fl-collection-description><?php echo esc_textarea( $collection['description'] ); ?></textarea></label><div class="faluss-link-studio__inline-actions"><button type="button" data-fl-save-collection="<?php echo esc_attr( $collection['block_id'] ); ?>"><?php esc_html_e( 'Enregistrer', 'faluss-link' ); ?></button><button type="button" class="is-destructive" data-fl-dissolve-collection="<?php echo esc_attr( $collection['block_id'] ); ?>"><?php esc_html_e( 'Dissoudre la collection', 'faluss-link' ); ?></button></div></div><?php if ( $collection['description'] ) : ?><p class="faluss-link-studio__collection-description"><?php echo esc_html( $collection['description'] ); ?></p><?php endif; ?><?php if ( $collection['links'] ) : ?><div class="faluss-link-studio__cards"><?php foreach ( $collection['links'] as $link ) { self::studio_link_card( $link ); } ?></div><?php endif;
    }

    private static function studio_link_card( $link ) {
        $id = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'faluss-studio-link-' ) : 'faluss-studio-link-' . substr( $link['block_id'], 0, 8 );
        ?><article class="faluss-link-studio__link-card" data-fl-link-card data-block-id="<?php echo esc_attr( $link['block_id'] ); ?>"><button class="faluss-link-studio__link-summary" type="button" aria-expanded="false" aria-controls="<?php echo esc_attr( $id ); ?>"><span><?php echo esc_html( $link['label'] ); ?></span><span aria-hidden="true">⌄</span></button><div id="<?php echo esc_attr( $id ); ?>" class="faluss-link-studio__link-details" hidden><label><?php esc_html_e( 'Nom affiché du lien', 'faluss-link' ); ?><input type="text" maxlength="80" value="<?php echo esc_attr( $link['label'] ); ?>" data-fl-link-label></label><label><?php esc_html_e( 'URL du lien', 'faluss-link' ); ?><input type="url" maxlength="2048" value="<?php echo esc_attr( $link['url'] ); ?>" placeholder="https://" data-fl-link-url></label><div class="faluss-link-studio__inline-actions"><button type="button" data-fl-save-link><?php esc_html_e( 'Enregistrer', 'faluss-link' ); ?></button><button class="is-destructive" type="button" data-fl-delete-link><?php esc_html_e( 'Supprimer', 'faluss-link' ); ?></button></div></div></article><?php
    }

    private static function studio_empty_state( $kind ) {
        if ( ! in_array( $kind, array( 'links', 'collections' ), true ) ) { return; }
        $collection = 'collections' === $kind;
        ?><div class="faluss-link-studio__empty" role="status"><span class="faluss-link-studio__empty-illustration" aria-hidden="true"><i></i><i></i><i></i></span><strong><?php echo esc_html( $collection ? __( 'Zéro collection', 'faluss-link' ) : __( 'Zéro lien', 'faluss-link' ) ); ?></strong><p><?php echo esc_html( $collection ? __( 'Regroupez vos liens. Ajoutez votre première collection.', 'faluss-link' ) : __( 'Montrez à votre audience qui vous êtes. Ajoutez vos premiers liens.', 'faluss-link' ) ); ?></p></div><?php
    }

    private static function studio_create_views() {
        $smart_action = self::studio_ecosystem_smart_action();
        $faluss_me_symbol_url = plugins_url( 'assets/link/images/faluss-onboarding-header-logo.png', FALUSS_LINK_FILE );
        $hub_symbol_url       = plugins_url( 'assets/link/images/studio-ecosystem/faluss-studio-hub.png', FALUSS_LINK_FILE );
        $pro_symbol_url       = plugins_url( 'assets/link/images/studio-ecosystem/faluss-studio-pro.png', FALUSS_LINK_FILE );
        $date_symbol_url      = plugins_url( 'assets/link/images/studio-ecosystem/faluss-studio-date.png', FALUSS_LINK_FILE );
        ?>
        <section class="faluss-link-studio__create-view" data-fl-studio-screen="create-link" hidden inert>
            <div class="faluss-link-studio__create-copy"><h2><?php esc_html_e( 'Un lien, c’est sacré. Rendez-le unique !', 'faluss-link' ); ?></h2><p><?php esc_html_e( 'Vous pourrez le changer quand vous voulez dans le Studio.', 'faluss-link' ); ?></p></div>
            <div class="faluss-link-studio__create-card"><h3><?php esc_html_e( 'Nouveau lien', 'faluss-link' ); ?></h3><label><?php esc_html_e( 'Nom affiché du lien', 'faluss-link' ); ?><input type="text" maxlength="80" data-fl-new-link-label></label><label><?php esc_html_e( 'URL du lien', 'faluss-link' ); ?><input type="url" maxlength="2048" placeholder="https://" data-fl-new-link-url></label><button type="button" data-fl-create-link-submit><?php esc_html_e( 'Ajouter le lien', 'faluss-link' ); ?></button></div>
        </section>
        <section class="faluss-link-studio__create-view" data-fl-studio-screen="create-collection" hidden inert>
            <div class="faluss-link-studio__create-copy"><h2><?php esc_html_e( 'Catégorisez vos liens. C’est plus simple !', 'faluss-link' ); ?></h2><p><?php esc_html_e( 'Vous pourrez la changer quand vous voulez dans le Studio.', 'faluss-link' ); ?></p></div>
            <div class="faluss-link-studio__create-card"><h3><?php esc_html_e( 'Nouvelle collection', 'faluss-link' ); ?></h3><label><?php esc_html_e( 'Nom de la collection', 'faluss-link' ); ?><input type="text" maxlength="80" data-fl-new-collection-name></label><label><?php esc_html_e( 'Description facultative', 'faluss-link' ); ?><textarea maxlength="480" rows="3" data-fl-new-collection-description></textarea></label><button type="button" data-fl-create-collection-submit><?php esc_html_e( 'Ajouter la collection', 'faluss-link' ); ?></button></div>
        </section>
        <section class="faluss-link-studio__create-view faluss-link-studio__ecosystem" data-fl-studio-screen="ecosystem" hidden inert>
            <div class="faluss-link-studio__create-copy"><h2><?php esc_html_e( 'Faluss, c’est juste un écosystème complet.', 'faluss-link' ); ?></h2><p><?php esc_html_e( 'Visitez nos autres produits !', 'faluss-link' ); ?></p></div>
            <div class="faluss-link-studio__ecosystem-lead">
                <article class="faluss-link-studio__ecosystem-card is-current" data-faluss-product-url="https://www.faluss.me/" aria-current="page"><img class="faluss-link-studio__ecosystem-symbol faluss-link-studio__ecosystem-symbol--faluss-me" src="<?php echo esc_url( $faluss_me_symbol_url ); ?>" alt="" aria-hidden="true"><strong>Faluss Me</strong><span><?php esc_html_e( 'Vous êtes déjà ici', 'faluss-link' ); ?></span></article>
                <nav class="faluss-link-studio__ecosystem-actions" aria-label="<?php esc_attr_e( 'Accès Faluss', 'faluss-link' ); ?>"><a href="<?php echo esc_url( home_url( '/mon-faluss/' ) ); ?>"><span><?php esc_html_e( 'Mon Faluss', 'faluss-link' ); ?></span><i aria-hidden="true">›</i></a><a href="<?php echo esc_url( home_url( '/list/' ) ); ?>"><span><?php esc_html_e( 'Ma Liste', 'faluss-link' ); ?></span><i aria-hidden="true">›</i></a><a href="<?php echo esc_url( $smart_action['url'] ); ?>"><span><?php echo esc_html( $smart_action['label'] ); ?></span></a></nav>
            </div>
            <div class="faluss-link-studio__ecosystem-products">
                <a class="faluss-link-studio__ecosystem-card" href="https://faluss.com/mon-faluss" target="_blank" rel="noopener noreferrer"><img class="faluss-link-studio__ecosystem-symbol" src="<?php echo esc_url( $hub_symbol_url ); ?>" alt="" aria-hidden="true"><strong>Faluss Hub</strong><span><?php esc_html_e( 'M’y rendre', 'faluss-link' ); ?></span></a>
                <a class="faluss-link-studio__ecosystem-card" href="https://www.pro.faluss.com/" target="_blank" rel="noopener noreferrer"><img class="faluss-link-studio__ecosystem-symbol" src="<?php echo esc_url( $pro_symbol_url ); ?>" alt="" aria-hidden="true"><strong>Faluss Pro</strong><span><?php esc_html_e( 'M’y rendre', 'faluss-link' ); ?></span></a>
                <a class="faluss-link-studio__ecosystem-card" href="https://www.faluss.fans/" target="_blank" rel="noopener noreferrer"><span class="faluss-link-studio__ecosystem-symbol faluss-link-studio__ecosystem-placeholder" aria-hidden="true"></span><strong>Faluss Fans</strong><span><?php esc_html_e( 'M’y rendre', 'faluss-link' ); ?></span></a>
                <a class="faluss-link-studio__ecosystem-card" href="https://date.faluss.com/" target="_blank" rel="noopener noreferrer"><img class="faluss-link-studio__ecosystem-symbol" src="<?php echo esc_url( $date_symbol_url ); ?>" alt="" aria-hidden="true"><strong>Faluss Date</strong><span><?php esc_html_e( 'M’y rendre', 'faluss-link' ); ?></span></a>
            </div>
        </section>
        <?php
    }

    /** Reuses the Navigation Faluss smart action without adding an auth flow. */
    private static function studio_ecosystem_smart_action() {
        if ( \Faluss\Platform\Link\LinkIdentityAdapter::ready() ) {
            foreach ( \Faluss\Platform\Link\LinkIdentityAdapter::navigationActions() as $action ) {
                if ( 'smart' === ( $action['key'] ?? '' ) && ! empty( $action['url'] ) ) {
                    return array( 'label' => (string) $action['label'], 'url' => (string) $action['url'] );
                }
            }
        }
        return is_user_logged_in()
            ? array( 'label' => __( 'Déconnexion', 'faluss-link' ), 'url' => wp_logout_url( home_url( '/mon-faluss/' ) ) )
            : array( 'label' => __( 'Connexion', 'faluss-link' ), 'url' => add_query_arg( 'redirect_to', home_url( '/mon-faluss/' ), home_url( '/login/' ) ) );
    }

    private static function studio_appearance_panel( $preferences ) {
        self::studio_cover_control( $preferences );
        self::theme_picker( $preferences );
        self::studio_color_palette( 'page_background', __( 'Arrière-plan', 'faluss-link' ), $preferences['page_background'] );
        ?><details class="faluss-link-studio__advanced"><summary><?php esc_html_e( 'Transition de couverture', 'faluss-link' ); ?></summary><label><?php esc_html_e( 'Couleur de transition', 'faluss-link' ); ?><input name="hero_transition_color" type="color" value="<?php echo esc_attr( $preferences['hero_transition_color'] ); ?>"></label><label><?php esc_html_e( 'Intensité', 'faluss-link' ); ?><input name="hero_transition_intensity" type="range" min="0" max="100" value="<?php echo (int) $preferences['hero_transition_intensity']; ?>"></label><label><?php esc_html_e( 'Position', 'faluss-link' ); ?><input name="hero_transition_position" type="range" min="35" max="100" value="<?php echo (int) $preferences['hero_transition_position']; ?>"></label></details><?php
    }

    /** Restores the existing member upload and renderer contract in Studio V1. */
    private static function studio_cover_control( $preferences ) {
        $attachment_id = (int) ( $preferences['cover_attachment_id'] ?? 0 );
        ?><div class="faluss-link-editor__media faluss-link-studio__cover-control"><label><?php esc_html_e( 'Couverture haute', 'faluss-link' ); ?></label><input class="faluss-link-editor__cover-id" name="cover_attachment_id" type="hidden" value="<?php echo $attachment_id; ?>"><div class="faluss-link-editor__cover-preview"><?php if ( $attachment_id ) { echo wp_get_attachment_image( $attachment_id, 'medium', false, array( 'alt' => '' ) ); } ?></div><div class="faluss-link-studio__inline-actions"><button class="faluss-link-editor__select-cover" type="button"><?php echo esc_html( $attachment_id ? __( 'Remplacer l’image', 'faluss-link' ) : __( 'Choisir une image', 'faluss-link' ) ); ?></button><button class="faluss-link-editor__remove-cover" type="button"<?php echo $attachment_id ? '' : ' hidden'; ?>><?php esc_html_e( 'Retirer', 'faluss-link' ); ?></button></div><p class="faluss-link-studio__hint"><?php esc_html_e( 'L’image reste collée en haut de la carte et conserve son effet de défilement et de dézoom.', 'faluss-link' ); ?></p></div><?php
    }

    private static function studio_header_panel( $profile, $preferences ) {
        ?><div class="faluss-link-studio__settings-card"><?php self::identity_fields( $profile ); ?><input type="hidden" name="available" value="0"><label class="faluss-link-studio__check"><input name="available" type="checkbox" value="1" <?php checked( $preferences['available'] ); ?>> <?php esc_html_e( 'Afficher Disponible', 'faluss-link' ); ?></label><input type="hidden" name="avatar_visible" value="0"><label class="faluss-link-studio__check"><input name="avatar_visible" type="checkbox" value="1" <?php checked( $preferences['avatar_visible'] ); ?>> <?php esc_html_e( 'Afficher l’avatar', 'faluss-link' ); ?></label><input type="hidden" name="avatar_border" value="0"><label class="faluss-link-studio__check"><input name="avatar_border" type="checkbox" value="1" <?php checked( $preferences['avatar_border'] ); ?>> <?php esc_html_e( 'Afficher la bordure de l’avatar', 'faluss-link' ); ?></label><label><?php esc_html_e( 'Police du nom', 'faluss-link' ); ?><select name="name_font"><?php foreach ( self::onboarding_name_fonts() as $key => $font ) : ?><option value="<?php echo esc_attr( $key ); ?>" data-font-stack="<?php echo esc_attr( $font['stack'] ); ?>" <?php selected( $preferences['name_font'], $key ); ?>><?php echo esc_html( $font['label'] ); ?></option><?php endforeach; ?></select></label><label><?php esc_html_e( 'Traitement du nom', 'faluss-link' ); ?><select name="name_treatment"><?php self::name_treatment_options( $preferences['name_treatment'] ); ?></select></label><fieldset class="faluss-link-studio__segments"><legend><?php esc_html_e( 'Alignement du profil', 'faluss-link' ); ?></legend><label><input type="radio" name="alignment" value="left" <?php checked( $preferences['alignment'], 'left' ); ?>><span><?php esc_html_e( 'Gauche', 'faluss-link' ); ?></span></label><label><input type="radio" name="alignment" value="center" <?php checked( $preferences['alignment'], 'center' ); ?>><span><?php esc_html_e( 'Centre', 'faluss-link' ); ?></span></label></fieldset><?php self::name_color_field( $preferences ); ?><div data-fl-social-manager tabindex="-1"><?php self::social_editor( $preferences ); ?></div><label><?php esc_html_e( 'Affichage des réseaux', 'faluss-link' ); ?><select name="social_layout"><?php self::options( self::LAYOUTS, $preferences['social_layout'] ); ?></select></label><label><?php esc_html_e( 'Style des réseaux', 'faluss-link' ); ?><select name="social_variant"><?php self::options( self::SOCIAL_VARIANTS, $preferences['social_variant'] ); ?></select></label><button class="faluss-link-studio__save-settings" type="submit"><?php esc_html_e( 'Enregistrer l’en-tête', 'faluss-link' ); ?></button></div><?php
    }

    private static function studio_link_style_panel( $preferences ) {
        self::studio_color_palette( 'button_color', __( 'Couleur des boutons', 'faluss-link' ), $preferences['button_color'] );
        ?><fieldset class="faluss-link-studio__style-options"><legend><?php esc_html_e( 'Style des boutons', 'faluss-link' ); ?></legend><?php foreach ( self::LINK_STYLES as $value => $label ) : ?><label class="faluss-link-studio__style-option"><input type="radio" name="link_style" value="<?php echo esc_attr( $value ); ?>" <?php checked( $preferences['link_style'], $value ); ?>><span class="faluss-link-studio__style-preview faluss-link-studio__style-preview--<?php echo esc_attr( $value ); ?>" style="--fl-studio-button-color:<?php echo esc_attr( $preferences['button_color'] ); ?>"><i><?php echo esc_html( $label ); ?></i></span><strong><?php echo esc_html( $label ); ?></strong></label><?php endforeach; ?></fieldset><?php
    }

    /** A single palette component keeps page and button colour ergonomics identical. */
    private static function studio_color_palette( $field, $legend, $value ) {
        $swatches = array( '#000000', '#191919', '#737373', '#DDDDDD', '#FFFFFF', '#321752', '#350D0D', '#1A7061', '#A748B5' );
        $value = self::valid_hex( $value ) ?: ( 'button_color' === $field ? '#080808' : '#FFFDF5' );
        ?><fieldset class="faluss-link-studio__palette" data-fl-color-palette="<?php echo esc_attr( $field ); ?>"><legend><?php echo esc_html( $legend ); ?></legend><input class="faluss-link-studio__native-color" name="<?php echo esc_attr( $field ); ?>" type="color" value="<?php echo esc_attr( $value ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Couleur personnalisée : %s', 'faluss-link' ), $legend ) ); ?>"><div><?php foreach ( $swatches as $color ) : ?><button type="button" data-fl-color-field="<?php echo esc_attr( $field ); ?>" data-fl-color="<?php echo esc_attr( $color ); ?>" aria-pressed="<?php echo strtoupper( $value ) === $color ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( '%s %s', $legend, $color ) ); ?>" style="--fl-studio-swatch:<?php echo esc_attr( $color ); ?>"></button><?php endforeach; ?><button class="is-custom" type="button" data-fl-open-color="<?php echo esc_attr( $field ); ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Choisir une couleur personnalisée : %s', 'faluss-link' ), $legend ) ); ?>">✣</button></div></fieldset><?php
    }

    /** Server-owned route; never derive canonical response links from admin-post context. */
    private static function studio_collection_url( $block_id ) {
        $block_id = strtolower( sanitize_text_field( (string) $block_id ) );
        if ( ! self::valid_block_id( $block_id ) ) { return home_url( '/mon-faluss/' ); }
        return add_query_arg(
            array(
                'faluss_studio_tab' => 'links',
                'faluss_studio_section' => 'collection',
                'faluss_studio_collection' => $block_id,
            ),
            home_url( '/mon-faluss/' )
        );
    }

    public static function upload_cover() {
        self::upload_member_image( 'cover', 'faluss_link_upload_cover' );
    }

    public static function upload_avatar() {
        self::upload_member_image( 'avatar', 'faluss_link_upload_avatar' );
    }

    public static function upload_teaser() {
        self::upload_member_image( 'teaser', 'faluss_link_upload_teaser' );
    }

    /** ONB-02 is rendered only through the selected Identity onboarding page. */
    public static function render_onboarding_wizard() {
        if ( ! self::identity_ready() || ! is_user_logged_in() || ! \Faluss\Platform\Link\LinkIdentityAdapter::onboardingAvailable() || ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) {
            return self::empty_card( __( 'Votre création Faluss est indisponible.', 'faluss-link' ) );
        }
        $faluss_id = self::current_faluss_id();
        $context = \Faluss\Platform\Link\LinkIdentityAdapter::onboardingContext();
        if ( ! self::valid_faluss_id( $faluss_id ) || empty( $context['required'] ) ) {
            return '';
        }
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        $preferences = self::valid_prefs( $faluss_id );
        $blocks = self::content_blocks( $faluss_id, $profile['links'], true );
        $step = self::onboarding_step( $context['step'] ?? '' );
        $step_keys = array_keys( self::onboarding_steps() );
        $step_index = array_search( $step, $step_keys, true );
        $step_index = false === $step_index ? 0 : $step_index;
        $step_number = $step_index + 1;
        $step_count = count( $step_keys );
        $progress_scale = round( $step_number / $step_count, 4 );
        $selected_networks = self::onboarding_network_selection( $preferences['social_selected'] );
        if ( ! $selected_networks ) {
            foreach ( self::socials( $preferences['social_links'] ) as $social ) { $selected_networks[] = $social['network']; }
        }
        self::onboarding_assets();
        $instance = function_exists( 'wp_unique_id' ) ? wp_unique_id( 'faluss-link-onboarding-' ) : 'faluss-link-onboarding';
        $onboarding_assets = array(
            'back' => plugins_url( 'assets/link/images/faluss-onboarding-header-back.svg', FALUSS_LINK_FILE ),
            'logo' => plugins_url( 'assets/link/images/faluss-onboarding-header-logo.png', FALUSS_LINK_FILE ),
            'device' => plugins_url( 'assets/link/images/faluss-onboarding-device.png', FALUSS_LINK_FILE ),
            'upload_plus' => plugins_url( 'assets/link/images/faluss-onboarding-upload-plus.svg', FALUSS_LINK_FILE ),
        );
        /* ONB-02 reads this finite Figma palette into the existing validated
         * page_background field. The final swatch remains the native colour
         * input for a member's own HEX value. */
        $background_swatches = array( '#000000', '#191919', '#737373', '#DDDDDD', '#FFFFFF', '#321752', '#350D0D', '#1A7061', '#A748B5' );
        ob_start();
        ?>
        <section id="<?php echo esc_attr( $instance ); ?>" class="faluss-link-onboarding" data-faluss-link-onboarding data-current-step="<?php echo esc_attr( $step ); ?>" data-onboarding-layout="<?php echo esc_attr( self::onboarding_layout( $step ) ); ?>" aria-label="<?php esc_attr_e( 'Création de votre carte Faluss', 'faluss-link' ); ?>" style="--flo-progress-scale:<?php echo esc_attr( (string) $progress_scale ); ?>">
            <header class="faluss-link-onboarding__topbar">
                <button class="faluss-link-onboarding__back" type="button" data-onboarding-back aria-label="<?php esc_attr_e( 'Revenir à l’étape précédente', 'faluss-link' ); ?>"<?php echo 0 === $step_index ? ' hidden' : ''; ?>><img src="<?php echo esc_url( $onboarding_assets['back'] ); ?>" width="44" height="44" alt="" aria-hidden="true"></button>
                <div class="faluss-link-onboarding__gauge" role="progressbar" aria-label="<?php esc_attr_e( 'Progression de création', 'faluss-link' ); ?>" aria-valuemin="1" aria-valuemax="<?php echo (int) $step_count; ?>" aria-valuenow="<?php echo (int) $step_number; ?>" aria-valuetext="<?php echo esc_attr( sprintf( __( 'Étape %1$d sur %2$d : %3$s', 'faluss-link' ), $step_number, $step_count, self::onboarding_steps()[ $step ] ) ); ?>">
                    <span class="faluss-link-onboarding__gauge-fill" data-onboarding-progress-bar aria-hidden="true"></span>
                    <span class="screen-reader-text" data-onboarding-progress-text><?php echo esc_html( sprintf( __( 'Étape %1$d sur %2$d : %3$s', 'faluss-link' ), $step_number, $step_count, self::onboarding_steps()[ $step ] ) ); ?></span>
                </div>
                <span class="faluss-link-onboarding__symbol"><img src="<?php echo esc_url( $onboarding_assets['logo'] ); ?>" width="35" height="35" alt="<?php esc_attr_e( 'Faluss', 'faluss-link' ); ?>"></span>
            </header>
            <div class="faluss-link-onboarding__layout">
                <form class="faluss-link-onboarding__form" novalidate>
                    <p class="faluss-link-onboarding__status" role="status" aria-live="polite"></p>
                    <p class="faluss-link-onboarding__error" role="alert" hidden></p>
                    <div class="faluss-link-onboarding__panels" data-onboarding-panels>
                    <section class="faluss-link-onboarding__panel<?php echo 'name' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="name" role="group" aria-hidden="<?php echo 'name' === $step ? 'false' : 'true'; ?>"<?php echo 'name' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-name-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-name-title"><?php esc_html_e( 'Chaque Faluss commence par un nom. Quel est le vôtre ?', 'faluss-link' ); ?></h2>
                        <label class="faluss-link-onboarding__field"><?php esc_html_e( 'Nom', 'faluss-link' ); ?><input name="display_name" type="text" maxlength="80" autocomplete="name" value="<?php echo esc_attr( $profile['display_name'] ); ?>" required></label>
                        <p class="faluss-link-onboarding__hint"><?php esc_html_e( 'Votre identifiant @ reste déjà réservé et ne changera pas.', 'faluss-link' ); ?></p>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'avatar' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="avatar" role="group" aria-hidden="<?php echo 'avatar' === $step ? 'false' : 'true'; ?>"<?php echo 'avatar' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-avatar-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-avatar-title"><?php esc_html_e( 'Choisissez la photo qui vous représentera', 'faluss-link' ); ?></h2>
                        <p><?php esc_html_e( 'Vous pourrez la changer à tout moment.', 'faluss-link' ); ?></p>
                        <input name="faluss_identity_avatar_id" type="hidden" value="<?php echo (int) $profile['avatar_attachment_id']; ?>">
                        <button type="button" class="faluss-link-onboarding__avatar-upload" data-onboarding-avatar-select data-onboarding-avatar-preview aria-label="<?php esc_attr_e( 'Choisir ou remplacer votre photo de profil', 'faluss-link' ); ?>"><?php if ( (int) $profile['avatar_attachment_id'] ) { echo wp_get_attachment_image( (int) $profile['avatar_attachment_id'], 'medium', false, array( 'alt' => '' ) ); } else { ?><span class="faluss-link-onboarding__avatar-upload-empty"><img src="<?php echo esc_url( $onboarding_assets['upload_plus'] ); ?>" width="31" height="32" alt="" aria-hidden="true"><span><?php esc_html_e( 'Ajoute une image', 'faluss-link' ); ?></span></span><?php } ?></button>
                        <p class="faluss-link-onboarding__avatar-name" data-onboarding-avatar-name><?php echo esc_html( $profile['display_name'] ?: __( 'Mon Faluss', 'faluss-link' ) ); ?></p>
                        <p class="faluss-link-onboarding__hint"><?php esc_html_e( 'Facultatif. Seules vos images valides sont acceptées.', 'faluss-link' ); ?></p>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'header' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="header" role="group" aria-hidden="<?php echo 'header' === $step ? 'false' : 'true'; ?>"<?php echo 'header' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-header-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-header-title"><?php esc_html_e( 'Personnalisez votre en-tête', 'faluss-link' ); ?></h2>
                        <div class="faluss-link-onboarding__choice-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Options d’en-tête', 'faluss-link' ); ?>">
                            <span class="faluss-link-onboarding__choice-indicator" role="presentation" aria-hidden="true"></span>
                            <button type="button" role="tab" aria-selected="true" aria-controls="<?php echo esc_attr( $instance ); ?>-header-layout" id="<?php echo esc_attr( $instance ); ?>-header-layout-tab" data-onboarding-choice-tab data-onboarding-choice-group="header" data-onboarding-choice-target="layout"><?php esc_html_e( 'Mise en page', 'faluss-link' ); ?></button>
                            <button type="button" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr( $instance ); ?>-header-font" id="<?php echo esc_attr( $instance ); ?>-header-font-tab" data-onboarding-choice-tab data-onboarding-choice-group="header" data-onboarding-choice-target="font"><?php esc_html_e( 'Police', 'faluss-link' ); ?></button>
                        </div>
                        <div id="<?php echo esc_attr( $instance ); ?>-header-layout" class="faluss-link-onboarding__choice-panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $instance ); ?>-header-layout-tab" data-onboarding-choice-panel data-onboarding-choice-group="header" data-onboarding-choice-panel-name="layout">
                            <fieldset class="faluss-link-onboarding__choice-grid faluss-link-onboarding__choice-grid--two"><legend><?php esc_html_e( 'Style de l’avatar', 'faluss-link' ); ?></legend><label class="faluss-link-onboarding__choice"><input name="avatar_border" type="radio" value="1" <?php checked( ! empty( $preferences['avatar_border'] ) ); ?>><span class="faluss-link-onboarding__choice-preview faluss-link-onboarding__choice-preview--border" aria-hidden="true"></span><span><?php esc_html_e( 'Avec bordure', 'faluss-link' ); ?></span></label><label class="faluss-link-onboarding__choice"><input name="avatar_border" type="radio" value="0" <?php checked( empty( $preferences['avatar_border'] ) ); ?>><span class="faluss-link-onboarding__choice-preview faluss-link-onboarding__choice-preview--plain" aria-hidden="true"></span><span><?php esc_html_e( 'Sans bordure', 'faluss-link' ); ?></span></label></fieldset>
                        </div>
                        <div id="<?php echo esc_attr( $instance ); ?>-header-font" class="faluss-link-onboarding__choice-panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $instance ); ?>-header-font-tab" data-onboarding-choice-panel data-onboarding-choice-group="header" data-onboarding-choice-panel-name="font" hidden inert>
                            <label class="faluss-link-onboarding__field"><?php esc_html_e( 'Police disponible', 'faluss-link' ); ?><select name="name_font"><?php foreach ( self::onboarding_name_fonts() as $font_key => $font ) : ?><option value="<?php echo esc_attr( $font_key ); ?>" data-font-stack="<?php echo esc_attr( $font['stack'] ); ?>" <?php selected( $preferences['name_font'], $font_key ); ?>><?php echo esc_html( $font['label'] ); ?></option><?php endforeach; ?></select></label>
                            <label class="faluss-link-onboarding__field"><?php esc_html_e( 'Traitement du nom', 'faluss-link' ); ?><select name="name_treatment"><?php self::name_treatment_options( $preferences['name_treatment'] ); ?></select></label>
                        </div>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'style' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="style" role="group" aria-hidden="<?php echo 'style' === $step ? 'false' : 'true'; ?>"<?php echo 'style' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-style-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-style-title"><?php esc_html_e( 'Ajoutez de la couleur', 'faluss-link' ); ?></h2>
                        <?php self::theme_picker( $preferences ); ?>
                        <div class="faluss-link-onboarding__choice-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Options de style', 'faluss-link' ); ?>">
                            <span class="faluss-link-onboarding__choice-indicator" role="presentation" aria-hidden="true"></span>
                            <button type="button" role="tab" aria-selected="true" aria-controls="<?php echo esc_attr( $instance ); ?>-style-background" id="<?php echo esc_attr( $instance ); ?>-style-background-tab" data-onboarding-choice-tab data-onboarding-choice-group="style" data-onboarding-choice-target="background"><?php esc_html_e( 'Arrière-plan', 'faluss-link' ); ?></button>
                            <button type="button" role="tab" aria-selected="false" aria-controls="<?php echo esc_attr( $instance ); ?>-style-buttons" id="<?php echo esc_attr( $instance ); ?>-style-buttons-tab" data-onboarding-choice-tab data-onboarding-choice-group="style" data-onboarding-choice-target="buttons"><?php esc_html_e( 'Boutons', 'faluss-link' ); ?></button>
                        </div>
                        <div id="<?php echo esc_attr( $instance ); ?>-style-background" class="faluss-link-onboarding__choice-panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $instance ); ?>-style-background-tab" data-onboarding-choice-panel data-onboarding-choice-group="style" data-onboarding-choice-panel-name="background">
                            <fieldset class="faluss-link-onboarding__swatches"><legend><?php esc_html_e( 'Fond de votre carte', 'faluss-link' ); ?></legend><?php foreach ( $background_swatches as $swatch ) : ?><label class="faluss-link-onboarding__swatch" style="--flo-swatch:<?php echo esc_attr( $swatch ); ?>"><input type="radio" name="faluss_onboarding_background_swatch" value="<?php echo esc_attr( $swatch ); ?>" data-onboarding-background-choice<?php checked( strtoupper( $preferences['page_background'] ), $swatch ); ?>><span aria-hidden="true"></span><span class="screen-reader-text"><?php echo esc_html( $swatch ); ?></span></label><?php endforeach; ?><label class="faluss-link-onboarding__swatch faluss-link-onboarding__swatch--custom"><input name="page_background" type="color" value="<?php echo esc_attr( $preferences['page_background'] ); ?>" aria-label="<?php esc_attr_e( 'Couleur personnalisée du fond', 'faluss-link' ); ?>"></label></fieldset>
                        </div>
                        <div id="<?php echo esc_attr( $instance ); ?>-style-buttons" class="faluss-link-onboarding__choice-panel" role="tabpanel" aria-labelledby="<?php echo esc_attr( $instance ); ?>-style-buttons-tab" data-onboarding-choice-panel data-onboarding-choice-group="style" data-onboarding-choice-panel-name="buttons" hidden inert>
                            <fieldset class="faluss-link-onboarding__button-choices"><legend><?php esc_html_e( 'Style de vos liens', 'faluss-link' ); ?></legend><label class="faluss-link-onboarding__button-choice faluss-link-onboarding__button-choice--outline"><input name="link_style" type="radio" value="outline" <?php checked( 'outline', $preferences['link_style'] ); ?>><span aria-hidden="true"><?php esc_html_e( 'Formel', 'faluss-link' ); ?></span><b class="screen-reader-text"><?php esc_html_e( 'Formel', 'faluss-link' ); ?></b></label><label class="faluss-link-onboarding__button-choice faluss-link-onboarding__button-choice--solid"><input name="link_style" type="radio" value="solid" <?php checked( 'solid', $preferences['link_style'] ); ?>><span aria-hidden="true"><?php esc_html_e( 'Visuel', 'faluss-link' ); ?></span><b class="screen-reader-text"><?php esc_html_e( 'Visuel', 'faluss-link' ); ?></b></label><label class="faluss-link-onboarding__button-choice faluss-link-onboarding__button-choice--light"><input name="link_style" type="radio" value="light" <?php checked( 'light', $preferences['link_style'] ); ?>><span aria-hidden="true"><?php esc_html_e( 'Minutieux', 'faluss-link' ); ?></span><b class="screen-reader-text"><?php esc_html_e( 'Minutieux', 'faluss-link' ); ?></b></label></fieldset>
                        </div>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'socials' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="socials" role="group" aria-hidden="<?php echo 'socials' === $step ? 'false' : 'true'; ?>"<?php echo 'socials' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-socials-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-socials-title"><?php esc_html_e( 'Quels réseaux ajouter ?', 'faluss-link' ); ?></h2>
                        <div class="faluss-link-onboarding__networks faluss-link-onboarding__scroll-region" data-onboarding-scroll-region>
                            <?php foreach ( self::active_network_catalog() as $network => $network_settings ) : ?><label class="faluss-link-onboarding__network"><input name="social_selected[]" type="checkbox" value="<?php echo esc_attr( $network ); ?>" <?php checked( in_array( $network, $selected_networks, true ) ); ?>><span class="faluss-link-onboarding__network-asset" aria-hidden="true"><?php echo self::social_asset_markup( $network, 'outline' ); ?></span><span><?php echo esc_html( $network_settings['label'] ); ?></span></label><?php endforeach; ?>
                        </div>
                        <p class="faluss-link-onboarding__hint"><?php esc_html_e( 'Vous pourrez les compléter ou passer cette étape.', 'faluss-link' ); ?></p>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'links' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="links" role="group" aria-hidden="<?php echo 'links' === $step ? 'false' : 'true'; ?>"<?php echo 'links' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-links-title">
                        <h2 id="<?php echo esc_attr( $instance ); ?>-links-title"><?php esc_html_e( 'Ajoutez vos liens', 'faluss-link' ); ?></h2>
                        <div class="faluss-link-onboarding__scroll-region faluss-link-onboarding__links-scroll" data-onboarding-scroll-region>
                            <div class="faluss-link-onboarding__social-urls" data-onboarding-social-urls><?php foreach ( $selected_networks as $network ) : $existing_url = ''; foreach ( self::socials( $preferences['social_links'] ) as $social ) { if ( $network === $social['network'] ) { $existing_url = $social['url']; break; } } ?><label data-onboarding-social-url="<?php echo esc_attr( $network ); ?>"><?php echo esc_html( self::network_label( $network ) ); ?><input name="social_urls[<?php echo esc_attr( $network ); ?>]" type="text" maxlength="2048" value="<?php echo esc_attr( $existing_url ); ?>" placeholder="@identifiant ou https://"></label><?php endforeach; ?></div>
                            <div class="faluss-link-onboarding__free-links" data-onboarding-free-links><?php foreach ( $blocks as $block ) : if ( 'link' === $block['type'] ) : $field_key = sanitize_key( (string) $block['block_id'] ); ?><div class="faluss-link-onboarding__free-link"><input name="wizard_links[<?php echo esc_attr( $field_key ); ?>][block_id]" type="hidden" value="<?php echo esc_attr( $block['block_id'] ); ?>"><label><?php esc_html_e( 'Libellé', 'faluss-link' ); ?><input name="wizard_links[<?php echo esc_attr( $field_key ); ?>][label]" type="text" maxlength="80" value="<?php echo esc_attr( $block['label'] ); ?>"></label><label><?php esc_html_e( 'URL HTTPS', 'faluss-link' ); ?><input name="wizard_links[<?php echo esc_attr( $field_key ); ?>][url]" type="url" maxlength="2048" value="<?php echo esc_attr( $block['url'] ); ?>" placeholder="https://"></label><button type="button" class="faluss-link-onboarding__remove-link" aria-label="<?php esc_attr_e( 'Supprimer ce lien', 'faluss-link' ); ?>">×</button></div><?php endif; endforeach; ?></div>
                            <button type="button" class="faluss-link-onboarding__secondary" data-onboarding-add-link><?php esc_html_e( 'Ajouter un lien', 'faluss-link' ); ?></button>
                            <p class="faluss-link-onboarding__hint"><?php esc_html_e( 'Les liens libres doivent utiliser HTTPS.', 'faluss-link' ); ?></p>
                        </div>
                    </section>
                    <section class="faluss-link-onboarding__panel<?php echo 'finish' === $step ? ' is-active' : ''; ?>" data-onboarding-panel="finish" role="group" aria-hidden="<?php echo 'finish' === $step ? 'false' : 'true'; ?>"<?php echo 'finish' === $step ? '' : ' hidden inert'; ?> aria-labelledby="<?php echo esc_attr( $instance ); ?>-finish-title"><h2 id="<?php echo esc_attr( $instance ); ?>-finish-title"><?php esc_html_e( 'Votre Faluss est prêt', 'faluss-link' ); ?></h2><p><?php esc_html_e( 'Vérifiez votre aperçu. Votre carte restera modifiable dans Studio Faluss et ne devient publique qu’après cette action.', 'faluss-link' ); ?></p></section>
                    </div>
                    <footer class="faluss-link-onboarding__actions"><button class="faluss-link-onboarding__secondary" type="button" data-onboarding-skip hidden><?php esc_html_e( 'Passer', 'faluss-link' ); ?></button><button class="faluss-link-onboarding__next" type="submit" data-onboarding-next><?php esc_html_e( 'Continuer', 'faluss-link' ); ?></button><button class="faluss-link-onboarding__finish" type="button" data-onboarding-finish hidden><?php esc_html_e( 'Terminer et publier mon Faluss', 'faluss-link' ); ?></button></footer>
                </form>
                <aside class="faluss-link-onboarding__preview" aria-label="<?php esc_attr_e( 'Aperçu de votre Faluss', 'faluss-link' ); ?>" data-onboarding-preview>
                    <p class="screen-reader-text"><?php esc_html_e( 'Aperçu de votre carte Faluss', 'faluss-link' ); ?></p>
                    <div class="faluss-link-onboarding__device" aria-hidden="true"><img src="<?php echo esc_url( $onboarding_assets['device'] ); ?>" width="271" height="557" alt=""></div>
                    <div class="faluss-link-onboarding__device-viewport" data-onboarding-preview-card><?php echo self::card_preview_markup( $profile, $preferences, $preferences['alignment'], $blocks, true, 'onboarding-preview' ); ?></div>
                </aside>
            </div>
        </section>
        <?php
        return (string) ob_get_clean();
    }

    public static function save_onboarding_wizard() {
        $faluss_id = self::onboarding_ajax_member( 'faluss_link_onboarding' );
        if ( '' === $faluss_id ) { wp_send_json_error( array( 'message' => __( 'Votre création Faluss n’est plus disponible.', 'faluss-link' ) ), 403 ); }
        $step = self::onboarding_step( $_POST['step'] ?? '' );
        $direction = isset( $_POST['direction'] ) && is_string( $_POST['direction'] ) && 'backward' === sanitize_key( wp_unslash( $_POST['direction'] ) ) ? 'backward' : 'forward';
        $target_step = 'backward' === $direction ? self::onboarding_previous_step( $step ) : self::onboarding_next_step( $step );
        if ( ! self::save_onboarding_step( $faluss_id, $step, $_POST ) || ! \Faluss\Platform\Link\LinkIdentityAdapter::advanceCardWizard( $target_step ) ) {
            wp_send_json_error( array( 'message' => __( 'Nous ne pouvons pas enregistrer cette étape. Vérifiez vos informations.', 'faluss-link' ) ), 400 );
        }
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        $preferences = self::valid_prefs( $faluss_id );
        $blocks = self::content_blocks( $faluss_id, is_array( $profile ) ? ( $profile['links'] ?? array() ) : array(), false );
        wp_send_json_success( array(
            'step' => $target_step,
            // Reuse the same safe card renderer after each saved choice rather
            // than maintaining a second browser-only preview implementation.
            'preview' => is_array( $profile ) ? self::card_preview_markup( $profile, $preferences, $preferences['alignment'], $blocks, true, 'onboarding-preview' ) : '',
        ) );
    }

    public static function preview_onboarding_wizard() {
        $faluss_id = self::onboarding_ajax_member( 'faluss_link_onboarding' );
        if ( '' === $faluss_id ) { wp_send_json_error( array( 'message' => __( 'Votre aperçu Faluss n’est plus disponible.', 'faluss-link' ) ), 403 ); }
        $draft = self::onboarding_preview_state( $faluss_id, $_POST );
        if ( false === $draft ) { wp_send_json_error( array( 'message' => __( 'Cet aperçu ne peut pas être actualisé.', 'faluss-link' ) ), 400 ); }
        wp_send_json_success( array(
            'preview' => self::card_preview_markup( $draft['profile'], $draft['preferences'], $draft['preferences']['alignment'], $draft['blocks'], true, 'onboarding-preview' ),
        ) );
    }

    public static function finish_onboarding_wizard() {
        self::onboarding_no_cache();
        if ( ! is_user_logged_in() || ! check_ajax_referer( 'faluss_link_onboarding', 'nonce', false ) || ! self::identity_ready() || ! \Faluss\Platform\Link\LinkIdentityAdapter::onboardingAvailable() || ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) {
            wp_send_json_error( array( 'message' => __( 'Votre création Faluss n’est plus disponible.', 'faluss-link' ) ), 403 );
        }
        $faluss_id = self::current_faluss_id();
        $profile = self::valid_faluss_id( $faluss_id ) ? \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id ) : array();
        if ( '' === $faluss_id || ! is_array( $profile ) || '' === trim( (string) ( $profile['display_name'] ?? '' ) ) || ! self::finish_onboarding_profile( $faluss_id, $profile ) ) {
            wp_send_json_error( array( 'message' => __( 'Ajoutez un nom affiché avant de publier votre Faluss.', 'faluss-link' ) ), 400 );
        }
        // Identity owns a pending first-party SSO continuation. It returns
        // only its local authorize route here, never an OAuth callback or
        // browser-visible request data.
        wp_send_json_success( array( 'redirect' => \Faluss\Platform\Link\LinkIdentityAdapter::onboardingCompletionDestination() ) );
    }

    public static function upload_onboarding_avatar() {
        if ( '' === self::onboarding_ajax_member( 'faluss_link_onboarding_avatar' ) ) { wp_send_json_error( array( 'message' => __( 'Votre création Faluss n’est plus disponible.', 'faluss-link' ) ), 403 ); }
        self::upload_member_image( 'avatar', 'faluss_link_onboarding_avatar' );
    }

    /** @return array<string, string> */
    private static function onboarding_steps() {
        return array( 'name' => __( 'Nom', 'faluss-link' ), 'avatar' => __( 'Photo', 'faluss-link' ), 'header' => __( 'En-tête', 'faluss-link' ), 'style' => __( 'Style', 'faluss-link' ), 'socials' => __( 'Réseaux', 'faluss-link' ), 'links' => __( 'Liens', 'faluss-link' ), 'finish' => __( 'Publier', 'faluss-link' ) );
    }

    private static function onboarding_step( $step ) {
        $step = is_string( $step ) ? sanitize_key( wp_unslash( $step ) ) : '';
        if ( 0 === strpos( $step, 'wizard_' ) ) { $step = substr( $step, 7 ); }
        return isset( self::onboarding_steps()[ $step ] ) ? $step : 'name';
    }

    private static function onboarding_layout( $step ) {
        $step = self::onboarding_step( $step );
        if ( 'name' === $step ) { return 'name'; }
        if ( 'avatar' === $step ) { return 'upload'; }
        if ( in_array( $step, array( 'socials', 'links' ), true ) ) { return 'list'; }
        return 'preview';
    }

    private static function onboarding_next_step( $step ) {
        $steps = array_keys( self::onboarding_steps() );
        $index = array_search( self::onboarding_step( $step ), $steps, true );
        return false === $index || ! isset( $steps[ $index + 1 ] ) ? 'wizard_finish' : 'wizard_' . $steps[ $index + 1 ];
    }

    private static function onboarding_previous_step( $step ) {
        $steps = array_keys( self::onboarding_steps() );
        $index = array_search( self::onboarding_step( $step ), $steps, true );
        return false === $index || $index < 1 ? 'wizard_name' : 'wizard_' . $steps[ $index - 1 ];
    }

    private static function onboarding_ajax_member( $nonce_action ) {
        self::onboarding_no_cache();
        if ( ! is_user_logged_in() || ! check_ajax_referer( $nonce_action, 'nonce', false ) || ! self::identity_ready() || ! \Faluss\Platform\Link\LinkIdentityAdapter::onboardingAvailable() ) { return ''; }
        $faluss_id = self::current_faluss_id();
        $context = \Faluss\Platform\Link\LinkIdentityAdapter::onboardingContext();
        return self::valid_faluss_id( $faluss_id ) && ! empty( $context['required'] ) ? $faluss_id : '';
    }

    /** Session- and nonce-bound wizard responses can never be shared cached. */
    private static function onboarding_no_cache() {
        if ( ! defined( 'DONOTCACHEPAGE' ) ) { define( 'DONOTCACHEPAGE', true ); }
        if ( function_exists( 'nocache_headers' ) ) { nocache_headers(); }
        do_action( 'litespeed_control_set_nocache' );
    }

    /**
     * Resolves an unsaved ONB-02 form through the same normalized card inputs
     * used by Studio and public rendering. Nothing in this method is persisted.
     *
     * @return array{profile: array<string,mixed>, preferences: array<string,mixed>, blocks: array<int,array<string,mixed>>}|false
     */
    private static function onboarding_preview_state( $faluss_id, $post ) {
        if ( ! is_array( $post ) || ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) { return false; }
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        if ( ! is_array( $profile ) ) { return false; }
        $preferences = self::prefs( $faluss_id, false );
        $field = static function( $key, $fallback ) use ( $post ) { return array_key_exists( $key, $post ) && is_string( $post[ $key ] ) ? wp_unslash( $post[ $key ] ) : $fallback; };

        $name = sanitize_text_field( $field( 'display_name', $profile['display_name'] ?? '' ) );
        if ( '' !== trim( $name ) ) { $profile['display_name'] = $name; }
        if ( isset( $post['faluss_identity_avatar_id'] ) && ! is_array( $post['faluss_identity_avatar_id'] ) ) {
            $avatar = absint( $post['faluss_identity_avatar_id'] );
            $profile['avatar_attachment_id'] = $avatar && self::owned_image( $avatar, get_current_user_id() ) ? $avatar : 0;
        }

        if ( array_key_exists( 'avatar_border', $post ) ) { $preferences['avatar_border'] = ! empty( $post['avatar_border'] ) ? 1 : 0; }
        if ( array_key_exists( 'selected_theme', $post ) ) {
            $reference = self::theme_reference( $field( 'selected_theme', $preferences['theme_reference'] ) );
            $theme = self::catalog_theme( $reference );
            if ( ! $theme || ! self::theme_available_to_subject( $theme, $faluss_id ) ) { return false; }
            $preferences['theme_reference'] = $reference;
        }
        $preferences['name_font'] = self::onboarding_name_font( $field( 'name_font', $preferences['name_font'] ) );
        $name_treatment = sanitize_key( $field( 'name_treatment', $preferences['name_treatment'] ) );
        $preferences['name_treatment'] = isset( self::NAME_TREATMENTS[ $name_treatment ] ) ? $name_treatment : $preferences['name_treatment'];
        $background = self::valid_hex( $field( 'page_background', $preferences['page_background'] ) );
        if ( '' !== $background ) { $preferences['page_background'] = $background; }
        $link_style = sanitize_key( $field( 'link_style', $preferences['link_style'] ) );
        $preferences['link_style'] = isset( self::LINK_STYLES[ $link_style ] ) ? $link_style : $preferences['link_style'];
        /* A current onboarding draft is an explicit member choice for the
         * preview. It must sit above a future selected theme without writing
         * anything to storage until the server step is accepted. */
        $preferences['theme_overrides'] = array_key_exists( 'theme_overrides', $post )
            ? self::theme_overrides( wp_unslash( $post['theme_overrides'] ) )
            : self::onboarding_theme_overrides( $preferences['theme_overrides'], $post );

        $selected = self::onboarding_network_selection( $post['social_selected'] ?? $preferences['social_selected'] );
        $socials = self::onboarding_socials( $post['social_urls'] ?? array(), $selected );
        $preferences['social_selected'] = $selected;
        $preferences['social_links'] = false === $socials ? array() : $socials;

        $raw_blocks = isset( $post['wizard_links'] ) && is_array( $post['wizard_links'] ) ? wp_unslash( $post['wizard_links'] ) : array();
        $links = self::normalise_blocks( array_map( static function( $block ) { return is_array( $block ) ? array_merge( $block, array( 'type' => 'link' ) ) : array(); }, $raw_blocks ) );
        $existing = self::content_blocks( $faluss_id, $profile['links'] ?? array(), false );
        $blocks = array_merge( array_values( array_filter( $existing, static function( $block ) { return is_array( $block ) && 'link' !== ( $block['type'] ?? '' ); } ) ), $links );
        $preferences = self::resolve_card_presentation( $preferences, true );
        return array( 'profile' => $profile, 'preferences' => $preferences, 'blocks' => $blocks );
    }

    /**
     * ONB-02 has no theme picker: values submitted by its visual decisions are
     * explicit member choices. Keep that precedence in the transient preview
     * and persist it only when the corresponding server step succeeds.
     */
    private static function onboarding_theme_overrides( $stored, $post ) {
        $overrides = self::theme_overrides( $stored );
        foreach ( array( 'page_background', 'link_style', 'alignment', 'social_variant' ) as $key ) {
            if ( is_array( $post ) && array_key_exists( $key, $post ) && ! in_array( $key, $overrides, true ) ) {
                $overrides[] = $key;
            }
        }
        return $overrides;
    }

    private static function save_onboarding_step( $faluss_id, $step, $post ) {
        if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) { return false; }
        $profile = \Faluss\Platform\Link\LinkIdentityAdapter::studioProfile( $faluss_id );
        if ( ! is_array( $profile ) || '' === (string) ( $profile['public_slug'] ?? '' ) ) { return false; }
        if ( 'name' === $step ) {
            $name = isset( $post['display_name'] ) ? sanitize_text_field( wp_unslash( $post['display_name'] ) ) : '';
            return '' !== trim( $name ) && self::save_onboarding_identity( $faluss_id, $profile, array( 'display_name' => $name ) );
        }
        if ( 'avatar' === $step ) {
            $avatar = isset( $post['faluss_identity_avatar_id'] ) ? absint( $post['faluss_identity_avatar_id'] ) : 0;
            return ! $avatar || self::save_onboarding_identity( $faluss_id, $profile, array( 'faluss_identity_avatar_id' => $avatar ) );
        }
        if ( in_array( $step, array( 'header', 'style', 'socials' ), true ) ) {
            $preferences = self::prefs( $faluss_id, false );
            $post['theme_overrides'] = array_key_exists( 'theme_overrides', $post )
                ? self::theme_overrides( wp_unslash( $post['theme_overrides'] ) )
                : self::onboarding_theme_overrides( $preferences['theme_overrides'], $post );
            return self::save_preferences( $faluss_id, $post );
        }
        if ( 'links' === $step ) {
            $preferences = self::prefs( $faluss_id, false );
            $selected = self::onboarding_network_selection( $post['social_selected'] ?? $preferences['social_selected'] );
            $socials = self::onboarding_socials( $post['social_urls'] ?? array(), $selected );
            $raw_blocks = isset( $post['wizard_links'] ) && is_array( $post['wizard_links'] ) ? wp_unslash( $post['wizard_links'] ) : array();
            $provided = 0;
            foreach ( $raw_blocks as $block ) { if ( is_array( $block ) && ( '' !== trim( (string) ( $block['label'] ?? '' ) ) || '' !== trim( (string) ( $block['url'] ?? '' ) ) ) ) { ++$provided; } }
            $links = self::normalise_blocks( array_map( static function( $block ) { return is_array( $block ) ? array_merge( $block, array( 'type' => 'link' ) ) : array(); }, $raw_blocks ) );
            if ( false === $socials || $provided !== count( $links ) ) { return false; }
            $post['social_networks'] = $socials;
            $post['social_selected'] = $selected;
            return self::save_onboarding_links_transaction( $faluss_id, $links, $socials, $selected );
        }
        return 'finish' === $step;
    }

    /** Onboarding upserts submitted links but never treats an absent row as deletion. */
    private static function save_onboarding_links_transaction( $faluss_id, $links, $socials, $selected ) {
        global $wpdb;
        if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() || false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        try {
            if ( false === \Faluss\Platform\Link\LinkIdentityAdapter::lockStudioProfileInTransaction( $faluss_id ) || false === self::lock_studio_card_in_transaction( $faluss_id ) || false === self::lock_studio_blocks_in_transaction( $faluss_id ) ) { throw new RuntimeException( 'lock' ); }
            $blocks = self::stored_blocks( $faluss_id )['blocks'];
            $by_id = array(); foreach ( $blocks as $index => $block ) { $by_id[ $block['block_id'] ] = $index; }
            foreach ( $links as $link ) {
                if ( isset( $by_id[ $link['block_id'] ] ) ) {
                    if ( 'link' !== $blocks[ $by_id[ $link['block_id'] ] ]['type'] || ! self::update_block_payload_in_transaction( $faluss_id, $link ) ) { throw new RuntimeException( 'update' ); }
                } else {
                    $insert = count( $blocks ); foreach ( $blocks as $index => $block ) { if ( 'section_title' === $block['type'] ) { $insert = $index; break; } }
                    if ( count( $blocks ) >= 32 || ! self::insert_block_in_transaction( $faluss_id, $link, $insert + 1 ) ) { throw new RuntimeException( 'insert' ); }
                    array_splice( $blocks, $insert, 0, array( $link ) ); $by_id[ $link['block_id'] ] = $insert;
                }
            }
            if ( ! self::persist_studio_preferences_in_transaction( $faluss_id, array( 'social_networks' => $socials, 'social_selected' => $selected ) ) ) { throw new RuntimeException( 'preferences' ); }
            $canonical_blocks = self::stored_blocks( $faluss_id )['blocks'];
            if ( ! \Faluss\Platform\Link\LinkIdentityAdapter::persistExternalLinksInTransaction( $faluss_id, self::identity_links( $canonical_blocks ) ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'projection' ); }
            return true;
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' ); return false;
        }
    }

    private static function finish_onboarding_profile( $faluss_id, $profile ) {
        $context = \Faluss\Platform\Link\LinkIdentityAdapter::onboardingContext();
        if ( empty( $context['required'] ) && 'published' === ( $profile['publication_status'] ?? '' ) ) {
            return \Faluss\Platform\Link\LinkIdentityAdapter::completeCardWizard();
        }
        return self::save_onboarding_identity( $faluss_id, $profile, array(), true ) && \Faluss\Platform\Link\LinkIdentityAdapter::completeCardWizard();
    }

    private static function save_onboarding_identity( $faluss_id, $profile, $overrides = array(), $publish = false ) {
        global $wpdb;
        if ( ! is_array( $profile ) || ! \Faluss\Platform\Link\LinkIdentityAdapter::studioAvailable() ) { return false; }
        $fields = array();
        if ( array_key_exists( 'display_name', $overrides ) ) { $fields['display_name'] = $overrides['display_name']; }
        if ( array_key_exists( 'bio', $overrides ) ) { $fields['bio'] = $overrides['bio']; }
        if ( array_key_exists( 'faluss_identity_avatar_id', $overrides ) ) { $fields['avatar_attachment_id'] = absint( $overrides['faluss_identity_avatar_id'] ); }
        if ( $publish ) { $fields['publication_status'] = 'published'; }
        if ( ! $fields || false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        try {
            if ( false === \Faluss\Platform\Link\LinkIdentityAdapter::lockStudioProfileInTransaction( $faluss_id ) || ! \Faluss\Platform\Link\LinkIdentityAdapter::persistStudioProfileInTransaction( $faluss_id, $fields ) || false === $wpdb->query( 'COMMIT' ) ) { throw new RuntimeException( 'identity' ); }
            return true;
        } catch ( Throwable $exception ) {
            $wpdb->query( 'ROLLBACK' );
            return false;
        }
    }

    /** @return array<int, array{network: string, url: string}>|false */
    private static function onboarding_socials( $raw, $selected ) {
        $out = array();
        foreach ( $selected as $network ) {
            $value = is_array( $raw ) && isset( $raw[ $network ] ) ? trim( (string) wp_unslash( $raw[ $network ] ) ) : '';
            if ( '' === $value ) { continue; }
            $url = self::onboarding_social_url( $network, $value );
            if ( '' === $url ) { return false; }
            $out[] = array( 'network' => $network, 'url' => $url );
        }
        return $out;
    }

    private static function onboarding_social_url( $network, $value ) {
        $valid = self::socials( array( array( 'network' => $network, 'url' => $value ) ) );
        if ( $valid ) { return $valid[0]['url']; }
        $identifier = ltrim( trim( $value ), '@' );
        if ( '' === $identifier || 1 !== preg_match( '/^[A-Za-z0-9._-]{1,100}$/D', $identifier ) ) { return ''; }
        $bases = array( 'instagram' => 'https://www.instagram.com/', 'tiktok' => 'https://www.tiktok.com/@', 'youtube' => 'https://www.youtube.com/@', 'x' => 'https://x.com/', 'linkedin' => 'https://www.linkedin.com/in/', 'github' => 'https://github.com/' );
        if ( ! isset( $bases[ $network ] ) ) { return ''; }
        $valid = self::socials( array( array( 'network' => $network, 'url' => $bases[ $network ] . rawurlencode( $identifier ) ) ) );
        return $valid ? $valid[0]['url'] : '';
    }

    /** @return array<int, string> */
    private static function onboarding_network_selection( $values ) {
        $allowed = array_keys( self::active_network_catalog() ); $out = array();
        foreach ( (array) $values as $value ) {
            $network = is_array( $value ) ? ( $value['network'] ?? '' ) : $value;
            $network = sanitize_key( (string) $network );
            if ( in_array( $network, $allowed, true ) && ! in_array( $network, $out, true ) ) { $out[] = $network; }
        }
        return $out;
    }

    /** @return array<string, array{label: string, stack: string}> */
    private static function onboarding_name_fonts() {
        $fonts = array( 'outfit' => array( 'label' => 'Outfit', 'stack' => 'Outfit, ui-sans-serif, system-ui, sans-serif' ) );
        $fonts = function_exists( 'apply_filters' ) ? apply_filters( 'faluss_link_onboarding_name_fonts', $fonts ) : $fonts;
        $clean = array();
        foreach ( (array) $fonts as $key => $font ) {
            $key = sanitize_key( (string) $key ); $label = is_array( $font ) ? sanitize_text_field( $font['label'] ?? '' ) : ''; $stack = is_array( $font ) ? (string) ( $font['stack'] ?? '' ) : '';
            if ( '' !== $key && '' !== $label && 1 === preg_match( '/^[A-Za-z0-9, ."\'-]+$/D', $stack ) ) { $clean[ $key ] = array( 'label' => $label, 'stack' => $stack ); }
        }
        return $clean ?: array( 'outfit' => array( 'label' => 'Outfit', 'stack' => 'Outfit, ui-sans-serif, system-ui, sans-serif' ) );
    }

    private static function onboarding_name_font( $value ) {
        $key = sanitize_key( (string) $value ); $fonts = self::onboarding_name_fonts();
        return isset( $fonts[ $key ] ) ? $key : 'outfit';
    }

    private static function onboarding_name_font_stack( $value ) {
        $fonts = self::onboarding_name_fonts(); $key = self::onboarding_name_font( $value );
        return $fonts[ $key ]['stack'];
    }

    /** @return array{key:string,label:string,weight:int,tracking:string} */
    private static function name_treatment_presentation( $value ) {
        $key = sanitize_key( (string) $value );
        if ( ! isset( self::NAME_TREATMENTS[ $key ] ) ) { $key = 'strong'; }
        return 'editorial' === $key
            ? array( 'key' => 'editorial', 'label' => self::NAME_TREATMENTS['editorial'], 'weight' => 500, 'tracking' => '-.065em' )
            : array( 'key' => 'strong', 'label' => self::NAME_TREATMENTS['strong'], 'weight' => 800, 'tracking' => '-.045em' );
    }

    private static function name_treatment_options( $selected ) {
        foreach ( array_keys( self::NAME_TREATMENTS ) as $key ) {
            $treatment = self::name_treatment_presentation( $key );
            ?><option value="<?php echo esc_attr( $treatment['key'] ); ?>" data-name-weight="<?php echo (int) $treatment['weight']; ?>" data-name-tracking="<?php echo esc_attr( $treatment['tracking'] ); ?>" <?php selected( $selected, $treatment['key'] ); ?>><?php echo esc_html( $treatment['label'] ); ?></option><?php
        }
    }

    private static function onboarding_assets() {
        if ( ! wp_style_is( self::ONBOARDING_STYLE, 'registered' ) ) { self::assets(); }
        wp_enqueue_style( self::STYLE ); wp_enqueue_style( self::ONBOARDING_STYLE );
        wp_localize_script( self::ONBOARDING_SCRIPT, 'falussLinkOnboarding', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'faluss_link_onboarding' ), 'avatarNonce' => wp_create_nonce( 'faluss_link_onboarding_avatar' ), 'themes' => self::catalog_themes_for_client( self::current_faluss_id() ) ) );
        wp_enqueue_script( self::ONBOARDING_SCRIPT );
    }

    private static function upload_member_image( $field, $nonce_action ) {
        if ( ! is_user_logged_in() || ! check_ajax_referer( $nonce_action, 'nonce', false ) || ! self::identity_ready() || ! \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId() ) { wp_send_json_error( array( 'message' => 'Accès refusé.' ), 403 ); }
        if ( empty( $_FILES[ $field ] ) || ! is_array( $_FILES[ $field ] ) ) { wp_send_json_error( array( 'message' => 'Image requise.' ), 400 ); }
        require_once ABSPATH . 'wp-admin/includes/file.php'; require_once ABSPATH . 'wp-admin/includes/image.php';
        $file = $_FILES[ $field ]; $type = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        if ( empty( $type['type'] ) || 0 !== strpos( $type['type'], 'image/' ) ) { wp_send_json_error( array( 'message' => 'Image invalide.' ), 400 ); }
        $upload = wp_handle_upload( $file, array( 'test_form' => false, 'mimes' => array( 'jpg|jpeg|jpe' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp' ) ) );
        if ( ! empty( $upload['error'] ) ) { wp_send_json_error( array( 'message' => 'Envoi impossible.' ), 400 ); }
        $attachment_id = wp_insert_attachment( array( 'post_mime_type' => $upload['type'], 'post_title' => sanitize_file_name( pathinfo( $upload['file'], PATHINFO_FILENAME ) ), 'post_status' => 'inherit', 'post_author' => get_current_user_id() ), $upload['file'] );
        if ( is_wp_error( $attachment_id ) || ! self::owned_image( (int) $attachment_id, get_current_user_id() ) ) { wp_send_json_error( array( 'message' => 'Image invalide.' ), 400 ); }
        wp_update_attachment_metadata( $attachment_id, wp_generate_attachment_metadata( $attachment_id, $upload['file'] ) );
        wp_send_json_success( array( 'id' => (int) $attachment_id, 'url' => wp_get_attachment_image_url( $attachment_id, 'medium' ) ) );
    }

    public static function widgets( $manager ) {
        if ( ! class_exists( 'Elementor\\Widget_Base' ) || ! is_object( $manager ) || ! method_exists( $manager, 'register' ) ) { return; }
        require_once FALUSS_LINK_DIR . 'LegacyLinkWidgets.php';
        $manager->register( new Faluss_Link_Card_Widget() ); $manager->register( new Faluss_Link_Appearance_Widget() ); $manager->register( new Faluss_Link_Studio_Widget() ); $manager->register( new Faluss_Link_Daily_Reward_Widget() ); $manager->register( new Faluss_Link_Discoveries_Widget() );
    }

    public static function socials( $value ) {
        $decoded = is_string( $value ) ? json_decode( $value, true ) : null;
        $items = is_array( $decoded ) ? ( isset( $decoded['networks'] ) ? $decoded['networks'] : $decoded ) : $value;
        if ( is_string( $items ) ) { $items = preg_split( '/\r\n|\r|\n/', $items ); }
        $allowed = array_keys( self::active_network_catalog() ); $out = array(); $seen = array();
        foreach ( (array) $items as $item ) {
            if ( is_array( $item ) ) { $network = $item['network'] ?? ''; $url = $item['url'] ?? ''; } else { list( $network, $url ) = array_pad( explode( '|', (string) $item, 2 ), 2, '' ); }
            $network = strtolower( trim( (string) $network ) ); $url = trim( (string) $url ); $parts = wp_parse_url( $url );
            if ( ! in_array( $network, $allowed, true ) || ! filter_var( $url, FILTER_VALIDATE_URL ) || ! is_array( $parts ) || 'https' !== strtolower( $parts['scheme'] ?? '' ) || isset( $seen[ $network . '|' . $url ] ) ) { continue; }
            $seen[ $network . '|' . $url ] = true; $out[] = array( 'network' => $network, 'url' => $url );
        }
        return $out;
    }

    private static function save_preferences( $faluss_id, $post ) {
        global $wpdb; $table = Faluss_Link_Schema::table(); if ( '' === $table ) { return false; }
        $pick = static function( $value, $allowed, $fallback ) { return in_array( $value, $allowed, true ) ? $value : $fallback; };
        $old = self::prefs( $faluss_id, false );
        $field = static function( $key, $fallback ) use ( $post ) { return array_key_exists( $key, $post ) ? wp_unslash( $post[ $key ] ) : $fallback; };
        $cover = max( 0, (int) $field( 'cover_attachment_id', $old['cover_attachment_id'] ) ); if ( $cover && ! self::owned_image( $cover, get_current_user_id() ) ) { return false; }
        $color_input = $field( 'hero_transition_color', $old['hero_transition_color'] );
        $background_input = $field( 'page_background', $old['page_background'] );
        $button_color_input = $field( 'button_color', $old['button_color'] );
        $color = is_string( $color_input ) ? sanitize_hex_color( $color_input ) : null;
        $page_background = is_string( $background_input ) ? sanitize_hex_color( $background_input ) : null;
        $button_color = is_string( $button_color_input ) ? sanitize_hex_color( $button_color_input ) : null;
        $name_color = self::name_color( $field( 'name_color', $old['name_color'] ) );
        $social_variant = self::social_variant( $field( 'social_variant', $old['social_variant'] ) );
        $avatar_border = array_key_exists( 'avatar_border', $post ) ? ( ! empty( $post['avatar_border'] ) ? 1 : 0 ) : (int) $old['avatar_border'];
        $name_font = self::onboarding_name_font( $field( 'name_font', $old['name_font'] ) );
        $social_selected = array_key_exists( 'social_selected', $post ) ? self::onboarding_network_selection( $post['social_selected'] ) : $old['social_selected'];
        $theme_reference = self::theme_reference( $field( 'selected_theme', $old['theme_reference'] ) );
        if ( array_key_exists( 'selected_theme', $post ) ) {
            $theme = self::catalog_theme( $theme_reference );
            if ( ! $theme || ! self::theme_available_to_subject( $theme, $faluss_id ) ) { return false; }
        }
        $theme_overrides = self::theme_overrides( $field( 'theme_overrides', $old['theme_overrides'] ) );
        $networks = array_key_exists( 'social_networks', $post ) || array_key_exists( 'social_links', $post ) ? self::socials( wp_unslash( $post['social_networks'] ?? $post['social_links'] ) ) : self::socials( $old['social_links'] );
        $payload = array( 'networks' => $networks, 'social_selected' => $social_selected, 'avatar_border' => $avatar_border, 'name_font' => $name_font, 'alignment' => $pick( $field( 'alignment', $old['alignment'] ), array( 'left', 'center' ), $old['alignment'] ), 'page_background' => $page_background ? $page_background : '#FFFDF5', 'button_color' => $button_color ? $button_color : '#080808', 'hero_transition_color' => $color ? $color : '#FFFDF5', 'hero_transition_intensity' => min( 100, max( 0, (int) $field( 'hero_transition_intensity', $old['hero_transition_intensity'] ) ) ), 'hero_transition_position' => min( 100, max( 35, (int) $field( 'hero_transition_position', $old['hero_transition_position'] ) ) ), 'name_color' => $name_color, 'social_variant' => $social_variant, 'selected_theme' => $theme_reference, 'theme_overrides' => $theme_overrides );
        $values = array( 'cover_attachment_id' => $cover, 'avatar_visible' => array_key_exists( 'avatar_visible', $post ) ? ( ! empty( $post['avatar_visible'] ) ? 1 : 0 ) : (int) $old['avatar_visible'], 'name_weight' => 'bold', 'name_treatment' => $pick( $field( 'name_treatment', $old['name_treatment'] ), array_keys( self::NAME_TREATMENTS ), $old['name_treatment'] ), 'available' => array_key_exists( 'available', $post ) ? ( ! empty( $post['available'] ) ? 1 : 0 ) : (int) $old['available'], 'bio_mode' => $pick( $field( 'bio_mode', $old['bio_mode'] ), array( 'editorial', 'announcement' ), $old['bio_mode'] ), 'announcement' => sanitize_text_field( $field( 'announcement', $old['announcement'] ) ), 'announcement_variant' => $pick( $field( 'announcement_variant', $old['announcement_variant'] ), array_keys( self::ANNOUNCEMENTS ), $old['announcement_variant'] ), 'social_links' => wp_json_encode( $payload ), 'social_layout' => $pick( $field( 'social_layout', $old['social_layout'] ), array_keys( self::LAYOUTS ), $old['social_layout'] ), 'link_style' => $pick( $field( 'link_style', $old['link_style'] ), array_keys( self::LINK_STYLES ), $old['link_style'] ) );
        $now = gmdate( 'Y-m-d H:i:s' );
        if ( empty( $old['_has_row'] ) ) { return false !== $wpdb->query( $wpdb->prepare( 'INSERT INTO ' . $table . ' (faluss_id,cover_attachment_id,avatar_visible,name_weight,name_treatment,available,bio_mode,announcement,announcement_variant,social_links,social_layout,link_style,created_at,updated_at) VALUES (%s,%d,%d,%s,%s,%d,%s,%s,%s,%s,%s,%s,%s,%s)', ...array_merge( array( $faluss_id ), array_values( $values ), array( $now, $now ) ) ) ); }
        return false !== $wpdb->update( $table, $values + array( 'updated_at' => $now ), array( 'faluss_id' => $faluss_id ) );
    }

    private static function identity_fields( $profile ) {
        $published = 'published' === $profile['publication_status'];
        ?><label for="faluss-studio-slug"><?php esc_html_e( 'Identifiant public', 'faluss-link' ); ?></label><input id="faluss-studio-slug" name="public_slug" type="text" value="<?php echo esc_attr( $profile['public_slug'] ); ?>" pattern="[a-z0-9][a-z0-9-]{1,39}" maxlength="40" <?php echo '' !== $profile['public_slug'] ? 'readonly' : ''; ?> required><p class="faluss-link-studio__hint"><?php esc_html_e( 'Il reste stable après sa création.', 'faluss-link' ); ?></p><label for="faluss-studio-name"><?php esc_html_e( 'Nom affiché', 'faluss-link' ); ?></label><input id="faluss-studio-name" name="display_name" type="text" maxlength="80" value="<?php echo esc_attr( $profile['display_name'] ); ?>" required><label for="faluss-studio-bio"><?php esc_html_e( 'Bio courte', 'faluss-link' ); ?></label><textarea id="faluss-studio-bio" name="bio" maxlength="280" rows="4"><?php echo esc_textarea( $profile['bio'] ); ?></textarea><label for="faluss-studio-avatar"><?php esc_html_e( 'Avatar', 'faluss-link' ); ?></label><input name="faluss_identity_avatar_id" type="hidden" value="<?php echo (int) $profile['avatar_attachment_id']; ?>"><input id="faluss-studio-avatar" name="faluss_identity_avatar" type="file" accept="image/jpeg,image/png,image/webp,image/gif"><fieldset class="faluss-link-publication"><legend><?php esc_html_e( 'Profil public', 'faluss-link' ); ?></legend><label class="faluss-link-publication__switch"><input name="publication_status" type="checkbox" value="published" role="switch" aria-describedby="faluss-studio-publication-help" aria-checked="<?php echo $published ? 'true' : 'false'; ?>" <?php checked( $published ); ?>><span class="faluss-link-publication__track" aria-hidden="true"><span></span></span><span class="faluss-link-publication__state" data-fl-publication-state><?php echo esc_html( $published ? __( 'Visible', 'faluss-link' ) : __( 'Masqué', 'faluss-link' ) ); ?></span></label><p id="faluss-studio-publication-help" class="faluss-link-publication__hint"><?php esc_html_e( 'Choisissez si votre carte peut être consultée publiquement.', 'faluss-link' ); ?></p></fieldset><?php
    }

    private static function preference_fields( $preferences, $studio = false ) {
        $prefix = $studio ? 'faluss-studio-' : 'faluss-link-editor-';
        ?>
        <div class="faluss-link-preferences">
            <div class="faluss-link-editor__media">
                <label><?php esc_html_e( 'Photo de couverture', 'faluss-link' ); ?></label>
                <input class="faluss-link-editor__cover-id" name="cover_attachment_id" type="hidden" value="<?php echo (int) $preferences['cover_attachment_id']; ?>">
                <button class="faluss-link-editor__select-cover" type="button"><?php esc_html_e( 'Choisir une image', 'faluss-link' ); ?></button>
                <button class="faluss-link-editor__remove-cover" type="button"><?php esc_html_e( 'Retirer', 'faluss-link' ); ?></button>
                <div class="faluss-link-editor__cover-preview"><?php if ( (int) $preferences['cover_attachment_id'] ) { echo wp_get_attachment_image( (int) $preferences['cover_attachment_id'], 'medium', false, array( 'alt' => '' ) ); } ?></div>
            </div>
            <label class="faluss-link-studio__check"><input name="avatar_visible" type="checkbox" value="1" <?php checked( $preferences['avatar_visible'] ); ?>> <?php esc_html_e( 'Afficher l’avatar', 'faluss-link' ); ?></label>
            <?php if ( ! $studio ) : ?><label class="faluss-link-studio__check"><input name="available" type="checkbox" value="1" <?php checked( $preferences['available'] ); ?>> <?php esc_html_e( 'Afficher Disponible', 'faluss-link' ); ?></label><?php endif; ?>
            <label for="<?php echo esc_attr( $prefix ); ?>name"><?php esc_html_e( 'Traitement du nom', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>name" name="name_treatment"><?php self::name_treatment_options( $preferences['name_treatment'] ); ?></select>
            <label for="<?php echo esc_attr( $prefix ); ?>bio"><?php esc_html_e( 'Bio', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>bio" name="bio_mode"><option value="editorial" <?php selected( $preferences['bio_mode'], 'editorial' ); ?>><?php esc_html_e( 'Texte éditorial', 'faluss-link' ); ?></option><option value="announcement" <?php selected( $preferences['bio_mode'], 'announcement' ); ?>><?php esc_html_e( 'Annonce', 'faluss-link' ); ?></option></select>
            <label for="<?php echo esc_attr( $prefix ); ?>announcement"><?php esc_html_e( 'Texte de l’annonce', 'faluss-link' ); ?></label>
            <input id="<?php echo esc_attr( $prefix ); ?>announcement" name="announcement" maxlength="120" value="<?php echo esc_attr( $preferences['announcement'] ); ?>">
            <label for="<?php echo esc_attr( $prefix ); ?>announcement-variant"><?php esc_html_e( 'Couleur de l’annonce', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>announcement-variant" name="announcement_variant"><?php self::options( self::ANNOUNCEMENTS, $preferences['announcement_variant'] ); ?></select>
            <label for="<?php echo esc_attr( $prefix ); ?>alignment"><?php esc_html_e( 'Alignement du profil', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>alignment" name="alignment"><option value="left" <?php selected( $preferences['alignment'], 'left' ); ?>><?php esc_html_e( 'Gauche', 'faluss-link' ); ?></option><option value="center" <?php selected( $preferences['alignment'], 'center' ); ?>><?php esc_html_e( 'Centre', 'faluss-link' ); ?></option></select>
            <div class="faluss-link-color-field"><label for="<?php echo esc_attr( $prefix ); ?>transition-color"><?php esc_html_e( 'Couleur de transition de la couverture', 'faluss-link' ); ?></label><div class="faluss-link-color-field__control"><input id="<?php echo esc_attr( $prefix ); ?>transition-color" name="hero_transition_color" type="color" value="<?php echo esc_attr( $preferences['hero_transition_color'] ); ?>"><output class="faluss-link-color-field__value" data-fl-color-value="hero_transition_color" for="<?php echo esc_attr( $prefix ); ?>transition-color"><?php echo esc_html( $preferences['hero_transition_color'] ); ?></output></div><p class="faluss-link-color-field__hint"><?php esc_html_e( 'Fondu entre la couverture et votre fond', 'faluss-link' ); ?></p></div>
            <label for="<?php echo esc_attr( $prefix ); ?>transition-intensity"><?php esc_html_e( 'Intensité de transition', 'faluss-link' ); ?></label>
            <input id="<?php echo esc_attr( $prefix ); ?>transition-intensity" name="hero_transition_intensity" type="range" min="0" max="100" value="<?php echo (int) $preferences['hero_transition_intensity']; ?>">
            <label for="<?php echo esc_attr( $prefix ); ?>transition-position"><?php esc_html_e( 'Position de transition', 'faluss-link' ); ?></label>
            <input id="<?php echo esc_attr( $prefix ); ?>transition-position" name="hero_transition_position" type="range" min="35" max="100" value="<?php echo (int) $preferences['hero_transition_position']; ?>">
            <label for="<?php echo esc_attr( $prefix ); ?>links"><?php esc_html_e( 'Boutons de liens', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>links" name="link_style"><?php self::options( self::LINK_STYLES, $preferences['link_style'] ); ?></select>
            <label for="<?php echo esc_attr( $prefix ); ?>social-variant"><?php esc_html_e( 'Style des réseaux', 'faluss-link' ); ?></label>
            <select id="<?php echo esc_attr( $prefix ); ?>social-variant" name="social_variant"><?php self::options( self::SOCIAL_VARIANTS, $preferences['social_variant'] ); ?></select>
        </div>
        <?php
    }

    private static function content_composer( $blocks ) {
        ?><fieldset class="faluss-link-content-composer"><legend><?php esc_html_e( 'Contenu de ma carte', 'faluss-link' ); ?></legend><p class="faluss-link-content-composer__hint"><?php esc_html_e( 'Composez vos sections dans l’ordre de lecture.', 'faluss-link' ); ?></p><div class="faluss-link-content-composer__list"><?php foreach ( $blocks as $index => $block ) { self::content_block_fields( $block, $index, count( $blocks ) ); if ( 'media_teaser' === $block['type'] ) { ?><input class="faluss-link-content-block__format-source" type="hidden" value="<?php echo esc_attr( self::teaser_format( $block['format'] ?? 'landscape' ) ); ?>"><?php } } ?></div><div class="faluss-link-content-composer__add"><label for="faluss-link-content-type"><?php esc_html_e( 'Type d’élément', 'faluss-link' ); ?></label><select id="faluss-link-content-type" class="faluss-link-content-composer__type"><?php self::options( self::BLOCK_TYPES, 'section_title' ); ?></select><button class="faluss-link-content-composer__add-button" type="button"><?php esc_html_e( 'Ajouter un élément', 'faluss-link' ); ?></button></div></fieldset><?php
    }

    private static function content_block_fields( $block, $index, $count ) {
        $type = $block['type']; $label = self::BLOCK_TYPES[ $type ]; $prefix = 'content_blocks[' . (int) $index . ']';
        ?><article class="faluss-link-content-block" data-block-type="<?php echo esc_attr( $type ); ?>"><input data-fl-block-field="block_id" name="<?php echo esc_attr( $prefix ); ?>[block_id]" type="hidden" value="<?php echo esc_attr( $block['block_id'] ); ?>"><input data-fl-block-field="type" name="<?php echo esc_attr( $prefix ); ?>[type]" type="hidden" value="<?php echo esc_attr( $type ); ?>"><header class="faluss-link-content-block__header"><strong><?php echo esc_html( $label ); ?></strong><div class="faluss-link-content-block__actions"><button data-fl-block-action="up" type="button" aria-label="<?php echo esc_attr( sprintf( __( 'Monter %s', 'faluss-link' ), $label ) ); ?>" <?php disabled( 0 === (int) $index ); ?>><?php esc_html_e( 'Monter', 'faluss-link' ); ?></button><button data-fl-block-action="down" type="button" aria-label="<?php echo esc_attr( sprintf( __( 'Descendre %s', 'faluss-link' ), $label ) ); ?>" <?php disabled( (int) $index === (int) $count - 1 ); ?>><?php esc_html_e( 'Descendre', 'faluss-link' ); ?></button><button data-fl-block-action="remove" type="button" aria-label="<?php echo esc_attr( sprintf( __( 'Supprimer %s', 'faluss-link' ), $label ) ); ?>"><?php esc_html_e( 'Supprimer', 'faluss-link' ); ?></button></div></header><?php if ( 'section_title' === $type ) : ?><label><?php esc_html_e( 'Titre', 'faluss-link' ); ?><input data-fl-block-field="value" name="<?php echo esc_attr( $prefix ); ?>[value]" type="text" maxlength="80" value="<?php echo esc_attr( $block['value'] ); ?>"></label><?php elseif ( 'text' === $type ) : ?><label><?php esc_html_e( 'Texte', 'faluss-link' ); ?><textarea data-fl-block-field="value" name="<?php echo esc_attr( $prefix ); ?>[value]" maxlength="480" rows="3"><?php echo esc_textarea( $block['value'] ); ?></textarea></label><?php elseif ( 'media_teaser' === $type ) : ?><div class="faluss-link-content-block__media"><input data-fl-block-field="attachment_id" name="<?php echo esc_attr( $prefix ); ?>[attachment_id]" type="hidden" value="<?php echo (int) $block['attachment_id']; ?>"><button class="faluss-link-content-block__select-teaser" type="button"><?php esc_html_e( 'Choisir une image', 'faluss-link' ); ?></button><button class="faluss-link-content-block__remove-teaser" type="button"><?php esc_html_e( 'Retirer', 'faluss-link' ); ?></button><div class="faluss-link-content-block__media-preview"><?php echo self::teaser_image_markup( (int) $block['attachment_id'], $block['title'] ); ?></div></div><label><?php esc_html_e( 'Titre facultatif', 'faluss-link' ); ?><input data-fl-block-field="title" name="<?php echo esc_attr( $prefix ); ?>[title]" type="text" maxlength="80" value="<?php echo esc_attr( $block['title'] ); ?>"></label><label><?php esc_html_e( 'Texte facultatif', 'faluss-link' ); ?><textarea data-fl-block-field="text" name="<?php echo esc_attr( $prefix ); ?>[text]" maxlength="240" rows="3"><?php echo esc_textarea( $block['text'] ); ?></textarea></label><?php self::teaser_access_fields( $block, $prefix ); ?><?php else : ?><label><?php esc_html_e( 'Libellé', 'faluss-link' ); ?><input data-fl-block-field="label" name="<?php echo esc_attr( $prefix ); ?>[label]" type="text" maxlength="80" value="<?php echo esc_attr( $block['label'] ); ?>"></label><label><?php esc_html_e( 'URL HTTPS', 'faluss-link' ); ?><input data-fl-block-field="url" name="<?php echo esc_attr( $prefix ); ?>[url]" type="url" maxlength="2048" value="<?php echo esc_attr( $block['url'] ); ?>" placeholder="https://"></label><?php endif; ?></article><?php
    }

    /** The owner selects only Core-defined rights; raw entitlement codes have no Studio field. */
    private static function teaser_access_fields( $block, $prefix ) {
        $mode = self::teaser_access_mode( $block['access_mode'] ?? 'public' );
        $code = self::entitlement_code( $block['entitlement_code'] ?? '' );
        $rights = self::teaser_entitlement_choices();
        ?><fieldset class="faluss-link-content-block__access"><legend><?php esc_html_e( 'Accès au teaser', 'faluss-link' ); ?></legend><label for="<?php echo esc_attr( $prefix ); ?>-access-mode"><?php esc_html_e( 'Visibilité', 'faluss-link' ); ?></label><select id="<?php echo esc_attr( $prefix ); ?>-access-mode" data-fl-block-field="access_mode" name="<?php echo esc_attr( $prefix ); ?>[access_mode]"><?php self::options( self::TEASER_ACCESS_MODES, $mode ); ?></select><?php if ( $rights ) : ?><label for="<?php echo esc_attr( $prefix ); ?>-entitlement"><?php esc_html_e( 'Droit requis', 'faluss-link' ); ?></label><select id="<?php echo esc_attr( $prefix ); ?>-entitlement" data-fl-block-field="entitlement_code" name="<?php echo esc_attr( $prefix ); ?>[entitlement_code]"<?php disabled( 'entitlement' !== $mode ); ?>><option value=""><?php esc_html_e( 'Choisir un droit', 'faluss-link' ); ?></option><?php foreach ( $rights as $right ) : ?><option value="<?php echo esc_attr( $right['code'] ); ?>" <?php selected( $code, $right['code'] ); ?>><?php echo esc_html( $right['label'] ); ?></option><?php endforeach; ?></select><?php else : ?><p class="faluss-link-content-block__access-hint" data-fl-entitlement-unavailable><?php esc_html_e( 'Aucun droit lisible : vérifiez le Connector et la permission entitlements.read avant d’utiliser « Droit requis ».', 'faluss-link' ); ?></p><?php if ( 'entitlement' === $mode && '' !== $code ) : ?><input data-fl-block-field="entitlement_code" name="<?php echo esc_attr( $prefix ); ?>[entitlement_code]" type="hidden" value="<?php echo esc_attr( $code ); ?>"><?php endif; ?><?php endif; ?><p class="faluss-link-content-block__access-state" data-fl-access-state><?php echo esc_html( self::TEASER_ACCESS_MODES[ $mode ] ); ?></p></fieldset><?php
    }

    private static function theme_picker( $preferences ) {
        $themes = self::catalog_themes_for_client( $preferences['faluss_id'] ?? '' );
        /* The editor always hydrates the same effective theme as the public card. */
        $reference = self::theme_reference( $preferences['theme_reference'] ?? self::system_card_theme()['slug'] );
        ?>
        <fieldset class="faluss-link-theme-picker">
            <legend><?php esc_html_e( 'Thèmes', 'faluss-link' ); ?></legend>
            <p class="faluss-link-theme-picker__hint"><?php esc_html_e( 'Un thème pose une base. Vos réglages individuels restent prioritaires.', 'faluss-link' ); ?></p>
            <input type="hidden" name="selected_theme" value="<?php echo esc_attr( $reference ); ?>">
            <input type="hidden" name="theme_overrides" value="<?php echo esc_attr( wp_json_encode( array_values( $preferences['theme_overrides'] ?? array() ) ) ); ?>">
            <div class="faluss-link-theme-picker__rail" aria-label="<?php esc_attr_e( 'Thèmes de carte disponibles', 'faluss-link' ); ?>">
                <?php foreach ( $themes as $theme ) : ?>
                    <?php $preview = (int) $theme['preview_attachment_id']; $locked = ! empty( $theme['locked'] ); $selected = $reference === $theme['slug']; ?>
                    <button class="faluss-link-theme-picker__theme<?php echo $locked ? ' is-locked' : ''; ?>" type="button" data-faluss-theme="<?php echo esc_attr( $theme['slug'] ); ?>" aria-pressed="<?php echo $selected ? 'true' : 'false'; ?>"<?php echo $locked ? ' disabled aria-disabled="true"' : ''; ?>>
                        <span class="faluss-link-theme-picker__image" style="--fl-theme-page:<?php echo esc_attr( $theme['page_background'] ); ?>;--fl-theme-hero:<?php echo esc_attr( $theme['hero_transition_color'] ); ?>;--fl-theme-name:<?php echo esc_attr( $theme['name_color'] ); ?>"><?php if ( $preview && wp_attachment_is_image( $preview ) ) { echo wp_get_attachment_image( $preview, 'medium', false, array( 'alt' => '' ) ); } else { ?><span aria-hidden="true"></span><?php } ?></span>
                        <span class="faluss-link-theme-picker__name"><?php echo esc_html( $theme['name'] ); ?></span>
                        <span class="faluss-link-theme-picker__selected"><?php esc_html_e( 'Sélectionné', 'faluss-link' ); ?></span>
                        <?php if ( $locked ) : ?><span class="faluss-link-theme-picker__locked"><?php esc_html_e( 'Droit requis', 'faluss-link' ); ?></span><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </fieldset>
        <?php
    }

    private static function page_background_field( $preferences, $studio ) {
        $id = $studio ? 'faluss-studio-page-background' : 'faluss-link-editor-page-background';
        ?><div class="faluss-link-color-field"><label for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'Fond de page', 'faluss-link' ); ?></label><div class="faluss-link-color-field__control"><input id="<?php echo esc_attr( $id ); ?>" name="page_background" type="color" value="<?php echo esc_attr( $preferences['page_background'] ); ?>"><output class="faluss-link-color-field__value" data-fl-color-value="page_background" for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $preferences['page_background'] ); ?></output></div><p class="faluss-link-color-field__hint"><?php esc_html_e( 'Couleur derrière votre carte', 'faluss-link' ); ?></p></div><?php
    }

    private static function name_color_field( $preferences ) {
        ?><fieldset class="faluss-link-name-colors"><legend><?php esc_html_e( 'Couleur du nom', 'faluss-link' ); ?></legend><p class="faluss-link-name-colors__hint"><?php esc_html_e( 'Choisissez une couleur Faluss pour votre nom affiché.', 'faluss-link' ); ?></p><div class="faluss-link-name-colors__options"><?php foreach ( self::NAME_COLORS as $color => $label ) : ?><label class="faluss-link-name-color"><input name="name_color" type="radio" value="<?php echo esc_attr( $color ); ?>" <?php checked( $preferences['name_color'], $color ); ?>><span class="faluss-link-name-color__swatch" aria-hidden="true" style="--fl-name-color-swatch:<?php echo esc_attr( $color ); ?>"></span><span class="faluss-link-name-color__label"><?php echo esc_html( $label ); ?></span><span class="faluss-link-name-color__state"><?php esc_html_e( 'Sélectionnée', 'faluss-link' ); ?></span></label><?php endforeach; ?></div></fieldset><?php
    }

    private static function social_editor( $preferences ) {
        $catalog = self::active_network_catalog(); $socials = self::socials( $preferences['social_links'] );
        ?><fieldset class="faluss-link-studio__social-fields"><legend><?php esc_html_e( 'Réseaux sociaux', 'faluss-link' ); ?></legend><div class="faluss-link-studio__network-list"><?php foreach ( $socials as $index => $social ) : ?><div class="faluss-link-studio__network-row"><select name="social_networks[<?php echo (int) $index; ?>][network]" aria-label="<?php esc_attr_e( 'Réseau', 'faluss-link' ); ?>"><?php self::options( wp_list_pluck( $catalog, 'label' ), $social['network'] ); ?></select><input name="social_networks[<?php echo (int) $index; ?>][url]" type="url" maxlength="2048" value="<?php echo esc_attr( $social['url'] ); ?>" placeholder="https://" aria-label="<?php esc_attr_e( 'URL HTTPS', 'faluss-link' ); ?>"><button type="button" class="faluss-link-studio__remove-row"><?php esc_html_e( 'Supprimer', 'faluss-link' ); ?></button></div><?php endforeach; ?></div><button type="button" class="faluss-link-studio__add-network"><?php esc_html_e( 'Ajouter un réseau', 'faluss-link' ); ?></button></fieldset><?php
    }

    /**
     * Shared preview facade used by Studio and ONB-02. The context changes only
     * density, never the member's resolved card appearance, and never publishes.
     */
    private static function card_preview_markup( $profile, $preferences, $alignment, $blocks, $demo_links = false, $context = 'studio-preview' ) {
        return self::card_markup( $profile, $preferences, $alignment, true, $blocks, $demo_links, $context );
    }

    /**
     * Card presentation facade: one canonical source for public cards, Studio
     * and onboarding. Future themes enter through resolve_card_presentation(),
     * while an onboarding draft only changes this in-memory presentation.
     *
     * @return array<string,mixed>
     */
    private static function card_presentation( $profile, $preferences, $alignment = 'center', $blocks = array(), $preview = false, $preview_demo_links = false, $context = 'public' ) {
        $contexts = array( 'public', 'studio-preview', 'onboarding-preview' );
        $context = in_array( $context, $contexts, true ) ? $context : 'public';
        $preferences = self::resolve_card_presentation( $preferences, true );
        $cover = (int) ( $preferences['cover_attachment_id'] ?? 0 );
        $avatar = (int) ( $profile['avatar_attachment_id'] ?? 0 );
        $name_treatment = self::name_treatment_presentation( $preferences['name_treatment'] ?? 'strong' );
        return array(
            'profile' => is_array( $profile ) ? $profile : array(),
            'preferences' => $preferences,
            'alignment' => self::align( $alignment ),
            'blocks' => is_array( $blocks ) ? $blocks : array(),
            'preview' => (bool) $preview,
            'preview_demo_links' => (bool) $preview_demo_links,
            'context' => $context,
            'density' => 'onboarding-preview' === $context ? 'compact' : 'standard',
            'name_treatment' => $name_treatment,
            'has_cover' => $cover > 0,
            'has_avatar' => ! empty( $preferences['avatar_visible'] ) && $avatar > 0,
        );
    }

    private static function card_markup( $profile, $preferences, $alignment = 'center', $preview = false, $blocks = array(), $preview_demo_links = false, $context = 'public' ) {
        return self::card_markup_from_presentation( self::card_presentation( $profile, $preferences, $alignment, $blocks, $preview, $preview_demo_links, $context ) );
    }

    /** @param array<string,mixed> $presentation */
    private static function card_markup_from_presentation( $presentation ) {
        $profile = $presentation['profile'];
        $preferences = $presentation['preferences'];
        $cover = (int) $preferences['cover_attachment_id']; $avatar = (int) $profile['avatar_attachment_id']; $has_cover = ! empty( $presentation['has_cover'] ); $has_avatar = ! empty( $presentation['has_avatar'] );
        $styles = self::resolve_card_styles( $preferences );
        $name_treatment = $presentation['name_treatment'];
        $variant = self::social_variant( $preferences['social_variant'] ?? 'outline' );
        $social_markup = self::social_markup( $preferences['social_links'], $variant );
        $show_social_skeleton = '' === $social_markup && ! empty( $presentation['preview'] ) && ! empty( $presentation['preview_demo_links'] );
        if ( $show_social_skeleton ) { $social_markup = self::preview_social_markup( $variant ); }
        $style = sprintf( '--fl-page-background:%s;--fl-canvas:%s;--fl-action:%s;--fl-hero-transition-color:%s;--fl-hero-transition-intensity:%d%%;--fl-hero-transition-position:%d%%;--fl-name-color:%s;--fl-name-font:%s;--fl-name-weight:%d;--fl-name-tracking:%s;', $preferences['page_background'], $preferences['page_background'], $preferences['button_color'], $preferences['hero_transition_color'], (int) $preferences['hero_transition_intensity'], (int) $preferences['hero_transition_position'], $styles['name_color'], self::onboarding_name_font_stack( $preferences['name_font'] ?? 'outfit' ), (int) $name_treatment['weight'], $name_treatment['tracking'] );
        ob_start();
        ?>
        <article class="faluss-link-card faluss-link-card--align-<?php echo esc_attr( $presentation['alignment'] ); ?> faluss-link-card--links-<?php echo esc_attr( $preferences['link_style'] ); ?> faluss-link-card--density-<?php echo esc_attr( $presentation['density'] ); ?> faluss-link-card--cover-<?php echo $has_cover ? 'yes' : 'no'; ?> faluss-link-card--avatar-<?php echo $has_avatar ? 'yes' : 'no'; ?> faluss-link-card--avatar-border-<?php echo ! empty( $preferences['avatar_border'] ) ? 'yes' : 'no'; ?>" data-faluss-card-context="<?php echo esc_attr( $presentation['context'] ); ?>" data-faluss-card-density="<?php echo esc_attr( $presentation['density'] ); ?>" data-faluss-card-theme="<?php echo esc_attr( $preferences['selected_theme'] ?? self::system_card_theme()['slug'] ); ?>" style="<?php echo esc_attr( $style ); ?>">
            <div class="faluss-link-card__cover" <?php echo $has_cover ? '' : 'hidden'; ?>><?php if ( $has_cover ) { echo wp_get_attachment_image( $cover, 'large', false, array( 'alt' => '' ) ); } ?></div>
            <div class="faluss-link-card__body">
                <div class="faluss-link-card__avatar" <?php echo $has_avatar ? '' : 'hidden'; ?>><?php if ( $has_avatar ) { echo wp_get_attachment_image( $avatar, 'medium', false, array( 'alt' => '' ) ); } ?></div>
                <h2 class="faluss-link-card__name faluss-link-card__name--<?php echo esc_attr( $name_treatment['key'] ); ?>"><?php echo esc_html( $profile['display_name'] ?: __( 'Mon Faluss', 'faluss-link' ) ); ?></h2>
                <p class="faluss-link-card__handle"><?php echo '' === $profile['public_slug'] ? '@—' : '@' . esc_html( $profile['public_slug'] ); ?></p>
                <span class="faluss-link-card__availability" <?php echo (int) $preferences['available'] ? '' : 'hidden'; ?>><i></i><?php esc_html_e( 'Disponible', 'faluss-link' ); ?></span>
                <p class="faluss-link-card__announcement faluss-link-card__announcement--<?php echo esc_attr( $preferences['announcement_variant'] ); ?>" <?php echo 'announcement' === $preferences['bio_mode'] && '' !== $preferences['announcement'] ? '' : 'hidden'; ?>><?php echo esc_html( $preferences['announcement'] ); ?></p>
                <p class="faluss-link-card__bio" <?php echo 'announcement' === $preferences['bio_mode'] ? 'hidden' : ''; ?>><?php echo esc_html( $profile['bio'] ); ?></p>
                <nav class="faluss-link-card__social faluss-link-card__social--<?php echo esc_attr( $preferences['social_layout'] ); ?> faluss-link-card__social--variant-<?php echo esc_attr( $variant ); ?><?php echo $show_social_skeleton ? ' faluss-link-card__social--skeleton' : ''; ?>" data-faluss-social-variant="<?php echo esc_attr( $variant ); ?>" aria-label="<?php esc_attr_e( 'Réseaux sociaux', 'faluss-link' ); ?>"<?php echo '' === $social_markup ? ' hidden' : ''; ?>><?php echo $social_markup; ?></nav>
                <?php echo self::public_blocks_markup( $presentation['blocks'], $profile, ! empty( $presentation['preview'] ), ! empty( $presentation['preview_demo_links'] ) ); ?>
            </div>
        </article>
        <?php
        return (string) ob_get_clean();
    }

    /** Temporary, non-actionable placeholders rendered only by the shared preview facade. */
    private static function preview_social_markup( $variant ) {
        $markup = '';
        foreach ( array( 'instagram', 'tiktok', 'x' ) as $network ) {
            $asset = self::social_asset_markup( $network, $variant );
            if ( '' !== $asset ) { $markup .= '<span class="faluss-link-card__social-demo" data-faluss-preview-only data-faluss-network="' . esc_attr( $network ) . '" aria-hidden="true">' . $asset . '</span>'; }
        }
        return $markup;
    }

    private static function public_blocks_markup( $blocks, $profile = array(), $preview = false, $preview_demo_links = false ) {
        $has_link = false;
        foreach ( (array) $blocks as $block ) { if ( is_array( $block ) && 'link' === ( $block['type'] ?? '' ) ) { $has_link = true; break; } }
        if ( ! $blocks && ! ( $preview && $preview_demo_links ) ) { return ''; }
        ob_start(); ?><div class="faluss-link-card__content-blocks faluss-link-card__links"><?php foreach ( (array) $blocks as $block ) { echo self::public_block_markup( $block, $profile, $preview ); } ?><?php if ( $preview && $preview_demo_links && ! $has_link ) : ?><div class="faluss-link-card__preview-links" data-faluss-preview-only aria-hidden="true"><span class="faluss-link-card__link faluss-link-card__link--skeleton"></span><span class="faluss-link-card__link faluss-link-card__link--skeleton"></span><span class="faluss-link-card__link faluss-link-card__link--skeleton"></span></div><?php endif; ?></div><?php return (string) ob_get_clean();
    }

    /* All current and future strictly validated blocks use this single rendering registry. */
    private static function public_block_markup( $block, $profile = array(), $preview = false ) {
        if ( ! is_array( $block ) || empty( $block['type'] ) ) { return ''; }
        ob_start();
        if ( 'section_title' === $block['type'] ) : ?><h3 class="faluss-link-card__section-title"><?php echo esc_html( $block['value'] ); ?></h3><?php endif;
        if ( 'text' === $block['type'] ) : ?><p class="faluss-link-card__content-text"><?php echo esc_html( $block['value'] ); ?></p><?php endif;
        if ( 'link' === $block['type'] ) : ?><a class="faluss-link-card__link" href="<?php echo esc_url( $block['url'] ); ?>" target="_blank" rel="noopener noreferrer nofollow"><?php echo esc_html( $block['label'] ); ?></a><?php endif;
        if ( 'media_teaser' === $block['type'] ) :
            $access = self::teaser_access_decision( $block, $profile['faluss_id'] ?? '', $preview );
            $has_copy = '' !== $block['title'] || '' !== $block['text'];
            ?><section class="faluss-link-card__media-teaser faluss-link-card__media-teaser--format-<?php echo esc_attr( self::teaser_format( $block['format'] ?? 'landscape' ) ); ?><?php echo $has_copy ? '' : ' faluss-link-card__media-teaser--image-only'; ?><?php echo empty( $access['visible'] ) ? ' faluss-link-card__media-teaser--locked' : ''; ?>"><?php echo self::teaser_image_markup( (int) $block['attachment_id'], empty( $access['visible'] ) ? '' : $block['title'] ); ?><?php if ( ! empty( $access['visible'] ) && $has_copy ) : ?><div class="faluss-link-card__media-teaser-copy"><?php if ( '' !== $block['title'] ) : ?><h3><?php echo esc_html( $block['title'] ); ?></h3><?php endif; ?><?php if ( '' !== $block['text'] ) : ?><p><?php echo esc_html( $block['text'] ); ?></p><?php endif; ?></div><?php endif; ?><?php if ( empty( $access['visible'] ) ) : ?><div class="faluss-link-card__media-teaser-lock"><p><?php esc_html_e( 'Contenu réservé', 'faluss-link' ); ?></p><?php if ( ! empty( $access['login_required'] ) ) : ?><a class="faluss-link-card__media-teaser-unlock" href="<?php echo esc_url( self::teaser_login_url() ); ?>"><?php esc_html_e( 'Créer mon Faluss pour débloquer', 'faluss-link' ); ?></a><?php endif; ?></div><?php endif; ?></section><?php endif;
        return (string) ob_get_clean();
    }

    private static function teaser_image_markup( $attachment_id, $alt = '' ) {
        return $attachment_id && wp_attachment_is_image( $attachment_id ) ? wp_get_attachment_image( $attachment_id, 'large', false, array( 'alt' => $alt, 'loading' => 'lazy' ) ) : '';
    }

    private static function published_profile( $slug ) {
        if ( '' === (string) $slug ) { return null; }
        return \Faluss\Platform\Link\LinkIdentityAdapter::publishedProfileBySlug( sanitize_title( $slug ) );
    }

    /**
     * Hydrates the stored member preferences. The optional raw mode exists only
     * for a same-owner save: public rendering always revalidates entitlement.
     */
    private static function prefs( $faluss_id, $resolve_entitlement = true ) {
        global $wpdb;
        $defaults = array( 'faluss_id' => '', 'cover_attachment_id' => 0, 'avatar_visible' => 1, 'avatar_border' => 1, 'name_font' => 'outfit', 'name_weight' => 'bold', 'name_treatment' => 'strong', 'available' => 0, 'bio_mode' => 'editorial', 'announcement' => '', 'announcement_variant' => 'accent', 'social_links' => '[]', 'social_selected' => array(), 'social_layout' => 'bubbles', 'link_style' => 'solid', 'alignment' => 'center', 'page_background' => '#FFFDF5', 'button_color' => '#080808', 'hero_transition_color' => '#FFFDF5', 'hero_transition_intensity' => 82, 'hero_transition_position' => 72, 'name_color' => '#000000', 'social_variant' => 'outline', 'theme_reference' => 'faluss-default', 'selected_theme' => 'faluss-default', 'theme_overrides' => array() );
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Faluss_Link_Schema::table() . ' WHERE faluss_id=%s', $faluss_id ), ARRAY_A );
        $preferences = is_array( $row ) ? array_merge( $defaults, $row ) : $defaults;
        $preferences['_has_row'] = is_array( $row );
        $preferences['faluss_id'] = (string) $faluss_id;
        $payload = json_decode( $preferences['social_links'], true );
        $legacy = is_array( $payload ) && ! array_key_exists( 'selected_theme', $payload );
        if ( is_array( $payload ) ) {
            $preferences['avatar_border'] = array_key_exists( 'avatar_border', $payload ) ? ( ! empty( $payload['avatar_border'] ) ? 1 : 0 ) : (int) $preferences['avatar_border'];
            $preferences['name_font'] = self::onboarding_name_font( $payload['name_font'] ?? $preferences['name_font'] );
            $preferences['social_selected'] = self::onboarding_network_selection( $payload['social_selected'] ?? self::socials( $payload['networks'] ?? array() ) );
            $preferences['alignment'] = self::align( $payload['alignment'] ?? $preferences['alignment'] );
            $preferences['page_background'] = self::valid_hex( $payload['page_background'] ?? '' ) ?: $preferences['page_background'];
            $preferences['button_color'] = self::valid_hex( $payload['button_color'] ?? '' ) ?: $preferences['button_color'];
            $preferences['hero_transition_color'] = self::valid_hex( $payload['hero_transition_color'] ?? '' ) ?: $preferences['hero_transition_color'];
            $preferences['hero_transition_intensity'] = min( 100, max( 0, (int) ( $payload['hero_transition_intensity'] ?? $preferences['hero_transition_intensity'] ) ) );
            $preferences['hero_transition_position'] = min( 100, max( 35, (int) ( $payload['hero_transition_position'] ?? $preferences['hero_transition_position'] ) ) );
            $preferences['name_color'] = self::name_color( $payload['name_color'] ?? $preferences['name_color'] );
            $preferences['social_variant'] = self::social_variant( $payload['social_variant'] ?? $payload['social_appearance'] ?? $preferences['social_variant'] );
            $preferences['theme_reference'] = self::theme_reference( $payload['selected_theme'] ?? self::system_card_theme()['slug'] );
            $preferences['theme_overrides'] = $legacy ? self::theme_setting_keys() : self::theme_overrides( $payload['theme_overrides'] ?? array() );
        }
        return $resolve_entitlement ? self::resolve_card_presentation( $preferences, true ) : $preferences;
    }

    private static function name_color( $value ) {
        $color = self::valid_hex( $value );
        return isset( self::NAME_COLORS[ $color ] ) ? $color : '#000000';
    }

    private static function social_variant( $value ) { return 'full' === $value ? 'full' : 'outline'; }

    /** Compatibility adapter: the public name still reads the single presentation resolver. */
    private static function resolve_card_styles( $preferences ) {
        $presentation = self::resolve_card_presentation( $preferences, true );
        return array( 'name_color' => $presentation['name_color'] );
    }

    /** The Link-owned fallback keeps cards working while the optional catalogue is absent. */
    private static function system_card_theme() {
        return array( 'name' => 'Faluss par défaut', 'slug' => 'faluss-default', 'active' => 1, 'preview_attachment_id' => 0, 'scope' => 'faluss-link', 'page_background' => '#FFFDF5', 'hero_transition_color' => '#FFFDF5', 'name_color' => '#000000', 'alignment' => 'center', 'social_variant' => 'outline', 'link_style' => 'solid', 'entitlement_code' => '', 'system' => true );
    }

    private static function theme_setting_keys() { return array( 'page_background', 'hero_transition_color', 'name_color', 'alignment', 'social_variant', 'link_style' ); }

    private static function theme_reference( $value ) {
        $slug = is_string( $value ) ? ( function_exists( 'sanitize_title' ) ? sanitize_title( $value ) : strtolower( preg_replace( '/[^a-z0-9-]/', '', $value ) ) ) : '';
        return '' === $slug ? self::system_card_theme()['slug'] : $slug;
    }

    private static function selected_theme( $value ) {
        $reference = self::theme_reference( $value );
        return self::catalog_theme( $reference ) ? $reference : self::system_card_theme()['slug'];
    }

    private static function theme_overrides( $value ) {
        $raw = is_string( $value ) ? json_decode( $value, true ) : $value;
        $overrides = array();
        foreach ( (array) $raw as $key ) {
            if ( is_string( $key ) && in_array( $key, self::theme_setting_keys(), true ) && ! in_array( $key, $overrides, true ) ) {
                $overrides[] = $key;
            }
        }
        return $overrides;
    }

    /**
     * Single effective presentation resolver for storage hydration, Studio and
     * public cards. Elementor's explicit CSS stays a final local CSS layer.
     */
    private static function resolve_card_presentation( $preferences, $enforce_entitlement = true ) {
        $reference = self::theme_reference( $preferences['theme_reference'] ?? $preferences['selected_theme'] ?? '' );
        $catalog_theme = self::catalog_theme( $reference );
        $theme = $catalog_theme ?: self::system_card_theme();
        /* Existing inactive/deleted presets retain FL-15's durable default fallback. */
        if ( ! $catalog_theme ) {
            $reference = self::system_card_theme()['slug'];
        }
        $locked = $enforce_entitlement && ! self::theme_available_to_subject( $theme, $preferences['faluss_id'] ?? '' );
        if ( $locked ) {
            $theme = self::system_card_theme();
        }
        $effective_theme = self::theme_reference( $theme['slug'] ?? self::system_card_theme()['slug'] );
        /* A right can disappear without rewriting the card row. The system base
         * replaces the unavailable theme, while the already explicit member
         * overrides stay above it. */
        $stored_overrides = self::theme_overrides( $preferences['theme_overrides'] ?? array() );
        $overrides = $stored_overrides;
        $member_values = $preferences;
        $preferences['theme_reference'] = $reference;
        $preferences['selected_theme'] = $effective_theme;
        $preferences['theme_locked'] = $locked ? 1 : 0;
        /* Keep the persisted override list intact: merely opening or saving a
         * Studio while a right is absent must not convert a temporary visual
         * fallback into a permanent override of the previously chosen theme. */
        $preferences['theme_overrides'] = $stored_overrides;
        foreach ( self::theme_setting_keys() as $key ) {
            if ( ! in_array( $key, $overrides, true ) && isset( $theme[ $key ] ) ) {
                $preferences[ $key ] = $theme[ $key ];
            } elseif ( array_key_exists( $key, $member_values ) ) {
                $preferences[ $key ] = $member_values[ $key ];
            }
        }
        $preferences['alignment'] = self::align( $preferences['alignment'] );
        $preferences['name_color'] = self::name_color( $preferences['name_color'] );
        $preferences['social_variant'] = self::social_variant( $preferences['social_variant'] );
        $preferences['link_style'] = self::theme_link_style( $preferences['link_style'] );
        $preferences['page_background'] = self::valid_hex( $preferences['page_background'] ) ?: '#FFFDF5';
        $preferences['button_color'] = self::valid_hex( $preferences['button_color'] ?? '' ) ?: '#080808';
        $preferences['hero_transition_color'] = self::valid_hex( $preferences['hero_transition_color'] ) ?: $preferences['page_background'];
        return $preferences;
    }

    private static function theme_link_style( $value ) {
        if ( 'dark' === $value ) { return 'solid'; }
        return in_array( $value, array_keys( self::LINK_STYLES ), true ) ? $value : 'solid';
    }

    private static function catalog_theme( $slug ) {
        $slug = self::theme_reference( $slug );
        $theme = \Faluss\Platform\Link\LinkCatalogAdapter::activeTheme( $slug );
        if ( is_array( $theme ) ) {
            return self::normalise_catalog_theme( $theme );
        }
        return 'faluss-default' === $slug ? self::system_card_theme() : false;
    }

    private static function catalog_themes( $active_only = true ) {
        $themes = \Faluss\Platform\Link\LinkCatalogAdapter::themes( (bool) $active_only );
        if ( ! $themes ) { $themes = array( 'faluss-default' => self::system_card_theme() ); }
        $out = array();
        foreach ( (array) $themes as $theme ) {
            $normalised = self::normalise_catalog_theme( $theme );
            if ( $normalised ) { $out[ $normalised['slug'] ] = $normalised; }
        }
        return $out ?: array( 'faluss-default' => self::system_card_theme() );
    }

    private static function normalise_catalog_theme( $theme ) {
        if ( ! is_array( $theme ) || 'faluss-link' !== ( $theme['scope'] ?? '' ) ) { return false; }
        $slug = self::theme_reference( $theme['slug'] ?? '' );
        if ( 'faluss-default' !== $slug && ! preg_match( '/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug ) ) { return false; }
        return array( 'name' => sanitize_text_field( $theme['name'] ?? '' ) ?: 'Faluss par défaut', 'slug' => $slug, 'active' => empty( $theme['active'] ) ? 0 : 1, 'preview_attachment_id' => absint( $theme['preview_attachment_id'] ?? 0 ), 'scope' => 'faluss-link', 'page_background' => self::valid_hex( $theme['page_background'] ?? '' ) ?: '#FFFDF5', 'hero_transition_color' => self::valid_hex( $theme['hero_transition_color'] ?? '' ) ?: '#FFFDF5', 'name_color' => self::name_color( $theme['name_color'] ?? '#000000' ), 'alignment' => self::align( $theme['alignment'] ?? 'center' ), 'social_variant' => self::social_variant( $theme['social_variant'] ?? 'outline' ), 'link_style' => self::theme_link_style( $theme['link_style'] ?? 'solid' ), 'entitlement_code' => self::entitlement_code( $theme['entitlement_code'] ?? '' ), 'system' => ! empty( $theme['system'] ) );
    }

    /** Studio receives presentation data and an opaque locked state, never a right code. */
    private static function catalog_themes_for_client( $faluss_id = '' ) {
        $themes = array();
        foreach ( self::catalog_themes( true ) as $theme ) {
            $preview = (int) $theme['preview_attachment_id'];
            $client_theme = $theme;
            $client_theme['locked'] = ! self::theme_available_to_subject( $theme, $faluss_id );
            unset( $client_theme['entitlement_code'] );
            $themes[] = $client_theme + array( 'preview_url' => $preview && wp_attachment_is_image( $preview ) ? (string) wp_get_attachment_image_url( $preview, 'medium' ) : '' );
        }
        return $themes;
    }

    /** A required entitlement is always checked centrally and failures close to the system theme. */
    private static function theme_available_to_subject( $theme, $faluss_id ) {
        if ( ! is_array( $theme ) ) {
            return false;
        }
        if ( ! empty( $theme['system'] ) || '' === self::entitlement_code( $theme['entitlement_code'] ?? '' ) ) {
            return true;
        }
        if ( '' === (string) $faluss_id || ! \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::available() ) {
            return false;
        }
        return \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::subjectHasEntitlement( $faluss_id, self::entitlement_code( $theme['entitlement_code'] ) );
    }

    private static function entitlement_code( $value ) {
        $code = is_string( $value ) ? strtolower( trim( $value ) ) : '';
        return 1 === preg_match( '/^[a-z][a-z0-9_.-]{1,118}$/', $code ) ? $code : '';
    }

    /**
     * The Connector is the read-only registry authority. This UI cache lasts
     * only for the current request: Faluss Link never persists a right list.
     *
     * @return array<int,array{code:string,label:string}>
     */
    private static function teaser_entitlement_choices() {
        if ( null !== self::$teaser_entitlement_choices ) {
            return self::$teaser_entitlement_choices;
        }
        self::$teaser_entitlement_choices = array();
        $definitions = \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::entitlementDefinitions();
        if ( ! is_array( $definitions ) ) {
            return self::$teaser_entitlement_choices;
        }
        $seen = array();
        foreach ( $definitions as $definition ) {
            $code = self::entitlement_code( $definition['code'] ?? '' );
            $label = is_string( $definition['label'] ?? null ) ? sanitize_text_field( $definition['label'] ) : '';
            if ( '' === $code || '' === $label || isset( $seen[ $code ] ) ) {
                continue;
            }
            $seen[ $code ] = true;
            self::$teaser_entitlement_choices[] = array( 'code' => $code, 'label' => $label );
        }
        return self::$teaser_entitlement_choices;
    }

    /**
     * Server-only visual-access decision. A media URL is not an entitlement;
     * this deliberately remains a presentation layer until a content service
     * owns protected delivery.
     *
     * @return array{visible:bool,login_required:bool}
     */
    private static function teaser_access_decision( $block, $owner_faluss_id, $preview = false ) {
        $mode = self::teaser_access_mode( $block['access_mode'] ?? 'public' );
        if ( 'public' === $mode ) {
            return array( 'visible' => true, 'login_required' => false );
        }
        $viewer_faluss_id = self::current_faluss_id();
        if ( $preview || ( '' !== $viewer_faluss_id && hash_equals( (string) $owner_faluss_id, $viewer_faluss_id ) ) ) {
            return array( 'visible' => true, 'login_required' => false );
        }
        if ( '' === $viewer_faluss_id ) {
            return array( 'visible' => false, 'login_required' => true );
        }
        if ( 'member' === $mode ) {
            return array( 'visible' => true, 'login_required' => false );
        }
        $code = self::entitlement_code( $block['entitlement_code'] ?? '' );
        if ( '' === $code || ! \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::available() ) {
            return array( 'visible' => false, 'login_required' => false );
        }
        return array( 'visible' => \Faluss\Platform\Link\LinkTokenEngineConnectorAdapter::subjectHasEntitlement( $viewer_faluss_id, $code ), 'login_required' => false );
    }

    /** Replace only exact stored references to a preset that just became unavailable. */
    public static function migrate_deactivated_theme_references( $slug ) {
        global $wpdb;
        $slug = self::theme_reference( $slug );
        if ( self::system_card_theme()['slug'] === $slug || ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
            return false;
        }
        $table = Faluss_Link_Schema::table();
        if ( '' === $table || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'update' ) ) {
            return false;
        }
        $candidate = '%' . $slug . '%';
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT faluss_id,social_links FROM ' . $table . ' WHERE social_links LIKE %s', $candidate ), ARRAY_A );
        $migrated = true;
        foreach ( (array) $rows as $row ) {
            $payload = json_decode( $row['social_links'] ?? '', true );
            if ( ! is_array( $payload ) || $slug !== self::theme_reference( $payload['selected_theme'] ?? '' ) ) {
                continue;
            }
            $payload['selected_theme'] = self::system_card_theme()['slug'];
            $payload['theme_overrides'] = self::theme_overrides( $payload['theme_overrides'] ?? array() );
            if ( false === $wpdb->update( $table, array( 'social_links' => wp_json_encode( $payload ), 'updated_at' => gmdate( 'Y-m-d H:i:s' ) ), array( 'faluss_id' => $row['faluss_id'] ) ) ) {
                $migrated = false;
            }
        }
        return $migrated;
    }

    /** Catch an inactive catalogue record from an earlier request without scanning unrelated data. */
    public static function migrate_inactive_catalog_theme_references() {
        if ( ! \Faluss\Platform\Link\LinkCatalogAdapter::available() ) {
            return false;
        }
        $migrated = true;
        foreach ( \Faluss\Platform\Link\LinkCatalogAdapter::themes( false ) as $theme ) {
            if ( is_array( $theme ) && empty( $theme['system'] ) && empty( $theme['active'] ) ) {
                $migrated = self::migrate_deactivated_theme_references( $theme['slug'] ?? '' ) && $migrated;
            }
        }
        return $migrated;
    }

    private static function valid_hex( $value ) {
        $color = is_string( $value ) ? strtoupper( (string) sanitize_hex_color( $value ) ) : '';
        return preg_match( '/^#[0-9A-F]{6}$/', $color ) ? $color : '';
    }

    /* Studio and public cards always consume this same normalized source. */
    private static function content_blocks( $faluss_id, $legacy_links, $migrate = false ) {
        $stored = self::stored_blocks( $faluss_id );
        if ( $stored['has_rows'] ) { return $stored['blocks']; }
        $legacy = self::legacy_blocks( $faluss_id, $legacy_links );
        if ( $migrate && $legacy && self::migrate_legacy_links( $faluss_id, $legacy ) ) { $stored = self::stored_blocks( $faluss_id ); return $stored['blocks'] ?: $legacy; }
        return $legacy;
    }

    private static function stored_blocks( $faluss_id ) {
        global $wpdb; $table = Faluss_Link_Schema::blocks_table();
        if ( '' === $table ) { return array( 'blocks' => array(), 'has_rows' => false ); }
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT block_id,block_type,payload FROM ' . $table . ' WHERE faluss_id=%s ORDER BY sort_order ASC,id ASC', $faluss_id ), ARRAY_A );
        $blocks = array(); $has_rows = false; $seen = array();
        foreach ( (array) $rows as $row ) { $has_rows = true; $block = self::hydrate_stored_block( $row ); if ( $block && ! isset( $seen[ $block['block_id'] ] ) ) { $seen[ $block['block_id'] ] = true; $blocks[] = $block; } }
        return array( 'blocks' => $blocks, 'has_rows' => $has_rows );
    }

    private static function migrate_legacy_links( $faluss_id, $legacy ) {
        $stored = self::stored_blocks( $faluss_id );
        return $stored['blocks'] ? true : self::save_blocks( $faluss_id, $legacy );
    }

    private static function legacy_blocks( $faluss_id, $links ) {
        $raw = array();
        foreach ( array_values( is_array( $links ) ? $links : array() ) as $index => $link ) { $raw[] = array( 'block_id' => self::legacy_block_id( $faluss_id, $index, $link ), 'type' => 'link', 'label' => $link['label'] ?? '', 'url' => $link['url'] ?? '' ); }
        return self::normalise_blocks( $raw );
    }

    private static function legacy_block_id( $faluss_id, $index, $link ) {
        $hash = hash( 'sha256', $faluss_id . '|' . (int) $index . '|' . (string) ( $link['label'] ?? '' ) . '|' . (string) ( $link['url'] ?? '' ) );
        return substr( $hash, 0, 8 ) . '-' . substr( $hash, 8, 4 ) . '-' . substr( $hash, 12, 4 ) . '-' . substr( $hash, 16, 4 ) . '-' . substr( $hash, 20, 12 );
    }

    private static function normalise_blocks( $raw, $require_owned_media = true ) {
        $blocks = array(); $seen = array();
        foreach ( array_slice( is_array( $raw ) ? $raw : array(), 0, 32 ) as $block ) { $clean = self::normalise_block( $block, false, $require_owned_media ); if ( $clean && ! isset( $seen[ $clean['block_id'] ] ) ) { $seen[ $clean['block_id'] ] = true; $blocks[] = $clean; } }
        return $blocks;
    }

    private static function hydrate_stored_block( $row ) {
        if ( ! is_array( $row ) || ! self::valid_block_id( $row['block_id'] ?? '' ) ) { return null; }
        $payload = json_decode( $row['payload'] ?? '', true );
        return is_array( $payload ) ? self::normalise_block( array_merge( $payload, array( 'block_id' => $row['block_id'], 'type' => $row['block_type'] ?? '' ) ), true, false ) : null;
    }

    private static function normalise_block( $block, $stored = false, $require_owned_media = true ) {
        if ( ! is_array( $block ) ) { return null; }
        $type = sanitize_key( (string) ( $block['type'] ?? '' ) ); $block_id = self::block_id( $block['block_id'] ?? '', $stored );
        if ( '' === $block_id ) { return null; }
        if ( 'section_title' === $type ) { $value = self::block_text( $block['value'] ?? '', 80, false ); return '' === $value ? null : array( 'block_id' => $block_id, 'type' => $type, 'value' => $value ); }
        if ( 'text' === $type ) { $value = self::block_text( $block['value'] ?? '', 480, true ); return '' === $value ? null : array( 'block_id' => $block_id, 'type' => $type, 'value' => $value ); }
        if ( 'link' === $type ) { $label = self::block_text( $block['label'] ?? '', 80, false ); $url = self::block_url( $block['url'] ?? '' ); return '' === $label || '' === $url ? null : array( 'block_id' => $block_id, 'type' => $type, 'label' => $label, 'url' => $url ); }
        if ( 'media_teaser' === $type ) { $attachment_id = self::media_attachment_id( $block['attachment_id'] ?? 0, $require_owned_media ); if ( ! $attachment_id ) { return null; } $access_mode = self::teaser_access_mode( $block['access_mode'] ?? 'public' ); $entitlement_code = 'entitlement' === $access_mode ? self::entitlement_code( $block['entitlement_code'] ?? '' ) : ''; return array( 'block_id' => $block_id, 'type' => $type, 'attachment_id' => $attachment_id, 'title' => self::block_text( $block['title'] ?? '', 80, false ), 'text' => self::block_text( $block['text'] ?? '', 240, true ), 'format' => self::teaser_format( $block['format'] ?? 'landscape' ), 'access_mode' => $access_mode, 'entitlement_code' => $entitlement_code ); }
        return null;
    }

    private static function valid_block_id( $value ) { return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower( (string) $value ) ); }
    private static function teaser_format( $value ) { return is_string( $value ) && isset( self::TEASER_FORMATS[ $value ] ) ? $value : 'landscape'; }
    private static function teaser_access_mode( $value ) { return is_string( $value ) && isset( self::TEASER_ACCESS_MODES[ $value ] ) ? $value : 'public'; }
    private static function block_id( $value, $strict = false ) { $value = strtolower( (string) $value ); return self::valid_block_id( $value ) ? $value : ( $strict ? '' : wp_generate_uuid4() ); }
    private static function block_text( $value, $length, $multiline ) { if ( ! is_string( $value ) ) { return ''; } $value = trim( $multiline ? sanitize_textarea_field( wp_unslash( $value ) ) : sanitize_text_field( wp_unslash( $value ) ) ); return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $length ) : substr( $value, 0, $length ); }
    private static function block_url( $value ) { $url = is_string( $value ) ? esc_url_raw( trim( wp_unslash( $value ) ), array( 'https' ) ) : ''; $parts = wp_parse_url( $url ); return '' !== $url && is_array( $parts ) && 'https' === strtolower( $parts['scheme'] ?? '' ) && ! empty( $parts['host'] ) && ! isset( $parts['user'], $parts['pass'] ) ? $url : ''; }
    private static function media_attachment_id( $value, $require_owned ) { $attachment_id = absint( $value ); if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) { return 0; } return ! $require_owned || self::owned_image( $attachment_id, get_current_user_id() ) ? $attachment_id : 0; }

    private static function identity_links( $blocks ) {
        $links = array(); foreach ( $blocks as $block ) { if ( 'link' === $block['type'] && count( $links ) < 8 ) { $links[] = array( 'label' => $block['label'], 'url' => $block['url'], 'position' => count( $links ) + 1 ); } } return $links;
    }

    private static function save_blocks( $faluss_id, $blocks ) {
        global $wpdb; $table = Faluss_Link_Schema::blocks_table(); $blocks = self::normalise_blocks( $blocks );
        if ( '' === $table || false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        try {
            $existing = self::lock_studio_blocks_in_transaction( $faluss_id );
            if ( false === $existing ) { $wpdb->query( 'ROLLBACK' ); return false; }
            /* Legacy import is append-only. An existing stream is canonical and
             * can never be replaced merely because a browser omitted blocks. */
            if ( $existing ) { $wpdb->query( 'COMMIT' ); return true; }
            $now = current_time( 'mysql', true );
            foreach ( $blocks as $order => $block ) { $payload = $block; unset( $payload['block_id'], $payload['type'] ); if ( false === $wpdb->insert( $table, array( 'faluss_id' => $faluss_id, 'block_id' => $block['block_id'], 'sort_order' => $order + 1, 'block_type' => $block['type'], 'payload' => wp_json_encode( $payload ), 'created_at' => $now, 'updated_at' => $now ), array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; } }
            if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
        } catch ( Exception $exception ) { $wpdb->query( 'ROLLBACK' ); return false; }
        return true;
    }

    /** Record only a server-resolved public profile for the active local member. */
    private static function record_discovery_for_current_visitor( $discovered_faluss_id ) {
        $viewer = self::current_faluss_id();
        if ( ! self::valid_faluss_id( $viewer ) || ! self::valid_faluss_id( $discovered_faluss_id ) || hash_equals( $viewer, (string) $discovered_faluss_id ) || ! self::discovery_recording_enabled( $viewer ) ) {
            return false;
        }
        global $wpdb;
        $table = Faluss_Link_Schema::discoveries_table();
        if ( '' === $table || false === $wpdb->query( 'START TRANSACTION' ) ) { return false; }
        $now = current_time( 'mysql', true );
        try {
            $saved = $wpdb->query( $wpdb->prepare(
                'INSERT INTO ' . $table . ' (viewer_faluss_id,discovered_faluss_id,first_seen_at,last_seen_at,view_count) VALUES (%s,%s,%s,%s,%d) ON DUPLICATE KEY UPDATE last_seen_at=VALUES(last_seen_at),view_count=view_count+1',
                $viewer, $discovered_faluss_id, $now, $now, 1
            ) );
            if ( false === $saved || false === $wpdb->query( $wpdb->prepare(
                'DELETE FROM ' . $table . ' WHERE viewer_faluss_id=%s AND id NOT IN (SELECT retained.id FROM (SELECT id FROM ' . $table . ' WHERE viewer_faluss_id=%s ORDER BY last_seen_at DESC,id DESC LIMIT 250) AS retained)',
                $viewer, $viewer
            ) ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            if ( false === $wpdb->query( 'COMMIT' ) ) { $wpdb->query( 'ROLLBACK' ); return false; }
            return true;
        } catch ( Throwable $exception ) { $wpdb->query( 'ROLLBACK' ); return false; }
    }

    /** No setting row means enabled: discovery is active by default. */
    private static function discovery_recording_enabled( $viewer ) {
        global $wpdb;
        $table = Faluss_Link_Schema::discovery_settings_table();
        if ( '' === $table || ! self::valid_faluss_id( $viewer ) ) { return false; }
        $enabled = $wpdb->get_var( $wpdb->prepare( 'SELECT recording_enabled FROM ' . $table . ' WHERE viewer_faluss_id=%s', $viewer ) );
        return null === $enabled ? true : 1 === (int) $enabled;
    }

    private static function set_discovery_recording_enabled( $viewer, $enabled ) {
        global $wpdb;
        $table = Faluss_Link_Schema::discovery_settings_table();
        if ( '' === $table || ! self::valid_faluss_id( $viewer ) ) { return false; }
        return false !== $wpdb->query( $wpdb->prepare(
            'INSERT INTO ' . $table . ' (viewer_faluss_id,recording_enabled,updated_at) VALUES (%s,%d,%s) ON DUPLICATE KEY UPDATE recording_enabled=VALUES(recording_enabled),updated_at=VALUES(updated_at)',
            $viewer, $enabled ? 1 : 0, current_time( 'mysql', true )
        ) );
    }

    /** Identity publishes only the fields required by this private list. */
    private static function discoveries_for_viewer( $viewer, $limit, $offset = 0 ) {
        global $wpdb;
        $discoveries = Faluss_Link_Schema::discoveries_table();
        if ( '' === $discoveries || ! self::valid_faluss_id( $viewer ) ) { return array(); }
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT id,discovered_faluss_id,last_seen_at FROM ' . $discoveries . ' WHERE viewer_faluss_id=%s ORDER BY last_seen_at DESC,id DESC LIMIT 250',
            $viewer
        ), ARRAY_A );
        $ids = array();
        foreach ( (array) $rows as $row ) {
            if ( is_array( $row ) && self::valid_faluss_id( $row['discovered_faluss_id'] ?? '' ) ) { $ids[] = (string) $row['discovered_faluss_id']; }
        }
        $profiles = \Faluss\Platform\Link\LinkIdentityAdapter::publishedProfilesByFalussIds( $ids );
        $out = array();
        foreach ( (array) $rows as $row ) {
            $faluss_id = is_array( $row ) ? strtolower( (string) ( $row['discovered_faluss_id'] ?? '' ) ) : '';
            $profile = $profiles[ $faluss_id ] ?? null;
            if ( ! is_array( $row ) || ! is_array( $profile ) || ! absint( $row['id'] ?? 0 ) || '' === sanitize_title( $profile['public_slug'] ?? '' ) ) { continue; }
            $out[] = array(
                'id' => absint( $row['id'] ),
                'last_seen_at' => (string) $row['last_seen_at'],
                'public_slug' => sanitize_title( $profile['public_slug'] ),
                'display_name' => sanitize_text_field( $profile['display_name'] ?? '' ),
                'avatar_attachment_id' => absint( $profile['avatar_attachment_id'] ?? 0 ),
            );
        }
        return array_slice( $out, max( 0, absint( $offset ) ), min( 250, max( 1, absint( $limit ) ) ) );
    }

    private static function discoveries_count_for_viewer( $viewer ) {
        global $wpdb;
        $discoveries = Faluss_Link_Schema::discoveries_table();
        if ( '' === $discoveries || ! self::valid_faluss_id( $viewer ) ) { return 0; }
        $ids = $wpdb->get_col( $wpdb->prepare( 'SELECT discovered_faluss_id FROM ' . $discoveries . ' WHERE viewer_faluss_id=%s ORDER BY last_seen_at DESC,id DESC LIMIT 250', $viewer ) );
        return count( \Faluss\Platform\Link\LinkIdentityAdapter::publishedProfilesByFalussIds( array_values( array_filter( (array) $ids, array( __CLASS__, 'valid_faluss_id' ) ) ) ) );
    }

    private static function discoveries_page_url( $page ) {
        $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $parts = is_string( $request ) ? wp_parse_url( $request ) : array();
        $path = is_array( $parts ) && isset( $parts['path'] ) ? $parts['path'] : '/';
        $query = array();
        if ( is_array( $parts ) && ! empty( $parts['query'] ) ) { parse_str( $parts['query'], $query ); }
        $query['faluss_discoveries_page'] = max( 1, absint( $page ) );
        return add_query_arg( $query, home_url( $path ) );
    }

    private static function delete_discovery_for_viewer( $viewer, $id ) {
        global $wpdb;
        return 0 < (int) $wpdb->delete( Faluss_Link_Schema::discoveries_table(), array( 'id' => absint( $id ), 'viewer_faluss_id' => $viewer ), array( '%d', '%s' ) );
    }

    private static function clear_discoveries_for_viewer( $viewer ) {
        global $wpdb;
        return false !== $wpdb->delete( Faluss_Link_Schema::discoveries_table(), array( 'viewer_faluss_id' => $viewer ), array( '%s' ) );
    }

    private static function discovery_datetime( $value ) {
        $timestamp = strtotime( (string) $value . ' UTC' );
        return $timestamp ? gmdate( DATE_ATOM, $timestamp ) : '';
    }

    private static function discovery_last_seen_label( $value ) {
        $timestamp = strtotime( (string) $value . ' UTC' );
        if ( ! $timestamp ) { return __( 'Découverte récente', 'faluss-link' ); }
        $date = function_exists( 'wp_date' ) ? wp_date( get_option( 'date_format' ), $timestamp ) : gmdate( 'Y-m-d', $timestamp );
        return sprintf( __( 'Découvert le %s', 'faluss-link' ), $date );
    }

    private static function discoveries_assets() {
        if ( ! wp_style_is( self::DISCOVERIES_STYLE, 'registered' ) ) { self::assets(); }
        wp_enqueue_style( self::DISCOVERIES_STYLE );
    }

    private static function valid_faluss_id( $value ) {
        return 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string) $value );
    }

    private static function valid_prefs( $faluss_id ) { $preferences = self::prefs( $faluss_id ); if ( (int) $preferences['cover_attachment_id'] && ! self::owned_image( (int) $preferences['cover_attachment_id'], get_current_user_id() ) ) { $preferences['cover_attachment_id'] = 0; } return $preferences; }
    private static function current_faluss_id() { return \Faluss\Platform\Link\LinkIdentityAdapter::currentFalussId(); }
    private static function verify( $field, $action ) { return is_user_logged_in() && isset( $_POST[ $field ] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ $field ] ) ), $action ); }
    private static function studio_tab( $value ) { $value = sanitize_key( (string) wp_unslash( $value ) ); return in_array( $value, array( 'profile', 'links', 'style' ), true ) ? $value : 'profile'; }
    private static function redirect( $key, $notice, $studio_tab = '' ) { $url = wp_validate_redirect( wp_get_referer(), home_url( '/' ) ); $args = array( $key => $notice ); if ( '' !== $studio_tab ) { $args['faluss_studio_tab'] = self::studio_tab( $studio_tab ); } wp_safe_redirect( add_query_arg( $args, $url ) ); exit; }
    private static function notice( $key ) { $value = isset( $_GET[ $key ] ) ? sanitize_key( wp_unslash( $_GET[ $key ] ) ) : ''; $message = self::notice_message( $value ); return '' !== $message ? '<p class="faluss-link-notice">' . esc_html( $message ) . '</p>' : ''; }
    private static function notice_message( $value ) { $messages = array( 'saved' => __( 'Studio enregistré.', 'faluss-link' ), 'taken' => __( 'Cet identifiant public n’est pas disponible.', 'faluss-link' ), 'invalid' => __( 'Nous ne pouvons pas enregistrer le Studio.', 'faluss-link' ) ); return $messages[ $value ] ?? ''; }
    private static function discovery_redirect( $notice ) { $url = wp_validate_redirect( wp_get_referer(), home_url( '/' ) ); wp_safe_redirect( add_query_arg( 'faluss_link_discoveries_notice', sanitize_key( $notice ), $url ) ); exit; }
    private static function discovery_notice() { $value = isset( $_GET['faluss_link_discoveries_notice'] ) ? sanitize_key( wp_unslash( $_GET['faluss_link_discoveries_notice'] ) ) : ''; $messages = array( 'saved' => __( 'Préférences des découvertes enregistrées.', 'faluss-link' ), 'deleted' => __( 'Découverte retirée.', 'faluss-link' ), 'cleared' => __( 'Vos découvertes ont été effacées.', 'faluss-link' ), 'invalid' => __( 'Cette action n’a pas pu être effectuée.', 'faluss-link' ) ); return isset( $messages[ $value ] ) ? '<p class="faluss-link-notice">' . esc_html( $messages[ $value ] ) . '</p>' : ''; }
    private static function options( $options, $selected ) { foreach ( $options as $key => $label ) { ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $selected, $key ); ?>><?php echo esc_html( $label ); ?></option><?php } }
    private static function align( $value ) { return in_array( $value, array( 'left', 'center', 'right' ), true ) ? $value : 'center'; }

    private static function is_onboarding_surface_request() {
        if ( is_admin() || ! \Faluss\Platform\Link\LinkIdentityAdapter::onboardingAvailable() ) {
            return false;
        }
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) && is_string( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
        $request_path = wp_parse_url( $request_uri, PHP_URL_PATH );
        $onboarding_url = \Faluss\Platform\Link\LinkIdentityAdapter::onboardingUrl();
        $onboarding_path = wp_parse_url( $onboarding_url, PHP_URL_PATH );
        if ( ! is_string( $request_path ) || ! is_string( $onboarding_path ) || untrailingslashit( $request_path ) !== untrailingslashit( $onboarding_path ) ) {
            return false;
        }
        $required_query = array();
        $request_query = array();
        parse_str( (string) wp_parse_url( $onboarding_url, PHP_URL_QUERY ), $required_query );
        parse_str( (string) wp_parse_url( $request_uri, PHP_URL_QUERY ), $request_query );
        foreach ( $required_query as $key => $value ) {
            if ( ! array_key_exists( $key, $request_query ) || (string) $request_query[ $key ] !== (string) $value ) {
                return false;
            }
        }
        return true;
    }
    /** @param array<string,mixed> $state @param array<string,mixed> $attributes */
    private static function daily_reward_markup( $state, $attributes ) {
        $state_name = is_array( $state ) && in_array( $state['state'] ?? '', array( 'login', 'available', 'granted', 'already_claimed', 'rule_unavailable', 'permission_denied', 'subject_unavailable', 'configuration_invalid', 'transient_error' ), true ) ? $state['state'] : 'transient_error';
        if ( ! in_array( $state_name, array( 'login', 'available', 'granted', 'already_claimed' ), true ) && $attributes['hide_unavailable'] && empty( $state['visible'] ) ) { return ''; }
        $amount = max( 0, (int) ( $state['amount'] ?? 0 ) );
        $unit = sanitize_text_field( (string) ( $state['unit'] ?? '' ) );
        $classes = 'faluss-link-reward faluss-link-reward--' . $state_name . ' faluss-link-reward--align-' . $attributes['align'] . ' faluss-link-reward--presentation-' . $attributes['presentation'];
        ob_start();
        ?>
        <section class="<?php echo esc_attr( $classes ); ?>" data-faluss-link-reward data-faluss-reward-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" data-faluss-reward-nonce="<?php echo esc_attr( wp_create_nonce( 'faluss_link_daily_reward_claim' ) ); ?>" data-faluss-reward-claimed-label="<?php echo esc_attr( $attributes['claimed_label'] ); ?>" data-faluss-reward-unavailable-label="<?php echo esc_attr( $attributes['unavailable_label'] ); ?>" data-faluss-reward-loading-label="<?php echo esc_attr__( 'Réclamation en cours…', 'faluss-link' ); ?>" data-faluss-reward-error-label="<?php echo esc_attr__( 'La récompense est temporairement indisponible. Réessayez plus tard.', 'faluss-link' ); ?>" aria-live="polite">
            <div class="faluss-link-reward__status">
                <div class="faluss-link-reward__action">
                    <?php if ( 'login' === $state_name ) : ?><a class="faluss-link-reward__button" href="<?php echo esc_url( self::daily_reward_login_url() ); ?>"><?php echo esc_html( self::daily_reward_label( $attributes['login_label'], $amount, $unit, __( 'Réclamer mes récompenses', 'faluss-link' ) ) ); ?></a><?php endif; ?>
                    <?php if ( 'available' === $state_name ) : ?><button class="faluss-link-reward__button" type="button" data-faluss-reward-claim><?php echo esc_html( self::daily_reward_label( $attributes['claim_label'], $amount, $unit ) ); ?></button><?php endif; ?>
                </div>
                <?php if ( 'login' === $state_name && '' !== $attributes['login_microcopy'] ) : ?><p class="faluss-link-reward__microcopy"><?php echo esc_html( $attributes['login_microcopy'] ); ?></p><?php endif; ?>
                <p class="faluss-link-reward__feedback" role="status"<?php if ( in_array( $state_name, array( 'login', 'available' ), true ) ) : ?> hidden<?php endif; ?>><?php if ( 'granted' === $state_name ) { echo esc_html( __( 'Récompense obtenue.', 'faluss-link' ) ); echo self::daily_reward_next_markup( $state['next_available_at'] ?? '' ); } elseif ( 'already_claimed' === $state_name ) { echo esc_html( $attributes['claimed_label'] ); echo self::daily_reward_next_markup( $state['next_available_at'] ?? '' ); } else { echo esc_html( sanitize_text_field( (string) ( $state['message'] ?? self::daily_reward_public_message( $state_name, $attributes['unavailable_label'] ) ) ) ); } ?></p>
            </div>
            <?php if ( $attributes['show_balance'] && in_array( $state_name, array( 'available', 'granted', 'already_claimed' ), true ) ) : ?><p class="faluss-link-reward__balance"><?php echo esc_html( sprintf( __( 'Solde : %1$s %2$s', 'faluss-link' ), number_format_i18n( max( 0, (int) ( $state['balance'] ?? 0 ) ) ), $unit ) ); ?></p><?php endif; ?>
        </section>
        <?php
        return (string) ob_get_clean();
    }
    /** @return array<string,mixed> */
    private static function daily_reward_response_payload( $state ) {
        $states = array( 'available', 'granted', 'already_claimed', 'rule_unavailable', 'permission_denied', 'subject_unavailable', 'configuration_invalid', 'transient_error' );
        if ( ! is_array( $state ) || ! in_array( $state['state'] ?? '', $states, true ) ) { return array( 'state' => 'transient_error', 'message' => self::daily_reward_public_message( 'transient_error' ) ); }
        $payload = array( 'state' => $state['state'] );
        if ( in_array( $state['state'], array( 'available', 'granted', 'already_claimed' ), true ) ) {
            $payload['amount'] = max( 0, (int) ( $state['amount'] ?? 0 ) );
            $payload['unit'] = sanitize_text_field( (string) ( $state['unit'] ?? '' ) );
            $payload['balance'] = max( 0, (int) ( $state['balance'] ?? 0 ) );
            $payload['next_available_at'] = is_string( $state['next_available_at'] ?? null ) ? $state['next_available_at'] : '';
            $payload['claimed_now'] = ! empty( $state['claimed_now'] );
        } else {
            $payload['message'] = self::daily_reward_public_message( $state['state'] );
        }
        return $payload;
    }
    /** @return array<string,mixed> */
    private static function daily_reward_error_state( $error ) {
        $payload = self::daily_reward_error_payload( $error );
        return array( 'state' => $payload['state'], 'visible' => true, 'message' => $payload['message'] );
    }
    /** @return array<string,string> */
    private static function daily_reward_error_payload( $error ) {
        $code = is_wp_error( $error ) ? (string) $error->get_error_code() : '';
        if ( 'connector_permission_reward_claim_missing' === $code ) {
            return array( 'state' => 'permission_denied', 'message' => self::daily_reward_public_message( 'permission_denied' ) );
        }
        if ( 'connector_subject_unavailable' === $code ) {
            return array( 'state' => 'subject_unavailable', 'message' => self::daily_reward_public_message( 'subject_unavailable' ) );
        }
        if ( in_array( $code, array( 'connector_core_url_invalid', 'connector_client_invalid', 'connector_project_invalid', 'connector_secret_missing', 'connector_secret_required', 'connector_secret_unavailable', 'connector_unavailable', 'not_configured', 'schema_not_ready' ), true ) ) {
            return array( 'state' => 'configuration_invalid', 'message' => self::daily_reward_public_message( 'configuration_invalid' ) );
        }
        return array( 'state' => 'transient_error', 'message' => self::daily_reward_public_message( 'transient_error' ) );
    }
    private static function daily_reward_public_message( $state, $fallback = '' ) {
        $messages = array(
            'rule_unavailable' => __( 'La récompense quotidienne n’est pas disponible actuellement.', 'faluss-link' ),
            'permission_denied' => __( 'La réclamation n’est pas disponible sur cette carte.', 'faluss-link' ),
            'subject_unavailable' => __( 'Votre identité Faluss active est nécessaire pour réclamer cette récompense.', 'faluss-link' ),
            'configuration_invalid' => __( 'La récompense quotidienne n’est pas encore configurée.', 'faluss-link' ),
            'transient_error' => __( 'La récompense est temporairement indisponible. Réessayez plus tard.', 'faluss-link' ),
        );
        return $messages[ $state ] ?? ( '' !== $fallback ? $fallback : $messages['transient_error'] );
    }
    private static function daily_reward_label( $template, $amount, $unit, $fallback = '' ) {
        if ( $amount < 1 || '' === $unit ) { return '' === $fallback ? __( 'Réclamer la récompense', 'faluss-link' ) : $fallback; }
        $label = str_replace( array( '%1$s', '%2$s' ), array( number_format_i18n( $amount ), $unit ), $template );
        return '' === trim( $label ) ? sprintf( __( 'Réclamer %1$s %2$s', 'faluss-link' ), number_format_i18n( $amount ), $unit ) : $label;
    }
    private static function daily_reward_next_markup( $value ) {
        $timestamp = is_string( $value ) ? strtotime( $value ) : false;
        if ( ! $timestamp ) { return ''; }
        $label = function_exists( 'wp_date' ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) : gmdate( 'Y-m-d H:i', $timestamp );
        return ' <time datetime="' . esc_attr( gmdate( DATE_ATOM, $timestamp ) ) . '">' . esc_html( sprintf( __( 'Disponible à nouveau le %s.', 'faluss-link' ), $label ) ) . '</time>';
    }
    /** Login receives only a server-backed intent and one validated local profile path. */
    private static function local_card_login_url( $intent = 'generic_login' ) {
        $request_uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
        $parts = is_string( $request_uri ) ? wp_parse_url( $request_uri ) : array();
        $path = is_array( $parts ) ? (string) ( $parts['path'] ?? '/' ) : '/';
        if ( '' === $path || '/' !== substr( $path, 0, 1 ) || 0 === strpos( $path, '//' ) ) { $path = '/'; }
        $return = wp_validate_redirect( home_url( $path ), home_url( '/' ) );
        $identity_url = \Faluss\Platform\Link\LinkIdentityAdapter::loginUrl( $intent, $return );
        if ( '' !== $identity_url ) {
            return $identity_url;
        }
        return add_query_arg( 'redirect_to', $return, home_url( '/login/' ) );
    }
    private static function daily_reward_login_url() { return self::local_card_login_url( 'claim_reward' ); }
    private static function teaser_login_url() { return self::local_card_login_url( 'unlock_teaser' ); }
    private static function identity_ready() { return \Faluss\Platform\Link\LinkIdentityAdapter::ready(); }
    private static function enqueue_assets() { if ( ! wp_style_is( self::STYLE, 'registered' ) ) { self::assets(); } wp_enqueue_style( self::STYLE ); wp_enqueue_style( self::IMMERSIVE_STYLE ); wp_enqueue_style( self::STUDIO_STYLE ); wp_enqueue_script( self::CARD_SCRIPT ); }
    private static function editor_assets() { self::enqueue_assets(); wp_localize_script( self::SCRIPT, 'falussLinkCover', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'faluss_link_upload_cover' ), 'avatarNonce' => wp_create_nonce( 'faluss_link_upload_avatar' ), 'teaserNonce' => wp_create_nonce( 'faluss_link_upload_teaser' ), 'networks' => self::network_catalog_for_client(), 'themes' => self::catalog_themes_for_client( self::current_faluss_id() ), 'teaserRights' => self::teaser_entitlement_choices() ) ); wp_enqueue_script( self::SCRIPT ); }
    private static function reward_assets() { if ( ! wp_style_is( self::REWARD_STYLE, 'registered' ) ) { self::assets(); } wp_localize_script( self::REWARD_SCRIPT, 'falussLinkReward', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'faluss_link_daily_reward_claim' ) ) ); wp_enqueue_style( self::REWARD_STYLE ); wp_enqueue_script( self::REWARD_SCRIPT ); }
    private static function owned_image( $attachment_id, $user_id ) { $attachment = get_post( (int) $attachment_id ); return $attachment instanceof WP_Post && (int) $attachment->post_author === (int) $user_id && 0 === strpos( (string) $attachment->post_mime_type, 'image/' ); }
    private static function empty_card( $message ) { return '<div class="faluss-link-card faluss-link-card--empty" role="status">' . esc_html( $message ) . '</div>'; }
    private static function network_catalog() { return class_exists( 'Faluss_Link_Admin' ) ? Faluss_Link_Admin::catalog() : array_combine( self::NETWORKS, array_map( static function( $network ) { return array( 'label' => ucfirst( $network ), 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ); }, self::NETWORKS ) ); }
    private static function network_catalog_for_client() { $catalog = self::network_catalog(); foreach ( $catalog as $network => $settings ) { $catalog[ $network ]['outline'] = self::network_asset_data( $settings['outline_icon'] ?? 0 ); $catalog[ $network ]['full'] = self::network_asset_data( $settings['full_logo'] ?? 0 ); } return $catalog; }
    private static function active_network_catalog() { return class_exists( 'Faluss_Link_Admin' ) ? Faluss_Link_Admin::active_catalog() : self::network_catalog(); }
    private static function network_label( $network ) { $catalog = self::network_catalog(); return sanitize_text_field( $catalog[ $network ]['label'] ?? ucfirst( $network ) ); }
    /** @return array{src: string, srcset: string, sizes: string} */
    private static function network_asset_data( $attachment_id ) {
        $attachment_id = (int) $attachment_id;
        if ( ! $attachment_id || ! wp_attachment_is_image( $attachment_id ) ) {
            return array( 'src' => '', 'srcset' => '', 'sizes' => '' );
        }
        $src = wp_get_attachment_image_url( $attachment_id, 'full' );
        if ( ! is_string( $src ) || '' === $src ) {
            return array( 'src' => '', 'srcset' => '', 'sizes' => '' );
        }
        $srcset = function_exists( 'wp_get_attachment_image_srcset' ) ? (string) wp_get_attachment_image_srcset( $attachment_id, 'full' ) : '';
        $sizes = function_exists( 'wp_get_attachment_image_sizes' ) ? (string) wp_get_attachment_image_sizes( $attachment_id, 'full' ) : '';
        return array(
            'src' => self::versioned_attachment_url( $src, $attachment_id ),
            'srcset' => self::versioned_attachment_srcset( $srcset, $attachment_id ),
            'sizes' => $sizes,
        );
    }

    private static function versioned_attachment_url( $url, $attachment_id ) {
        if ( '' === (string) $url ) {
            return '';
        }
        $version = self::attachment_version( $attachment_id );
        if ( function_exists( 'add_query_arg' ) ) {
            return (string) add_query_arg( 'ver', $version, $url );
        }
        return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . 'ver=' . rawurlencode( $version );
    }

    private static function versioned_attachment_srcset( $srcset, $attachment_id ) {
        if ( '' === trim( (string) $srcset ) ) {
            return '';
        }
        $candidates = array();
        foreach ( explode( ',', $srcset ) as $candidate ) {
            $parts = preg_split( '/\s+/', trim( $candidate ), 2 );
            if ( empty( $parts[0] ) ) {
                continue;
            }
            $candidates[] = self::versioned_attachment_url( $parts[0], $attachment_id ) . ( isset( $parts[1] ) ? ' ' . $parts[1] : '' );
        }
        return implode( ', ', $candidates );
    }

    private static function attachment_version( $attachment_id ) {
        $modified = function_exists( 'get_post_modified_time' ) ? (int) get_post_modified_time( 'U', true, (int) $attachment_id ) : 0;
        return $modified > 0 ? (string) $modified : (string) (int) $attachment_id;
    }
    private static function social_markup( $links, $variant ) { $markup = ''; foreach ( self::socials( $links ) as $social ) { $asset = self::social_asset_markup( $social['network'], $variant ); if ( '' !== $asset ) { $markup .= '<a href="' . esc_url( $social['url'] ) . '" target="_blank" rel="noopener noreferrer nofollow" aria-label="' . esc_attr( self::network_label( $social['network'] ) ) . '" data-faluss-network="' . esc_attr( $social['network'] ) . '">' . $asset . '</a>'; } } return $markup; }
    private static function social_asset_markup( $network, $variant ) { $catalog = self::network_catalog(); $settings = $catalog[ $network ] ?? array(); $fields = 'full' === self::social_variant( $variant ) ? array( 'full_logo', 'outline_icon' ) : array( 'outline_icon', 'full_logo' ); foreach ( $fields as $field ) { $asset = self::network_asset_data( $settings[ $field ] ?? 0 ); if ( '' !== $asset['src'] ) { return self::social_image_markup( $asset, self::network_label( $network ) ); } } return ''; }
    /** @param array{src: string, srcset: string, sizes: string} $asset */
    private static function social_image_markup( $asset, $label ) { $attributes = ' class="faluss-link-card__network-asset" src="' . esc_url( $asset['src'] ) . '" alt="' . esc_attr( $label ) . '" loading="lazy"'; if ( '' !== $asset['srcset'] ) { $attributes .= ' srcset="' . esc_attr( $asset['srcset'] ) . '"'; } if ( '' !== $asset['sizes'] ) { $attributes .= ' sizes="' . esc_attr( $asset['sizes'] ) . '"'; } return '<img' . $attributes . '>'; }

    private static function is_public_profile_request( $request = null ) {
        $slug = '';
        if ( is_object( $request ) && isset( $request->query_vars ) && is_array( $request->query_vars ) ) {
            $slug = $request->query_vars['faluss_public_profile'] ?? '';
        } elseif ( function_exists( 'get_query_var' ) ) {
            $slug = get_query_var( 'faluss_public_profile' );
        }
        return '' !== sanitize_title( (string) $slug );
    }
}
