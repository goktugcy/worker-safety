<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Ast\Index\SingletonAnalyzer;
use WorkerSafety\Ast\Index\StateWrite;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\ProjectContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * WS001 — a static property that is written at runtime.
 *
 * Runs after the whole project has been indexed so that writes performed from
 * another file (`Registry::$items[] = …`) still count, and so that a property
 * which is never written anywhere is not reported as mutable.
 */
final class MutableStaticPropertyRule extends AbstractRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::MUTABLE_STATIC_PROPERTY,
            'Mutable static property',
            'Static properties are bound to the PHP process, not to the request. Under a persistent worker any value written during one request stays visible to every later request served by the same worker. Singleton instance holders are reported by WS004 instead.',
            Severity::High,
            RuleCategory::StaticState,
            [
                'Move the value into a request-scoped service and inject it where it is needed.',
                'If the value really is shared, make it immutable: a constant, a readonly instance property or a value object.',
                'Otherwise reset the property explicitly at the start or the end of every request.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function finishProject(ProjectContext $context): iterable
    {
        foreach ($context->index()->classes() as $class) {
            foreach ($class->staticProperties as $property) {
                $finding = $this->inspect($property, $class, $context);

                if ($finding instanceof Finding) {
                    yield $finding;
                }
            }
        }
    }

    private function inspect(PropertyShape $property, ClassShape $class, ProjectContext $context): ?Finding
    {
        // The singleton instance holder is WS004's territory: reporting it here
        // as well would put two different severities on the same line.
        if (SingletonAnalyzer::isInstanceHolder($class, $property)) {
            return null;
        }

        $writes = $context->index()->writesForProperty($property);

        if ($writes === []) {
            return $this->inspectUnwritten($property, $class, $context);
        }

        $mutating = array_values(array_filter($writes, static fn (StateWrite $w): bool => !$w->isClearing()));

        if ($mutating === []) {
            // Only ever emptied — the property cannot accumulate, but something
            // outside the analyzed sources must be filling it.
            return $this->finding(
                $context,
                $property->location,
                sprintf('Static property $%s is only ever cleared, never assigned in the analyzed paths.', $property->name),
                sprintf(
                    'The reset path exists, but %s is a process-wide slot: whatever fills it (a facade, a trait or code outside the scanned paths) leaks into later requests until the reset runs.',
                    $this->describe($class, $property),
                ),
                Severity::Low,
                new SymbolContext($class->name, null, $property->name),
                $property->snippet,
                null,
                $property->excerpt,
            );
        }

        $hasResetPath = $this->hasResetPath($writes, $class);
        $first = $mutating[0];
        $accumulatorOnly = $this->isAccumulatorOnly($mutating);

        return $this->finding(
            $context,
            $property->location,
            sprintf('Mutable static property $%s may persist between requests.', $property->name),
            $this->explain($class, $property, $first, $hasResetPath, $accumulatorOnly),
            $this->severityFor($hasResetPath, $accumulatorOnly),
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
            $hasResetPath ? $this->resetRemediation() : null,
            $property->excerpt,
        );
    }

    private function inspectUnwritten(PropertyShape $property, ClassShape $class, ProjectContext $context): ?Finding
    {
        // A scalar property holding a literal is configuration, not state.
        if ($property->looksLikeConfiguration()) {
            return null;
        }

        // Nothing in the analyzed sources writes it and nothing outside can:
        // there is no reachable mutation to warn about.
        if (!$property->isPublic()) {
            return null;
        }

        return $this->finding(
            $context,
            $property->location,
            sprintf('Public static property $%s is writable from anywhere and would persist between requests.', $property->name),
            sprintf(
                'No assignment to %s was found in the analyzed paths, but a public static property can be written by any caller — including code outside those paths — and the value would then survive for the lifetime of the worker.',
                $this->describe($class, $property),
            ),
            Severity::Medium,
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
            null,
            $property->excerpt,
        );
    }

    /**
     * @param list<StateWrite> $writes
     */
    private function hasResetPath(array $writes, ClassShape $class): bool
    {
        foreach ($writes as $write) {
            if ($write->isClearing() && $write->inResetMethod) {
                return true;
            }
        }

        return false;
    }

    /**
     * A property that is only ever appended to is a shared cache. That is a
     * real cross-request risk, but a milder one than a slot whose whole value
     * is replaced per request, and WS008 tells the memory side of the story.
     *
     * @param list<StateWrite> $mutating
     */
    private function isAccumulatorOnly(array $mutating): bool
    {
        foreach ($mutating as $write) {
            if (!$write->isGrowth()) {
                return false;
            }
        }

        return $mutating !== [];
    }

    private function severityFor(bool $hasResetPath, bool $accumulatorOnly): Severity
    {
        if ($accumulatorOnly) {
            return $hasResetPath ? Severity::Low : Severity::Medium;
        }

        return $hasResetPath ? Severity::Medium : Severity::High;
    }

    private function explain(
        ClassShape $class,
        PropertyShape $property,
        StateWrite $first,
        bool $hasResetPath,
        bool $accumulatorOnly,
    ): string {
        $where = $first->inMethod !== null
            ? sprintf('%s::%s()', $class->name, $first->inMethod)
            : $first->location->relativePath;

        if ($accumulatorOnly) {
            $explanation = sprintf(
                '%s accumulates entries at runtime (%s, %s). The array is shared by every request the worker serves, so a value written for one request can be read back by the next one.',
                $this->describe($class, $property),
                $where,
                (string) $first->location,
            );
        } else {
            $explanation = sprintf(
                '%s is assigned at runtime (%s, %s) and is never bound to a single request. Under a persistent worker the last value written stays readable by the next request served by the same process.',
                $this->describe($class, $property),
                $where,
                (string) $first->location,
            );
        }

        if ($hasResetPath) {
            $explanation .= ' The class does expose a reset path, so the remaining risk is that the reset is not wired into the worker request lifecycle.';
        }

        return $explanation;
    }

    /**
     * @return list<string>
     */
    private function resetRemediation(): array
    {
        return [
            'Make sure the existing reset method runs on every request, not only on the paths that remember to call it.',
            'Under Laravel Octane, call it from a RequestReceived or RequestTerminated listener. Note that `octane.flush` will not help: it calls forgetInstance() on container bindings and never touches static properties.',
            'Prefer moving the value into a request-scoped service so that no reset is needed at all.',
        ];
    }

    private function describe(ClassShape $class, PropertyShape $property): string
    {
        return $class->shortName . '::$' . $property->name;
    }
}
