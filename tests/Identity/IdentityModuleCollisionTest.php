<?php

declare(strict_types=1);

namespace Faluss\Platform\Identity;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/WordPressStubs.php';

final class IdentityModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToLoadBesideAnotherIdentityAuthority(): void
    {
        eval('namespace { final class Faluss_Identity_Schema {} }');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('legacy Faluss Identity authority');
        (new IdentityModule())->boot();
    }
}
