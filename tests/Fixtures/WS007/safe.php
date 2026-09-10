<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS007;

final class UserQuery
{
    /**
     * Configuration, not state: neutralising vocabulary plus a literal default.
     */
    private static string $userTable = 'users';

    private static string $defaultLocale = 'en';

    private static array $userRoleLabels = ['admin' => 'Administrator', 'guest' => 'Guest'];

    public function table(): string
    {
        return self::$userTable;
    }

    public function locale(): string
    {
        return self::$defaultLocale;
    }

    public function label(string $role): string
    {
        return self::$userRoleLabels[$role] ?? $role;
    }
}

final class UserRepository
{
    public function find(int $id): ?User
    {
        return null;
    }
}
