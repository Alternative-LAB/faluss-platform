<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TokenEngineContractTest extends TestCase
{
    public function testUnavailableCoreFailsClosed(): void
    {
        self::assertSame(['state' => 'unavailable'], TokenEngineContract::hubDailyStatus('invalid'));
        self::assertSame(['state' => 'unavailable'], TokenEngineContract::claimHubDaily('invalid'));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testOnlyTheFixedHubRewardAndServerProofReachTheCore(): void
    {
        define('TOKEN_ENGINE_VERSION', '0.4.1');
        eval('namespace { final class Token_Engine_Schema { public const VERSION = 5; } final class Token_Engine_Points_Service { public static array $statusArgs = []; public static array $claimArgs = []; public static function daily_status(...$args): array { self::$statusArgs=$args; return ["state"=>"claimable","amount_pf"=>20]; } public static function claim_hub_daily(...$args): array { self::$claimArgs=$args; return ["state"=>"claimed","amount_pf"=>20]; } } }');
        $falussId = '11111111-1111-4111-8111-111111111111';

        self::assertSame('claimable', TokenEngineContract::hubDailyStatus($falussId)['state']);
        self::assertSame('claimed', TokenEngineContract::claimHubDaily($falussId)['state']);
        self::assertSame(
            [$falussId, 'faluss-hub', 'hub.daily_accrual', ['owner' => 'faluss-hub', 'identity_active' => true]],
            \Token_Engine_Points_Service::$statusArgs
        );
        self::assertSame(
            [$falussId, ['owner' => 'faluss-hub', 'identity_active' => true]],
            \Token_Engine_Points_Service::$claimArgs
        );
    }
}
