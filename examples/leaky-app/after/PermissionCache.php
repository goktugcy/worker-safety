<?php

declare(strict_types=1);

namespace Example\After;

/**
 * Still a cache, but a bounded one: growth and eviction live in the same
 * method, so the size cannot run away.
 */
final class PermissionCache
{
    private const LIMIT = 500;

    /**
     * The eviction below caps this at LIMIT entries, but that is a property of
     * the code's behaviour rather than of its shape, so WS008 reports it at
     * MEDIUM instead of certifying a bound it cannot prove. Having reviewed it,
     * we accept the trade-off here — which is exactly what the inline ignore is
     * for. WS001 is deliberately left in place: the cache is still shared
     * between requests.
     *
     * @var array<string, object>
     */
    // worker-safety-ignore WS008
    private static array $entries = [];

    public static function remember(string $token, object $user): void
    {
        self::$entries[$token] = $user;

        if (count(self::$entries) > self::LIMIT) {
            array_shift(self::$entries);
        }
    }

    public static function flush(): void
    {
        self::$entries = [];
    }
}
