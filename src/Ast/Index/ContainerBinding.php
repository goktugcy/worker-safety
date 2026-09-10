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
        public readonly bool $abstractIsClass = true,
        public readonly ?string $excerpt = null,
    ) {
    }

    /**
     * The class whose shape determines the risk: the closure's concrete type
     * when known, otherwise the abstract identifier.
     */
    /**
     * The class whose shape decides the risk. A free-form service id such as
     * `'auth.context'` is not one, so only the concrete type counts there.
     */
    public function resolvedClass(): ?string
    {
        if ($this->concrete !== null) {
            return $this->concrete;
        }

        return $this->abstractIsClass ? $this->abstract : null;
    }

    public function describeTarget(): string
    {
        $target = $this->resolvedClass() ?? $this->abstract ?? 'binding';

        return str_contains($target, '\\')
            ? substr($target, (int) strrpos($target, '\\') + 1)
            : $target;
    }
}
