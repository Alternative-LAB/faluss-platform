<?php

declare(strict_types=1);

namespace Faluss\Platform\IdentityClient;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class IdentityClientModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesTheHistoricalClient(): void
    {
        class_alias(self::class, 'Faluss_Identity_Client');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy Identity Client is already loaded.');

        (new IdentityClientModule())->boot();
    }
}
