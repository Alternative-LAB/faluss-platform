<?php

declare(strict_types=1);

namespace Faluss\Platform\Fans\Ui {
    use PHPUnit\Framework\TestCase;

    function add_rewrite_rule(string $pattern, string $query, string $priority): void
    {
        $GLOBALS['fans_ui_rewrites'][$pattern] = [$query, $priority];
    }
    function home_url(string $path): string { return 'https://fans.example.test' . $path; }
    function rest_url(string $path): string { return 'https://fans.example.test/wp-json/' . $path; }
    function plugins_url(string $path, string $plugin): string { return 'https://fans.example.test/wp-content/plugins/faluss-platform/' . $path; }
    function wp_enqueue_style(string $handle, string $url, array $dependencies, string $version): void {}
    function wp_enqueue_script(string $handle, string $url, array $dependencies, string $version, bool $footer): void {}
    function status_header(int $status): void {}
    function wp_head(): void {}
    function wp_footer(): void {}
    function get_query_var(string $name): mixed { return $GLOBALS['fans_ui_query'][$name] ?? ''; }
    function current_user_can(string $capability): bool { return $capability === 'manage_options' && ($GLOBALS['fans_ui_admin'] ?? false); }
    function add_action(string $hook, callable $callback, int $priority = 10): void {}
    function add_filter(string $hook, callable $callback): void { $GLOBALS['fans_ui_filters'][$hook] = $callback; }
    function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
    function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

    require_once dirname(__DIR__, 3) . '/src/Fans/Ui/FansUiRoutes.php';
    require_once dirname(__DIR__, 3) . '/src/Fans/Ui/FansUiView.php';

    final class FansUiContractTest extends TestCase
    {
        protected function setUp(): void
        {
            if (!defined('FALUSS_PLATFORM_VERSION')) {
                define('FALUSS_PLATFORM_VERSION', 'test');
            }
            $GLOBALS['fans_ui_rewrites'] = [];
            $GLOBALS['fans_ui_query'] = [];
            $GLOBALS['fans_ui_filters'] = [];
            $GLOBALS['fans_ui_admin'] = false;
        }

        public function testDistinctRoutesAndDeniedCreatorAccess(): void
        {
            FansUiRoutes::rewrite();
            self::assertCount(5, $GLOBALS['fans_ui_rewrites']);
            self::assertSame(
                'https://fans.example.test/app/creator/mon-profil',
                FansUiRoutes::url('creator', 'mon-profil')
            );
            self::assertNotSame(
                FansUiRoutes::url('creator', 'mon-profil'),
                'https://fans.example.test/app/creators/123e4567-e89b-42d3-a456-426614174000'
            );
            self::assertTrue(FansUiRoutes::validView('fan', 'explorer'));
            self::assertFalse(FansUiRoutes::validView('fan', 'boutique'));
            self::assertFalse(FansUiRoutes::validView('creator', 'espace'));
            self::assertTrue(FansUiRoutes::isCanonicalPath(
                FansUiRoutes::url('creator', 'creer'),
                '/app/creator/creer/?source=sidebar'
            ));
            self::assertFalse(FansUiRoutes::isCanonicalPath(
                FansUiRoutes::url('creator', 'creer'),
                '/?faluss_fans_ui_role=creator&faluss_fans_ui_view=creer'
            ));
            self::assertSame(200, FansUiRoutes::accessStatus('fan', 'explorer', false, false));
            self::assertSame(200, FansUiRoutes::accessStatus('creator', 'explorer', false, false));
            self::assertSame(200, FansUiRoutes::accessStatus('fan', 'public-profile', false, false));
            self::assertSame(200, FansUiRoutes::accessStatus('fan', 'hof', false, false));
            self::assertSame(200, FansUiRoutes::accessStatus('creator', 'hof', false, false));
            self::assertSame(403, FansUiRoutes::accessStatus('fan', 'hof/session', false, false));
            self::assertSame(403, FansUiRoutes::accessStatus('fan', 'classements', false, false));
            self::assertSame(403, FansUiRoutes::accessStatus('fan', 'accueil', false, false));
            self::assertSame(403, FansUiRoutes::accessStatus('creator', 'creer', false, true));
            self::assertSame(404, FansUiRoutes::accessStatus('creator', 'creer', true, false));
            self::assertSame(200, FansUiRoutes::accessStatus('creator', 'creer', true, true));
        }

        public function testCreatorSidebarIsCompleteAccessibleAndDistinctFromFan(): void
        {
            $creator = $this->render('creator', 'creer');
            $fan = $this->render('fan', 'accueil');
            self::assertSame(8, substr_count($creator, 'class="fu-nav__item'));
            self::assertSame(6, substr_count($fan, 'class="fu-nav__item'));
            self::assertSame(1, substr_count($creator, 'aria-current="page"'));
            self::assertSame(1, substr_count($fan, 'aria-current="page"'));
            $cursor = -1;
            foreach (['Accueil', 'Explorer', 'HoF', 'Messages', 'Créer', 'Ma boutique', 'Progression', 'Mon profil'] as $label) {
                $next = strpos($creator, 'aria-label="' . $label . '"', $cursor + 1);
                self::assertNotFalse($next, $label);
                self::assertGreaterThan($cursor, $next);
                $cursor = $next;
            }
            self::assertStringNotContainsString('aria-label="Ma boutique"', $fan);
            self::assertStringNotContainsString('aria-label="Créer"', $fan);
            foreach (['Publication', 'Prestation', 'Service', 'Produit'] as $choice) {
                self::assertStringContainsString('<h3>' . $choice . '</h3>', $creator);
            }
            self::assertSame(4, substr_count($creator, 'Indisponible pour le moment'));
            self::assertStringNotContainsString('<form', $creator);
        }

