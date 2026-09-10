<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Support;

use WorkerSafety\Analyzer\AnalysisRequest;
use WorkerSafety\Analyzer\AnalysisResult;
use WorkerSafety\Analyzer\ProjectAnalyzer;
use WorkerSafety\Ast\Index\ContainerBindingCollector;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\ExcludeMatcher;
use WorkerSafety\Support\FileFinder;

/**
 * Runs the real analyzer over a fixture with an explicit rule set.
 *
 * Rule tests use this so that they exercise the same code path as the CLI —
 * discovery, parsing, indexing, dispatch and suppression — instead of poking
 * at rules in isolation.
 */
final class AnalyzerHarness
{
    private function __construct()
    {
    }

    /**
     * @param list<Rule> $rules
     * @param list<ContainerBindingCollector> $bindingCollectors
     */
    public static function analyze(
        string $path,
        array $rules,
        ?DetectedFramework $framework = null,
        ?Configuration $configuration = null,
        ?RuntimeTargetSet $runtimes = null,
        array $bindingCollectors = [],
        ?string $projectRoot = null,
    ): AnalysisResult {
        $absolute = str_starts_with($path, '/') ? $path : Fixtures::path($path);
        $root = $projectRoot ?? dirname(Fixtures::root(), 2);
        $configuration ??= Configuration::defaults();

        $files = (new FileFinder($root, new ExcludeMatcher($configuration->exclude), $configuration->extensions))
            ->find([$absolute]);

        return (new ProjectAnalyzer())->analyze(new AnalysisRequest(
            $root,
            $files,
            $configuration,
            $framework ?? DetectedFramework::none(),
            $runtimes ?? RuntimeTargetSet::all(),
            $rules,
            $bindingCollectors,
        ));
    }
}
