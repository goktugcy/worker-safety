<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Application\WorkerSafetyApplication;
use WorkerSafety\Command\TestCommand;
use WorkerSafety\Tests\Support\Fixtures;
use WorkerSafety\Tests\Support\FixtureWorker;
use WorkerSafety\Tests\Support\JsonAccess;

/**
 * The `test` command through the real console application.
 */
#[CoversClass(TestCommand::class)]
final class ReplayCommandTest extends TestCase
{
    use JsonAccess;

    private ?FixtureWorker $worker = null;

    protected function tearDown(): void
    {
        $this->worker?->stop();
        $this->worker = null;
    }

    private function worker(): FixtureWorker
    {
        return $this->worker ??= FixtureWorker::start();
    }

    /**
     * @param array<string, mixed> $parameters
     * @param array<string, mixed> $options
     *
     * @return array{0: int, 1: string}
     */
    private function replay(array $parameters, array $options = []): array
    {
        $tester = new CommandTester((new WorkerSafetyApplication())->find('test'));
        $status = $tester->execute($parameters + ['--no-ansi' => true], $options);

        return [$status, $tester->getDisplay()];
    }

    private function scenario(string $name = 'scenario.yaml'): string
    {
        return Fixtures::path('Replay/leaky-worker/' . $name);
    }

    public function test_the_command_is_registered(): void
    {
        self::assertTrue((new WorkerSafetyApplication())->has('test'));
    }

    public function test_the_help_documents_the_single_worker_requirement(): void
    {
        $help = (string) preg_replace(
            '/\s+/',
            ' ',
            (new WorkerSafetyApplication())->find('test')->getProcessedHelp(),
        );

        self::assertStringContainsString('--workers=1', $help);
        self::assertStringContainsString('same persistent process', $help);
        self::assertStringContainsString('never loads, autoloads or executes', $help);

        // `frankenphp run` defaults to 2x CPU worker threads, so it must never
        // be offered on its own as the single-worker recipe. The guarantee
        // comes from `num`, not from the command.
        self::assertStringContainsString('num 1', $help);
        self::assertStringContainsString('frankenphp run` alone is not enough', $help);
        self::assertDoesNotMatchRegularExpression(
            '/frankenphp run\s*(\(|#)?\s*a single worker/i',
            $help,
            '`frankenphp run` must not be presented as a single-worker instance.',
        );
    }

    public function test_it_reports_the_leak_and_exits_with_one(): void
    {
        [$status, $display] = $this->replay([
            'scenario' => $this->scenario(),
            '--base-url' => $this->worker()->baseUrl(),
        ]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);
        self::assertStringContainsString('Result: FAILED', $display);
        self::assertStringContainsString('json.user', $display);
        self::assertStringContainsString('"alice"', $display);
    }

    public function test_the_scenario_can_be_passed_as_an_option(): void
    {
        [$status] = $this->replay([
            '--scenario' => $this->scenario(),
            '--base-url' => $this->worker()->baseUrl(),
        ]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);
    }

    public function test_a_passing_scenario_exits_with_zero(): void
    {
        [$status, $display] = $this->replay([
            'scenario' => $this->scenario('scenario-reset.yaml'),
            '--base-url' => $this->worker()->baseUrl(),
        ]);

        self::assertSame(ExitCode::Success->value, $status);
        self::assertStringContainsString('Result: PASSED', $display);
    }

    public function test_json_output_is_pure_json(): void
    {
        [$status, $display] = $this->replay([
            'scenario' => $this->scenario(),
            '--base-url' => $this->worker()->baseUrl(),
            '--format' => 'json',
        ]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);

        $decoded = self::decodeJson($display);

        self::assertSame(1, $decoded['version']);
        self::assertFalse($decoded['passed']);
    }

    public function test_quiet_suppresses_console_output_but_keeps_the_exit_code(): void
    {
        [$status, $display] = $this->replay(
            [
                'scenario' => $this->scenario(),
                '--base-url' => $this->worker()->baseUrl(),
            ],
            ['verbosity' => OutputInterface::VERBOSITY_QUIET],
        );

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);
        self::assertSame('', trim($display));
    }

    public function test_a_connection_failure_exits_with_three(): void
    {
        [$status, $display] = $this->replay([
            'scenario' => $this->scenario(),
            '--base-url' => 'http://127.0.0.1:9',
        ]);

        self::assertSame(ExitCode::InternalError->value, $status, 'Transport failure is not a failed expectation.');
        self::assertStringContainsString('Replay error', $display);
    }

    public function test_an_unreadable_scenario_exits_with_two(): void
    {
        [$status] = $this->replay(['scenario' => '/definitely/not/here.yaml']);

        self::assertSame(ExitCode::InvalidConfiguration->value, $status);
    }

    public function test_a_missing_scenario_argument_exits_with_two(): void
    {
        [$status, $display] = $this->replay([]);

        self::assertSame(ExitCode::InvalidConfiguration->value, $status);
        self::assertStringContainsString('No scenario given', $display);
    }

    public function test_an_unknown_format_exits_with_two(): void
    {
        [$status] = $this->replay([
            'scenario' => $this->scenario(),
            '--format' => 'xml',
        ]);

        self::assertSame(ExitCode::InvalidConfiguration->value, $status);
    }

    public function test_an_invalid_timeout_exits_with_two(): void
    {
        [$status] = $this->replay([
            'scenario' => $this->scenario(),
            '--timeout' => '0',
        ]);

        self::assertSame(ExitCode::InvalidConfiguration->value, $status);
    }

    /**
     * Scenarios carry tokens; a CI log is not the place for them.
     */
    public function test_request_headers_are_never_echoed(): void
    {
        [, $display] = $this->replay([
            'scenario' => Fixtures::path('Replay/leaky-worker/scenario-authorized.yaml'),
            '--base-url' => $this->worker()->baseUrl(),
        ]);

        self::assertStringNotContainsString('s3cret-token', $display);
        self::assertStringNotContainsString('Authorization', $display);
    }

    public function test_request_headers_are_absent_from_json_output_too(): void
    {
        [, $display] = $this->replay([
            'scenario' => Fixtures::path('Replay/leaky-worker/scenario-authorized.yaml'),
            '--base-url' => $this->worker()->baseUrl(),
            '--format' => 'json',
        ]);

        self::assertStringNotContainsString('s3cret-token', $display);
    }
}
