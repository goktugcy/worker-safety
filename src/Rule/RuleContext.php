<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use PhpParser\Node;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Finding\Location;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\SourceFile;

/**
 * Analysis state for the file currently being visited.
 */
final class RuleContext implements ReportingContext
{
    public function __construct(
        private readonly SourceFile $file,
        private readonly Scope $scope,
        private readonly ProjectContext $project,
    ) {
    }

    public function file(): SourceFile
    {
        return $this->file;
    }

    public function scope(): Scope
    {
        return $this->scope;
    }

    public function project(): ProjectContext
    {
        return $this->project;
    }

    public function index(): ProjectIndex
    {
        return $this->project->index();
    }

    public function framework(): DetectedFramework
    {
        return $this->project->framework();
    }

    public function runtimes(): RuntimeTargetSet
    {
        return $this->project->runtimes();
    }

    public function projectRoot(): string
    {
        return $this->project->projectRoot();
    }

    public function location(Node $node): Location
    {
        return AstHelper::location($node, $this->file);
    }

    public function snippet(Node|int $at): ?string
    {
        return $this->file->snippet($at instanceof Node ? max(1, $at->getStartLine()) : $at);
    }
}
