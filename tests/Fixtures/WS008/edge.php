<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS008;

final class ResolvedPaths
{
    private static array $resolved = [];

    public static function resolve(string $path): string
    {
        return self::$resolved[$path] ??= realpath($path) ?: $path;
    }

    /**
     * A release path exists, but it has to be called from somewhere.
     */
    public static function flush(): void
    {
        self::$resolved = [];
    }
}

function memoize(string $key, callable $factory): mixed
{
    static $memo = [];

    return $memo[$key] ??= $factory();
}
