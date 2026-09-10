<?php

declare(strict_types=1);

namespace WorkerSafety\Config;

use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Framework\DetectedFramework;

/**
 * Renders the commented configuration file created by `worker-safety init`.
 *
 * The same template is committed as worker-safety.example.yaml so the two can
 * never drift apart.
 */
final class ConfigurationTemplate
{
    private function __construct()
    {
    }

    public static function render(?DetectedFramework $framework = null): string
    {
        $paths = ['src'];

        if ($framework !== null && $framework->isLaravel()) {
            $paths = ['app', 'bootstrap', 'config', 'routes'];
        } elseif ($framework !== null && $framework->isSymfony()) {
            $paths = ['src', 'config'];
        }

        $pathLines = implode("\n", array_map(static fn (string $path): string => '  - ' . $path, $paths));
        $name = ApplicationInfo::NAME;
        $binary = ApplicationInfo::BINARY;
        $homepage = ApplicationInfo::HOMEPAGE;
        $baseline = ApplicationInfo::BASELINE_FILE;

        $lines = [
            '# ' . $name . ' configuration',
            '# ' . $homepage,
            '',
            '# Directories or files to analyze, relative to this file.',
            'paths:',
            $pathLines,
            '',
            '# Paths that are never analyzed. A bare name matches any path segment,',
            '# a value containing "/" matches a path prefix, and globs are supported.',
            'exclude:',
            '  - vendor',
            '  - node_modules',
            '  - storage',
            '  - cache',
            '  - .git',
            '',
            '# Persistent worker runtimes to analyze for.',
            '# One or more of: frankenphp, octane, roadrunner, swoole - or "all".',
            'runtime:',
            '  - frankenphp',
            '  - octane',
            '',
            '# Per-rule overrides. Run `' . $binary . ' rules` for the full list.',
            'rules:',
            '  WS001:',
            '    enabled: true',
            '    severity: high',
            '',
            '  # Event listener detection is heuristic; turn it off if it is noisy.',
            '  WS009:',
            '    enabled: true',
            '',
            '# Suppress a rule for specific paths, in addition to inline',
            '# `// ' . ApplicationInfo::IGNORE_MARKER . ' WS001` comments.',
            'ignore:',
            '  WS008:',
            '    - app/Legacy',
            '',
            '# Exit with code 1 when a finding at or above this severity remains.',
            '# One of: critical, high, medium, low, info, never.',
            'fail_on: high',
            '',
            '# Path to the baseline file. Set to false to ignore an existing baseline.',
            'baseline: ' . $baseline,
            '',
        ];

        return implode("\n", $lines);
    }
}
