<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Replay;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Result\ReplayResult;
use WorkerSafety\Replay\Result\StepResult;
use WorkerSafety\Reporting\ReplayConsoleReporter;
use WorkerSafety\Reporting\ReplayJsonReporter;
use WorkerSafety\Tests\Support\JsonAccess;

#[CoversClass(ReplayConsoleReporter::class)]
#[CoversClass(ReplayJsonReporter::class)]
#[CoversClass(ReplayResult::class)]
#[CoversClass(StepResult::class)]
#[CoversClass(AssertionResult::class)]
final class ReplayReportersTest extends TestCase
{
    use JsonAccess;

    private function failingResult(): ReplayResult
    {
        return new ReplayResult('shared request context leak', 'http://127.0.0.1:8080', [
            new StepResult('seed', 'POST /context', 200, [
                AssertionResult::pass('status', null, 200, 200),
            ], 0.01),
            new StepResult('observe', 'GET /context', 200, [
                AssertionResult::pass('status', null, 200, 200),
                AssertionResult::fail('json_equals', 'user', null, 'alice'),
            ], 0.01),
        ], 0.02);
    }

    private function passingResult(): ReplayResult
    {
        return new ReplayResult('clean', 'http://127.0.0.1:8080', [
            new StepResult('observe', 'GET /context', 200, [
                AssertionResult::pass('status', null, 200, 200),
                AssertionResult::pass('json_equals', 'user', null, null),
            ], 0.01),
        ], 0.01);
    }

    private function render(ReplayResult $result): string
    {
        $output = new BufferedOutput();
        (new ReplayConsoleReporter())->report($result, $output);

        return $output->fetch();
    }

    /**
     * Prose in the report is word-wrapped, so a sentence spanning two lines
     * would not match a contiguous needle. Collapse whitespace before
     * asserting on wording.
     */
    private function renderFlat(ReplayResult $result): string
    {
        return (string) preg_replace('/\s+/', ' ', $this->render($result));
    }

    public function test_the_console_report_shows_expected_and_observed(): void
    {
        $text = $this->render($this->failingResult());

        self::assertStringContainsString('shared request context leak', $text);
        self::assertStringContainsString('Step 1', $text);
        self::assertStringContainsString('POST /context', $text);
        self::assertStringContainsString('json.user', $text);
        self::assertStringContainsString('Expected:', $text);
        self::assertStringContainsString('null', $text);
        self::assertStringContainsString('Observed:', $text);
        self::assertStringContainsString('"alice"', $text);
        self::assertStringContainsString('Result: FAILED', $text);
    }

    /**
     * The tool observes behaviour; calling it a vulnerability is a judgement
     * about data and exposure that replay cannot make.
     */
    public function test_the_console_report_stays_neutral(): void
    {
        $text = $this->renderFlat($this->failingResult());

        self::assertStringContainsString('cross-request state remained observable', $text);
        self::assertStringNotContainsStringIgnoringCase('vulnerability', $text);
        self::assertStringNotContainsStringIgnoringCase('exploit', $text);
    }

    public function test_a_passing_report_does_not_claim_the_absence_of_leaks(): void
    {
        $text = $this->renderFlat($this->passingResult());

        self::assertStringContainsString('Result: PASSED', $text);
        self::assertStringContainsString('not a proof that no state is retained anywhere', $text);
    }

    public function test_the_json_report_matches_the_documented_schema(): void
    {
        $json = (new ReplayJsonReporter())->encode($this->failingResult());
        $decoded = self::decodeJson($json);

        self::assertSame(1, self::at($decoded, 'version'));
        self::assertSame('shared request context leak', self::stringAt($decoded, 'scenario', 'name'));
        self::assertSame('http://127.0.0.1:8080', self::stringAt($decoded, 'scenario', 'target'));
        self::assertFalse(self::at($decoded, 'passed'));
        self::assertCount(2, self::arrayAt($decoded, 'steps'));

        $observe = self::arrayAt($decoded, 'steps', 1);
        self::assertSame('observe', self::at($observe, 'id'));
        self::assertFalse(self::at($observe, 'passed'));

        $assertion = self::arrayAt($observe, 'assertions', 1);
        self::assertSame('json_equals', self::at($assertion, 'type'));
        self::assertSame('user', self::at($assertion, 'path'));
        self::assertNull(self::at($assertion, 'expected'));
        self::assertSame('alice', self::at($assertion, 'actual'));
        self::assertFalse(self::at($assertion, 'passed'));
        self::assertFalse(self::at($assertion, 'actual_missing'));
    }

    public function test_a_missing_value_is_flagged_rather_than_encoded_as_null(): void
    {
        $result = new ReplayResult('missing', 'http://x', [
            new StepResult('observe', 'GET /a', 200, [
                AssertionResult::fail('json_equals', 'tenant.id', 42, AssertionResult::MISSING),
            ], 0.0),
        ]);

        $decoded = self::decodeJson((new ReplayJsonReporter())->encode($result));
        $assertion = self::arrayAt($decoded, 'steps', 0, 'assertions', 0);

        self::assertNull(self::at($assertion, 'actual'));
        self::assertTrue(self::at($assertion, 'actual_missing'));
    }

    public function test_exit_codes(): void
    {
        self::assertSame(ExitCode::Success, $this->passingResult()->exitCode());
        self::assertSame(ExitCode::FindingsAboveThreshold, $this->failingResult()->exitCode());
    }

    public function test_failures_are_collected_in_execution_order(): void
    {
        $failures = $this->failingResult()->failures();

        self::assertCount(1, $failures);
        self::assertSame('user', $failures[0]->path);
        self::assertSame(3, $this->failingResult()->assertionCount());
    }
}
