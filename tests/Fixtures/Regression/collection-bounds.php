<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

class SliceIsNotAShrink
{
    private static array $items = [];

    public static function add(string $value): array
    {
        self::$items[] = $value;

        // array_slice() returns a copy; the source keeps every entry.
        return array_slice(self::$items, -10);
    }
}

class PartialBound
{
    private static array $items = [];

    public static function bounded(string $value): void
    {
        self::$items[] = $value;
        array_shift(self::$items);
    }

    public static function unbounded(string $value): void
    {
        self::$items[] = $value;
    }
}

class FixedSlot
{
    private static array $items = [];

    public static function set(string $value): void
    {
        // A literal key always targets the same slot.
        self::$items['last'] = $value;
    }
}

function transient(string $key): array
{
    static $items = [];

    $items[$key] = true;
    unset($items[$key]);

    return $items;
}
