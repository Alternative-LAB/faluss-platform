<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngineConnector;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class TokenEngineConnectorModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToBootBesideTheHistoricalService(): void
    {
        class_alias(self::class, 'Token_Engine_Connector_Service');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy Token Engine Connector is already loaded.');

        (new TokenEngineConnectorModule())->boot();
    }
}
