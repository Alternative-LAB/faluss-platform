<?php

declare(strict_types=1);

// Disposable WordPress fixture only. No module activation or real client credentials.
require_once WP_PLUGIN_DIR . '/faluss-platform/vendor/autoload.php';
define('FALUSS_PLATFORM_VERSION', 'button-fixture');
define('FALUSS_FANS_SSO_CLIENT_ID', 'unusable-local-render-fixture');
define('FALUSS_FANS_SSO_CLIENT_SECRET', str_repeat('x', 43));
add_filter('pre_http_request', static fn () => new WP_Error('fixture_offline'), PHP_INT_MAX);
add_filter('pre_wp_mail', static fn () => false);
// Exercise the same rendering hooks, not a copied form. No SSO start/callback is registered.
add_shortcode('faluss_fans_sso_button', [\Faluss\Platform\Fans\Sso\FansSsoService::class, 'button']);
add_action('wp_enqueue_scripts', [\Faluss\Platform\Fans\Sso\FansSsoService::class, 'enqueueButtonStyle']);
add_action('template_redirect', static function (): void {
    if (!isset($_GET['button_fixture'])) { return; }
    // Only the configuration guard needs an HTTPS test home. All assets/actions stay loopback.
    add_filter('home_url', static fn (string $url, string $path) => 'https://fans.example.test/' . ltrim($path, '/'), 10, 2);
    $shortcode = '[faluss_fans_sso_button return_to="/app/fan/espace"]';
    if (isset($_GET['fans_ui'])) {
        // Rendering-only creator fixture: no invented identity, profile or service data.
        $creator = isset($_GET['creator_view']) && in_array($_GET['creator_view'], ['accueil', 'explorer', 'hof', 'creer', 'boutique', 'progression', 'mon-profil'], true);
        \Faluss\Platform\Fans\Ui\FansUiView::render($creator ? 'creator' : 'visitor', $creator ? $_GET['creator_view'] : 'connexion', null, 200, $creator ? '' : do_shortcode($shortcode));
        exit;
    }
    ?>
<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Recette locale · bouton SSO</title><?php wp_head(); ?>
<style>
body{margin:0;background:#030a07;color:white;font-family:FalussSsoOutfit,sans-serif}
main{box-sizing:border-box;min-height:100vh;max-width:1200px;margin:auto;padding:120px 32px;text-align:center}
h1{font-size:50px;line-height:1.15;margin:36px 0 28px}p{font-size:28px;line-height:1.25;margin:0 auto 72px}
small{color:#a8b9b1}#control{margin-top:60px}
@media(max-width:520px){main{padding:64px 20px}h1{font-size:34px}p{font-size:20px;margin-bottom:40px}}
</style></head><body><main><small>Recette locale · formulaire réel · aucune connexion externe</small>
<h1>Accède à bien plus que du contenu</h1>
<p>les fans sont vraiment visibles pour les créateurs, le succès des créateurs est donc plus bénéfique pour les fans qui y contribuent.</p>
<?php echo do_shortcode($shortcode); // Rendered after wp_head as in a theme/Elementor shortcode. ?>
<button id="control" type="button">Bouton témoin hors shortcode</button>
</main><?php wp_footer(); ?></body></html>
    <?php
    exit;
}, -1);
