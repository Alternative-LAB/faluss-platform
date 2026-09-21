<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use PHPUnit\Framework\TestCase;

final class SubscriptionsCharacterizationTest extends TestCase
{
    public function testHistoricalSourcesAssetsAndLicenseRemainByteIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $legacy = $root . '/src/Subscriptions/Legacy/includes/';
        $files = [
            'class-faluss-subscriptions-admin-notices.php' => '942cb0211100ceb06d18933527101292641a1a7e47a52342ca03437784c129a4',
            'class-faluss-subscriptions-admin.php' => '33c66e15875aa9bac15e56c9bb8faa72c8589ded4774ae4ec47a08891cae436d',
            'class-faluss-subscriptions-audit.php' => '741937a8cf40eb5ad16f32258c3bfdc1f7d44b39bc07afc1c73a71e7a806ce94',
            'class-faluss-subscriptions-billing.php' => 'e00707472eb383886703b5bebc76fb630d3e54a3e00853b5f1c8192728eb3bde',
            'class-faluss-subscriptions-catalog.php' => '7c0791df3cd52f07b70a3b85aecf0a5dcf6e1fe13de10e81082f12f9f0c96e0b',
            'class-faluss-subscriptions-diagnostics.php' => 'd54b20259fe8a430fadf072f6c9e84261ebe56391be13c7f1d36306588eb3b07',
            'class-faluss-subscriptions-entitlements.php' => 'a058c55b637f5dc471b581108bb24375c6013cf93e103ae40a73c7d9b8e54689',
            'class-faluss-subscriptions-notifications.php' => '7381d1cd6b9fa95d0153c6401722b42809baed96986dcda04a72c29f1b240d0d',
            'class-faluss-subscriptions-repository.php' => '08be872a55e593c77f079fd6bfc04a70213cf639d591d56c1882b62f75fd4324',
            'class-faluss-subscriptions-resolver.php' => '549d5b50be428d449690e5da413689f3c80b400a390895812bf110f3afc2cd16',
            'class-faluss-subscriptions-returns.php' => '15c4604352666d9acb68cf31f9de3497eff8751587db4d68f78072e938fc09dc',
            'class-faluss-subscriptions-schema.php' => '63172b2e2930bf0714ef9b19db319c7e50fbeb411fa4c6440db38314889db88e',
            'class-faluss-subscriptions-stripe-config.php' => 'bd0d7d3a8569a12c253b6410cafb14b54f17bfdbfbc80a96e50bcec5ad1c40dd',
            'class-faluss-subscriptions-stripe-sdk.php' => '2bc420cf23a4f012e471ac9bd7b1aeb5e631c8dfb0e9844308e45066385d3134',
            'class-faluss-subscriptions-trials.php' => '82caba8a3ebdda21b975721806b91bc5ec7c6f8bda38b9cd0de52f1f700e9474',
            'class-faluss-subscriptions-webhooks.php' => 'dc2f026bf203cdb7aaeee2175682c5f818692286c04b60019ce8db22164f745e',
        ];

        foreach ($files as $file => $hash) {
            self::assertFileExists($legacy . $file);
            self::assertSame($hash, hash_file('sha256', $legacy . $file), $file);
        }
        self::assertSame(
            '99666a94a56bc932997cc4849d02a9acf565d0b6df449077ebff6c8d8b0e001f',
            hash_file('sha256', $root . '/assets/css/faluss-subscriptions-admin.css')
        );
        self::assertSame(
            '94f42d92c166f90b1af271f69eb2202f7be24f9aa32944d777ab0ff2beee84a0',
            hash_file('sha256', $root . '/licenses/stripe-php-MIT.txt')
        );
    }
}
