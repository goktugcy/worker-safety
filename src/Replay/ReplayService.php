<?php

declare(strict_types=1);

namespace WorkerSafety\Replay;

use WorkerSafety\Replay\Assertion\BodyContainsAssertion;
use WorkerSafety\Replay\Assertion\BodyNotContainsAssertion;
use WorkerSafety\Replay\Assertion\HeaderEqualsAssertion;
use WorkerSafety\Replay\Assertion\JsonEqualsAssertion;
use WorkerSafety\Replay\Assertion\ReplayAssertion;
use WorkerSafety\Replay\Assertion\StatusAssertion;
use WorkerSafety\Replay\Http\HttpClient;
use WorkerSafety\Replay\Http\StreamHttpClient;
use WorkerSafety\Replay\Result\ReplayResult;
use WorkerSafety\Replay\Result\StepResult;
use WorkerSafety\Replay\Scenario\ReplayExpectation;
use WorkerSafety\Replay\Scenario\ReplayScenario;
use WorkerSafety\Replay\Scenario\ReplayStep;

/**
 * Runs a scenario against an already-running application.
 *
 * Steps run in declaration order over one client, because that ordering is the
 * only reason the result says anything about cross-request behaviour. Every
 * step is executed even after one fails, so a report shows the whole picture
 * rather than the first disagreement.
 *
 * Nothing here loads, autoloads or executes any code belonging to the target
 * application: replay is a black-box HTTP client by design, which is what lets
 * the static scanner keep its promise never to run what it analyzes.
 */
final class ReplayService
{
    public const DEFAULT_TIMEOUT_SECONDS = 10.0;

    public function __construct(private readonly HttpClient $client = new StreamHttpClient())
    {
    }

    /**
     * @param (callable(ReplayStep, StepResult): void)|null $onStep
     *
     * @throws \WorkerSafety\Exception\ReplayException when a request could not be completed
     */
    public function run(
        ReplayScenario $scenario,
        float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS,
        ?callable $onStep = null,
    ): ReplayResult {
        $started = microtime(true);
        $results = [];

        foreach ($scenario->steps as $step) {
            $stepStarted = microtime(true);

            $response = $this->client->send($scenario->baseUrl, $step->request, $timeoutSeconds);

            $assertions = [];

            foreach ($this->assertionsFor($step->expect) as $assertion) {
                foreach ($assertion->check($response) as $result) {
                    $assertions[] = $result;
                }
            }

            $result = new StepResult(
                $step->id,
                $step->request->describe(),
                $response->status,
                $assertions,
                microtime(true) - $stepStarted,
            );

            $results[] = $result;

            if ($onStep !== null) {
                $onStep($step, $result);
            }
        }

        return new ReplayResult(
            $scenario->name,
            $scenario->baseUrl,
            $results,
            microtime(true) - $started,
        );
    }

    /**
     * @return list<ReplayAssertion>
     */
    private function assertionsFor(ReplayExpectation $expect): array
    {
        $assertions = [];

        if ($expect->status !== null) {
            $assertions[] = new StatusAssertion($expect->status);
        }

        if ($expect->json !== []) {
            $assertions[] = new JsonEqualsAssertion($expect->json);
        }

        if ($expect->headers !== []) {
            $assertions[] = new HeaderEqualsAssertion($expect->headers);
        }

        if ($expect->bodyContains !== []) {
            $assertions[] = new BodyContainsAssertion($expect->bodyContains);
        }

        if ($expect->bodyNotContains !== []) {
            $assertions[] = new BodyNotContainsAssertion($expect->bodyNotContains);
        }

        return $assertions;
    }
}
