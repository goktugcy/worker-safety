<?php

declare(strict_types=1);

namespace Example\After;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // `scoped()` gives every request its own instance and discards it when
        // the request ends.
        $this->app->scoped(UserContext::class);

        // Immutable, so sharing one instance across requests is correct.
        $this->app->singleton(Config::class, static fn (): Config => new Config('EUR'));
    }

    public function boot(): void
    {
        // Registered once while the worker boots, which is where it belongs.
        Event::listen('report.viewed', static fn (): null => null);
    }
}
