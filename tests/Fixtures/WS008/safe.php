<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS008;

final class BoundedCache
{
    private const LIMIT = 50;

    private static array $items = [];

    /**
     * Growth and eviction live in the same method, so the size is bounded.
     */
    public static function put(string $key, string $value): void
    {
        self::$items[$key] = $value;

        if (count(self::$items) > self::LIMIT) {
            array_shift(self::$items);
        }
    }
}

final class Constants
{
    private static array $labels = ['a' => 'A'];

    public static function label(string $key): string
    {
        return self::$labels[$key] ?? '';
    }
}
