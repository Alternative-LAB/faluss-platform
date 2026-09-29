<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui;

use Faluss\Platform\Fans\Profiles\CreatorProfileService;
use Faluss\Platform\Fans\Profiles\CreatorProfileSchema;
use Faluss\Platform\Fans\Sso\FansSsoService;

final class FansUiRoutes
{
    public const ROLE_VAR = 'faluss_fans_ui_role';
    public const VIEW_VAR = 'faluss_fans_ui_view';
    public const CREATOR_VAR = 'faluss_fans_ui_creator';

    /** @var array<string,list<string>> */
    private const VIEWS = [
        'fan' => ['accueil', 'explorer', 'hof', 'hof/session', 'classements', 'classement-fans', 'messages', 'espace'],
        'creator' => ['accueil', 'explorer', 'hof', 'hof/session', 'classements', 'messages', 'progression', 'creer', 'boutique', 'mon-profil'],
    ];

    public static function register(): void
    {
        add_action('init', [self::class, 'rewrite'], 20);
        add_filter('query_vars', [self::class, 'queryVars']);
        add_filter('show_admin_bar', [self::class, 'adminBarVisible']);
        add_action('template_redirect', [self::class, 'handle'], 1);
    }

    public static function rewrite(): void
    {
        add_rewrite_rule(
            '^faluss-fans/creators/([0-9a-f-]{36})/?$',
            'index.php?' . self::VIEW_VAR . '=public-profile&' . self::CREATOR_VAR . '=$matches[1]',
            'top'
        );
        add_rewrite_rule(
            '^faluss-fans/(fan|creator)/([a-z/-]+)/?$',
            'index.php?' . self::ROLE_VAR . '=$matches[1]&' . self::VIEW_VAR . '=$matches[2]',
            'top'
        );
    }

    /** @param list<string> $vars
     *  @return list<string>
     */
    public static function queryVars(array $vars): array
    {
        return array_merge($vars, [self::ROLE_VAR, self::VIEW_VAR, self::CREATOR_VAR]);
    }

    public static function validView(string $role, string $view): bool
    {
        return in_array($view, self::VIEWS[$role] ?? [], true);
    }

    public static function accessStatus(string $role, string $view, bool $linked, bool $hasCreatorProfile): int
    {
        if (in_array($view, ['explorer', 'hof', 'public-profile'], true)) {
            return 200;
        }
        if (!$linked) {
            return 403;
        }

        return $role === 'creator' && !$hasCreatorProfile ? 404 : 200;
    }

    public static function url(string $role, string $view): string
    {
        return home_url('/faluss-fans/' . $role . '/' . $view);
    }

    public static function isCanonicalPath(string $url, string $requestUri): bool
    {
        $expected = parse_url($url, PHP_URL_PATH);
        $actual = parse_url($requestUri, PHP_URL_PATH);

        return is_string($expected) && is_string($actual)
            && rtrim($expected, '/') === rtrim($actual, '/');
    }

    public static function adminBarVisible(bool $show): bool
    {
        $view = get_query_var(self::VIEW_VAR);
        $creatorId = get_query_var(self::CREATOR_VAR);
        if ($view === 'public-profile' && is_string($creatorId)) {
            $canonical = home_url('/faluss-fans/creators/' . $creatorId);
        } else {
            $role = get_query_var(self::ROLE_VAR);
            if (!is_string($role) || !is_string($view) || !self::validView($role, $view)) {
                return $show;
            }
            $canonical = self::url($role, $view);
        }

        return self::isCanonicalPath($canonical, (string) ($_SERVER['REQUEST_URI'] ?? ''))
            ? $show && current_user_can('manage_options')
            : $show;
    }

    public static function handle(): void
    {
        $view = get_query_var(self::VIEW_VAR);
        if (!is_string($view) || $view === '') {
            return;
        }
        $role = get_query_var(self::ROLE_VAR);
        $creatorId = get_query_var(self::CREATOR_VAR);
        $publicProfile = $view === 'public-profile' && is_string($creatorId)
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $creatorId) === 1;
        if (!$publicProfile && (!is_string($role) || !self::validView($role, $view))) {
            wp_die('Page introuvable.', '', ['response' => 404]);
        }
        $canonical = $publicProfile
            ? home_url('/faluss-fans/creators/' . $creatorId)
            : self::url($role, $view);
        if (!self::isCanonicalPath($canonical, (string) ($_SERVER['REQUEST_URI'] ?? ''))) {
            wp_die('Page introuvable.', '', ['response' => 404]);
        }

        self::noStore();
        $profilesEnabled = defined('FALUSS_PLATFORM_FANS_CREATOR_PROFILES')
            && constant('FALUSS_PLATFORM_FANS_CREATOR_PROFILES') === true
            && CreatorProfileSchema::ready();
        if ($publicProfile && (!$profilesEnabled || CreatorProfileService::publicById($creatorId) === null)) {
            wp_die('Profil public introuvable.', '', ['response' => 404]);
        }

        $linked = FansSsoService::currentLinkedSubject() !== null;
        $ownProfile = $linked && $profilesEnabled ? CreatorProfileService::own() : null;
        $signIn = $linked ? '' : FansSsoService::button(['return_to' => parse_url($canonical, PHP_URL_PATH)]);
        if ($publicProfile || $view === 'explorer' || $view === 'hof') {
            $role = !$linked ? 'visitor' : ($ownProfile === null ? 'fan' : 'creator');
        } else {
            $access = self::accessStatus($role, $view, $linked, $ownProfile !== null);
            if ($access === 403) {
                FansUiView::render('visitor', 'connexion', null, 403, $signIn);
                exit;
            }
            if ($access !== 200) {
                wp_die('Espace créateur indisponible.', '', ['response' => $access]);
            }
        }

        FansUiView::render((string) $role, $view, $publicProfile ? $creatorId : null, 200, $signIn);
        exit;
    }

    private static function noStore(): void
    {
        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0, must-revalidate');
        header('X-Content-Type-Options: nosniff');
    }
}
