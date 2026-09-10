<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use PhpParser\NodeTraverser;
use WorkerSafety\Ast\AstParser;
use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Ast\Visitor\IndexCollectingVisitor;
use WorkerSafety\Ast\Visitor\RuleDispatchVisitor;
use WorkerSafety\Ignore\IgnoreAttributeVisitor;
use WorkerSafety\Ignore\IgnoreDirectiveParser;
use WorkerSafety\Rule\ProjectContext;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\Scope;
use WorkerSafety\Support\SourceFile;

/**
 * Analyzes one file: parse once, then walk the AST once.
 *
 * The single walk drives three visitors — semantic index collection, ignore
 * attribute collection and rule dispatch — so adding a rule never costs
 * another pass over the tree.
 */
final class FileAnalyzer
{
    public function __construct(
        private readonly AstParser $parser = new AstParser(),
        private readonly IgnoreDirectiveParser $ignoreParser = new IgnoreDirectiveParser(),
    ) {
    }

    /**
     * @param list<Rule> $rules
     * @param list<ContainerBindingCollector> $bindingCollectors
     */
    public function analyze(
        SourceFile $file,
        array $rules,
        ProjectContext $project,
        array $bindingCollectors = [],
    ): FileAnalysisResult {
        $suppressions = $this->ignoreParser->parse($file);
        $parsed = $this->parser->parse($file);

        if (!$parsed->usable) {
            return new FileAnalysisResult([], $parsed->failures, $suppressions, false);
        }

        $scope = new Scope();
        $context = new RuleContext($file, $scope, $project);

        foreach ($rules as $rule) {
            $rule->beginFile($context);
        }

        $dispatcher = new RuleDispatchVisitor($rules, $scope);
        $dispatcher->startFile($context);

        $traverser = new NodeTraverser(
            new IndexCollectingVisitor($project->index(), $file, $bindingCollectors),
            new IgnoreAttributeVisitor($suppressions),
            $dispatcher,
        );

        $traverser->traverse($parsed->statements);

        $findings = $dispatcher->findings();

        foreach ($rules as $rule) {
            foreach ($rule->finishFile($context) as $finding) {
                $findings[] = $finding;
            }
        }

        return new FileAnalysisResult($findings, $parsed->failures, $suppressions, true);
    }
}
