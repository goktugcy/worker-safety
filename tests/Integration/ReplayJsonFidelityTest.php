<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Application\WorkerSafetyApplication;
use WorkerSafety\Tests\Support\Fixtures;
use WorkerSafety\Tests\Support\FixtureWorker;
use WorkerSafety\Tests\Support\JsonAccess;

/**
 * JSON type fidelity through the CLI, against a real HTTP response.
 *
 * The unit tests pin the comparison; these pin that the same answers survive
 * the whole path — YAML parse, socket, decode, report — and that the value the
 * report shows has the type the response actually sent.
 */
#[CoversNothing]
final class ReplayJsonFidelityTest extends TestCase
{
    use JsonAccess;

    private ?FixtureWorker $server = null;

    protected function tearDown(): void
    {
        $this->server?->stop();
        $this->server = null;
    }

    private function server(): FixtureWorker
    {
        return $this->server ??= FixtureWorker::startHttpIntegrity();
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function replay(string $scenario, string $format = 'console'): array
    {
        $tester = new CommandTester((new WorkerSafetyApplication())->find('test'));

        $status = $tester->execute([
            'scenario' => Fixtures::path('Replay/http-integrity/' . $scenario),
            '--base-url' => $this->server()->baseUrl(),
            '--format' => $format,
            '--no-ansi' => true,
        ], ['verbosity' => OutputInterface::VERBOSITY_NORMAL]);

        return [$status, $tester->getDisplay()];
    }

    /**
     * The report's assertions, keyed by path.
     *
     * Decoded WITHOUT assoc on purpose: decoding the report into associative
     * arrays would itself collapse {} into [], which is the very distinction
     * under test.
     *
     * @return array<string, \stdClass>
     */
    private function assertionsByPath(string $display): array
    {
        $report = json_decode($display);
        self::assertInstanceOf(\stdClass::class, $report);

        $steps = $report->steps;
        self::assertIsArray($steps);

        $step = $steps[0];
        self::assertInstanceOf(\stdClass::class, $step);

        $assertions = $step->assertions;
        self::assertIsArray($assertions);

        $byPath = [];

        foreach ($assertions as $assertion) {
            self::assertInstanceOf(\stdClass::class, $assertion);
            $path = $assertion->path;

            // `status` and the body_* assertions carry no path.
            if (!is_string($path)) {
                continue;
            }

            $byPath[$path] = $assertion;
        }

        return $byPath;
    }

    public function test_every_type_distinction_holds_over_real_http(): void
    {
        [$status, $display] = $this->replay('shapes.yaml');

        self::assertSame(ExitCode::Success->value, $status, $display);
        self::assertStringContainsString('Result: PASSED', $display);
    }

    public function test_type_mismatches_fail_over_real_http(): void
    {
        [$status, $display] = $this->replay('shapes-mismatch.yaml');

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);
        self::assertStringContainsString('Result: FAILED', $display);
    }

    /**
     * `{}` sent, `[]` expected: the report must show an object, not `[]`.
     */
    public function test_reported_values_keep_the_received_json_type(): void
    {
        [, $display] = $this->replay('shapes-mismatch.yaml', 'json');

        $byPath = $this->assertionsByPath($display);

        // The response sent {} here; re-encoding must still produce an object.
        self::assertSame(
            '{}',
            json_encode($byPath['empty_obj']->actual),
            'An empty object must not be reported as an empty array.',
        );
        self::assertSame('[]', json_encode($byPath['empty_arr']->actual));

        // The expectation side keeps its YAML type too.
        self::assertSame('[]', json_encode($byPath['empty_obj']->expected));
        self::assertSame('{}', json_encode($byPath['empty_arr']->expected));

        self::assertFalse($byPath['empty_obj']->actual_missing);
        self::assertFalse($byPath['empty_arr']->actual_missing);

        // An absent key is the one case where `actual` is null for a reason
        // other than the response saying null.
        self::assertTrue($byPath['missing_key']->actual_missing);
        self::assertNull($byPath['missing_key']->actual);
    }

    public function test_the_json_report_is_still_pure_json(): void
    {
        [, $display] = $this->replay('shapes.yaml', 'json');

        self::assertNotNull(json_decode($display));
    }
}
