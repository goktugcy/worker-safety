<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Analyzer\ScanOptions;
use WorkerSafety\Analyzer\ScanOutcome;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Baseline\Baseline;
use WorkerSafety\Baseline\BaselineRepository;
use WorkerSafety\Reporting\OutputFormat;
use WorkerSafety\Reporting\ReporterFactory;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\Paths;

#[AsCommand(
    name: 'scan',
    description: 'Analyze a project for cross-request state risks under persistent workers',
)]
final class ScanCommand extends AbstractCommand
{
    /**
     * Below this many files a progress bar is more noise than help.
     */
    private const PROGRESS_THRESHOLD = 50;

    public function __construct(
        private readonly ScanService $scanService = new ScanService(),
        private readonly BaselineRepository $baselines = new BaselineRepository(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'paths',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'Files or directories to scan (defaults to the configured paths)',
            )
            ->addOption('config', 'c', InputOption::VALUE_REQUIRED, 'Path to a configuration file')
            ->addOption(
                'format',
                'f',
                InputOption::VALUE_REQUIRED,
                'Output format: ' . implode(', ', OutputFormat::names()),
                OutputFormat::Console->value,
            )
            ->addOption(
                'runtime',
                'r',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Runtime target(s) to analyze for: frankenphp, octane, roadrunner, swoole, all',
            )
            ->addOption(
                'fail-on',
                null,
                InputOption::VALUE_REQUIRED,
                'Exit with code 1 when a finding reaches this severity: critical, high, medium, low, info, never',
            )
            ->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to the working directory)')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Path to the baseline file')
            ->addOption('no-baseline', null, InputOption::VALUE_NONE, 'Ignore an existing baseline file')
            ->addOption(
                'generate-baseline',
                null,
                InputOption::VALUE_NONE,
                'Write the current findings to the baseline file instead of reporting a failure',
            )
            ->addOption('no-progress', null, InputOption::VALUE_NONE, 'Do not render a progress bar')
            ->setHelp($this->help());
    }

    protected function handle(InputInterface $input, OutputInterface $output): ExitCode
    {
        $format = OutputFormat::fromString($this->stringOption($input, 'format') ?? OutputFormat::Console->value);
        $projectRoot = $this->projectRoot($input);
        $generateBaseline = (bool) $input->getOption('generate-baseline');

        $runtimeValues = $this->listOption($input, 'runtime');

        $options = new ScanOptions(
            $projectRoot,
            $this->stringArrayArgument($input, 'paths'),
            $this->stringOption($input, 'config'),
            $runtimeValues === [] ? null : RuntimeTargetSet::fromStrings($runtimeValues),
            $this->stringOption($input, 'fail-on'),
            (bool) $input->getOption('no-baseline') || $generateBaseline,
            $this->stringOption($input, 'baseline'),
        );

        $outcome = $this->scanService->scan(
            $options,
            $this->progressCallback($input, $output, $format),
        );

        if ($generateBaseline) {
            return $this->writeBaseline($outcome, $input, $output, $projectRoot);
        }

        $reporter = (new ReporterFactory($outcome->registry))->create($format);
        $reporter->report($outcome->report, $output);

        return $outcome->report->exitCode();
    }

    private function writeBaseline(
        ScanOutcome $outcome,
        InputInterface $input,
        OutputInterface $output,
        string $projectRoot,
    ): ExitCode {
        $path = $this->stringOption($input, 'baseline')
            ?? $outcome->configuration->baseline
            ?? Paths::normalize($projectRoot . '/' . ApplicationInfo::BASELINE_FILE);

        $path = Paths::makeAbsolute($path, $projectRoot);
        $baseline = Baseline::fromFindings($outcome->findingsBeforeBaseline);

        $this->baselines->save($path, $baseline);

        $target = $this->errorOutput($output);
        $target->writeln('');
        $target->writeln(sprintf(
            '<info>Wrote %d finding(s) to %s</info>',
            $baseline->count(),
            Paths::makeRelative($path, $projectRoot),
        ));
        $target->writeln('');
        $target->writeln('These findings will no longer fail the build. New findings still will.');
        $target->writeln('');

        return ExitCode::Success;
    }

    /**
     * A progress bar is only useful for a large scan, and it always goes to
     * stderr so that machine-readable output on stdout stays clean.
     *
     * @return (callable(int, int, string): void)|null
     */
    private function progressCallback(
        InputInterface $input,
        OutputInterface $output,
        OutputFormat $format,
    ): ?callable {
        if ((bool) $input->getOption('no-progress') || $format->isMachineReadable()) {
            return null;
        }

        if ($output->isQuiet() || !$output->isDecorated()) {
            return null;
        }

        $error = $this->errorOutput($output);
        $bar = null;

        return static function (int $position, int $total, string $relativePath) use (&$bar, $error): void {
            if ($total < self::PROGRESS_THRESHOLD) {
                return;
            }

            if (!$bar instanceof ProgressBar) {
                $bar = new ProgressBar($error, $total);
                $bar->setFormat(' %current%/%max% [%bar%] %message%');
                $bar->setMessage('');
                $bar->start();
            }

            $bar->setMessage(strlen($relativePath) > 48 ? '…' . substr($relativePath, -47) : $relativePath);
            $bar->setProgress($position);

            if ($position === $total) {
                $bar->finish();
                $bar->clear();
            }
        };
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function help(): string
    {
        $binary = ApplicationInfo::BINARY;

        return <<<HELP
            Statically analyzes PHP source for state that survives a request when the
            application runs on a persistent worker (FrankenPHP, Octane, RoadRunner, Swoole).

            <comment>Examples</comment>

              <info>{$binary} scan</info>
                Scan the configured paths, or the framework defaults.

              <info>{$binary} scan app src</info>
                Scan specific directories.

              <info>{$binary} scan --runtime=frankenphp --fail-on=high</info>
                Analyze for one runtime and fail the build on HIGH findings.

              <info>{$binary} scan --format=sarif > worker-safety.sarif</info>
                Produce SARIF for GitHub code scanning.

              <info>{$binary} scan --generate-baseline</info>
                Accept every current finding so only new ones fail the build.

            <comment>Exit codes</comment>

              0  no findings at or above the failure threshold
              1  findings reached the threshold
              2  invalid configuration or CLI option
              3  internal error
            HELP;
    }
}
