<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class TokenEngineModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideTheHistoricalCore(): void
    {
        eval('namespace { final class Token_Engine_Schema {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Token Engine');
        (new TokenEngineModule())->boot();
    }
}
