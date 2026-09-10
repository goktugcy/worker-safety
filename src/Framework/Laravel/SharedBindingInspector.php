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
     * The container flushes scoped instances by the abstract they are
     * registered under, so only a scoped registration of *the same key* makes
     * this binding safe.
     *
     * Keys are compared the way the container compares them — as plain array
     * keys, byte for byte, after following any alias chain. `'Shared'` and
     * `'shared'` are two different services, so a scoped `'shared'` does not
     * flush a singleton `'Shared'`. This is deliberately not PHP class-name
     * matching, which is case-insensitive; see ProjectIndex::findClass().
     */
    private function hasScopedBinding(ProjectIndex $index, ContainerBinding $binding): bool
    {
        if ($binding->abstract === null) {
            return false;
        }

        $needle = $index->resolveContainerKey($binding->abstract);

        foreach ($index->bindings() as $other) {
            if (!$other->scoped || $other->abstract === null) {
                continue;
            }

            if ($index->resolveContainerKey($other->abstract) === $needle) {
                return true;
            }
        }

        return false;
    }
}
