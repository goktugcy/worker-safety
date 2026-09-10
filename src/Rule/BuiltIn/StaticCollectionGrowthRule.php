<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\DefaultValueKind;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Ast\Index\StateWrite;
use WorkerSafety\Ast\Index\StaticLocalVariable;
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
 * WS008 — a static collection that only ever grows.
 *
 * Three outcomes, based on where the release path is:
 *
 *  - no clearing operation anywhere                       → high (unbounded)
 *  - cleared only from a reset-style method               → medium (release
 *    path exists but something has to call it every request)
 *  - cleared in the same method that grows it (LRU/bound) → not reported
 */
final class StaticCollectionGrowthRule extends AbstractRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::STATIC_COLLECTION_GROWTH,
            'Static collection growth',
            'A static array that is appended to on every request never releases its entries. Under PHP-FPM the process exits and the memory goes away; under a persistent worker it accumulates until the worker is recycled or the memory limit is hit.',
            Severity::Medium,
            RuleCategory::MemoryRetention,
            [
                'Bound the collection: cap its size, or key it so that entries are reused instead of added.',
                'Clear it at the end of every request (Laravel Octane: a RequestTerminated listener or `octane.flush`).',
                'Move the cache into a real cache backend with a TTL, so eviction is somebody else\'s problem.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function finishProject(ProjectContext $context): iterable
    {
        foreach ($context->index()->classes() as $class) {
            foreach ($class->staticProperties as $property) {
                $finding = $this->inspectProperty($property, $class, $context);

                if ($finding instanceof Finding) {
                    yield $finding;
                }
            }
        }

        foreach ($context->index()->staticLocals() as $local) {
            $finding = $this->inspectStaticLocal($local, $context);

            if ($finding instanceof Finding) {
                yield $finding;
            }
        }
    }

    private function inspectProperty(PropertyShape $property, ClassShape $class, ProjectContext $context): ?Finding
    {
        if (!$this->isCollection($property)) {
            return null;
        }

        $writes = $context->index()->writesForProperty($property);
        $verdict = $this->judge($writes);

        if ($verdict === null) {
            return null;
        }

        [$severity, $growth] = $verdict;

        return $this->finding(
            $context,
            $property->location,
            $severity === Severity::High
                ? sprintf('Static collection $%s grows without any release path.', $property->name)
                : sprintf('Static collection $%s is only released by an explicit reset.', $property->name),
            $this->explain($class->shortName . '::$' . $property->name, $growth, $severity, $class),
            $severity,
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
        );
    }

    private function inspectStaticLocal(StaticLocalVariable $local, ProjectContext $context): ?Finding
    {
        if ($local->default !== DefaultValueKind::EmptyArray && $local->default !== DefaultValueKind::None) {
            return null;
        }

        $verdict = $this->judge($local->writes);

        if ($verdict === null) {
            return null;
        }

        [$severity, $growth] = $verdict;

        return $this->finding(
            $context,
            $local->location,
            sprintf('Function-scoped static collection $%s grows across requests.', $local->name),
            sprintf(
                'The `static` array in %s is created once per worker process, so every entry added while serving a request is still there for the next one. First growth at %s (%s).',
                $local->describeScope(),
                (string) $growth->location,
                $growth->kind->value,
            ),
            $severity,
            new SymbolContext($local->inClass, $local->inFunction, null, $local->name),
            $local->snippet,
        );
    }

    /**
     * @param list<StateWrite> $writes
     *
     * @return array{0: Severity, 1: StateWrite}|null
     */
    private function judge(array $writes): ?array
    {
        $growth = array_values(array_filter($writes, static fn (StateWrite $w): bool => $w->isGrowth()));

        if ($growth === []) {
            return null;
        }

        $clearing = array_values(array_filter($writes, static fn (StateWrite $w): bool => $w->isClearing()));

        if ($clearing === []) {
            return [Severity::High, $growth[0]];
        }

        // A clear that happens in the very method that grows the collection is
        // a size bound (LRU eviction, per-call reset) rather than a leak.
        $growthMethods = [];

        foreach ($growth as $write) {
            if ($write->inMethod !== null) {
                $growthMethods[] = strtolower($write->inMethod);
            }
        }

        foreach ($clearing as $write) {
            if ($write->inMethod !== null && in_array(strtolower($write->inMethod), $growthMethods, true)) {
                return null;
            }
        }

        return [Severity::Medium, $growth[0]];
    }

    /**
     * Only report things that can actually accumulate entries.
     */
    private function isCollection(PropertyShape $property): bool
    {
        if ($property->typeIsScalarOnly) {
            return false;
        }

        return $property->typeIsArray
            || $property->typeString === null
            || $property->default === DefaultValueKind::EmptyArray
            || $property->default === DefaultValueKind::NonEmptyArray;
    }

    private function explain(string $subject, StateWrite $growth, Severity $severity, ClassShape $class): string
    {
        $explanation = sprintf(
            '%s is appended to at runtime (%s at %s) and the analyzer found no operation that removes entries again.',
            $subject,
            $growth->kind->value,
            (string) $growth->location,
        );

        if ($severity === Severity::Medium) {
            $names = [];

            foreach ($class->resetMethods() as $method) {
                $names[] = $method->name . '()';
            }

            $explanation = sprintf(
                '%s is appended to at runtime (%s at %s). Entries are only removed by %s, so the collection keeps growing for as long as nothing calls it — and under a persistent worker that is the whole life of the process.',
                $subject,
                $growth->kind->value,
                (string) $growth->location,
                $names === [] ? 'an explicit reset' : implode(', ', $names),
            );
        }

        return $explanation . ' Each request therefore leaves memory behind that is never reclaimed until the worker restarts.';
    }
}
