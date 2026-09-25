<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use PHPUnit\Framework\TestCase;

final class LinkCharacterizationTest extends TestCase
{
    public function testVisualAndInteractionAssetsRemainPinnedToTheAuditedSource(): void
    {
        $target = dirname(__DIR__, 2) . '/assets/link';
        $files = [
            'css/faluss-link-discoveries.css' => 'ef4ff3a1f13f15456d06303694b278461bdbafdbe49bf8bdba57c0ba39872901',
            'css/faluss-link-immersive.css' => '5d0fb6c972663e4df7a7bdd1e6f549d8cfc4bbda90b58ccd4a587c20cf937d5c',
            'css/faluss-link-onboarding.css' => '86a31fa5fcd8f712b465291de5bb0f3def5608f792bf89c8bf012f13af30ac4e',
            'css/faluss-link-reward.css' => '40d9584e2f373e3e63e073e5e472e75fc34e56940bb78b763bfcc477c8e24371',
            'css/faluss-link-studio.css' => 'd605c7c6607d73a7f7927cced4aff9edf15670327c1fc64ff8c6a7a9a255e0cc',
            'css/faluss-link.css' => '7c8cc9833a6bd227381427d4b179f136cda04fd6974458136c0b8de38bbcc45f',
            'js/faluss-link-card.js' => '94cafb8da5c001990dc433bbcdc3bfc201fffd37a3da50c747db8e3ecd5080b6',
            'js/faluss-link-editor.js' => '18a3a4bbdbfc0bf2278c4418bc678fb60d2e0ed412aa6ca8e8d9239c39dc2023',
            'js/faluss-link-immersive.js' => 'cf9b6e9cf7f1c16b91ea53c6da636b4fca252254468e3cfaf4a689c4f14980d2',
            'js/faluss-link-onboarding.js' => '918a6f4c778ec671fd6514bf45944cd60c4925089a7fad26b0e41f4a5a3b34a4',
            'js/faluss-link-reward.js' => '1aa529f7bac8ea5ff0e4bb9ef634e5a4dce105f2b311e7fed5250e798404683a',
            'images/faluss-onboarding-device.png' => '9f35fe8c4092a6fc0ccd2375f494710bac7fb4796514d02c5d732b0bba8f1c47',
            'images/faluss-onboarding-header-back.svg' => '9836e048a4fd6eca220f2c3f7b870609d8209243d3f1c3cd47a6736c9c9aa38a',
            'images/faluss-onboarding-header-logo.png' => '24d638ffbfd2503f082071065afc668f03d5932b650d861b77febc67b4c6377b',
            'images/faluss-onboarding-upload-plus.svg' => 'dc39ae0b5b7505625bcf137f09744db3b2b7aa0716421405066f83114e832256',
            'images/studio-ecosystem/faluss-studio-date.png' => '21ee86bbe26342a2ca4a6f979a0052b3cf24a9ff0fd15c1c54c3638e8c113ed8',
            'images/studio-ecosystem/faluss-studio-hub.png' => '5a534c15e5254c6982df0739152c3784dbb2edd7c7972976e32e306fc55b017b',
            'images/studio-ecosystem/faluss-studio-pro.png' => '33c828517cb2b9de0599ea3df6d0c735266bcbbc63212c743d1ef4e81f2467ff',
        ];

        foreach ($files as $file => $expectedHash) {
            self::assertFileExists($target . '/' . $file);
            self::assertSame($expectedHash, $this->sourceHash($target . '/' . $file), $file);
        }
    }

    public function testLegacyRuntimeUsesOnlyThePlatformBoundaryAdapters(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Link/LegacyLinkService.php');
        self::assertIsString($source);
        self::assertStringContainsString('LinkIdentityAdapter::', $source);
        self::assertStringContainsString('LinkCatalogAdapter::', $source);
        self::assertStringContainsString('LinkTokenEngineConnectorAdapter::', $source);
        self::assertStringNotContainsString('Faluss_Identity_', $source);
        self::assertStringNotContainsString('Faluss_Catalog_Themes', $source);
        self::assertStringNotContainsString('Token_Engine_Connector_Service', $source);
        self::assertStringContainsString('publishedProfilesByFalussIds', $source);
        self::assertStringNotContainsString('get_public_profiles_table', $source);
        self::assertStringNotContainsString('INNER JOIN', $source);
    }

    public function testSupportingCompatibilityFacadesRemainByteIdentical(): void
    {
        $target = dirname(__DIR__, 2) . '/src/Link';
        $files = [
            'LegacyLinkAdmin.php' => '4baa9135fd4b568231cf64a01432004c7083651427dd200ae18ff0de2f2a846d',
            'LegacyLinkEventsCatalog.php' => '68fcc8d19da8c67c8dbd5d0085a04f46789740e4f93cf0563ec5aa171a886ee1',
            'LegacyLinkEventsRuntime.php' => 'ddb578a7e66d0fcf67fd600484fe27b1bb91b4136c2436f66fb9cef44ee0843d',
            'LegacyLinkManifest.php' => '4da011366c5a15387dae6e6c199c5d8268624db5732716b4f6e46ee4a91273c3',
            'LegacyLinkSchema.php' => '7393884b2f8cde76daab6616e98dd4ebf8e62cb1d4b59d851d380afbc3b534eb',
            'LegacyLinkWidgets.php' => '6c84ac7a657ce6a978549b3b383bf3e975c55c15fce4f3fb9a832c16da36c83b',
        ];

        foreach ($files as $file => $expectedHash) {
            self::assertSame($expectedHash, $this->sourceHash($target . '/' . $file), $file);
        }
    }

    public function testStudioMutationContractRemainsClosedAndTransactional(): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/src/Link/LegacyLinkService.php');
        self::assertIsString($source);
        self::assertStringContainsString("array_diff( array_keys( (array) \$post )", $source);
        self::assertStringContainsString("\$wpdb->query( 'START TRANSACTION' )", $source);
        self::assertStringContainsString("\$wpdb->query( 'ROLLBACK' )", $source);
        self::assertStringContainsString("\$wpdb->query( 'COMMIT' )", $source);
        self::assertStringContainsString("'stale_version'", $source);
        self::assertStringContainsString("'legacy_blocks_not_initialized'", $source);
        self::assertStringNotContainsString('wp_ajax_nopriv_', $source);
    }

    private function sourceHash(string $path): string
    {
        $contents = file_get_contents($path);
        self::assertNotFalse($contents, $path);

        return hash('sha256', str_ends_with($path, '.png') ? $contents : str_replace("\r\n", "\n", $contents));
    }
}
