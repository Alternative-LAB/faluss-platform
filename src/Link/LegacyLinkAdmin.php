<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Faluss_Link_Admin {
    const OPTION = 'faluss_link_network_catalog';

    public static function defaults() {
        return array(
            'instagram' => array( 'label' => 'Instagram', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'tiktok' => array( 'label' => 'TikTok', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'telegram' => array( 'label' => 'Telegram', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'snapchat' => array( 'label' => 'Snapchat', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'threads' => array( 'label' => 'Threads', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'onlyfans' => array( 'label' => 'OnlyFans', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'youtube' => array( 'label' => 'YouTube', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'x' => array( 'label' => 'X', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'linkedin' => array( 'label' => 'LinkedIn', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
            'github' => array( 'label' => 'GitHub', 'active' => 1, 'outline_icon' => 0, 'full_logo' => 0 ),
        );
    }

    public static function catalog() {
        $saved = get_option( self::OPTION, array() );
        $saved = is_array( $saved ) ? $saved : array();
        $catalog = array();
        foreach ( self::defaults() as $slug => $default ) {
            $saved_row = (array) ( $saved[ $slug ] ?? array() );
            $row = array_merge( $default, $saved_row );
            $outline = array_key_exists( 'outline_icon', $saved_row ) ? $saved_row['outline_icon'] : ( $saved_row['icon'] ?? 0 );
            $catalog[ $slug ] = array(
                'label' => sanitize_text_field( $row['label'] ?? $default['label'] ) ?: $default['label'],
                'active' => empty( $row['active'] ) ? 0 : 1,
                'outline_icon' => self::image_id( $outline ),
                'full_logo' => self::image_id( $row['full_logo'] ?? 0 ),
            );
        }
        return $catalog;
    }

    public static function active_catalog() {
        return array_filter( self::catalog(), static function( $network ) { return ! empty( $network['active'] ); } );
    }

    public static function boot() {
        if ( ! is_admin() ) { return; }
        self::maybe_migrate_legacy_assets();
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_post_faluss_link_save_networks', array( __CLASS__, 'save' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
    }

    public static function menu() {
        add_options_page( 'Réseaux Faluss Link', 'Réseaux Faluss Link', 'manage_options', 'faluss-link-networks', array( __CLASS__, 'page' ) );
    }

    public static function assets( $hook ) {
        if ( 'settings_page_faluss-link-networks' !== $hook ) { return; }
        wp_enqueue_media();
        wp_add_inline_script( 'media-editor', 'jQuery(function($){$(document).on("click",".faluss-link-network-asset-picker",function(event){event.preventDefault();var button=$(this),field=button.closest(".faluss-link-network-asset").find("input"),frame=wp.media({title:button.data("faluss-title"),button:{text:"Utiliser cette image"},multiple:false,library:{type:"image"}});frame.on("select",function(){var item=frame.state().get("selection").first();field.val(item.get("id"));button.siblings(".faluss-link-network-asset__status").text("Ressource sélectionnée");});frame.open();});});' );
    }

    public static function save() {
        if ( ! current_user_can( 'manage_options' ) || ! isset( $_POST['faluss_link_networks_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['faluss_link_networks_nonce'] ) ), 'faluss_link_save_networks' ) ) { wp_die( 'Accès refusé.' ); }
        $out = array();
        foreach ( self::defaults() as $slug => $default ) {
            $row = isset( $_POST['networks'][ $slug ] ) ? (array) wp_unslash( $_POST['networks'][ $slug ] ) : array();
            $out[ $slug ] = array(
                'label' => sanitize_text_field( $row['label'] ?? $default['label'] ) ?: $default['label'],
                'active' => empty( $row['active'] ) ? 0 : 1,
                'outline_icon' => self::image_id( $row['outline_icon'] ?? 0 ),
                'full_logo' => self::image_id( $row['full_logo'] ?? 0 ),
            );
        }
        update_option( self::OPTION, $out, false );
        wp_safe_redirect( admin_url( 'options-general.php?page=faluss-link-networks&updated=1' ) );
        exit;
    }

    public static function page() {
        if ( ! current_user_can( 'manage_options' ) ) { return; }
        $catalog = self::catalog();
        ?>
        <div class="wrap">
            <h1>Réseaux Faluss Link</h1>
            <p>Chaque réseau actif peut utiliser deux ressources distinctes. Sans ressource disponible, ce réseau reste simplement masqué sur les cartes publiques : aucune image cassée ni pictogramme imposé.</p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="faluss_link_save_networks">
                <?php wp_nonce_field( 'faluss_link_save_networks', 'faluss_link_networks_nonce' ); ?>
                <table class="widefat striped">
                    <thead><tr><th>Réseau</th><th>Actif</th><th>Icône contour</th><th>Logo plein</th></tr></thead>
                    <tbody>
                    <?php foreach ( $catalog as $slug => $network ) : ?>
                        <tr>
                            <td><label><span class="screen-reader-text">Nom du réseau</span><input name="networks[<?php echo esc_attr( $slug ); ?>][label]" value="<?php echo esc_attr( $network['label'] ); ?>"></label></td>
                            <td><label><input type="checkbox" name="networks[<?php echo esc_attr( $slug ); ?>][active]" value="1" <?php checked( ! empty( $network['active'] ) ); ?>> <span class="screen-reader-text">Activer <?php echo esc_html( $network['label'] ); ?></span></label></td>
                            <?php self::asset_field( $slug, 'outline_icon', 'Icône contour', (int) $network['outline_icon'] ); ?>
                            <?php self::asset_field( $slug, 'full_logo', 'Logo plein', (int) $network['full_logo'] ); ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php submit_button( 'Enregistrer les réseaux' ); ?>
            </form>
        </div>
        <?php
    }

    private static function asset_field( $slug, $field, $label, $attachment_id ) {
        $status = $attachment_id ? 'Ressource sélectionnée' : 'Aucune ressource sélectionnée';
        ?><td><div class="faluss-link-network-asset"><strong><?php echo esc_html( $label ); ?></strong><input type="hidden" name="networks[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $field ); ?>]" value="<?php echo (int) $attachment_id; ?>"><button class="button faluss-link-network-asset-picker" type="button" data-faluss-title="<?php echo esc_attr( $label . ' — ' . $slug ); ?>">Choisir ou remplacer</button><span class="faluss-link-network-asset__status"><?php echo esc_html( $status ); ?></span></div></td><?php
    }

    private static function image_id( $value ) {
        $attachment_id = max( 0, (int) $value );
        return $attachment_id && wp_attachment_is_image( $attachment_id ) ? $attachment_id : 0;
    }

    private static function maybe_migrate_legacy_assets() {
        $saved = get_option( self::OPTION, array() );
        if ( ! is_array( $saved ) ) { return; }
        $changed = false;
        foreach ( self::defaults() as $slug => $default ) {
            if ( ! isset( $saved[ $slug ] ) || ! is_array( $saved[ $slug ] ) ) { continue; }
            if ( ! array_key_exists( 'outline_icon', $saved[ $slug ] ) && array_key_exists( 'icon', $saved[ $slug ] ) ) {
                $saved[ $slug ]['outline_icon'] = self::image_id( $saved[ $slug ]['icon'] );
                $changed = true;
            }
            if ( ! array_key_exists( 'full_logo', $saved[ $slug ] ) ) {
                $saved[ $slug ]['full_logo'] = 0;
                $changed = true;
            }
        }
        if ( $changed ) { update_option( self::OPTION, $saved, false ); }
    }
}
