<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Fixtures\WS009;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * A service provider is the correct place to register listeners.
 */
final class ReportServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen('report.viewed', ReportListener::class);
    }

    public function register(): void
    {
        $this->app->bind(ReportListener::class);
    }
}

final class ReportListener
{
    public function handle(string $event): void
    {
    }
}
