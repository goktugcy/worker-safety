<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\Ignore;

use WorkerSafety\Attribute\WorkerSafetyIgnore;

final class LegacyRegistry
{
    // worker-safety-ignore WS001
    public static ?object $handle = null;

    private static array $accepted = []; // worker-safety-ignore WS001,WS008

    #[WorkerSafetyIgnore('WS001', 'WS007')]
    public static ?object $currentUser = null;

    // A rule-specific directive stays precise: WS001 is suppressed here but
    // WS007 still reports the request-scoped name.
    /** @worker-safety-ignore-next-line WS001 */
    public static ?object $tenant = null;

    public static ?object $reported = null;

    public static function fill(object $value): void
    {
        self::$handle = $value;
        self::$accepted[] = $value;
        self::$currentUser = $value;
        self::$tenant = $value;
        self::$reported = $value;
    }
}
