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
        // Prefer the concrete type from the binding closure, then the abstract.
        foreach ([$binding->concrete, $binding->abstract] as $candidate) {
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

    private function hasScopedBinding(ProjectIndex $index, ContainerBinding $binding): bool
    {
        foreach ([$binding->concrete, $binding->abstract] as $candidate) {
            if ($candidate === null) {
                continue;
            }

            foreach ($index->bindingsFor($candidate) as $other) {
                if ($other->scoped) {
                    return true;
                }
            }
        }

        return false;
    }
}
