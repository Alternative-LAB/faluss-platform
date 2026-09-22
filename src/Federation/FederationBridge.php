<?php

declare(strict_types=1);

namespace Faluss\Platform\Federation;

use LogicException;

/** Narrow dynamic bridge shared by Platform consumers and the legacy facade. */
final class FederationBridge
{
    public static function isCallable(string $class, string $method): bool
    {
        return class_exists($class) && is_callable([$class, $method]);
    }

    public static function invoke(string $class, string $method, mixed ...$arguments): mixed
    {
        $callback = [$class, $method];
        if (!is_callable($callback)) {
            throw new LogicException('Federation runtime callable is unavailable.');
        }

        return $callback(...$arguments);
    }
}
