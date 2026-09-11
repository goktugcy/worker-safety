<?php

declare(strict_types=1);

namespace WorkerSafety\Integration\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Registers `php artisan worker-safety:scan`.
 *
 * Found automatically by Laravel's package discovery through the
 * `extra.laravel.providers` entry in composer.json; see the README for the
 * manual registration a project with discovery disabled needs.
 *
 * Laravel is not a dependency of this package. Nothing in this namespace is
 * autoloaded unless an application registers the provider, so
 * `vendor/bin/worker-safety` behaves identically in a project without Laravel.
 */
final class WorkerSafetyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(
            ArtisanScanCommand::class,
            static fn (Application $app): ArtisanScanCommand => new ArtisanScanCommand($app->basePath()),
        );
    }

    public function boot(): void
    {
        // A scanner has nothing to do during an HTTP request, so the command is
        // only wired up for console runs.
        if (!$this->app->runningInConsole()) {
            return;
        }

        $this->commands([ArtisanScanCommand::class]);
    }
}
