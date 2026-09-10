<?php

declare(strict_types=1);

namespace WorkerSafety\Config;

use WorkerSafety\Finding\Severity;

/**
 * Per-rule configuration overrides.
 */
final class RuleSetting
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly ?Severity $severity = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }
}
