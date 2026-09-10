<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * A service-container registration discovered in the source.
 *
 * Framework agnostic on purpose: the Laravel collector fills it in today, a
 * Symfony collector could fill in the same object tomorrow.
 */
final class ContainerBinding
{
    public function __construct(
        public readonly string $method,
        public readonly ?string $abstract,
        public readonly ?string $concrete,
        public readonly bool $shared,
        public readonly bool $scoped,
        public readonly Location $location,
        public readonly ?string $snippet = null,
        public readonly ?string $inClass = null,
        public readonly ?string $inMethod = null,
    ) {
    }

    /**
     * The class whose shape determines the risk: the closure's concrete type
     * when known, otherwise the abstract identifier.
     */
    public function resolvedClass(): ?string
    {
        return $this->concrete ?? $this->abstract;
    }

    public function describeTarget(): string
    {
        $target = $this->resolvedClass() ?? $this->abstract ?? 'binding';

        return str_contains($target, '\\')
            ? substr($target, (int) strrpos($target, '\\') + 1)
            : $target;
    }
}
