<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use PhpParser\Node;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\Location;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;

/**
 * Convenience base class: no-op lifecycle hooks plus a finding factory that
 * fills in the rule metadata and the applicable runtimes.
 */
abstract class AbstractRule implements Rule
{
    /**
     * @return list<class-string<Node>>
     */
    public function nodeTypes(): array
    {
        return [];
    }

    public function beginFile(RuleContext $context): void
    {
    }

    /**
     * @return iterable<Finding>
     */
    public function enterNode(Node $node, RuleContext $context): iterable
    {
        return [];
    }

    /**
     * @return iterable<Finding>
     */
    public function finishFile(RuleContext $context): iterable
    {
        return [];
    }

    /**
     * @return iterable<Finding>
     */
    public function finishProject(ProjectContext $context): iterable
    {
        return [];
    }

    /**
     * @param list<string>|null $remediation overrides the rule default
     */
    protected function finding(
        ReportingContext $context,
        Location $location,
        string $message,
        ?string $details = null,
        ?Severity $severity = null,
        ?SymbolContext $symbol = null,
        ?string $snippet = null,
        ?array $remediation = null,
    ): Finding {
        $definition = $this->definition();

        return new Finding(
            $definition->id,
            $definition->title,
            $severity ?? $definition->defaultSeverity,
            $definition->category,
            $location,
            $message,
            $details,
            $remediation ?? $definition->remediation,
            $symbol ?? new SymbolContext(),
            $snippet,
            $context->runtimes()->intersect($definition->runtimes),
            $context->framework()->findingLabel(),
        );
    }
}
