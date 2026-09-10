<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Ast\Index\ProjectIndex;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Ignore\FindingSuppressor;
use WorkerSafety\Rule\ProjectContext;
use WorkerSafety\Support\Paths;
use WorkerSafety\Support\SourceFile;

/**
 * Static analyzer: walks every discovered file once, then lets the rules that
 * need whole-project knowledge emit their findings.
 *
 * Files are read and released one at a time, so peak memory is bounded by the
 * largest single file plus the semantic index — not by the project size in
 * bytes.
 */
final class ProjectAnalyzer implements Analyzer
{
    public function __construct(private readonly FileAnalyzer $fileAnalyzer = new FileAnalyzer())
    {
    }

    public function analyze(AnalysisRequest $request, ?callable $onFile = null): AnalysisResult
    {
        $startedAt = microtime(true);

        $index = new ProjectIndex();
        $project = new ProjectContext($index, $request->framework, $request->runtimes, $request->projectRoot);
        $suppressor = new FindingSuppressor($request->configuration);

        /** @var list<Finding> $findings */
        $findings = [];
        /** @var list<\WorkerSafety\Ast\ParseFailure> $parseFailures */
        $parseFailures = [];

        $total = count($request->files);
        $scanned = 0;
        $position = 0;

        foreach ($request->files as $path) {
            ++$position;
            $relative = Paths::makeRelative($path, $request->projectRoot);

            if ($onFile !== null) {
                $onFile($position, $total, $relative);
            }

            $file = new SourceFile($path, $relative, $this->read($path));
            $result = $this->fileAnalyzer->analyze($file, $request->rules, $project, $request->bindingCollectors);

            foreach ($result->parseFailures as $failure) {
                $parseFailures[] = $failure;
            }

            $suppressor->addFileIndex($path, $result->suppressions);

            foreach ($result->findings as $finding) {
                $findings[] = $finding;
            }

            if ($result->analyzed) {
                ++$scanned;
            }
        }

        foreach ($request->rules as $rule) {
            foreach ($rule->finishProject($project) as $finding) {
                $findings[] = $finding;
            }
        }

        $suppressed = 0;
        $kept = [];

        foreach ($findings as $finding) {
            // The configured severity always wins over the severity the rule computed.
            $finding = $finding->withSeverity(
                $request->configuration->severityFor($finding->ruleId, $finding->severity),
            );

            if ($suppressor->isSuppressed($finding)) {
                ++$suppressed;

                continue;
            }

            $kept[] = $finding;
        }

        return new AnalysisResult(
            (new FindingCollection($kept))->sorted(),
            $scanned,
            $parseFailures,
            $suppressed,
            $index,
            microtime(true) - $startedAt,
        );
    }

    private function read(string $path): string
    {
        $source = @file_get_contents($path);

        return $source === false ? '' : $source;
    }
}
