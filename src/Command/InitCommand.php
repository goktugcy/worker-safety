<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Config\ConfigurationTemplate;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Framework\FrameworkDetector;
use WorkerSafety\Support\Paths;

#[AsCommand(
    name: 'init',
    description: 'Create a commented ' . ApplicationInfo::CONFIG_FILE . ' in the project root',
)]
final class InitCommand extends AbstractCommand
{
    public function __construct(private readonly FrameworkDetector $detector = new FrameworkDetector())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project-dir', null, InputOption::VALUE_REQUIRED, 'Project root (defaults to the working directory)')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Overwrite an existing configuration file')
            ->setHelp('Writes a configuration file with defaults matching the detected framework. Never overwrites an existing file unless --force is given, so it is safe to run in CI.');
    }

    protected function handle(InputInterface $input, OutputInterface $output): ExitCode
    {
        $projectRoot = $this->projectRoot($input);
        $path = Paths::normalize($projectRoot . '/' . ApplicationInfo::CONFIG_FILE);
        $relative = Paths::makeRelative($path, $projectRoot);

        if (is_file($path) && !(bool) $input->getOption('force')) {
            throw new ConfigurationException(sprintf(
                '%s already exists. Delete it or pass --force to overwrite it.',
                $relative,
            ));
        }

        $framework = $this->detector->detect($projectRoot);
        $contents = ConfigurationTemplate::render($framework);

        if (@file_put_contents($path, $contents) === false) {
            throw new ConfigurationException(sprintf('Could not write %s.', $relative));
        }

        $output->writeln(sprintf('Created %s', $relative));

        if ($framework->isKnown()) {
            $output->writeln(sprintf('Detected %s, so the default paths were set accordingly.', $framework->describe()));
        }

        return ExitCode::Success;
    }
}
