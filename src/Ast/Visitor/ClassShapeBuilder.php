<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Visitor;

use WorkerSafety\Ast\Index\ClassKind;
use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\MethodShape;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Finding\Location;

/**
 * Mutable accumulator used while walking one class-like declaration.
 *
 * @internal
 */
final class ClassShapeBuilder
{
    /**
     * @var array<string, PropertyShape>
     */
    private array $staticProperties = [];

    /**
     * @var array<string, PropertyShape>
     */
    private array $instanceProperties = [];

    /**
     * @var array<string, MethodShape>
     */
    private array $methods = [];

    /**
     * @var list<string>
     */
    private array $traits = [];

    /**
     * @param list<string> $interfaces
     */
    public function __construct(
        public readonly string $name,
        public readonly string $shortName,
        public readonly ClassKind $kind,
        public readonly bool $isFinal,
        public readonly bool $isAbstract,
        public readonly ?string $parent,
        public readonly array $interfaces,
        public readonly Location $location,
        public readonly bool $isAnonymous,
    ) {
    }

    public function addProperty(PropertyShape $property): void
    {
        if ($property->isStatic) {
            $this->staticProperties[$property->name] = $property;

            return;
        }

        $this->instanceProperties[$property->name] = $property;
    }

    public function addMethod(MethodShape $method): void
    {
        $this->methods[strtolower($method->name)] = $method;
    }

    /**
     * @param list<string> $traits
     */
    public function addTraits(array $traits): void
    {
        foreach ($traits as $trait) {
            if (!in_array($trait, $this->traits, true)) {
                $this->traits[] = $trait;
            }
        }
    }

    public function build(): ClassShape
    {
        return new ClassShape(
            $this->name,
            $this->shortName,
            $this->kind,
            $this->isFinal,
            $this->isAbstract,
            $this->parent,
            $this->interfaces,
            $this->traits,
            $this->staticProperties,
            $this->instanceProperties,
            $this->methods,
            $this->location,
        );
    }
}
