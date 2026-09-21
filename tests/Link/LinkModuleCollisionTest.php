<?php

declare(strict_types=1);

namespace Faluss\Platform\Link;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LinkModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTheHistoricalLinkPlugin(): void
    {
        class_alias(self::class, 'Faluss_Link');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy Faluss Link is already loaded.');

        (new LinkModule())->boot();
    }
}
