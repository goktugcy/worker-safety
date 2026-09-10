<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS010;

final class LegacyExporter
{
    public function export(string $payload): void
    {
        register_shutdown_function(static function () use ($payload): void {
            // Intended as "run when this request finishes".
        });

        if (PHP_SAPI === 'fpm-fcgi') {
            fastcgi_finish_request();
        }

        gc_disable();

        exit(0);
    }
}
