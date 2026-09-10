<?php

declare(strict_types=1);

namespace Example\Before;

/**
 * Works fine under PHP-FPM. Under a worker, $currentUser is whatever the
 * previous request left behind.
 */
final class UserContext
{
    public static ?object $currentUser = null;

    /**
     * Grows forever: nothing ever removes an entry.
     *
     * @var array<string, object>
     */
    private static array $permissionCache = [];

    public static function login(object $user, string $token): void
    {
        self::$currentUser = $user;
        self::$permissionCache[$token] = $user;

        // Process-wide, and never restored when the request ends.
        putenv('CURRENT_TENANT=' . $user->tenant);
    }

    public static function current(): ?object
    {
        return self::$currentUser;
    }
}
