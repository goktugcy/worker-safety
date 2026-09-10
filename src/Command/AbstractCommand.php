<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Exception\AnalysisException;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Support\Paths;

/**
 * Turns exceptions into the documented exit codes and keeps diagnostics off
 * stdout so that `--format=json` output stays parseable.
 */
abstract class AbstractCommand extends Command
{
    abstract protected function handle(InputInterface $input, OutputInterface $output): ExitCode;

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->handle($input, $output)->value;
        } catch (ConfigurationException $exception) {
            $this->writeError($output, 'Configuration error', $exception->getMessage());

            return ExitCode::InvalidConfiguration->value;
        } catch (AnalysisException $exception) {
            $this->writeError($output, 'Analysis error', $exception->getMessage());

            return ExitCode::InternalError->value;
        } catch (\Throwable $exception) {
            $this->writeError(
                $output,
                'Internal error',
                sprintf('%s: %s', $exception::class, $exception->getMessage()),
            );

            if ($output->isVerbose()) {
                $this->errorOutput($output)->writeln($exception->getTraceAsString());
            }

            return ExitCode::InternalError->value;
        }
    }

    protected function writeError(OutputInterface $output, string $heading, string $message): void
    {
        $error = $this->errorOutput($output);
        $error->writeln('');
        $error->writeln(sprintf('<error> %s </error>', $heading));
        $error->writeln('');
        $error->writeln('  ' . $message);
        $error->writeln('');
    }

    protected function errorOutput(OutputInterface $output): OutputInterface
    {
        return $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
    }

    /**
     * The directory the analyzed project lives in.
     */
    protected function projectRoot(InputInterface $input): string
    {
        $option = $input->hasOption('project-dir') ? $input->getOption('project-dir') : null;

        if (is_string($option) && $option !== '') {
            return Paths::makeAbsolute($option, $this->workingDirectory());
        }

        return $this->workingDirectory();
    }

    protected function workingDirectory(): string
    {
        $cwd = getcwd();

        return Paths::normalize($cwd === false ? '.' : $cwd);
    }

    /**
     * Flatten an array option that also accepts comma separated values.
     *
     * @return list<string>
     */
    protected function listOption(InputInterface $input, string $name): array
    {
        if (!$input->hasOption($name)) {
            return [];
        }

        $raw = $input->getOption($name);

        if ($raw === null || $raw === false || $raw === []) {
            return [];
        }

        $values = is_array($raw) ? $raw : [$raw];
        $result = [];

        foreach ($values as $value) {
            if (!is_string($value)) {
                continue;
            }

            foreach (explode(',', $value) as $part) {
                $part = trim($part);

                if ($part !== '') {
                    $result[] = $part;
                }
            }
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    protected function stringArrayArgument(InputInterface $input, string $name): array
    {
        $raw = $input->getArgument($name);

        if (!is_array($raw)) {
            return is_string($raw) && $raw !== '' ? [$raw] : [];
        }

        $result = [];

        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $result[] = $value;
            }
        }

        return $result;
    }
}
