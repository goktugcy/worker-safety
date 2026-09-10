<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

/**
 * Produces findings for a project.
 *
 * The static implementation is {@see ProjectAnalyzer}. A future runtime
 * analyzer (driving a real FrankenPHP/Octane worker) can implement the same
 * contract so that configuration, baselines and reporting stay shared.
 */
interface Analyzer
{
    /**
     * @param (callable(int, int, string): void)|null $onFile invoked before each file
     */
    public function analyze(AnalysisRequest $request, ?callable $onFile = null): AnalysisResult;
}
