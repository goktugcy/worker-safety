<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

/**
 * A fixed outer key does not bound what is stored under it.
 */
class NestedBucket
{
    private static array $items = [];

    public static function add(string $value): void
    {
        self::$items['bucket'][] = $value;
    }
}

class NestedKeyed
{
    private static array $items = [];

    public static function add(string $key, string $value): void
    {
        self::$items['bucket'][$key] = $value;
    }
}

/**
 * Every dimension is fixed, so this really is one slot.
 */
class FixedPath
{
    private static array $items = [];

    public static function set(string $value): void
    {
        self::$items['a']['b'] = $value;
    }
}

function nestedStaticLocal(string $value): array
{
    static $items = [];

    $items['bucket'][] = $value;

    return $items;
}
