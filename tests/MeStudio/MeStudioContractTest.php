<?php

declare(strict_types=1);

namespace Faluss\Platform\MeStudio;

use Faluss\Platform\Core\SiteRole;
use Faluss\Platform\Link\StudioProvider;
use Faluss\Platform\Link\StudioProviderRegistry;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class MeStudioContractTest extends TestCase
{
    protected function tearDown(): void
    {
        StudioProviderRegistry::resetForTests();
    }

    public function testNativeModuleIsExplicitlyOptedInAndDependencyBound(): void
    {
        $module = new MeStudioModule();

        self::assertSame('me-studio-v2', $module->id());
        self::assertSame([SiteRole::Me], $module->roles());
        self::assertSame(['admin-dashboard', 'identity', 'catalog', 'link', 'apps-registry'], $module->dependencies());

        $bootstrap = $this->source('faluss-platform.php');
        self::assertStringContainsString("defined('FALUSS_PLATFORM_ME_STUDIO_V2')", $bootstrap);
        self::assertStringContainsString("constant('FALUSS_PLATFORM_ME_STUDIO_V2') === true", $bootstrap);
        self::assertStringContainsString('new \\Faluss\\Platform\\MeStudio\\MeStudioModule()', $bootstrap);
    }

    public function testProviderRegistryHasOneProviderAndARecoverableFallback(): void
    {
        self::assertSame('link-internal', StudioProviderRegistry::activeId());
        self::assertSame('fallback', StudioProviderRegistry::renderStudio(static fn (): string => 'fallback'));

        StudioProviderRegistry::register($this->provider(false));
        self::assertSame('test-studio', StudioProviderRegistry::activeId());
        self::assertSame('studio-v2', StudioProviderRegistry::renderStudio(static fn (): string => 'fallback'));
        self::assertSame('onboarding-v2', StudioProviderRegistry::renderOnboarding(static fn (): string => 'fallback'));

        $this->expectException(LogicException::class);
        StudioProviderRegistry::register($this->provider(false));
    }

    public function testProviderFailureFallsBackWithoutASecondRenderer(): void
    {
        StudioProviderRegistry::register($this->provider(true));

        self::assertSame('fallback', StudioProviderRegistry::renderStudio(static fn (): string => 'fallback'));
        self::assertSame('fallback-onboarding', StudioProviderRegistry::renderOnboarding(static fn (): string => 'fallback-onboarding'));

        $provider = $this->source('src/MeStudio/MeStudioProvider.php');
        self::assertStringContainsString('$markup = $fallback();', $provider);
        self::assertStringContainsString('LinkStudioContract::preview([], true)', $provider);
        self::assertStringContainsString("LinkStudioContract::preview(['structure' => \$structure], true)", $provider);
        self::assertStringContainsString("['simple', 'atomic']", $provider);
        self::assertStringContainsString('return StudioRenderer::onboarding($step, $state);', $provider);
        self::assertStringNotContainsString('class Faluss_Link', $provider);
    }

    public function testOnboardingSequenceAndExactPrincipalLabelsArePinned(): void
    {
        $identity = $this->source('src/Identity/Legacy/includes/class-faluss-identity-onboarding.php');
        $actions = $this->source('src/MeStudio/StudioActions.php');
        $renderer = $this->source('src/MeStudio/StudioRenderer.php');

        foreach ([
            'wizard_structure', 'wizard_atomic_warning', 'wizard_atomic_colors', 'wizard_atomic_buttons',
            'wizard_atomic_avatar_upload', 'wizard_atomic_avatar', 'wizard_atomic_wallpaper_upload',
            'wizard_atomic_wallpaper', 'wizard_atomic_networks',
        ] as $step) {
            self::assertStringContainsString($step, $identity);
            self::assertStringContainsString($step, $actions);
        }
        foreach ([
            'Choisis la structure', 'Structure Atomique', 'Couleurs principales', 'Arrière Plan', 'Boutons',
            'Les boutons', 'Forme', 'Texture', 'Granulée', 'Lisse', 'Camo',
            'Choisis la photo qui te représentera', 'La photo principale', 'Bordure', 'Ombre', 'Les 2',
            'Ajoute une photo de couverture', 'L’image de fond', 'Compacte', 'Couverture', 'Aucun', 'Dégradé',
            'Les réseaux', 'Couleurs', 'Sélection', 'Passer', 'Continuer', 'J’ai compris',
        ] as $label) {
            self::assertStringContainsString($label, $renderer);
        }
        self::assertStringContainsString("\$structure === 'atomic' ? 'wizard_atomic_warning' : 'wizard_name'", $actions);
        self::assertStringContainsString("'wizard_atomic_networks' => 'wizard_finish'", $actions);
        self::assertStringContainsString("\$direction === 'back'", $actions);
        self::assertStringContainsString("['next', 'back', 'skip']", $actions);
        self::assertStringContainsString('socialStyleCards', $renderer);
        self::assertStringContainsString('LinkStudioContract::socialCatalog()', $renderer);
        self::assertStringContainsString('data-me-social-selection', $renderer);
        self::assertStringNotContainsString('Dylan', $renderer);
    }

    public function testSharedCardCompositionCoversAtomicVisualRules(): void
    {
        $link = $this->source('src/Link/LegacyLinkService.php');
        $card = $this->source('assets/me-studio/css/card-v2.css');
        $studio = $this->source('assets/me-studio/css/studio-v2.css');
        $editor = $this->source('assets/link/js/faluss-link-editor.js');

        self::assertStringContainsString('card_markup_from_presentation', $link);
        self::assertStringContainsString('card_preview_markup', $link);
        self::assertStringContainsString('faluss-link-card--structure-', $link);
        self::assertStringContainsString('faluss-link-card--links-mode-', $link);
        self::assertStringContainsString('platform_from_url', $link);
        self::assertStringContainsString("str_starts_with( \$social_style, 'brand-' ) ? 'full' : 'outline'", $link);
        self::assertStringContainsString('data-faluss-visibility', $link);
        self::assertStringContainsString('noopener noreferrer nofollow', $link);
        self::assertStringContainsString("if ( 'media_teaser' === \$block['type'] )", $link);
        self::assertStringContainsString('faluss-link-card__media-teaser', $link);
        self::assertStringContainsString("length < 32", $editor);
        foreach (['texture-grain', 'texture-camo', 'avatar-shape-rounded', 'avatar-effect-both', 'wallpaper-cover', 'wallpaper-effect-gradient', 'wallpaper-effect-none', 'links-mode-image-grid', 'social-style-solid-custom'] as $selector) {
            self::assertStringContainsString($selector, $card);
        }
        self::assertStringContainsString('inset: 58% 0 0', $card);
        self::assertStringContainsString('mask-image: none', $card);
        self::assertStringContainsString('--fl-v2-compact-cover-height', $card);
        self::assertStringContainsString('@media (max-width: 480px)', $card);
        self::assertStringContainsString('@media (prefers-reduced-motion: reduce)', $card);
        self::assertStringContainsString('.faluss-link-card--structure-atomic', $card);
        self::assertStringNotContainsString("\n.faluss-link-card {", $card);
        self::assertStringNotContainsString("\n.faluss-link-card__", $card);
        self::assertStringNotContainsString("\n.faluss-link-card .", $card);
        self::assertStringNotContainsString('!important', $card);
        self::assertStringContainsString('.faluss-link-studio[data-faluss-studio="v2"] .faluss-link-studio__topbar', $studio);
        self::assertStringContainsString('position: sticky', $studio);
        self::assertStringContainsString('transition: none', $studio);
    }

    public function testMutationAndUploadSurfaceFailsClosed(): void
    {
        $actions = $this->source('src/MeStudio/StudioActions.php');
        $link = $this->source('src/Link/LegacyLinkService.php');

        foreach (['faluss_me_studio_preview', 'faluss_me_studio_save', 'faluss_me_studio_onboarding', 'faluss_me_studio_upload_avatar', 'faluss_me_studio_upload_cover', 'faluss_me_studio_upload_link_image'] as $action) {
            self::assertStringContainsString("wp_ajax_{$action}", $actions);
            self::assertStringNotContainsString("wp_ajax_nopriv_{$action}", $actions);
        }
        self::assertStringContainsString('array_diff(array_keys($_POST)', $actions);
        self::assertStringContainsString("\$wpdb->query( 'START TRANSACTION' )", $link);
        self::assertStringContainsString("\$wpdb->query( 'ROLLBACK' )", $link);
        self::assertStringContainsString("\$wpdb->query( 'COMMIT' )", $link);
        self::assertStringContainsString("'stale_version'", $link);
        self::assertStringContainsString('8 * 1024 * 1024', $link);
        self::assertStringContainsString('self::owned_image', $link);
    }

    public function testAppsRegistryExtensionsAreDescriptiveAndLocalProvidersAreStrict(): void
    {
        $manifest = $this->source('src/Link/LegacyLinkManifest.php');
        foreach (['me.studio.tab', 'me.studio.block_source', 'me.public.tab', 'me.public.block'] as $binding) {
            self::assertStringContainsString($binding, $manifest);
        }

        $registry = new StudioBlockProviderRegistry();
        $registry->register(new class implements StudioBlockProvider {
            public function descriptor(): array
            {
                return [
                    'placement' => 'me.public.block',
                    'id' => 'future-fans',
                    'visibility' => 'members',
                    'read_model_contract' => ['contract_version' => '1.0.0', 'document_type' => 'faluss.fans.card'],
                    'fallback' => 'placeholder',
                    'actions' => ['fans.open'],
                ];
            }

            public function readModel(): array|\WP_Error
            {
                return [];
            }
        });
        self::assertSame('future-fans', $registry->descriptors()[0]['id']);

        $this->expectException(LogicException::class);
        $registry->register(new class implements StudioBlockProvider {
            public function descriptor(): array
            {
                return ['id' => 'bad', 'placement' => 'php', 'read_model_contract' => [], 'actions' => [], 'visibility' => 'all', 'fallback' => 'hide'];
            }

            public function readModel(): array|\WP_Error
            {
                return [];
            }
        });
    }

    public function testSchemaMigrationIsAdditiveVersionedAndNeverPublicOpportunistic(): void
    {
        $schema = $this->source('src/Link/LegacyLinkSchema.php');

        self::assertStringContainsString("const VERSION = '4'", $schema);
        self::assertStringContainsString("const LEGACY_VERSION = '3'", $schema);
        self::assertStringContainsString('ADD `composition` longtext NULL', $schema);
        self::assertStringContainsString('MODIFY `composition` longtext NOT NULL', $schema);
        self::assertStringContainsString("current_user_can( 'manage_options' )", $schema);
        self::assertStringContainsString('if ( ! $allow_schema_change )', $schema);
        self::assertStringNotContainsString('DROP TABLE', strtoupper($schema));
        self::assertStringNotContainsString('DROP COLUMN', strtoupper($schema));

        $link = $this->source('src/Link/LegacyLinkService.php');
        self::assertStringContainsString("\$storage_column = \$composition_ready ? 'composition' : 'social_links';", $link);
        self::assertStringContainsString("\$payload['presentation']['selected_theme'] = self::system_card_theme()['slug'];", $link);
        self::assertStringContainsString("\$payload = self::normalise_composition( \$payload );", $link);
    }

    public function testClientScriptsProvidePreviewConflictRehydrationKeyboardAndUploads(): void
    {
        $studio = $this->source('assets/me-studio/js/studio-v2.js');
        $onboarding = $this->source('assets/me-studio/js/onboarding-v2.js');

        self::assertStringContainsString("data.action = 'faluss_me_studio_preview'", $studio);
        self::assertStringContainsString("data.context = 'studio'", $studio);
        self::assertStringContainsString('response.httpStatus === 409', $studio);
        self::assertStringContainsString('hydrate(studio, payload.state)', $studio);
        self::assertStringContainsString('setField(root, name, preferences[name])', $studio);
        self::assertStringContainsString('[data-me-studio-social-color]', $studio);
        self::assertStringContainsString('syncSocialColor(root, event.target)', $studio);
        self::assertStringContainsString("action', 'faluss_me_studio_upload_link_image'", $studio);
        self::assertStringContainsString('ArrowLeft|ArrowRight|Home|End', $onboarding);
        self::assertStringContainsString("submit('back')", $onboarding);
        self::assertStringContainsString("submit('skip')", $onboarding);
        self::assertStringContainsString("submit('next')", $onboarding);
        self::assertStringContainsString("data.context = 'onboarding'", $onboarding);
        self::assertStringContainsString('data.aggregate_version = root.dataset.aggregateVersion', $onboarding);
        self::assertStringContainsString('response.httpStatus === 409', $onboarding);
        self::assertStringContainsString('window.location.reload()', $onboarding);
        self::assertStringContainsString('dataset.socialStyleLabel', $onboarding);
        self::assertStringContainsString("scrollIntoView({ block: 'nearest', inline: 'center' })", $onboarding);
    }

    private function provider(bool $throws): StudioProvider
    {
        return new class($throws) implements StudioProvider {
            public function __construct(private readonly bool $throws)
            {
            }

            public function id(): string
            {
                return 'test-studio';
            }

            public function renderStudio(callable $fallback): string
            {
                if ($this->throws) {
                    throw new RuntimeException('closed');
                }

                return 'studio-v2';
            }

            public function renderOnboarding(callable $fallback): string
            {
                if ($this->throws) {
                    throw new RuntimeException('closed');
                }

                return 'onboarding-v2';
            }

            public function renderStudioExtension(array $state): string
            {
                return '';
            }

            public function enqueueCardAssets(): void
            {
            }
        };
    }

    private function source(string $relativePath): string
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/' . $relativePath);
        self::assertIsString($source, $relativePath);

        return $source;
    }
}
