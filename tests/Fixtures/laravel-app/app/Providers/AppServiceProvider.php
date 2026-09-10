<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\CurrencyFormatter;
use App\Support\TenantRegistry;
use App\Support\UserContext;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // WS005 + WS006: request-specific mutable state in a singleton.
        $this->app->singleton(UserContext::class);

        // Safe: immutable service.
        $this->app->singleton(CurrencyFormatter::class, fn (): CurrencyFormatter => new CurrencyFormatter('USD'));

        // Safe: per-request lifetime is already correct.
        $this->app->scoped(TenantRegistry::class);
    }

    public function boot(): void
    {
        // Registering listeners in a provider is correct: WS009 must not fire.
        Event::listen('user.updated', fn (): null => null);
    }
}
