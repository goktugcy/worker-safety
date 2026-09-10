<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS001;

final class UserContext
{
    public static ?object $currentUser = null;

    private static array $cache = [];

    public static function setUser(object $user): void
    {
        self::$currentUser = $user;
        self::$cache[] = $user;
    }
}
