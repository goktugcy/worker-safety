<?php

declare(strict_types=1);

namespace WorkerSafety\Ignore;

use WorkerSafety\Config\Configuration;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Support\ExcludeMatcher;

/**
 * Decides whether a finding is suppressed, by inline directive or by config.
 */
final class FindingSuppressor
{
    /**
     * @var array<string, SuppressionIndex>
     */
    private array $indexes = [];

    /**
     * @var array<string, ExcludeMatcher|null>
     */
    private array $matchers = [];

    public function __construct(private readonly Configuration $configuration)
    {
    }

    public function addFileIndex(string $absolutePath, SuppressionIndex $index): void
    {
        if (!$index->isEmpty()) {
            $this->indexes[$absolutePath] = $index;
        }
    }

    public function isSuppressed(Finding $finding): bool
    {
        $index = $this->indexes[$finding->location->absolutePath] ?? null;

        if ($index instanceof SuppressionIndex && $index->isSuppressed($finding->ruleId, $finding->location->line)) {
            return true;
        }

        return $this->configMatcher($finding->ruleId)?->matches($finding->location->relativePath) === true;
    }

    private function configMatcher(string $ruleId): ?ExcludeMatcher
    {
        if (!array_key_exists($ruleId, $this->matchers)) {
            $this->matchers[$ruleId] = $this->configuration->ignoreMatcherFor($ruleId);
        }

        return $this->matchers[$ruleId];
    }
}
