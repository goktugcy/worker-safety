<?php

declare(strict_types=1);

namespace WorkerSafety\Framework\Laravel\Rule;

use WorkerSafety\Ast\Index\PropertyShape;
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
 * WS005 — a class registered as a container singleton that carries mutable state.
 *
 * Reported at the binding, because that is where the fix goes. The finding is
 * only produced when the bound class is declared inside the analyzed paths:
 * without the class shape there is nothing to be confident about.
 */
final class ContainerSingletonMutableStateRule extends AbstractRule
{
    public function __construct(private readonly SharedBindingInspector $inspector = new SharedBindingInspector())
    {
    }

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::LARAVEL_SINGLETON_MUTABLE_STATE,
            'Laravel container singleton with mutable state',
            'A `singleton()` binding is resolved once and then reused. Octane keeps the container between requests, so the same instance — and everything written into it — is handed to every request the worker serves.',
            Severity::High,
            RuleCategory::ContainerBinding,
            [
                'Use `$this->app->scoped()` instead of `singleton()`: Octane discards scoped instances between requests.',
                'Or make the service immutable and pass the per-request values in as method arguments.',
                'If the binding must stay a singleton, reset its state in a RequestReceived listener.',
            ],
            RuntimeTargetSet::all(),
            Framework::Laravel->value,
        );
    }

    public function finishProject(ProjectContext $context): iterable
    {
        foreach ($this->inspector->inspect($context->index()) as $inspection) {
            yield $this->report($inspection, $context);
        }
    }

    private function report(SharedBindingInspection $inspection, ProjectContext $context): Finding
    {
        $binding = $inspection->binding;
        $class = $inspection->class;
        $requestScoped = $inspection->looksRequestScoped();

        return $this->finding(
            $context,
            $binding->location,
            $requestScoped
                ? sprintf(
                    'Laravel singleton %s contains potentially request-specific mutable state (%s).',
                    $class->shortName,
                    $inspection->describeProperties(),
                )
                : sprintf(
                    'Laravel singleton %s contains mutable state (%s).',
                    $class->shortName,
                    $inspection->describeProperties(),
                ),
            $this->explain($inspection),
            $requestScoped ? Severity::High : Severity::Medium,
            new SymbolContext(
                $class->name,
                $binding->inMethod,
                $inspection->mutableProperties[0]->name ?? null,
            ),
            $binding->snippet,
        );
    }

    private function explain(SharedBindingInspection $inspection): string
    {
        $binding = $inspection->binding;
        $class = $inspection->class;

        $explanation = sprintf(
            '%s is registered with %s(), so the container builds it once and hands the same instance to every request. %s declares %d mutable instance %s (%s) at %s, which means values written while serving one request are still set for the next one.',
            $class->shortName,
            $binding->method,
            $class->shortName,
            count($inspection->mutableProperties),
            count($inspection->mutableProperties) === 1 ? 'property' : 'properties',
            $inspection->describeProperties(),
            (string) $class->location,
        );

        if ($inspection->looksRequestScoped()) {
            $explanation .= sprintf(
                ' The property names read as request-specific state (%s), so this is a concrete cross-request leak rather than a theoretical one: consider a scoped binding.',
                implode(', ', $inspection->requestTokens()),
            );
        }

        $writable = array_values(array_filter(
            $inspection->mutableProperties,
            static fn (PropertyShape $property): bool => $property->isPublic(),
        ));

        if ($writable !== []) {
            $explanation .= sprintf(
                ' $%s is public, so any caller holding the shared instance can change it.',
                $writable[0]->name,
            );
        }

        return $explanation;
    }
}
