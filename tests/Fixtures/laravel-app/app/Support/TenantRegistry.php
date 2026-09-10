<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Bound as `scoped()`, so the container gives every request its own instance:
 * the container rules must stay quiet about it.
 */
final class TenantRegistry
{
    public ?string $tenantId = null;

    /**
     * @var array<string, string>
     */
    public array $resolved = [];
}
