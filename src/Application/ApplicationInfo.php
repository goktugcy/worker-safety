<?php

declare(strict_types=1);

namespace WorkerSafety\Application;

/**
 * Single source of truth for the package identity.
 *
 * Renaming the vendor/package only requires editing the constants here, the
 * `name` field in composer.json and the PSR-4 namespace prefix.
 */
final class ApplicationInfo
{
    public const NAME = 'Worker Safety';

    public const BINARY = 'worker-safety';

    public const VERSION = '1.0.0';

    public const PACKAGE = 'goktugcy/worker-safety';

    public const HOMEPAGE = 'https://github.com/goktugcy/worker-safety';

    /**
     * Base name used for the generated configuration file.
     */
    public const CONFIG_FILE = 'worker-safety.yaml';

    public const BASELINE_FILE = 'worker-safety-baseline.json';

    /**
     * Prefix used by inline suppression comments.
     */
    public const IGNORE_MARKER = 'worker-safety-ignore';

    public const IGNORE_ATTRIBUTE = 'WorkerSafetyIgnore';

    private function __construct()
    {
    }
}
