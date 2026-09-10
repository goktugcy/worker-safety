<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS007;

final class User
{
}

final class Auth
{
    public static ?User $currentUser = null;

    private static ?string $sessionToken = null;

    public static function login(User $user, string $token): void
    {
        self::$currentUser = $user;
        self::$sessionToken = $token;
    }
}
