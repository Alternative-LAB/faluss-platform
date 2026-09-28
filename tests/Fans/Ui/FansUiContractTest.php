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
        }

        public function testDistinctRoutesAndDeniedCreatorAccess(): void
        {
            FansUiRoutes::rewrite();
            self::assertCount(2, $GLOBALS['fans_ui_rewrites']);
            self::assertSame(
                'https://fans.example.test/faluss-fans/creator/mon-profil',
                FansUiRoutes::url('creator', 'mon-profil')
            );
            self::assertNotSame(
                FansUiRoutes::url('creator', 'mon-profil'),
                'https://fans.example.test/faluss-fans/creators/123e4567-e89b-42d3-a456-426614174000'
            );
            self::assertTrue(FansUiRoutes::validView('fan', 'explorer'));
            self::assertFalse(FansUiRoutes::validView('fan', 'boutique'));
            self::assertFalse(FansUiRoutes::validView('creator', 'espace'));
            self::assertTrue(FansUiRoutes::isCanonicalPath(
                FansUiRoutes::url('creator', 'creer'),
                '/faluss-fans/creator/creer/?source=sidebar'
            ));
            self::assertFalse(FansUiRoutes::isCanonicalPath(
                FansUiRoutes::url('creator', 'creer'),
                '/?faluss_fans_ui_role=creator&faluss_fans_ui_view=creer'
            ));
            self::assertSame(403, FansUiRoutes::accessStatus('fan', false, false));
            self::assertSame(403, FansUiRoutes::accessStatus('creator', false, true));
            self::assertSame(404, FansUiRoutes::accessStatus('creator', true, false));
            self::assertSame(200, FansUiRoutes::accessStatus('creator', true, true));
        }

        public function testCreatorSidebarIsCompleteAccessibleAndDistinctFromFan(): void
        {
            $creator = $this->render('creator', 'creer');
            $fan = $this->render('fan', 'accueil');
            self::assertSame(8, substr_count($creator, 'class="fu-nav__item'));
            self::assertSame(5, substr_count($fan, 'class="fu-nav__item'));
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
        }

        private function render(string $role, string $view): string
        {
            ob_start();
            FansUiView::render($role, $view, null);
            return (string) ob_get_clean();
        }
    }
}
