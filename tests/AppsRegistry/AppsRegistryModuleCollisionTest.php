<?php

declare(strict_types=1);

namespace Faluss\Platform\AppsRegistry;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class AppsRegistryModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTheHistoricalRegistry(): void
    {
        class_alias(self::class, 'Faluss_Apps_Registry');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy Apps Registry is already loaded.');

        (new AppsRegistryModule())->boot();
    }
}
