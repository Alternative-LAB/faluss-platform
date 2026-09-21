<?php

declare(strict_types=1);

namespace Faluss\Platform\Portal;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class PortalModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTheHistoricalPortal(): void
    {
        class_alias(self::class, 'Faluss_Portal');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy Portal is already loaded.');

        (new PortalModule())->boot();
    }
}
