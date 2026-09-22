<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Faluss_Identity_Plugin {

    public static function boot() {
        Faluss_Identity_Front_Preferences::boot();
        Faluss_Identity_Member_Session::boot();
        add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
        add_action( 'init', array( 'Faluss_Identity_Passwordless', 'register' ) );
        add_action( 'init', array( 'Faluss_Identity_Public_Profile', 'register' ) );
        add_action( 'init', array( 'Faluss_Identity_Onboarding', 'register' ) );
        add_action( 'init', array( 'Faluss_Identity_Authorization', 'register' ) );
        add_action( 'wp_enqueue_scripts', array( 'Faluss_Identity_Passwordless', 'register_assets' ) );
        add_action( 'elementor/frontend/after_register_scripts', array( 'Faluss_Identity_Passwordless', 'register_assets' ), 5 );
        add_action( 'elementor/frontend/after_register_styles', array( 'Faluss_Identity_Passwordless', 'register_assets' ), 5 );
        add_action( 'wp_enqueue_scripts', array( 'Faluss_Identity_Public_Profile', 'register_assets' ) );
        add_action( 'wp_enqueue_scripts', array( 'Faluss_Identity_Authorization', 'register_assets' ) );
        add_action( 'wp_enqueue_scripts', array( 'Faluss_Identity_Navigation', 'register_assets' ) );
        add_action( 'elementor/frontend/after_register_scripts', array( 'Faluss_Identity_Navigation', 'register_assets' ), 5 );
        add_action( 'elementor/frontend/after_register_styles', array( 'Faluss_Identity_Navigation', 'register_assets' ), 5 );
        add_action( 'wp_enqueue_scripts', array( 'Faluss_Identity_Onboarding', 'register_assets' ) );
        add_action( 'elementor/frontend/after_register_scripts', array( 'Faluss_Identity_Onboarding', 'register_assets' ), 5 );
        add_action( 'elementor/frontend/after_register_styles', array( 'Faluss_Identity_Onboarding', 'register_assets' ), 5 );
        add_action( 'elementor/widgets/register', array( __CLASS__, 'register_elementor_widget' ) );

        if ( is_admin() ) {
            add_action( 'admin_notices', array( 'Faluss_Identity_Admin_Diagnostic', 'render' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_fi02' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_fi03' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_fi04' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_onb01' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_fi06_sso' ) );
            add_action( 'admin_init', array( __CLASS__, 'migrate_fi06' ) );
            Faluss_Identity_SSO_Clients_Admin::register();
        }
    }

    public static function activate() {
        Faluss_Identity_Schema::install_or_verify();
        Faluss_Identity_Schema::migrate_fi02();
        Faluss_Identity_Schema::migrate_fi03();
        Faluss_Identity_Schema::migrate_fi04();
        Faluss_Identity_Schema::migrate_onb01();
        Faluss_Identity_Schema::migrate_fi06_sso();
        Faluss_Identity_Front_Preferences::migrate_fi06();
        Faluss_Identity_Public_Profile::register_rewrite_rule();
        Faluss_Identity_Onboarding::register_rewrite_rule();
        Faluss_Identity_Authorization::register_rewrite_rules();
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function migrate_fi02() {
        if ( current_user_can( 'manage_options' ) ) {
            Faluss_Identity_Schema::migrate_fi02();
        }
    }

    public static function migrate_fi03() {
        if ( current_user_can( 'manage_options' ) ) {
            $was_fi03 = Faluss_Identity_Schema::FI03_VERSION === (string) get_option( Faluss_Identity_Schema::OPTION_VERSION, '' );
            if ( Faluss_Identity_Schema::migrate_fi03() && ! $was_fi03 ) {
                flush_rewrite_rules();
            }
        }
    }

    public static function migrate_fi04() {
        if ( current_user_can( 'manage_options' ) ) {
            Faluss_Identity_Schema::migrate_fi04();
        }
    }

    public static function migrate_onb01() {
        if ( current_user_can( 'manage_options' ) ) {
            $was_onb01 = Faluss_Identity_Schema::ONB01_VERSION === (string) get_option( Faluss_Identity_Schema::OPTION_VERSION, '' );
            if ( Faluss_Identity_Schema::migrate_onb01() && ! $was_onb01 ) {
                Faluss_Identity_Onboarding::register_rewrite_rule();
                flush_rewrite_rules();
            }
        }
    }

    public static function migrate_fi06() {
        if ( current_user_can( 'manage_options' ) ) {
            Faluss_Identity_Front_Preferences::migrate_fi06();
        }
    }

    /** Adds the explicit first-party marker used only by the Faluss.com SSO client. */
    public static function migrate_fi06_sso() {
        if ( current_user_can( 'manage_options' ) ) {
            Faluss_Identity_Schema::migrate_fi06_sso();
        }
    }

    public static function load_textdomain() {
        load_plugin_textdomain( 'faluss-identity', false, dirname( plugin_basename( FALUSS_IDENTITY_FILE ) ) . '/languages' );
    }

    /**
     * Keeps the public login usable without Elementor while exposing a native,
     * customizable widget when Elementor is active.
     *
     * @param mixed $widgets_manager Elementor widget manager.
     * @return void
     */
    public static function register_elementor_widget( $widgets_manager ) {
        if ( ! class_exists( 'Elementor\\Widget_Base' ) || ! is_object( $widgets_manager ) || ! method_exists( $widgets_manager, 'register' ) ) {
            return;
        }

        require_once FALUSS_IDENTITY_DIR . 'includes/class-faluss-identity-elementor-widget.php';
        require_once FALUSS_IDENTITY_DIR . 'includes/class-faluss-identity-public-profile-elementor-widgets.php';
        require_once FALUSS_IDENTITY_DIR . 'includes/class-faluss-identity-navigation-elementor-widget.php';
        require_once FALUSS_IDENTITY_DIR . 'includes/class-faluss-identity-onboarding-elementor-widget.php';
        $widgets_manager->register( new Faluss_Identity_Elementor_Widget() );
        $widgets_manager->register( new Faluss_Identity_Public_Profile_Editor_Widget() );
        $widgets_manager->register( new Faluss_Identity_Public_Profile_Widget() );
        $widgets_manager->register( new Faluss_Identity_Navigation_Elementor_Widget() );
        $widgets_manager->register( new Faluss_Identity_Onboarding_Elementor_Widget() );
    }
}
