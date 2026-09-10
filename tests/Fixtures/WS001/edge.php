<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS001;

/**
 * A static slot with an explicit release path: still a risk, but a lower one.
 */
final class ResettableRegistry
{
    private static ?object $handle = null;

    public static function set(object $handle): void
    {
        self::$handle = $handle;
    }

    public static function reset(): void
    {
        self::$handle = null;
    }
}

/**
 * Immutable shared state: no finding expected.
 */
final class Limits
{
    public const MAX = 100;

    private static int $configuredMax = self::MAX;

    public static function max(): int
    {
        return self::$configuredMax;
    }
}
