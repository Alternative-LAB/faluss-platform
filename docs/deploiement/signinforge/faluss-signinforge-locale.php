<?php
/** Français par défaut sur la connexion Fans ; le choix explicite du visiteur prévaut. */
declare(strict_types=1);

$falussSigninforgeLocale = static function ($locale) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script !== 'wp-login.php' || isset($_GET['wp_lang']) || isset($_COOKIE['wp_lang'])) {
        return $locale;
    }
    if (!in_array('signinforge/signinforge.php', get_option('active_plugins', []), true)) {
        return $locale;
    }
    return 'fr_FR';
};
add_filter('pre_determine_locale', $falussSigninforgeLocale);
add_filter('locale', $falussSigninforgeLocale);
unset($falussSigninforgeLocale);
