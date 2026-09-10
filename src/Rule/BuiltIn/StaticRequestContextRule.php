<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\DefaultValueKind;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Ast\Index\SingletonAnalyzer;
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
use WorkerSafety\Support\NameHeuristics;

/**
 * WS007 — process-wide state whose name or type reads as request-specific.
 *
 * This is the one deliberately name-driven rule, so the vocabulary is kept
 * narrow and is neutralised by configuration/infrastructure words: `$currentUser`
 * and `static ?User $u` match, `$userTable`, `$defaultLocale` and
 * `UserRepository` do not. See {@see NameHeuristics}.
 */
final class StaticRequestContextRule extends AbstractRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::STATIC_REQUEST_CONTEXT,
            'Static request/user context',
            'State that identifies the current user, request, session or tenant belongs to a single request. Held in a static property or a function-scoped static, it becomes the identity that the next request sees.',
            Severity::High,
            RuleCategory::StaticState,
            [
                'Resolve the value from the current request instead of caching it statically.',
                'Inject a request-scoped context object (Laravel: a `scoped()` binding) into the classes that need it.',
                'If the static slot must stay, clear it in a terminating middleware or a worker request-lifecycle hook.',
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
        $tokens = $property->requestTokens();

        if ($tokens === []) {
            return null;
        }

        // The singleton holder is WS004's finding, and its type name would
        // otherwise match through the class name.
        if (SingletonAnalyzer::isInstanceHolder($class, $property)) {
            return null;
        }

        if (!$this->isCandidate($property)) {
            return null;
        }

        $writes = $context->index()->writesForProperty($property);
        $mutating = array_values(array_filter($writes, static fn (StateWrite $w): bool => !$w->isClearing()));

        if ($mutating === [] && !$property->isPublic()) {
            return null;
        }

        $severity = $this->severityFor($mutating !== [], $property->isPublic(), $this->hasResetPath($writes));

        return $this->finding(
            $context,
            $property->location,
            sprintf('Static property $%s holds request-specific state.', $property->name),
            $this->explain(
                $class->shortName . '::$' . $property->name,
                $tokens,
                $mutating[0] ?? null,
                $property->isPublic(),
            ),
            $severity,
            new SymbolContext($class->name, null, $property->name),
            $property->snippet,
        );
    }

    private function inspectStaticLocal(StaticLocalVariable $local, ProjectContext $context): ?Finding
    {
        $tokens = NameHeuristics::requestTokens($local->name);

        if ($tokens === []) {
            return null;
        }

        if ($local->default === DefaultValueKind::NonEmptyArray || $local->default === DefaultValueKind::Scalar) {
            return null;
        }

        $mutating = array_values(array_filter($local->writes, static fn (StateWrite $w): bool => !$w->isClearing()));

        if ($mutating === []) {
            return null;
        }

        return $this->finding(
            $context,
            $local->location,
            sprintf('Function-scoped static $%s holds request-specific state.', $local->name),
            sprintf(
                'A `static` variable inside %s is initialised once per worker process, not once per request, so $%s (%s) keeps the value the first request gave it. Function-scoped statics are easy to miss because no class or property is involved.',
                $local->describeScope(),
                $local->name,
                implode(', ', $tokens),
            ),
            $local->hasClearingWrite() ? Severity::High : Severity::Critical,
            new SymbolContext($local->inClass, $local->inFunction, null, $local->name),
            $local->snippet,
        );
    }

    /**
     * Weed out declarations that read as configuration rather than as state.
     */
    private function isCandidate(PropertyShape $property): bool
    {
        // `private static string $userTable = 'users'` and friends.
        if ($property->looksLikeConfiguration()) {
            return false;
        }

        // A populated literal map is a lookup table, not request state.
        if ($property->default === DefaultValueKind::NonEmptyArray) {
            return false;
        }

        return true;
    }

    /**
     * @param list<StateWrite> $writes
     */
    private function hasResetPath(array $writes): bool
    {
        foreach ($writes as $write) {
            if ($write->isClearing() && $write->inResetMethod) {
                return true;
            }
        }

        return false;
    }

    private function severityFor(bool $written, bool $isPublic, bool $hasResetPath): Severity
    {
        $severity = match (true) {
            $written && $isPublic => Severity::Critical,
            $written => Severity::High,
            default => Severity::Medium,
        };

        if (!$hasResetPath) {
            return $severity;
        }

        return match ($severity) {
            Severity::Critical => Severity::High,
            Severity::High => Severity::Medium,
            default => Severity::Low,
        };
    }

    /**
     * @param list<string> $tokens
     */
    private function explain(string $subject, array $tokens, ?StateWrite $write, bool $isPublic): string
    {
        $explanation = sprintf(
            '%s reads as request-specific state (%s) but lives in a static slot, which exists once per worker process. Whatever the last request stored is what the next request will read, so one user can be served another user\'s context.',
            $subject,
            implode(', ', $tokens),
        );

        if ($write instanceof StateWrite && $write->inMethod !== null) {
            $explanation .= sprintf(' Assigned in %s() at %s.', $write->inMethod, (string) $write->location);
        }

        if ($isPublic) {
            $explanation .= ' The property is public, so any code in the application can overwrite it.';
        }

        return $explanation;
    }
}
