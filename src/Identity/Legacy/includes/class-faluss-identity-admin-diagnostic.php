<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Faluss_Identity_Admin_Diagnostic {

    public static function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $status = Faluss_Identity_Schema::get_status();
        if ( ! empty( $status['ready'] ) ) {
            return;
        }

        echo '<div class="notice notice-error"><p>Faluss Identity : schéma non prêt (' . esc_html( $status['code'] ) . ').</p></div>';
    }
}
