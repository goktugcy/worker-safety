<?php

declare(strict_types=1);

namespace WorkerSafety\Framework;

use WorkerSafety\Framework\Laravel\LaravelAdapter;
use WorkerSafety\Framework\Symfony\SymfonyAdapter;

/**
 * Holds the framework adapters and picks the one matching the detection result.
 */
final class FrameworkAdapterRegistry
{
    /**
     * @var list<FrameworkAdapter>
     */
    private array $adapters;

    private readonly FrameworkAdapter $fallback;

    /**
     * @param list<FrameworkAdapter>|null $adapters defaults to the shipped adapters
     */
    public function __construct(?array $adapters = null, ?FrameworkAdapter $fallback = null)
    {
        $this->adapters = $adapters ?? [
            new LaravelAdapter(),
            new SymfonyAdapter(),
        ];

        $this->fallback = $fallback ?? new PlainPhpAdapter();
    }

    public function for(DetectedFramework $framework): FrameworkAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($framework)) {
                return $adapter;
            }
        }

        return $this->fallback;
    }

    /**
     * Every adapter, including the plain-PHP fallback.
     *
     * @return list<FrameworkAdapter>
     */
    public function all(): array
    {
        return [...$this->adapters, $this->fallback];
    }
}
