<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Regression;

/**
 * Two additions and one removal: net growth of one entry per call.
 */
class NetGrowth
{
    private static array $items = [];

    public static function add(string $value): void
    {
        self::$items[] = $value;
        self::$items[] = $value;
        array_pop(self::$items);
    }
}

/**
 * The removal runs once, the additions run once per iteration.
 */
class GrowthInLoop
{
    private static array $items = [];

    public static function add(array $values): void
    {
        foreach ($values as $value) {
            self::$items[] = $value;
        }

        array_shift(self::$items);
    }
}

/**
 * The removal is unreachable whenever the early return is taken.
 */
class RemovalAfterEarlyReturn
{
    private static array $items = [];

    public static function add(string $value, bool $skip): void
    {
        self::$items[] = $value;

        if ($skip) {
            return;
        }

        array_pop(self::$items);
    }
}

/**
 * The removal is behind a condition that says nothing about the size.
 */
class ConditionalRemoval
{
    private static array $items = [];

    public static function add(string $value, bool $evict): void
    {
        self::$items[] = $value;

        if ($evict) {
            array_pop(self::$items);
        }
    }
}

/**
 * The eviction is guarded by a size check: the LRU idiom, genuinely bounded.
 */
class SizeGuarded
{
    private const LIMIT = 50;

    private static array $items = [];

    public static function put(string $key, string $value): void
    {
        self::$items[$key] = $value;

        if (count(self::$items) > self::LIMIT) {
            array_shift(self::$items);
        }
    }
}

/**
 * An unconditional full reset: nothing survives to the next call.
 */
class FullReset
{
    private static array $items = [];

    public static function rebuild(array $values): void
    {
        self::$items = [];

        foreach ($values as $value) {
            self::$items[] = $value;
        }
    }
}

/**
 * Added and removed under the same key, both unconditional.
 */
class AddThenRemove
{
    private static array $items = [];

    public static function touch(string $key): void
    {
        self::$items[$key] = true;
        unset(self::$items[$key]);
    }
}

/**
 * A count() call that measures something else proves nothing.
 */
class UnrelatedCountGuard
{
    private static array $items = [];

    public static function add(string $value): void
    {
        self::$items[] = $value;

        if (count([]) > 10) {
            array_pop(self::$items);
        }
    }
}

/**
 * The comparison can never be true, so the eviction never runs.
 */
class ImpossibleCountGuard
{
    private static array $items = [];

    public static function add(string $value): void
    {
        self::$items[] = $value;

        if (count(self::$items) < 0) {
            array_pop(self::$items);
        }
    }
}

/**
 * The removed key is never the key that was added.
 */
class UnsetsAnotherKey
{
    private static array $items = [];

    public static function add(string $value): void
    {
        self::$items[] = $value;
        unset(self::$items['never']);
    }
}

/**
 * array_push() adds two entries, array_pop() removes one: net growth.
 */
class PushesTwoPopsOne
{
    private static array $items = [];

    public static function add(string $value): void
    {
        array_push(self::$items, $value, $value);
        array_pop(self::$items);
    }
}

/**
 * The right operand of && only runs sometimes.
 */
class ShortCircuitRemoval
{
    private static array $items = [];

    public static function add(string $value, bool $evict): void
    {
        self::$items[] = $value;
        $evict && array_pop(self::$items);
    }
}
