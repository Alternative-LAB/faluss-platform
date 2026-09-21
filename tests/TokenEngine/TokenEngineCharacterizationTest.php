<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use PHPUnit\Framework\TestCase;

final class TokenEngineCharacterizationTest extends TestCase
{
    public function testHistoricalCoreAndAdminAssetsRemainByteIdentical(): void
    {
        $root = dirname(__DIR__, 2);
        $legacy = $root . '/src/TokenEngine/Legacy/includes/';
        $files = [
            'class-token-engine-admin.php' => 'd07838786693c5bfbb1b6ca422c3096666a13aae0e111369bd375b1596bab195',
            'class-token-engine-connector-access.php' => '901d4464290aa7345feb63a75a04d273241476ac25eeca837be3a58f22d61188',
            'class-token-engine-entitlements.php' => '3626ee9af220efaafa5a79028291b31e106cc41dcf587550a073f7c36a99f3a5',
            'class-token-engine-points-service.php' => '17bcf34819cd7de662ec61fb706fd3691ca9d2f608093d4961259a0f175d5281',
            'class-token-engine-schema.php' => '509f761fbe141a00548b4bcd47f047ef1e1e1dba77d4b87cdfeabd2579163e9b',
            'class-token-engine-service.php' => 'd29540f648b05267e679a175315614ba6a3688a221159d289fc5a998da1cf68d',
        ];
        foreach ($files as $file => $hash) {
            self::assertSame($hash, hash_file('sha256', $legacy . $file), $file);
        }
        self::assertSame(
            '510dd2928d7cbe66e43a503f0d5c8d7a81e235fa7bcb5084d8842c8423990a2e',
            hash_file('sha256', $root . '/assets/css/token-engine-admin.css')
        );
        self::assertSame(
            '0d2e978a58b61cb9db23672620c38d24026a8ce40ea06c1c27f1acfc92fe7384',
            hash_file('sha256', $root . '/assets/js/token-engine-admin.js')
        );
    }

    public function testAppendOnlyAndPrivateContractsRemainPresent(): void
    {
        $root = dirname(__DIR__, 2) . '/src/TokenEngine/Legacy/includes/';
        $schema = file_get_contents($root . 'class-token-engine-schema.php');
        $service = file_get_contents($root . 'class-token-engine-service.php');
        $points = file_get_contents($root . 'class-token-engine-points-service.php');
        $access = file_get_contents($root . 'class-token-engine-connector-access.php');
        $entitlements = file_get_contents($root . 'class-token-engine-entitlements.php');
        self::assertIsString($schema);
        self::assertIsString($service);
        self::assertIsString($points);
        self::assertIsString($access);
        self::assertIsString($entitlements);
        foreach (['token_engine_ledger', 'token_engine_pf_ledger', 'ENGINE=InnoDB', 'pf_compensates_entry_unique'] as $needle) {
            self::assertStringContainsString($needle, $schema);
        }
        foreach (['START TRANSACTION', 'FOR UPDATE', 'insufficient_balance', 'idempotency_key'] as $needle) {
            self::assertStringContainsString($needle, $service . $points);
        }
        self::assertSame(9, substr_count($access, 'register_rest_route'));
        self::assertStringNotContainsString('wp_ajax_nopriv', $access . $service . $points);
        self::assertStringContainsString('create_manual_grant', $entitlements);
        self::assertStringContainsString('revoke_grant', $entitlements);
    }
}
