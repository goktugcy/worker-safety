<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\ContainerBinding;
use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Ast\Index\PropertyShape;

/**
 * Correlates shared container bindings with the declaration of the bound class.
 *
 * This is the "best effort" data-flow the container rules are built on: the
 * binding says the object is created once, the class shape says whether that
 * object can change afterwards. A binding whose class is not declared inside
 * the analyzed paths is skipped rather than guessed at.
 */
final class SharedBindingInspector
{
    /**
     * @return list<SharedBindingInspection>
     */
    public function inspect(ProjectIndex $index): array
    {
        $inspections = [];

        foreach ($index->bindings() as $binding) {
            if (!$binding->shared || $binding->scoped) {
                continue;
            }

            $class = $this->resolveClass($index, $binding);

            if (!$class instanceof ClassShape) {
                continue;
            }

            // A class that is also registered as `scoped()` somewhere has
            // already been given a per-request lifetime.
            if ($this->hasScopedBinding($index, $binding)) {
                continue;
            }

            $mutable = $class->mutableInstanceProperties();

            if ($mutable === []) {
                continue;
            }

            $requestScoped = array_values(array_filter(
                $mutable,
                static fn (PropertyShape $property): bool => $property->looksRequestScoped(),
            ));

            $inspections[] = new SharedBindingInspection($binding, $class, $mutable, $requestScoped);
        }

        return $inspections;
    }

    private function resolveClass(ProjectIndex $index, ContainerBinding $binding): ?ClassShape
    {
        // Prefer the concrete type from the binding closure, then the abstract
        // — but only when the abstract actually names a class.
        $candidates = [$binding->concrete];

        if ($binding->abstractIsClass) {
            $candidates[] = $binding->abstract;
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null) {
                continue;
            }

            $class = $index->findClass($candidate);

            if ($class instanceof ClassShape && $class->instanceProperties !== []) {
                return $class;
            }

            if ($class instanceof ClassShape) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The container flushes scoped instances by the exact key they were
     * registered under, so only a scoped registration of that same key makes
     * this binding safe.
     *
     * `forgetScopedInstances()` does a plain `unset($this->instances[$scoped])`
     * with no alias resolution, and `bind()` deletes `$this->aliases[$abstract]`
     * for the key it registers — so `alias(Ctx::class, 'a')` followed by
     * `scoped('a')` leaves the `Ctx::class` singleton untouched. Aliases are
     * therefore deliberately *not* followed here: inferring a shared lifetime
     * from one would hide a real singleton. Keys are compared byte for byte,
     * the way array keys are, which is separate from PHP class-name identity
     * (case-insensitive; see ProjectIndex::findClass()).
     */
    private function hasScopedBinding(ProjectIndex $index, ContainerBinding $binding): bool
    {
        if ($binding->abstract === null) {
            return false;
        }

        foreach ($index->bindings() as $other) {
            if (!$other->scoped || $other->abstract === null) {
                continue;
            }

            if ($other->abstract === $binding->abstract) {
                return true;
            }
        }

        return false;
    }
}
