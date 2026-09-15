<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Replay\Http\StreamHttpClient;
use WorkerSafety\Replay\ReplayService;
use WorkerSafety\Replay\Scenario\ReplayRequest;
use WorkerSafety\Replay\Scenario\ScenarioLoader;
use WorkerSafety\Tests\Support\Fixtures;
use WorkerSafety\Tests\Support\FixtureWorker;

/**
 * The same scenario, two workers, opposite verdicts.
 *
 * `scenario-reset.yaml` is a useful control but a weak one: it proves replay
 * reports a pass when an extra `/reset` request is inserted, which is not the
 * same as proving the *unmodified* scenario passes against a corrected
 * application.
 *
 * So this runs `scenario.yaml` — the exact two steps, no reset, nothing added —
 * against two servers that differ by one line: where `new RequestContext()`
 * sits relative to the accept loop. Leaky fails, fixed passes. Because nothing
 * about the scenario changes between the two runs, the difference is
 * attributable to the object's lifetime and nothing else.
 *
 * On the fixture: this is a small persistent PHP process speaking real HTTP
 * over a loopback socket. It is not FrankenPHP and not Octane, and passing here
 * is not evidence about either of those runtimes.
 */
#[CoversNothing]
final class ReplayFixedWorkerControlTest extends TestCase
{
    /**
     * @var list<FixtureWorker>
     */
    private array $workers = [];

    protected function tearDown(): void
    {
        foreach ($this->workers as $worker) {
            $worker->stop();
        }

        $this->workers = [];
    }

    private function leaky(): FixtureWorker
    {
        return $this->workers[] = FixtureWorker::start();
    }

    private function fixed(): FixtureWorker
    {
        return $this->workers[] = FixtureWorker::startFixed();
    }

    private function replay(FixtureWorker $worker): \WorkerSafety\Replay\Result\ReplayResult
    {
        // The unmodified scenario — same file the README and CI use.
        $scenario = (new ScenarioLoader())->load(
            Fixtures::path('Replay/leaky-worker/scenario.yaml'),
            $worker->baseUrl(),
        );

        return (new ReplayService())->run($scenario);
    }

    public function test_the_unmodified_scenario_fails_against_the_leaky_worker(): void
    {
        $result = $this->replay($this->leaky());

        self::assertFalse($result->passed());
        self::assertSame(ExitCode::FindingsAboveThreshold, $result->exitCode());

        $failure = $result->failures()[0];
        self::assertSame('user', $failure->path);
        self::assertNull($failure->expected);
        self::assertSame('alice', $failure->actual, 'Request B must observe request A\'s user.');
    }

    public function test_the_same_unmodified_scenario_passes_against_the_fixed_worker(): void
    {
        $result = $this->replay($this->fixed());

        self::assertTrue($result->passed(), 'A per-request context must leave nothing for the next request.');
        self::assertSame(ExitCode::Success, $result->exitCode());
        self::assertSame([], $result->failures());
    }

    /**
     * Guards against the cheapest false pass: an endpoint that is simply broken
     * and returns null to everyone would also "pass" the observing step.
     */
    public function test_the_first_request_sees_alice_on_both_workers(): void
    {
        foreach (['leaky' => $this->leaky(), 'fixed' => $this->fixed()] as $label => $worker) {
            $seed = $this->replay($worker)->steps[0];

            self::assertSame('seed', $seed->id, $label);
            self::assertTrue($seed->passed(), sprintf('%s: the writing request must observe its own write.', $label));
            self::assertSame(200, $seed->status, $label);
        }
    }

    /**
     * Both requests must reach one process, or neither verdict means anything.
     *
     * Read from the fixture's own X-Worker-Pid header. That is a test-level
     * check against these fixtures; Worker Safety itself does no worker pinning
     * and makes no claim about which process serves a request.
     */
    public function test_both_requests_reach_the_same_worker_process(): void
    {
        foreach ([$this->leaky(), $this->fixed()] as $worker) {
            $client = new StreamHttpClient();

            $first = $client->send($worker->baseUrl(), new ReplayRequest('GET', '/context'), 5.0);
            $second = $client->send($worker->baseUrl(), new ReplayRequest('GET', '/context'), 5.0);

            $pid = $first->header('X-Worker-Pid');

            self::assertNotNull($pid);
            self::assertSame(
                $pid,
                $second->header('X-Worker-Pid'),
                'Sequential requests must be served by the same process for replay to mean anything.',
            );
        }
    }

    /**
     * The fixed worker is a persistent process, not a fresh one per request —
     * otherwise it would pass for the wrong reason.
     */
    public function test_the_fixed_worker_is_genuinely_persistent(): void
    {
        $worker = $this->fixed();
        $client = new StreamHttpClient();

        $pids = [];

        for ($i = 0; $i < 3; $i++) {
            $pids[] = $client->send($worker->baseUrl(), new ReplayRequest('GET', '/context'), 5.0)
                ->header('X-Worker-Pid');
        }

        self::assertCount(1, array_unique($pids), 'One process should have served all three requests.');
    }
}
