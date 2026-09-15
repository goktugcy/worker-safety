<?php

declare(strict_types=1);

namespace WorkerSafety\Exception;

/**
 * A replay run could not be carried out.
 *
 * Deliberately separate from a failing expectation: this is "the request never
 * happened" (connection refused, timeout, a response that is not HTTP), which
 * says nothing about whether the application leaks state. The CLI maps it to
 * exit code 3, while a failed assertion is exit code 1.
 */
final class ReplayException extends WorkerSafetyException
{
    public static function connectionFailed(string $url, string $reason): self
    {
        return new self(sprintf(
            'Could not reach %s: %s. Replay needs an already-running application; start one and try again.',
            $url,
            $reason,
        ));
    }

    public static function timedOut(string $url, float $seconds): self
    {
        return new self(sprintf(
            'No response from %s within %ss. Raise --timeout if the application is simply slow.',
            $url,
            rtrim(rtrim(number_format($seconds, 2, '.', ''), '0'), '.'),
        ));
    }

    /**
     * The body stopped short of the length the response declared.
     *
     * Reported as a transport failure rather than a short body, because an
     * expectation about content that never arrived cannot hold or fail — there
     * is nothing to judge.
     */
    public static function incompleteBody(string $url, int $expected, int $received): self
    {
        return new self(sprintf(
            'Incomplete response from %s: Content-Length declared %d byte(s) but %d arrived before the connection '
            . 'closed. Nothing can be asserted about a body that was cut short.',
            $url,
            $expected,
            $received,
        ));
    }

    public static function malformedResponse(string $url, string $reason): self
    {
        return new self(sprintf('Malformed HTTP response from %s: %s', $url, $reason));
    }
}
