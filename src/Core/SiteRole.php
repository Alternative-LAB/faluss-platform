<?php

declare(strict_types=1);

namespace Faluss\Platform\Core;

enum SiteRole: string
{
    case Me = 'me';
    case Hub = 'hub';
    case Fans = 'fans';

    public static function fromValue(mixed $value): ?self
    {
        return is_string($value) ? self::tryFrom($value) : null;
    }
}
