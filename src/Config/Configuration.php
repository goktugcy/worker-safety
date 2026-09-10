<?php

declare(strict_types=1);

namespace WorkerSafety\Config;

use WorkerSafety\Finding\Severity;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\ExcludeMatcher;

/**
 * Immutable, fully resolved scan configuration.
 */
final class Configuration
{
    /**
     * Never scanned unless the user overrides `exclude` entirely.
     *
     * @var list<string>
     */
    public const DEFAULT_EXCLUDES = [
        '.git',
        'cache',
        'node_modules',
        'storage',
        'vendor',
    ];

    /**
     * @var list<string>
     */
    public const DEFAULT_EXTENSIONS = ['php'];

    /**
     * @param list<string> $paths project-relative or absolute scan roots
     * @param list<string> $exclude exclude patterns
     * @param list<string> $extensions file extensions without the dot
     * @param array<string, RuleSetting> $rules keyed by rule id
     * @param array<string, list<string>> $ignore rule id => path patterns to ignore
     */
    public function __construct(
        public readonly array $paths = [],
        public readonly array $exclude = self::DEFAULT_EXCLUDES,
        public readonly array $extensions = self::DEFAULT_EXTENSIONS,
        public readonly RuntimeTargetSet $runtimes = new RuntimeTargetSet([]),
        public readonly array $rules = [],
        public readonly ?Severity $failOn = Severity::High,
        public readonly ?string $baseline = null,
        public readonly array $ignore = [],
        public readonly ?string $sourcePath = null,
        public readonly bool $failOnParseError = true,
        public readonly bool $baselineDisabled = false,
    ) {
    }

    public static function defaults(): self
    {
        return new self(runtimes: RuntimeTargetSet::all());
    }

    public function ruleSetting(string $ruleId): RuleSetting
    {
        return $this->rules[strtoupper($ruleId)] ?? RuleSetting::default();
    }

    public function isRuleEnabled(string $ruleId): bool
    {
        return $this->ruleSetting($ruleId)->enabled;
    }

    /**
     * The configured severity wins over whatever the rule computed.
     */
    public function severityFor(string $ruleId, Severity $reported): Severity
    {
        return $this->ruleSetting($ruleId)->severity ?? $reported;
    }

    /**
     * Path patterns for which the given rule is suppressed.
     */
    public function ignoreMatcherFor(string $ruleId): ?ExcludeMatcher
    {
        $patterns = $this->ignore[strtoupper($ruleId)] ?? null;

        if ($patterns === null || $patterns === []) {
            return null;
        }

        return new ExcludeMatcher($patterns);
    }

    /**
     * @param list<string> $paths
     */
    public function withPaths(array $paths): self
    {
        return $this->copyWith(paths: $paths);
    }

    /**
     * @param list<string> $exclude
     */
    public function withExclude(array $exclude): self
    {
        return $this->copyWith(exclude: $exclude);
    }

    /**
     * Adds patterns without dropping the configured ones.
     *
     * @param list<string> $patterns
     */
    public function withAdditionalExcludes(array $patterns): self
    {
        $merged = $this->exclude;

        foreach ($patterns as $pattern) {
            if (!in_array($pattern, $merged, true)) {
                $merged[] = $pattern;
            }
        }

        return $this->copyWith(exclude: $merged);
    }

    public function withRuntimes(RuntimeTargetSet $runtimes): self
    {
        return $this->copyWith(runtimes: $runtimes);
    }

    /**
     * @param ?Severity $failOn null disables the failure threshold
     */
    public function withFailOn(?Severity $failOn): self
    {
        return new self(
            $this->paths,
            $this->exclude,
            $this->extensions,
            $this->runtimes,
            $this->rules,
            $failOn,
            $this->baseline,
            $this->ignore,
            $this->sourcePath,
            $this->failOnParseError,
            $this->baselineDisabled,
        );
    }

    public function withBaseline(?string $baseline): self
    {
        return new self(
            $this->paths,
            $this->exclude,
            $this->extensions,
            $this->runtimes,
            $this->rules,
            $this->failOn,
            $baseline,
            $this->ignore,
            $this->sourcePath,
            $this->failOnParseError,
            $this->baselineDisabled,
        );
    }

    public function withSourcePath(?string $sourcePath): self
    {
        return new self(
            $this->paths,
            $this->exclude,
            $this->extensions,
            $this->runtimes,
            $this->rules,
            $this->failOn,
            $this->baseline,
            $this->ignore,
            $sourcePath,
            $this->failOnParseError,
            $this->baselineDisabled,
        );
    }

    /**
     * @param list<string>|null $paths
     * @param list<string>|null $exclude
     * @param array<string, RuleSetting>|null $rules
     */
    private function copyWith(
        ?array $paths = null,
        ?array $exclude = null,
        ?RuntimeTargetSet $runtimes = null,
        ?array $rules = null,
    ): self {
        return new self(
            $paths ?? $this->paths,
            $exclude ?? $this->exclude,
            $this->extensions,
            $runtimes ?? $this->runtimes,
            $rules ?? $this->rules,
            $this->failOn,
            $this->baseline,
            $this->ignore,
            $this->sourcePath,
            $this->failOnParseError,
            $this->baselineDisabled,
        );
    }
}
