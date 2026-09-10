<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS003;

final class TenantSwitcher
{
    public function switch(string $tenant): void
    {
        putenv('TENANT=' . $tenant);
        $_ENV['TENANT'] = $tenant;
        $_SERVER['TENANT'] = $tenant;
        ini_set('memory_limit', '512M');
        date_default_timezone_set('Europe/Istanbul');
    }
}
