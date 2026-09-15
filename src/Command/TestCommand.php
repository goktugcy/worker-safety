<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Replay\ReplayService;
use WorkerSafety\Replay\Scenario\ScenarioLoader;
use WorkerSafety\Reporting\ReplayConsoleReporter;
use WorkerSafety\Reporting\ReplayJsonReporter;

/**
 * `worker-safety test` — replay a scenario against a running application.
 *
 * The counterpart to `scan`: where the scanner asks whether code *could* retain
 * state between requests, this asks whether a later request *did* observe what
 * an earlier one left behind.
 */
#[AsCommand(
    name: 'test',
    description: 'Replay a scenario against a running persistent worker to observe cross-request state',
)]
final class TestCommand extends AbstractCommand
{
    public function __construct(
        private readonly ScenarioLoader $loader = new ScenarioLoader(),
        private readonly ReplayService $replay = new ReplayService(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('scenario', InputArgument::OPTIONAL, 'Path to the replay scenario file')
            ->addOption('scenario', 's', InputOption::VALUE_REQUIRED, 'Path to the replay scenario file')
            ->addOption('base-url', 'b', InputOption::VALUE_REQUIRED, 'Override the scenario base_url')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: console, json', 'console')
            ->addOption(
                'timeout',
                't',
                InputOption::VALUE_REQUIRED,
                'Per-request timeout in seconds',
                (string) ReplayService::DEFAULT_TIMEOUT_SECONDS,
            )
            ->setHelp($this->help());
    }

    protected function handle(InputInterface $input, OutputInterface $output): ExitCode
    {
        $format = $this->format($input);
        $scenario = $this->loader->load(
            $this->scenarioPath($input),
            $this->stringOption($input, 'base-url'),
            $this->workingDirectory(),
        );

        $result = $this->replay->run($scenario, $this->timeout($input));

        if ($format === 'json') {
            (new ReplayJsonReporter())->report($result, $output);

            return $result->exitCode();
        }

        if (!$output->isQuiet()) {
            (new ReplayConsoleReporter())->report($result, $output);
        }

        return $result->exitCode();
    }

    private function scenarioPath(InputInterface $input): string
    {
        $option = $this->stringOption($input, 'scenario');
        $argument = $input->getArgument('scenario');
        $argument = is_string($argument) && $argument !== '' ? $argument : null;

        if ($option !== null && $argument !== null && $option !== $argument) {
            throw ConfigurationException::invalidValue(
                'scenario',
                'The scenario was given twice, as an argument and as --scenario, with different values.',
            );
        }

        $path = $option ?? $argument;

        if ($path === null) {
            throw ConfigurationException::invalidValue(
                'scenario',
                'No scenario given. Pass one as an argument or with --scenario=FILE.',
            );
        }

        return $path;
    }

    private function format(InputInterface $input): string
    {
        $format = strtolower($this->stringOption($input, 'format') ?? 'console');

        if (!in_array($format, ['console', 'json'], true)) {
            throw ConfigurationException::invalidValue('format', sprintf('"%s" is not a known format: console, json.', $format));
        }

        return $format;
    }

    private function timeout(InputInterface $input): float
    {
        $raw = $this->stringOption($input, 'timeout');

        if ($raw === null) {
            return ReplayService::DEFAULT_TIMEOUT_SECONDS;
        }

        if (!is_numeric($raw) || (float) $raw <= 0.0) {
            throw ConfigurationException::invalidValue('timeout', 'The timeout must be a positive number of seconds.');
        }

        return (float) $raw;
    }

    private function stringOption(InputInterface $input, string $name): ?string
    {
        $value = $input->getOption($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function help(): string
    {
        return <<<'HELP'
            Replays an ordered list of HTTP requests against an application that is
            <comment>already running</comment>, and reports whether a later request could observe state
            left behind by an earlier one.

              <info>worker-safety test --scenario=worker-safety.replay.yaml</info>
              <info>worker-safety test worker-safety.replay.yaml --format=json</info>

            <comment>Single worker, or the result means nothing.</comment> Replay only demonstrates
            cross-request behaviour when consecutive requests reach the same persistent
            process. Start the target with exactly one worker:

              Laravel Octane:
                <info>php artisan octane:start --workers=1</info>

              FrankenPHP: the worker thread count defaults to 2x the CPU count, so
              `frankenphp run` alone is not enough. Set it in the worker config:

                <info>frankenphp {</info>
                <info>    worker {</info>
                <info>        file ./public/index.php</info>
                <info>        num 1</info>
                <info>    }</info>
                <info>}</info>

              or the short form <info>worker ./public/index.php 1</info>, or
              <info>FRANKENPHP_CONFIG="worker ./public/index.php 1"</info>.

            Against a load-balanced deployment, or any worker count above one, the
            requests may land on different processes, so a passing run proves nothing
            about retained state.

            This command never loads, autoloads or executes any of the target
            application's code. It is an HTTP client and nothing else.

            Exit codes:
              <info>0</info>  every expectation held
              <info>1</info>  at least one expectation did not hold
              <info>2</info>  the scenario or an option was invalid
              <info>3</info>  a request could not be completed (connection, timeout, malformed response)
            HELP;
    }
}
