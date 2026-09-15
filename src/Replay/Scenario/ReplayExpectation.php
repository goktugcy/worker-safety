<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Scenario;

/**
 * What a step expects back, as declared in the scenario.
 *
 * Turning this into assertions is the assertion layer's job; this type only
 * carries the parsed declaration.
 */
final class ReplayExpectation
{
    /**
     * @param array<string, mixed> $json dot-path => expected value
     * @param array<string, string> $headers header name => expected value
     * @param list<string> $bodyContains
     * @param list<string> $bodyNotContains
     */
    public function __construct(
        public readonly ?int $status = null,
        public readonly array $json = [],
        public readonly array $headers = [],
        public readonly array $bodyContains = [],
        public readonly array $bodyNotContains = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->status === null
            && $this->json === []
            && $this->headers === []
            && $this->bodyContains === []
            && $this->bodyNotContains === [];
    }
}
