<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS007;

final class TenantContext
{
    private static ?string $tenant = null;

    public static function set(string $tenant): void
    {
        self::$tenant = $tenant;
    }

    /**
     * An explicit release path lowers the severity but does not remove the risk.
     */
    public static function reset(): void
    {
        self::$tenant = null;
    }
}

function currentRequestId(): string
{
    static $requestId = null;

    if ($requestId === null) {
        $requestId = bin2hex(random_bytes(8));
    }

    return $requestId;
}
