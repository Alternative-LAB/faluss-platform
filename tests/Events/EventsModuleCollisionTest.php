<?php

declare(strict_types=1);

namespace Faluss\Platform\Events;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class EventsModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideTheHistoricalEventsPlugin(): void
    {
        eval('namespace { final class Faluss_Events_Engine {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Faluss Events plugin');
        (new EventsModule())->boot();
    }
}
