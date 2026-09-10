<?php

declare(strict_types=1);

namespace WorkerSafety\Exception;

/**
 * Thrown when the analyzer cannot continue at all.
 *
 * Surfaces as exit code 3.
 */
final class AnalysisException extends WorkerSafetyException
{
    public static function pathNotFound(string $path): self
    {
        return new self(sprintf('Path "%s" does not exist.', $path));
    }

    public static function noPaths(): self
    {
        return new self('No scan paths resolved. Provide a path argument or configure "paths" in the configuration file.');
    }
}
