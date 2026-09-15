<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Analyzer\ScanOptions;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Replay\ReplayService;
use WorkerSafety\Replay\Result\AssertionResult;
use WorkerSafety\Replay\Scenario\ScenarioLoader;
use WorkerSafety\Tests\Support\Fixtures;
use WorkerSafety\Tests\Support\FixtureWorker;

/**
 * The gap between the two questions, pinned.
 *
 *   Static analysis asks: could this code retain state between requests?
 *   Runtime replay asks:  did request B observe what request A left behind?
 *
 * `RequestContext` is an ordinary object with a mutable instance property.
 * There is nothing wrong with it, and the scanner is right to report nothing:
 * whether it leaks depends on who constructs it and how long they hold it,
 * which is not in the file. The fixture worker constructs one before its accept
 * loop and reuses it, and only running the thing reveals that.
 *
 * This test makes real HTTP requests to a real second process. It is not
 * mocked, because a mock would prove nothing about the gap it exists to show.
 *
 * On what the fixture is: a small persistent PHP process speaking HTTP over a
 * loopback socket. It is not FrankenPHP and not Octane, and nothing here is
 * evidence about those runtimes — only about the cross-request behaviour of a
 * process that serves more than one request.
 */
#[CoversNothing]
final class ReplayStaticGapRegressionTest extends TestCase
{
    public function test_replay_catches_a_leak_static_analysis_cannot_prove(): void
    {
        // 1. Static analysis: clean.
        $static = (new ScanService())->scan(new ScanOptions(
            projectRoot: Fixtures::path('Replay/static-safe'),
            paths: [Fixtures::path('Replay/static-safe')],
        ));

        self::assertCount(
            0,
            $static->report->findings->toArray(),
            'The safe fixture must stay free of findings, or this test stops demonstrating the gap.',
        );
        self::assertSame(ExitCode::Success, $static->report->exitCode());

        // 2. Runtime replay against the same class, used from a persistent loop.
        $worker = FixtureWorker::start();

        try {
            $scenario = (new ScenarioLoader())->load(
                Fixtures::path('Replay/leaky-worker/scenario.yaml'),
                $worker->baseUrl(),
            );

            $replay = (new ReplayService())->run($scenario);

            self::assertFalse($replay->passed(), 'Replay must observe the leak.');
            self::assertSame(ExitCode::FindingsAboveThreshold, $replay->exitCode());

            $failure = $replay->failures()[0];

            self::assertSame('json_equals', $failure->type);
            self::assertSame('user', $failure->path);
            self::assertNull($failure->expected);
            self::assertSame('alice', $failure->actual);
            self::assertFalse($failure->actualIsMissing());
        } finally {
            $worker->stop();
        }
    }

    /**
     * The seeding request is allowed to see what it just wrote — otherwise the
     * failure above would only prove the endpoint is broken.
     */
    public function test_the_first_request_legitimately_sees_its_own_write(): void
    {
        $worker = FixtureWorker::start();

        try {
            $scenario = (new ScenarioLoader())->load(
                Fixtures::path('Replay/leaky-worker/scenario.yaml'),
                $worker->baseUrl(),
            );

            $replay = (new ReplayService())->run($scenario);

            $seed = $replay->steps[0];

            self::assertSame('seed', $seed->id);
            self::assertTrue($seed->passed(), 'The request that set the user must observe it.');
            self::assertSame(200, $seed->status);
        } finally {
            $worker->stop();
        }
    }

    /**
     * A weaker control than its old name suggested: this proves that an
     * explicit `/reset` request between the two observations makes the run
     * pass, which shows the endpoint and the engine both work. It does NOT
     * show the unmodified scenario passing against a corrected application,
     * because it changes the scenario to get there.
     *
     * That stronger claim is ReplayFixedWorkerControlTest, which runs this
     * exact two-step scenario — no reset added — against a worker whose only
     * difference is where `new RequestContext()` sits.
     */
    public function test_an_explicit_reset_request_between_observations_passes(): void
    {
        $worker = FixtureWorker::start();

        try {
            $scenario = (new ScenarioLoader())->load(
                Fixtures::path('Replay/leaky-worker/scenario-reset.yaml'),
                $worker->baseUrl(),
            );

            $replay = (new ReplayService())->run($scenario);

            self::assertTrue(
                $replay->passed(),
                'An explicit reset between the observations must make the run pass.',
            );
            self::assertSame(ExitCode::Success, $replay->exitCode());
            self::assertSame([], $replay->failures());
        } finally {
            $worker->stop();
        }
    }

    /**
     * A missing path and an observed null are different answers, and the leak
     * case depends on telling them apart.
     */
    public function test_a_missing_json_path_is_not_reported_as_null(): void
    {
        $worker = FixtureWorker::start();

        try {
            $scenario = (new ScenarioLoader())->load(
                Fixtures::path('Replay/leaky-worker/scenario-missing-path.yaml'),
                $worker->baseUrl(),
            );

            $replay = (new ReplayService())->run($scenario);
            $failure = $replay->failures()[0];

            self::assertSame('tenant.id', $failure->path);
            self::assertTrue($failure->actualIsMissing());
            self::assertSame(AssertionResult::MISSING, $failure->actual);
        } finally {
            $worker->stop();
        }
    }
}
