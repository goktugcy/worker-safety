<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

use WorkerSafety\Finding\Location;

/**
 * The analysis-relevant shape of one class-like declaration.
 */
final class ClassShape
{
    /**
     * @param list<string> $interfaces
     * @param array<string, PropertyShape> $staticProperties keyed by property name
     * @param array<string, PropertyShape> $instanceProperties keyed by property name
     * @param array<string, MethodShape> $methods keyed by lowercase method name
     * @param list<string> $traits
     */
    public function __construct(
        public readonly string $name,
        public readonly string $shortName,
        public readonly ClassKind $kind,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly ?string $parent,
        public readonly array $interfaces,
        public readonly array $traits,
        public readonly array $staticProperties,
        public readonly array $instanceProperties,
        public readonly array $methods,
        public readonly Location $location,
    ) {
    }

    public function method(string $name): ?MethodShape
    {
        return $this->methods[strtolower($name)] ?? null;
    }

    public function hasResetMethod(): bool
    {
        foreach ($this->methods as $method) {
            if ($method->looksLikeReset) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<MethodShape>
     */
    public function resetMethods(): array
    {
        return array_values(array_filter(
            $this->methods,
            static fn (MethodShape $method): bool => $method->looksLikeReset,
        ));
    }

    /**
     * Instance properties that can change after construction.
     *
     * A property counts as mutable when it is not readonly and is either
     * publicly writable or assigned somewhere outside the constructor.
     *
     * @return list<PropertyShape>
     */
    public function mutableInstanceProperties(): array
    {
        $mutable = [];

        foreach ($this->instanceProperties as $property) {
            if ($property->isReadonly) {
                continue;
            }

            if ($property->isPublic() || $this->isAssignedOutsideConstructor($property->name)) {
                $mutable[] = $property;
            }
        }

        return $mutable;
    }

    public function isAssignedOutsideConstructor(string $propertyName): bool
    {
        foreach ($this->methods as $method) {
            if ($method->isConstructor()) {
                continue;
            }

            if (in_array($propertyName, $method->assignedInstanceProperties, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the class is a plain data holder with no behaviour that could
     * reset it — the shape of a request context object.
     */
    public function looksImmutable(): bool
    {
        return $this->mutableInstanceProperties() === [];
    }

    public function extendsName(string $suffix): bool
    {
        return $this->parent !== null && str_ends_with($this->parent, $suffix);
    }
}
