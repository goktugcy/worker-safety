<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * A `static $x = …;` declaration inside a function or method.
 *
 * Function-scoped statics survive for the whole worker lifetime exactly like
 * static properties do, so they get the same treatment.
 */
final class StaticLocalVariable
{
    /**
     * @param list<StateWrite> $writes
     */
    public function __construct(
        public readonly string $name,
        public readonly ?string $inClass,
        public readonly ?string $inFunction,
        public readonly DefaultValueKind $default,
        public readonly Location $location,
        public readonly ?string $snippet,
        public readonly array $writes,
    ) {
    }

    public function describeScope(): string
    {
        if ($this->inClass !== null && $this->inFunction !== null) {
            return $this->inClass . '::' . $this->inFunction . '()';
        }

        if ($this->inFunction !== null) {
            return $this->inFunction . '()';
        }

        return 'file scope';
    }

    /**
     * @return list<StateWrite>
     */
    public function growthWrites(): array
    {
        return array_values(array_filter($this->writes, static fn (StateWrite $w): bool => $w->isGrowth()));
    }

    public function hasClearingWrite(): bool
    {
        foreach ($this->writes as $write) {
            if ($write->isClearing()) {
                return true;
            }
        }

        return false;
    }
}
