<?php

declare(strict_types=1);

namespace Faluss\Platform\Catalog;

use LogicException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CatalogModuleCollisionTest extends TestCase
{
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testRefusesToBootBesideTheHistoricalClass(): void
    {
        class_alias(self::class, 'Faluss_Catalog_Themes');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The legacy catalog is already loaded.');

        (new CatalogModule())->boot();
    }
}
