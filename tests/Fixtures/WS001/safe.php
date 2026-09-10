<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS001;

final class Version
{
    public const VERSION = '1.0.0';

    private static string $shortVersion = '1.0';

    public static function version(): string
    {
        return self::VERSION;
    }

    public static function short(): string
    {
        return self::$shortVersion;
    }
}
