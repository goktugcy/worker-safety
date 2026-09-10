<?php

declare(strict_types=1);

namespace WorkerSafety\Analyzer;

use WorkerSafety\Baseline\Baseline;
use WorkerSafety\Baseline\BaselineFilter;
use WorkerSafety\Baseline\BaselineRepository;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Config\ConfigurationLoader;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Framework\FrameworkAdapter;
use WorkerSafety\Framework\FrameworkAdapterRegistry;
use WorkerSafety\Framework\FrameworkDetector;
use WorkerSafety\Reporting\ScanReport;
use WorkerSafety\Rule\RuleRegistry;
use WorkerSafety\Rule\RuleRegistryFactory;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\ExcludeMatcher;
use WorkerSafety\Support\FileFinder;
use WorkerSafety\Support\Paths;

/**
 * Wires configuration, framework detection, file discovery, the rule registry,
 * the analyzer and the baseline into one call.
 *
 * The CLI commands are thin shells around this, which is what makes the whole
 * pipeline testable without a terminal.
 */
final class ScanService
{
    /**
     * Directories that are excluded even if the configuration replaces the
     * exclude list, unless the user explicitly asks to scan inside them.
     *
     * @var list<string>
     */
    public const MANDATORY_EXCLUDES = ['vendor', 'node_modules', '.git'];

    public function __construct(
        private readonly ConfigurationLoader $configurationLoader = new ConfigurationLoader(),
        private readonly FrameworkDetector $frameworkDetector = new FrameworkDetector(),
        private readonly FrameworkAdapterRegistry $adapters = new FrameworkAdapterRegistry(),
        private readonly RuleRegistryFactory $ruleRegistryFactory = new RuleRegistryFactory(),
        private readonly ScanPathResolver $pathResolver = new ScanPathResolver(),
        private readonly Analyzer $analyzer = new ProjectAnalyzer(),
        private readonly BaselineRepository $baselines = new BaselineRepository(),
        private readonly BaselineFilter $baselineFilter = new BaselineFilter(),
    ) {
    }

    /**
     * @param (callable(int, int, string): void)|null $onFile
     */
    public function scan(ScanOptions $options, ?callable $onFile = null): ScanOutcome
    {
        $projectRoot = Paths::normalize($options->projectRoot);

        $registry = $this->ruleRegistryFactory->create();
        $configuration = $this->configurationLoader->load($options->configPath, $projectRoot, $registry->ids());

        $framework = $this->frameworkDetector->detect($projectRoot);
        $adapter = $this->adapters->for($framework);

        $runtimes = $options->runtimes ?? $configuration->runtimes;

        if ($runtimes->isEmpty()) {
            $runtimes = RuntimeTargetSet::all();
        }

        $failOn = $this->resolveFailOn($options->failOn, $configuration);

        $paths = $this->pathResolver->resolve(
            $options->paths,
            $configuration->paths,
            $adapter,
            $projectRoot,
        );

        $excludes = $this->resolveExcludes($configuration, $adapter, $paths, $projectRoot);

        $files = (new FileFinder($projectRoot, new ExcludeMatcher($excludes), $configuration->extensions))
            ->find($paths);

        $rules = $registry->enabledFor($configuration, $framework, $runtimes);

        $result = $this->analyzer->analyze(
            new AnalysisRequest(
                $projectRoot,
                $files,
                $configuration,
                $framework,
                $runtimes,
                $rules,
                $adapter->bindingCollectors(),
            ),
            $onFile,
        );

        $baselinePath = $this->resolveBaselinePath($options, $configuration);
        $baseline = Baseline::empty();

        if ($baselinePath !== null && $this->baselines->exists($baselinePath)) {
            $baseline = $this->baselines->load($baselinePath);
        } else {
            $baselinePath = null;
        }

        [$findings, $baselineFiltered] = $this->baselineFilter->apply($result->findings, $baseline);

        $report = new ScanReport(
            $projectRoot,
            $framework,
            $runtimes,
            $findings,
            $result->filesScanned,
            $result->parseFailures,
            $result->suppressedCount,
            $baselineFiltered,
            $result->durationSeconds,
            $failOn,
            $configuration->sourcePath === null
                ? null
                : Paths::makeRelative($configuration->sourcePath, $projectRoot),
            $baselinePath === null ? null : Paths::makeRelative($baselinePath, $projectRoot),
            array_map(
                static fn (string $path): string => Paths::makeRelative($path, $projectRoot),
                $paths,
            ),
            PHP_VERSION,
            \WorkerSafety\Application\ApplicationInfo::VERSION,
            !$options->allowParseErrors && $configuration->failOnParseError,
        );

        return new ScanOutcome($report, $result->findings, $registry, $configuration);
    }

    public function registry(): RuleRegistry
    {
        return $this->ruleRegistryFactory->create();
    }

    public function detectFramework(string $projectRoot): \WorkerSafety\Framework\DetectedFramework
    {
        return $this->frameworkDetector->detect(Paths::normalize($projectRoot));
    }

    public function adapterFor(\WorkerSafety\Framework\DetectedFramework $framework): FrameworkAdapter
    {
        return $this->adapters->for($framework);
    }

    private function resolveFailOn(?string $raw, Configuration $configuration): ?Severity
    {
        if ($raw === null) {
            return $configuration->failOn;
        }

        $normalized = strtolower(trim($raw));

        if ($normalized === 'never' || $normalized === 'none') {
            return null;
        }

        return Severity::fromString($normalized);
    }

    /**
     * @param list<string> $paths
     *
     * @return list<string>
     */
    private function resolveExcludes(
        Configuration $configuration,
        FrameworkAdapter $adapter,
        array $paths,
        string $projectRoot,
    ): array {
        $excludes = $configuration->exclude;

        foreach ($adapter->defaultExcludes() as $pattern) {
            if (!in_array($pattern, $excludes, true)) {
                $excludes[] = $pattern;
            }
        }

        foreach (self::MANDATORY_EXCLUDES as $pattern) {
            if (in_array($pattern, $excludes, true) || $this->pathsEnter($pattern, $paths, $projectRoot)) {
                continue;
            }

            $excludes[] = $pattern;
        }

        return $excludes;
    }

    /**
     * True when one of the requested scan paths lies inside the given directory,
     * i.e. the user explicitly asked for it.
     *
     * @param list<string> $paths
     */
    private function pathsEnter(string $directory, array $paths, string $projectRoot): bool
    {
        foreach ($paths as $path) {
            $relative = Paths::makeRelative($path, $projectRoot);

            if ($relative === $directory || str_starts_with($relative, $directory . '/')) {
                return true;
            }
        }

        return false;
    }

    private function resolveBaselinePath(ScanOptions $options, Configuration $configuration): ?string
    {
        if ($options->ignoreBaseline) {
            return null;
        }

        if ($options->baselinePath !== null) {
            return Paths::makeAbsolute($options->baselinePath, $options->projectRoot);
        }

        // `baseline: false` in the configuration is an explicit opt-out.
        if ($configuration->baselineDisabled) {
            return null;
        }

        return $configuration->baseline;
    }
}
