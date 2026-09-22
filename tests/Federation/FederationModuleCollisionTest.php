<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class FederationModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideTheHistoricalFederationPlugin(): void
    {
        eval('namespace { final class Faluss_Federation_Crypto {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Faluss Federation plugin');
        (new FederationModule())->boot();
    }
}
