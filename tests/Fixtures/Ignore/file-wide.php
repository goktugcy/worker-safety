<?php

declare(strict_types=1);

// worker-safety-ignore-file
// Everything in this legacy file is accepted for now.

namespace WorkerSafety\Tests\Fixtures\Ignore;

final class WholeFileIgnored
{
    public static ?object $user = null;

    private static array $cache = [];

    public static function put(object $user): void
    {
        self::$user = $user;
        self::$cache[] = $user;
    }
}
