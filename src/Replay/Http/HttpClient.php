<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Http;

use WorkerSafety\Replay\Scenario\ReplayRequest;

/**
 * Transport seam.
 *
 * Kept to one method so that swapping in cURL or Symfony HttpClient later is a
 * new class rather than a change to the replay engine, and so that transport
 * failures can be exercised in tests without a socket.
 */
interface HttpClient
{
    /**
     * @throws \WorkerSafety\Exception\ReplayException when no response could be read
     */
    public function send(string $baseUrl, ReplayRequest $request, float $timeoutSeconds): HttpResponse;
}
