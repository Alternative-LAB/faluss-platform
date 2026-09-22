<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use PHPUnit\Framework\TestCase;

final class FederationCharacterizationTest extends TestCase
{
    public function testHistoricalRuntimeSchemasAndContractsRemainContentIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $files = [
            'src/Federation/Legacy/faluss-federation.php' => 'eda4ce18e590f7f9611b3468b55af2623afb39953055f218b9b681a2f7373fed',
            'src/Federation/Legacy/includes/class-faluss-federation-admin.php' => '6bb8c5dd176c59630cbb78351c1576a3bb8a1850397bf8ae46ac40949f403666',
            'src/Federation/Legacy/includes/class-faluss-federation-client.php' => 'dd26b0932e435ba403f69f45c7f3830ca1584602e7e29abb37dc63ea6a9cf84d',
            'src/Federation/Legacy/includes/class-faluss-federation-crypto.php' => 'ae4d5cd099ec9069c378c5a153c7458ffd88053b6eabf661e75f10c2f75b7177',
            'src/Federation/Legacy/includes/class-faluss-federation-policy.php' => 'efe6f42c3f7c67a30a21f57695cfed26dd45f78f28691ceeda4bc6f7db83c8f2',
            'src/Federation/Legacy/includes/class-faluss-federation-providers.php' => '85169f5d3ece13e89947690256008d4c1954ac8c6884572729f699daaef35f49',
            'src/Federation/Legacy/includes/class-faluss-federation-schema.php' => '9b5c2fb67dd013b432a725f4ef3521313165f32e1f46cdb2bfa594ba8ef05f5f',
            'src/Federation/Legacy/includes/class-faluss-federation-server.php' => 'fe67e7812368e61d8a0b6271679c9893a61e5ff545988eeea0429afba22700f2',
            'contracts/faluss-federation-request.schema.json' => '2bacd090cd3648de8846892096a6517b7cde214da8651200a3f1b77dd5b6b9b4',
            'contracts/faluss-federation-response.schema.json' => '988d41f2d4d8730d9b804a71b4518a1869ca34d14a9fb12d3464dc9d2608cc65',
            'docs/FALUSS_FEDERATION.md' => 'aaa9a07629b532f5f37177ab688a6c2bbfbdd54317cbd2dee3b7bca4c8b83b47',
            'docs/FALUSS_FEDERATION_CONTRACT.md' => '213fc8ec9c7bfaa860ffa282ecb9f92c6cf0dc8c47400cf4b84cbb105575e2da',
        ];

        foreach ($files as $file => $hash) {
            $contents = file_get_contents($root . '/' . $file);
            self::assertNotFalse($contents, $file);
            self::assertSame($hash, hash('sha256', str_replace("\r\n", "\n", $contents)), $file);
        }
    }
}
