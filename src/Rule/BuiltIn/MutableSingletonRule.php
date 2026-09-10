<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use WorkerSafety\Ast\Index\ClassShape;
use WorkerSafety\Ast\Index\MethodShape;
use WorkerSafety\Ast\Index\PropertyShape;
use WorkerSafety\Ast\Index\SingletonAnalyzer;
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
 * WS004 — the classic self-instantiating singleton.
 *
 * The shape is recognised structurally: a static property that can hold an
 * instance of the declaring class, plus a static method of that class which
 * assigns `new self`/`new static` to it.
 *
 * An immutable singleton is reported at a much lower severity than one that
 * carries mutable instance state, because sharing an immutable object between
 * requests is usually intentional and safe.
 */
final class MutableSingletonRule extends AbstractRule
{
    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::MUTABLE_SINGLETON,
            'Mutable singleton',
            'A singleton is created once and then reused. Under a persistent worker "once" means once per worker process, so every request shares the same instance and everything written into it.',
            Severity::Medium,
            RuleCategory::Singleton,
            [
                'Register the class in your container instead, so its lifetime is controlled by the framework.',
                'Keep the shared instance immutable: readonly properties set in the constructor only.',
                'If it must hold per-request values, resolve a fresh instance per request (Laravel: `scoped()`).',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function finishProject(ProjectContext $context): iterable
    {
        foreach ($context->index()->classes() as $class) {
            $finding = $this->inspect($class, $context);

            if ($finding instanceof Finding) {
                yield $finding;
            }
        }
    }

    private function inspect(ClassShape $class, ProjectContext $context): ?Finding
    {
        $holder = SingletonAnalyzer::instanceHolder($class);

        if (!$holder instanceof PropertyShape) {
            return null;
        }

        $mutable = $class->mutableInstanceProperties();

        if ($mutable === []) {
            return $this->finding(
                $context,
                $holder->location,
                sprintf('Singleton %s is shared by every request the worker handles.', $class->shortName),
                sprintf(
                    '%s instantiates itself into a static property, so exactly one instance exists per worker process rather than per request. No mutable instance state was found, which makes this low risk today, but any property added later is immediately shared across requests.',
                    $class->shortName,
                ),
                Severity::Low,
                new SymbolContext($class->name, null, $holder->name),
                $holder->snippet,
            );
        }

        $names = array_map(static fn (PropertyShape $p): string => '$' . $p->name, $mutable);
        $requestScoped = array_values(array_filter(
            $mutable,
            static fn (PropertyShape $p): bool => $p->looksRequestScoped(),
        ));

        return $this->finding(
            $context,
            $holder->location,
            sprintf(
                'Singleton %s carries mutable instance state (%s).',
                $class->shortName,
                implode(', ', array_slice($names, 0, 4)) . (count($names) > 4 ? ', …' : ''),
            ),
            $this->explain($class, $mutable, $requestScoped),
            Severity::High,
            new SymbolContext($class->name, null, $holder->name),
            $holder->snippet,
            $requestScoped !== [] ? $this->requestScopedRemediation() : null,
        );
    }

    /**
     * @param list<PropertyShape> $mutable
     * @param list<PropertyShape> $requestScoped
     */
    private function explain(ClassShape $class, array $mutable, array $requestScoped): string
    {
        $explanation = sprintf(
            '%s creates itself once per worker process and keeps %d mutable instance %s. Anything a request writes into the instance is still there for the next request handled by the same worker.',
            $class->shortName,
            count($mutable),
            count($mutable) === 1 ? 'property' : 'properties',
        );

        if ($requestScoped !== []) {
            $tokens = [];

            foreach ($requestScoped as $property) {
                foreach ($property->requestTokens() as $token) {
                    if (!in_array($token, $tokens, true)) {
                        $tokens[] = $token;
                    }
                }
            }

            $explanation .= sprintf(
                ' $%s reads as request-specific state (%s), which makes cross-request leakage likely rather than theoretical.',
                $requestScoped[0]->name,
                implode(', ', $tokens),
            );
        }

        if ($class->hasResetMethod()) {
            $names = array_map(
                static fn (MethodShape $m): string => $m->name . '()',
                $class->resetMethods(),
            );

            $explanation .= sprintf(
                ' The class exposes %s, so a release path exists — make sure it runs on every request.',
                implode(', ', $names),
            );
        }

        return $explanation;
    }

    /**
     * @return list<string>
     */
    private function requestScopedRemediation(): array
    {
        return [
            'Do not keep request-specific values in a singleton: pass them as arguments or resolve them per request.',
            'Laravel: replace the singleton with a `scoped()` binding so the container discards it between requests.',
            'FrankenPHP/RoadRunner/Swoole: build the object inside the request handler instead of caching it statically.',
        ];
    }
}
