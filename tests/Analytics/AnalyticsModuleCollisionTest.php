<?php

declare(strict_types=1);

namespace Faluss\Platform\Analytics;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class AnalyticsModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideTheHistoricalAnalyticsPlugin(): void
    {
        eval('namespace { final class Faluss_Analytics_Consumer {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Faluss Analytics plugin');
        (new AnalyticsModule())->boot();
    }
}
