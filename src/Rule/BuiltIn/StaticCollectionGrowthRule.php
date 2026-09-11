<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Application\ApplicationInfo;
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
 * Three outcomes, based on what can actually be proven:
 *
 *  - no clearing operation anywhere              → high (unbounded)
 *  - a removal exists but bounds nothing that
 *    can be verified from the code alone         → medium
 *  - an unconditional reset of the whole
 *    collection in the growing function          → not reported
 *
 * Only that last shape is a proof. Certifying an eviction would mean knowing
 * that the removal runs on every path through its guard, that it removes at
 * least as much as was added, and that the limit is finite; matching a removal
 * to an addition would mean knowing their order and the runtime value of the
 * key. None of that follows from the shape of the code, so an unproven removal
 * lowers the severity and sharpens the message rather than silencing the
 * finding.
 */
final class StaticCollectionGrowthRule extends AbstractRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::STATIC_COLLECTION_GROWTH,
            'Static collection growth',
            'A static array that is appended to on every request never releases its entries. Under PHP-FPM the engine tears down the request context and the memory goes away; under a persistent worker the same context stays alive, so the array accumulates until the worker is recycled or the memory limit is hit.',
            Severity::Medium,
            RuleCategory::MemoryRetention,
            [
                'Bound the collection: cap its size, or key it so that entries are reused instead of added.',
                'Clear it at the end of every request. Under Laravel Octane that means a RequestTerminated listener that calls your reset: `octane.flush` only forgets container bindings, it does not empty static properties.',
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

        [$severity, $growth, $release] = $verdict;

        return $this->finding(
            $context,
            $property->location,
            match ($release) {
                'unproven' => sprintf(
                    'Static collection $%s grows and the removals beside it are not a provable bound.',
                    $property->name,
                ),
                'reset' => sprintf('Static collection $%s is only released by an explicit reset.', $property->name),
                default => sprintf('Static collection $%s grows without any release path.', $property->name),
            },
            $this->explain($class->shortName . '::$' . $property->name, $growth, $release, $class),
            $severity,
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
            null,
            $property->excerpt,
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

        [$severity, $growth, $release] = $verdict;

        return $this->finding(
            $context,
            $local->location,
            $release === 'unproven'
                ? sprintf(
                    'Function-scoped static collection $%s grows and the removals beside it are not a provable bound.',
                    $local->name,
                )
                : sprintf('Function-scoped static collection $%s grows across requests.', $local->name),
            sprintf(
                'The `static` array in %s is created once per worker process, so every entry added while serving a request is still there for the next one. First growth at %s (%s).%s',
                $local->describeScope(),
                (string) $growth->location,
                $growth->kind->value,
                $release === 'unproven'
                    ? sprintf(
                        ' There are removals in the same function, but nothing in the code proves they bound it. Confirm the behaviour, then silence this finding with `// %s WS008` if the trade-off is deliberate.',
                        ApplicationInfo::IGNORE_MARKER,
                    )
                    : '',
            ),
            $severity,
            new SymbolContext($local->inClass, $local->inFunction, null, $local->name),
            $local->snippet,
            null,
            $local->excerpt,
        );
    }

    /**
     * @param list<StateWrite> $writes
     *
     * @return array{0: Severity, 1: StateWrite, 2: string}|null severity, first
     *                                                           unbounded write,
     *                                                           and why
     */
    private function judge(array $writes): ?array
    {
        // A keyed write whose every dimension is a fixed key targets a fixed
        // slot, so it cannot grow the collection without bound.
        $growth = array_values(array_filter($writes, static fn (StateWrite $w): bool => $w->growsUnbounded()));

        if ($growth === []) {
            return null;
        }

        $clearing = array_values(array_filter($writes, static fn (StateWrite $w): bool => $w->isClearing()));

        if ($clearing === []) {
            return [Severity::High, $growth[0], 'none'];
        }

        $unbounded = array_values(array_filter(
            $growth,
            fn (StateWrite $w): bool => !$this->scopeIsBounded(self::scopeOf($w), $clearing),
        ));

        if ($unbounded === []) {
            return null;
        }

        // A removal in the growing function is evidence of intent, not a proof
        // of a bound, so it lowers the severity rather than removing the
        // finding. So does a reset method that something has to call.
        foreach ($clearing as $write) {
            if (self::scopeOf($write) === self::scopeOf($unbounded[0])) {
                return [Severity::Medium, $unbounded[0], 'unproven'];
            }
        }

        foreach ($clearing as $write) {
            if ($write->inResetMethod) {
                return [Severity::Medium, $unbounded[0], 'reset'];
            }
        }

        return [Severity::High, $unbounded[0], 'none'];
    }

    /**
     * Whether the growth in one function is provably bounded.
     *
     * @param list<StateWrite> $clearing
     */
    private function scopeIsBounded(string $scope, array $clearing): bool
    {
        foreach (self::inScope($clearing, $scope) as $write) {
            if ($write->kind->isFullRelease() && $write->guaranteed) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<StateWrite> $writes
     *
     * @return list<StateWrite>
     */
    private static function inScope(array $writes, string $scope): array
    {
        return array_values(array_filter(
            $writes,
            static fn (StateWrite $w): bool => self::scopeOf($w) === $scope,
        ));
    }

    /**
     * Identity of the function a write happens in, qualified by its class so
     * that two same-named methods on different classes never cancel out.
     */
    private static function scopeOf(StateWrite $write): string
    {
        return strtolower(($write->inClass ?? '') . '::' . ($write->inMethod ?? '{file}'));
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

    private function explain(string $subject, StateWrite $growth, string $release, ClassShape $class): string
    {
        $where = sprintf('(%s at %s)', $growth->kind->value, (string) $growth->location);

        if ($release === 'unproven') {
            return sprintf(
                '%s is appended to at runtime %s. There are removals in the same function, but nothing in the code proves they bound it: that would mean knowing the removal runs on every path, that it takes out at least as much as was added, and that any size limit is finite. If the eviction is correct this is bounded; if it is not, the array grows for the whole life of the worker. Confirm it, then silence this finding with `// %s WS008` if the trade-off is deliberate.',
                $subject,
                $where,
                ApplicationInfo::IGNORE_MARKER,
            );
        }

        if ($release === 'reset') {
            $names = [];

            foreach ($class->resetMethods() as $method) {
                $names[] = $method->name . '()';
            }

            return sprintf(
                '%s is appended to at runtime %s. Entries are only removed by %s, so the collection keeps growing for as long as nothing calls it — and under a persistent worker that is the whole life of the process. Each request therefore leaves memory behind that is never reclaimed until the worker restarts.',
                $subject,
                $where,
                $names === [] ? 'an explicit reset' : implode(', ', $names),
            );
        }

        return sprintf(
            '%s is appended to at runtime %s and the analyzer found no operation that removes entries again. Each request therefore leaves memory behind that is never reclaimed until the worker restarts.',
            $subject,
            $where,
        );
    }
}
