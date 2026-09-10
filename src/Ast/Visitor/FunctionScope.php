<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use WorkerSafety\Ast\Index\DefaultValueKind;
use WorkerSafety\Ast\Index\StateWrite;
use WorkerSafety\Finding\Location;

/**
 * Mutable accumulator for one function-like scope.
 *
 * @internal
 */
final class FunctionScope
{
    /**
     * @var list<string>
     */
    public array $assignedStaticProperties = [];

    /**
     * @var list<string>
     */
    public array $assignedInstanceProperties = [];

    public bool $instantiatesSelf = false;

    /**
     * Static locals declared directly in this scope.
     *
     * @var array<string, array{location: Location, snippet: string|null, default: DefaultValueKind, writes: list<StateWrite>}>
     */
    public array $staticLocals = [];

    public function __construct(
        public readonly ?string $name,
        public readonly bool $isMethod,
        public readonly bool $isStatic,
        public readonly ?ClassShapeBuilder $class,
        public readonly ?self $methodScope,
    ) {
    }

    public function recordStaticPropertyAssignment(string $property): void
    {
        $target = $this->methodScope ?? $this;

        if (!in_array($property, $target->assignedStaticProperties, true)) {
            $target->assignedStaticProperties[] = $property;
        }
    }

    public function recordInstancePropertyAssignment(string $property): void
    {
        $target = $this->methodScope ?? $this;

        if (!in_array($property, $target->assignedInstanceProperties, true)) {
            $target->assignedInstanceProperties[] = $property;
        }
    }

    public function markInstantiatesSelf(): void
    {
        $target = $this->methodScope ?? $this;
        $target->instantiatesSelf = true;
    }

    public function declareStaticLocal(string $name, Location $location, ?string $snippet, DefaultValueKind $default): void
    {
        $this->staticLocals[$name] = [
            'location' => $location,
            'snippet' => $snippet,
            'default' => $default,
            'writes' => [],
        ];
    }

    public function hasStaticLocal(string $name): bool
    {
        return isset($this->staticLocals[$name]);
    }

    public function recordStaticLocalWrite(string $name, StateWrite $write): void
    {
        if (!isset($this->staticLocals[$name])) {
            return;
        }

        $this->staticLocals[$name]['writes'][] = $write;
    }
}
