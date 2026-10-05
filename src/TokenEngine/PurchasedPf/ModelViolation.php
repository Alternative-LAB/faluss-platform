<?php

declare(strict_types=1);

namespace Faluss\Platform\TokenEngine\PurchasedPf;

use RuntimeException;

/** Reasons contain no identity, purchase reference or input payload. */
final class ModelViolation extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
