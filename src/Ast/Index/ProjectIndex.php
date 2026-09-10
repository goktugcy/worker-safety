<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

/**
 * Whole-project semantic model built during the single AST pass.
 *
 * Rules that need cross-file knowledge (a container binding in a service
 * provider plus the bound class declared in another file) read from here in
 * their `finishProject()` hook, which is what lets the scan parse every file
 * exactly once.
 */
final class ProjectIndex
{
    /**
     * @var array<string, ClassShape> keyed by lowercase FQCN
     */
    private array $classes = [];

    /**
     * @var array<string, list<string>> lowercase short name => list of lowercase FQCNs
     */
    private array $shortNames = [];

    /**
     * @var list<ContainerBinding>
     */
    private array $bindings = [];

    /**
     * Alias => abstract, exactly as the container stores it.
     *
     * @var array<string, string>
     */
    private array $aliases = [];

    /**
     * @var array<string, list<StateWrite>> keyed by PropertyShape::makeWriteKey()
     */
    private array $staticWrites = [];

    /**
     * @var list<StaticLocalVariable>
     */
    private array $staticLocals = [];

    public function addClass(ClassShape $class): void
    {
        $key = self::key($class->name);

        $this->classes[$key] = $class;

        $short = strtolower($class->shortName);
        $existing = $this->shortNames[$short] ?? [];

        if (!in_array($key, $existing, true)) {
            $existing[] = $key;
            $this->shortNames[$short] = $existing;
        }
    }

    public function addBinding(ContainerBinding $binding): void
    {
        $this->bindings[] = $binding;
    }

    public function addAlias(ContainerAlias $alias): void
    {
        $this->aliases[$alias->alias] = $alias->abstract;
    }

    /**
     * Follow the alias chain the way Container::getAlias() does.
     *
     * Container keys are plain array keys: they are matched byte for byte, so
     * `'Shared'` and `'shared'` are two different services. This is the key
     * identity, deliberately distinct from PHP class-name identity, which is
     * case-insensitive and used by {@see findClass()}.
     */
    public function resolveContainerKey(string $key): string
    {
        $seen = [];

        while (isset($this->aliases[$key]) && !isset($seen[$key])) {
            $seen[$key] = true;
            $key = $this->aliases[$key];
        }

        return $key;
    }

    public function addStaticWrite(string $class, string $property, StateWrite $write): void
    {
        $key = PropertyShape::makeWriteKey($class, $property);
        $this->staticWrites[$key][] = $write;
    }

    public function addStaticLocal(StaticLocalVariable $variable): void
    {
        $this->staticLocals[] = $variable;
    }

    public function class(string $fqcn): ?ClassShape
    {
        return $this->classes[self::key($fqcn)] ?? null;
    }

    /**
     * Resolve by FQCN, falling back to an unambiguous short-name match only for
     * names that carry no namespace of their own.
     *
     * A qualified name that is not declared in the analyzed paths must resolve
     * to nothing: matching `External\Context` against a local `Local\Context`
     * would attribute one class's risk to another.
     */
    public function findClass(string $name): ?ClassShape
    {
        $exact = $this->class($name);

        if ($exact instanceof ClassShape) {
            return $exact;
        }

        if (str_contains(ltrim($name, '\\'), '\\')) {
            return null;
        }

        $short = strtolower($name);

        $candidates = $this->shortNames[$short] ?? [];

        if (count($candidates) === 1) {
            return $this->classes[$candidates[0]] ?? null;
        }

        return null;
    }

    /**
     * @return list<ClassShape>
     */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    /**
     * @return list<ContainerBinding>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * Every binding that targets the given class, across the whole project.
     *
     * @return list<ContainerBinding>
     */
    public function bindingsFor(string $class): array
    {
        $needle = self::key($class);

        return array_values(array_filter($this->bindings, static function (ContainerBinding $binding) use ($needle): bool {
            foreach ([$binding->abstract, $binding->concrete] as $candidate) {
                if ($candidate !== null && strtolower(ltrim($candidate, '\\')) === $needle) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * @return list<StaticLocalVariable>
     */
    public function staticLocals(): array
    {
        return $this->staticLocals;
    }

    /**
     * @return list<StateWrite>
     */
    public function writesFor(string $class, string $property): array
    {
        return $this->staticWrites[PropertyShape::makeWriteKey($class, $property)] ?? [];
    }

    /**
     * @return list<StateWrite>
     */
    public function writesForProperty(PropertyShape $property): array
    {
        return $this->staticWrites[$property->writeKey()] ?? [];
    }

    public function classCount(): int
    {
        return count($this->classes);
    }

    private static function key(string $fqcn): string
    {
        return strtolower(ltrim($fqcn, '\\'));
    }
}
