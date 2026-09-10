<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS008;

final class QueryLog
{
    private static array $entries = [];

    private static array $byHash = [];

    public static function record(string $sql): void
    {
        self::$entries[] = $sql;
        self::$byHash[md5($sql)] = $sql;
    }
}
