<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel\Rule;

use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\Laravel\SharedBindingInspection;
use WorkerSafety\Framework\Laravel\SharedBindingInspector;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\ProjectContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * WS006 — a singleton binding that should be a scoped binding.
 *
 * WS005 reports the risk; this rule is the concrete migration suggestion, and
 * it only fires when the state actually reads as request-specific. Turning it
 * off leaves WS005's risk reporting intact.
 */
final class ScopedBindingCandidateRule extends AbstractRule
{
    public function __construct(private readonly SharedBindingInspector $inspector = new SharedBindingInspector())
    {
    }

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::LARAVEL_SCOPED_CANDIDATE,
            'Laravel scoped binding candidate',
            'Laravel offers a third lifetime between `bind()` (new instance every resolve) and `singleton()` (one instance forever): `scoped()`. A scoped instance is created once per request and discarded when the request ends, which is exactly what a request context object needs.',
            Severity::Medium,
            RuleCategory::ContainerBinding,
            [
                'Check how long the instance is meant to live and who resolves it, then decide. `scoped()` is the right lifetime for per-request state, but it is a lifetime change, not a drop-in.',
                'Look for consumers that outlive a request: a singleton (or any longer-lived object) that takes this class in its constructor captures the first instance and keeps it, so the binding changes while that consumer does not.',
                'Check what the instance carries across resolves today — a warmed cache, a connection, a lazily built index. Under `scoped()` that work happens once per request instead of once per worker.',
                'Once the lifetime is right, Octane flushes scoped instances between requests, so no manual reset listener is needed for them.',
            ],
            RuntimeTargetSet::all(),
            Framework::Laravel->value,
        );
    }

    public function finishProject(ProjectContext $context): iterable
    {
        foreach ($this->inspector->inspect($context->index()) as $inspection) {
            if (!$inspection->looksRequestScoped()) {
                continue;
            }

            yield $this->reportCandidate($inspection, $context);
        }
    }

    private function reportCandidate(SharedBindingInspection $inspection, ProjectContext $context): Finding
    {
        $binding = $inspection->binding;
        $class = $inspection->class;

        return $this->finding(
            $context,
            $binding->location,
            sprintf('%s is a candidate for a scoped binding instead of %s().', $class->shortName, $binding->method),
            sprintf(
                '%s holds %s, which reads as per-request state (%s). Registering it with `scoped()` would make the container build one instance per request and discard it afterwards, so the state could not cross a request boundary. That is a change of lifetime rather than a rename: review what else resolves %s, and whether anything holding on to it lives longer than a request, before switching `$this->app->%s(%s::class, …)` to `$this->app->scoped(%s::class, …)`.',
                $class->shortName,
                $inspection->describeProperties(),
                implode(', ', $inspection->requestTokens()),
                $class->shortName,
                $binding->method,
                $class->shortName,
                $class->shortName,
            ),
            Severity::Medium,
            new SymbolContext($class->name, $binding->inMethod),
            $binding->snippet,
            null,
            $binding->excerpt,
        );
    }
}
