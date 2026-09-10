<?php

declare(strict_types=1);

namespace WorkerSafety\Exception;

/**
 * Thrown when a configuration file (or CLI option) is invalid.
 *
 * Surfaces as exit code 2.
 */
final class ConfigurationException extends WorkerSafetyException
{
    public static function invalidValue(string $key, string $reason): self
    {
        return new self(sprintf('Invalid configuration value for "%s": %s', $key, $reason));
    }

    /**
     * @param list<string> $allowed
     */
    public static function unknownKey(string $key, string $context, array $allowed): self
    {
        sort($allowed);

        return new self(sprintf(
            'Unknown configuration key "%s" in %s. Allowed keys: %s.',
            $key,
            $context,
            implode(', ', $allowed),
        ));
    }

    public static function unreadable(string $path): self
    {
        return new self(sprintf('Configuration file "%s" does not exist or is not readable.', $path));
    }
}
