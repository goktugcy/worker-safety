<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\ParseError;

final class Healthy
{
    public static ?object $alsoLeaked = null;

    public static function set(object $value): void
    {
        self::$alsoLeaked = $value;
    }
}