        public function testExplorerUsesPublicProfileApiWithoutFabricatedPeople(): void
        {
            $html = $this->render('fan', 'explorer');
            self::assertStringContainsString('data-api="https://fans.example.test/wp-json/faluss-fans/v1/creators"', $html);
            self::assertStringContainsString('data-fans-results', $html);
            self::assertStringNotContainsString('<img', $html);
            self::assertStringNotContainsString('PF</', $html);
            self::assertStringNotContainsString('€', $html);
            self::assertStringContainsString('ne sont diffusés qu’après approbation', $html);
            self::assertStringContainsString('sans présentation approuvée reste explicitement incomplète', $html);
            $visitor = $this->render('visitor', 'explorer');
            self::assertSame(2, substr_count($visitor, 'class="fu-nav__item'));
            self::assertStringContainsString('href="https://fans.example.test/app/fan/explorer"', $visitor);
            self::assertStringContainsString('href="https://fans.example.test/app/fan/hof"', $visitor);
            self::assertStringNotContainsString('faluss-fans/visitor/', $visitor);
        }

        public function testPublicHofShowsAnHonestUnavailableState(): void
        {
            $html = $this->render('visitor', 'hof');
            self::assertSame(2, substr_count($html, 'class="fu-nav__item'));
            self::assertStringContainsString('aria-label="HoF"', $html);
            self::assertStringContainsString('Le Hall of Fame se prépare', $html);
            self::assertStringContainsString('Bientôt sur Fans', $html);
            self::assertStringNotContainsString('moteur', $html);
            self::assertStringNotContainsString('<form', $html);
            self::assertStringNotContainsString('€', $html);
        }

        public function testFanRankingStaysDistinctPrivateAndUnavailable(): void
        {
            self::assertTrue(FansUiRoutes::validView('fan', 'classement-fans'));
            self::assertFalse(FansUiRoutes::validView('creator', 'classement-fans'));
            self::assertSame(403, FansUiRoutes::accessStatus('fan', 'classement-fans', false, false));
            self::assertSame(200, FansUiRoutes::accessStatus('fan', 'classement-fans', true, false));
            self::assertNotSame(FansUiRoutes::url('fan', 'classements'), FansUiRoutes::url('fan', 'classement-fans'));
            $html = $this->render('fan', 'classement-fans');
            self::assertStringContainsString('Classement indisponible', $html);
            self::assertStringContainsString('Acheter un pack sans attribuer ses PF ne donnera aucun point.', $html);
            self::assertStringContainsString('Une même attribution ne comptera qu’une fois.', $html);
            self::assertStringContainsString('annulations, remboursements et corrections', $html);
            self::assertSame(1, substr_count($html, 'aria-current="page"'));
            foreach (['<table', '<form', '<progress', 'data-api=', '€', ' PC', 'wallet'] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $html);
            }
            self::assertStringNotContainsString('aria-label="Classement Fans"', $this->render('creator', 'progression'));
        }

        public function testToolbarPolicyIsNoLongerOwnedByOptionalUiRoutes(): void
        {
            FansUiRoutes::register();
            self::assertArrayNotHasKey('show_admin_bar', $GLOBALS['fans_ui_filters']);
        }

        public function testStoredRewriteAcceptsOneTrailingSlashWithoutAFlush(): void
        {
            FansUiRoutes::rewrite();
            $patterns = array_keys($GLOBALS['fans_ui_rewrites']);
            foreach (['fan/hof', 'creator/creer', 'creator/hof/session'] as $route) {
                foreach (['', '/'] as $suffix) {
                    $request = 'app/' . $route . $suffix;
                    self::assertSame(1, preg_match('~' . $patterns[2] . '~D', $request, $matches));
                    $GLOBALS['fans_ui_query'] = [FansUiRoutes::ROLE_VAR => $matches[1], FansUiRoutes::VIEW_VAR => $matches[2]];
                    $_SERVER['REQUEST_URI'] = '/' . $request;
                    self::assertTrue(FansUiRoutes::isCanonicalPath(FansUiRoutes::url($matches[1], $matches[2]), '/' . $request));
                }
            }
            $canonical = FansUiRoutes::url('creator', 'creer');
            foreach (['/app/creator/creer//', '/faluss-fans//creator/creer', '/app/creator/creer/extra', '/app/creator/%63reer', '/?faluss_fans_ui_view=creer'] as $request) {
                self::assertFalse(FansUiRoutes::isCanonicalPath($canonical, $request), $request);
            }
        }

        private function render(string $role, string $view): string
        {
            ob_start();
            FansUiView::render($role, $view, null);
            return (string) ob_get_clean();
        }
    }
}
