<?php

declare(strict_types=1);

namespace WorkerSafety\Application;

/**
 * The contract CI relies on.
 */
enum ExitCode: int
{
    /** Scan completed and nothing reached the failure threshold. */
    case Success = 0;

    /** Scan completed but findings reached the `--fail-on` threshold. */
    case FindingsAboveThreshold = 1;

    /** The configuration or a CLI option was invalid; nothing was analyzed. */
    case InvalidConfiguration = 2;

    /** An internal error aborted the run. */
    case InternalError = 3;

    public function describe(): string
    {
        return match ($this) {
            self::Success => 'success',
            self::FindingsAboveThreshold => 'findings above threshold',
            self::InvalidConfiguration => 'invalid configuration',
            self::InternalError => 'internal error',
        };
    }
}
