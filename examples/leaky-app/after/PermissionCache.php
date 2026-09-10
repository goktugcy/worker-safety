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
     * @var array<string, object>
     */
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
