<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Analyzer\ScanOptions;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Baseline\Baseline;
use WorkerSafety\Baseline\BaselineRepository;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\Paths;

#[AsCommand(
    name: 'baseline',
    description: 'Record the current findings so that only new ones fail the build',
)]
final class BaselineCommand extends AbstractCommand
{
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
            ->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to the working directory)')
            ->addOption('runtime', 'r', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Runtime target(s) to analyze for')
            ->addOption('baseline', null, InputOption::VALUE_REQUIRED, 'Where to write the baseline file')
            ->setHelp(sprintf(
                'Runs a full scan and stores a fingerprint of every finding in %s. Existing findings then stop failing the build while new ones still do. Fingerprints do not include line numbers, so edits above a baselined finding do not resurrect it.',
                ApplicationInfo::BASELINE_FILE,
            ));
    }

    protected function handle(InputInterface $input, OutputInterface $output): ExitCode
    {
        $projectRoot = $this->projectRoot($input);
        $runtimeValues = $this->listOption($input, 'runtime');

        $outcome = $this->scanService->scan(new ScanOptions(
            $projectRoot,
            $this->stringArrayArgument($input, 'paths'),
            $this->stringOption($input, 'config'),
            $runtimeValues === [] ? null : RuntimeTargetSet::fromStrings($runtimeValues),
            'never',
            true,
        ));

        $path = Paths::makeAbsolute(
            $this->stringOption($input, 'baseline')
                ?? $outcome->configuration->baseline
                ?? ApplicationInfo::BASELINE_FILE,
            $projectRoot,
        );

        $previous = $this->baselines->exists($path) ? $this->baselines->load($path)->count() : null;
        $baseline = Baseline::fromFindings($outcome->findingsBeforeBaseline);

        $this->baselines->save($path, $baseline);

        $relative = Paths::makeRelative($path, $projectRoot);

        $output->writeln('');
        $output->writeln(sprintf('Wrote %s', $relative));
        $output->writeln('');
        $output->writeln(sprintf(
            '  %d finding(s) recorded from %d file(s).',
            $baseline->count(),
            $outcome->report->filesScanned,
        ));

        if ($previous !== null) {
            $output->writeln(sprintf('  Previous baseline contained %d finding(s).', $previous));
        }

        $output->writeln('');
        $output->writeln('Commit this file. New findings will still fail the build.');
        $output->writeln('');

        return ExitCode::Success;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
