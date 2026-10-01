<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Moderation;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Fans\Sso\FansAccountDirectory;
use Faluss\Platform\Fans\Profiles\CreatorProfilesModule;
use Faluss\Platform\Fans\Profiles\EditorialModule;
use Faluss\Platform\Fans\Publications\TextPublicationsModule;
use Faluss\Platform\Fans\Images\ImagesModule;
use Faluss\Platform\Fans\Messaging\ReportModeration;
use Faluss\Platform\Fans\Messaging\MessageOperations;
use Faluss\Platform\Fans\Store\StoreCatalogModule;

/** Private WordPress-authenticated shell. Existing moderation adapters remain authoritative. */
final class BackOffice
{
    public static function register(): void
    { add_action('template_redirect', [self::class, 'handle'], -1); }

    public static function isRequest(): bool
    {
        return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) === SiteRole::Fans
            && rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/') === rtrim((string) parse_url(home_url('/app/admin'), PHP_URL_PATH), '/');
    }

    /** @param array<string,string|null> $args */
    public static function url(array $args = []): string
    { return add_query_arg(array_filter($args, static fn($value) => $value !== null && $value !== ''), home_url('/app/admin')); }

    public static function allowed(): bool
    { return SiteRole::fromValue(defined('FALUSS_PLATFORM_ROLE') ? constant('FALUSS_PLATFORM_ROLE') : null) === SiteRole::Fans && is_user_logged_in() && current_user_can('manage_options'); }

    /** @return array<string,string> */
    public static function sections(): array
    {
        if (!self::allowed()) { return []; }
        $sections = ['overview' => 'Vue d’ensemble'];
        if (FansAccountDirectory::allowed()) { $sections['accounts'] = 'Comptes'; $sections['search'] = 'Recherche'; }
        if (CreatorProfilesModule::available()) { $sections['profiles'] = 'Admission'; }
        if (EditorialModule::available()) { $sections['editorial'] = 'Présentations'; }
        if (TextPublicationsModule::available()) { $sections['texts'] = 'Publications'; }
        if (ImagesModule::available()) { $sections['images'] = 'Images'; }
        if (ReportModeration::allowed()) { $sections['messages'] = 'Signalements'; }
        if (MessageOperations::allowed()) { $sections['operations'] = 'Messagerie'; }
        if (StoreCatalogModule::available()) { $sections['catalog'] = 'Catalogue'; }
        if (current_user_can('list_users')) { $sections['staff'] = 'Équipe'; }
        $sections['modules'] = 'Modules';
        return $sections;
    }

    public static function handle(): void
    {
        if (!self::isRequest()) { return; }
        ModerationPanel::noStore();
        if (!self::allowed()) { wp_die('Accès réservé à l’administration Fans. Connectez-vous avec votre compte WordPress habilité.', '', ['response' => 403]); }
        CreatorProfilesModule::upgradeStatusJournal();
        $view = ModerationPanel::field('view', $_GET) ?: 'overview';
        $sections = self::sections();
        if (!isset($sections[$view])) { wp_die('Fonction indisponible ou habilitation absente.', '', ['response' => 403]); }
        status_header(200);
        ob_start();
        if ($view === 'images') { BackOfficeImages::render(); }
        elseif (in_array($view, ['profiles', 'editorial', 'texts', 'messages'], true)) {
            ModerationPanel::load(); ModerationPanel::render();
        } else { BackOfficeView::render($view); }
        $content = (string) ob_get_clean();
        $file = dirname(__DIR__, 3) . '/faluss-platform.php'; $version = (string) constant('FALUSS_PLATFORM_VERSION');
        wp_enqueue_style('faluss-fans-ui', plugins_url('assets/fans-ui-v2.css', $file), [], $version);
        wp_enqueue_style('faluss-fans-moderation', plugins_url('assets/fans-moderation.css', $file), [], $version);
        wp_enqueue_style('faluss-fans-backoffice', plugins_url('assets/fans-backoffice.css', $file), ['faluss-fans-ui', 'faluss-fans-moderation'], $version);
        wp_enqueue_script('faluss-fans-moderation', plugins_url('assets/fans-moderation.js', $file), [], $version, true);
        remove_action('wp_head', 'print_emoji_detection_script', 7);
        ?>
        <!doctype html><html <?php language_attributes(); ?>><head><meta charset="<?php bloginfo('charset'); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>Administration Fans</title><?php wp_head(); ?></head>
        <body class="fu-body fb-body"><a class="fu-skip" href="#fb-content">Aller au contenu</a>
        <header class="fb-header"><a class="fb-brand" href="<?php echo esc_url(self::url()); ?>">F <span>Fans · Administration</span></a>
            <nav class="fb-pills" aria-label="Administration Fans"><?php foreach ($sections as $key => $label): ?><a href="<?php echo esc_url(self::url(['view' => $key])); ?>" <?php echo $view === $key ? 'aria-current="page"' : ''; ?>><?php echo esc_html($label); ?></a><?php endforeach; ?></nav>
            <a class="fb-fallback" href="<?php echo esc_url(admin_url('admin.php?page=faluss-platform')); ?>">Administration WordPress ↗</a></header>
        <main id="fb-content" class="fb-content"><?php echo $content; // Already escaped by the shared rendering adapters. ?></main>
        <footer class="fb-footer">Espace privé · les flags sont gérés par configuration serveur.</footer><?php wp_footer(); ?></body></html>
        <?php exit;
    }
}
