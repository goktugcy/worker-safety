<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Application\ApplicationInfo;
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
        $conditional = $this->onlyConditionalAssignments($mutating);

        return $this->finding(
            $context,
            $property->location,
            $conditional
                ? sprintf(
                    'Static property $%s is initialized with `??=`, so a stored value is reused by later requests.',
                    $property->name,
                )
                : sprintf('Mutable static property $%s may persist between requests.', $property->name),
            $conditional
                ? $this->explainConditionalInitialization($class, $property, $first)
                : $this->explain($class, $property, $first, $hasResetPath, $accumulatorOnly),
            $this->severityFor($hasResetPath, $accumulatorOnly),
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
            match (true) {
                $conditional => $this->conditionalInitializationRemediation(),
                $hasResetPath => $this->resetRemediation(),
                default => null,
            },
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

    /**
     * True when every assignment to the property writes only into an empty slot.
     *
     * Worth telling apart from a slot that is overwritten per request, but it
     * is a fact about the assignments and nothing more. In particular it does
     * not establish that the initializer runs once — see
     * explainConditionalInitialization() — and it does not change the severity.
     *
     * Clearing writes are excluded by the caller, so a property that is also
     * reset somewhere still qualifies. That is why the wording talks about
     * reuse "until something resets or replaces it" rather than about a value
     * that is computed once.
     *
     * @param list<StateWrite> $mutating
     */
    private function onlyConditionalAssignments(array $mutating): bool
    {
        foreach ($mutating as $write) {
            if (!$write->kind->isConditionalAssignment()) {
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
     * What `??=` does establish, and what it does not.
     *
     * It establishes that the assignment writes only into an empty slot, so a
     * value already stored there is reused. It does NOT establish that the
     * initializer runs once: an initializer that yields null leaves the slot
     * empty and is evaluated again on the next pass, and any reset — including
     * one in code that was not scanned — re-opens it. The wording stays inside
     * that limit.
     *
     * It also does not guess whether the reuse is a problem. Separating a
     * memoized constant from a memoized request value would mean knowing what
     * every call in the initializer returns, and `Hash::make('password')`,
     * `request('tenant')` and `auth()->user()` are the same shape to a parser.
     * So the finding states both outcomes and points at the one thing a reader
     * can check in a second: where the value comes from.
     */
    private function explainConditionalInitialization(
        ClassShape $class,
        PropertyShape $property,
        StateWrite $first,
    ): string {
        $where = $first->inMethod !== null
            ? sprintf('%s::%s()', $class->name, $first->inMethod)
            : $first->location->relativePath;

        return sprintf(
            '%s is assigned with `??=` (%s, %s), which writes only while the slot is null or unset. Once a non-null '
            . 'value is stored there, later requests served by the same worker reuse it instead of recomputing, until '
            . 'something resets or replaces it. How long that lasts is not determined here: an initializer that '
            . 'returns null leaves the slot empty and runs again, and a reset elsewhere — including in code outside '
            . 'the scanned paths — re-opens it. What the reuse costs depends on where the value comes from, which '
            . 'cannot be read off the syntax: if every input is fixed in the source — the hash of a constant test '
            . 'password, say — reusing it is deliberate. If any input comes from the request, the session or the '
            . 'authenticated user, then whichever request stored the value hands it to the requests that follow. '
            . 'Check the initializer, then silence this finding with `// %s WS001` if the value really is constant.',
            $this->describe($class, $property),
            $where,
            (string) $first->location,
            ApplicationInfo::IGNORE_MARKER,
        );
    }

    /**
     * @return list<string>
     */
    private function conditionalInitializationRemediation(): array
    {
        return [
            'Follow the initializer to its inputs. Anything reaching it from the request, the session, the container or the authenticated user makes the stored value a cross-request leak from whichever request stored it.',
            'If the value is genuinely fixed, say so where it is written: `// ' . ApplicationInfo::IGNORE_MARKER . ' WS001` documents the decision and keeps the build green, and a baseline entry does the same for a whole set of them.',
            'If it is not fixed, compute it per request instead — a request-scoped binding, or a local variable passed where it is needed.',
        ];
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
