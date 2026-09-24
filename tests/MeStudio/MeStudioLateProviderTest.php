<?php

declare(strict_types=1);

namespace {
    if (!function_exists('add_action')) {
        function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): void
        {
            $GLOBALS['me_studio_test_actions'][$hook][] = compact('callback', 'priority', 'acceptedArgs');
        }
    }

    if (!function_exists('esc_html_e')) {
        function esc_html_e(string $text, string $domain = ''): void
        {
            unset($domain);
            echo htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (!function_exists('esc_html')) {
        function esc_html(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (!function_exists('esc_attr')) {
        function esc_attr(string $text): string
        {
            return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
    }

    if (!function_exists('checked')) {
        function checked(mixed $checked, mixed $current = true, bool $display = true): string
        {
            $result = $checked == $current ? 'checked="checked"' : '';
            if ($display) {
                echo $result;
            }

            return $result;
        }
    }
}

namespace Faluss\Platform\MeStudio {
    use Faluss\Platform\Core\Module;
    use Faluss\Platform\Core\SiteRole;
    use Faluss\Platform\Link\StudioProviderRegistry;
    use PHPUnit\Framework\Attributes\PreserveGlobalState;
    use PHPUnit\Framework\Attributes\RunInSeparateProcess;
    use PHPUnit\Framework\TestCase;

    final class MeStudioLateProviderTest extends TestCase
    {
        protected function tearDown(): void
        {
            StudioProviderRegistry::resetForTests();
            StudioBlockProviderRegistry::resetForTests();
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testModuleBootedAfterMeStudioCanRegisterAProviderVisibleInStudio(): void
        {
            define('FALUSS_PLATFORM_ME_STUDIO_V2', true);
            $this->defineLinkRuntimeFixture();
            (new MeStudioModule())->boot();
            self::assertSame('me-studio-v2', StudioProviderRegistry::activeId());

            $lateModule = new class implements Module {
                public function id(): string
                {
                    return 'future-fans';
                }

                public function roles(): array
                {
                    return [SiteRole::Me];
                }

                public function dependencies(): array
                {
                    return ['me-studio-v2'];
                }

                public function boot(): void
                {
                    StudioBlockProviderRegistry::shared()->register(new class implements StudioBlockProvider {
                        public function descriptor(): array
                        {
                            return [
                                'id' => 'future-fans',
                                'placement' => 'me.public.block',
                                'read_model_contract' => ['document_type' => 'faluss.fans.card', 'contract_version' => '1.0.0'],
                                'actions' => ['fans.open'],
                                'visibility' => 'members',
                                'fallback' => 'placeholder',
                            ];
                        }

                        public function readModel(): array|\WP_Error
                        {
                            return [];
                        }
                    });
                }
            };

            self::assertSame(['me-studio-v2'], $lateModule->dependencies());
            $lateModule->boot();

            $markup = StudioProviderRegistry::renderStudioExtension(['preferences' => ['social_color' => '']]);
            self::assertStringContainsString('Modules disponibles', $markup);
            self::assertStringContainsString('future-fans', $markup);
            self::assertStringContainsString('me.public.block', $markup);
        }

        #[RunInSeparateProcess]
        #[PreserveGlobalState(false)]
        public function testOnb13UsesTheExplicitNetworkOrderInsteadOfCatalogOrder(): void
        {
            $this->defineLinkRuntimeFixture();
            $GLOBALS['me_studio_social_catalog'] = [];
            foreach (['instagram', 'onlyfans', 'youtube', 'snapchat', 'x', 'tiktok', 'threads', 'telegram', 'linkedin'] as $network) {
                $GLOBALS['me_studio_social_catalog'][$network] = ['active' => 1, 'full' => [], 'outline' => []];
            }

            $method = new \ReflectionMethod(StudioRenderer::class, 'socialStyleCards');
            ob_start();
            $method->invoke(null, 'brand-light');
            $markup = (string) ob_get_clean();
            preg_match_all('/data-faluss-network="([a-z]+)"/', $markup, $matches);

            $expected = ['tiktok', 'telegram', 'x', 'snapchat', 'threads', 'onlyfans'];
            self::assertCount(54, $matches[1]);
            foreach (array_chunk($matches[1], 6) as $preview) {
                self::assertSame($expected, $preview);
            }
            self::assertStringNotContainsString('data-faluss-network="instagram"', $markup);
            self::assertStringNotContainsString('data-faluss-network="youtube"', $markup);
        }

        private function defineLinkRuntimeFixture(): void
        {
            eval(<<<'PHP'
namespace {
    final class Faluss_Link {
        public static function studio_v2_social_catalog(): array {
            return is_array($GLOBALS['me_studio_social_catalog'] ?? null) ? $GLOBALS['me_studio_social_catalog'] : [];
        }
    }
    final class Faluss_Link_Schema {
        public static function composition_ready(): bool { return true; }
    }
}
PHP);
        }
    }
}
