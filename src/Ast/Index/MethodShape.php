<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * A declared method, reduced to the facts the rules care about.
 */
final class MethodShape
{
    /**
     * @param list<string> $assignedStaticProperties
     * @param list<string> $assignedInstanceProperties
     */
    public function __construct(
        public readonly string $name,
        public readonly string $declaringClass,
        public readonly bool $isStatic,
        public readonly string $visibility,
        public readonly ?string $returnType,
        public readonly bool $returnsSelf,
        public readonly bool $instantiatesSelf,
        public readonly bool $looksLikeReset,
        public readonly array $assignedStaticProperties,
        public readonly array $assignedInstanceProperties,
        public readonly Location $location,
    ) {
    }

    public function isConstructor(): bool
    {
        return strtolower($this->name) === '__construct';
    }
}
