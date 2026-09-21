<?php

declare(strict_types=1);

namespace Faluss\Platform\Subscriptions;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class SubscriptionsModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideTheHistoricalPlugin(): void
    {
        eval('namespace { final class Faluss_Subscriptions_Schema {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Faluss Subscriptions');
        (new SubscriptionsModule())->boot();
    }
}
