<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * Static metadata describing one rule.
 */
final class RuleDefinition
{
    /**
     * @param list<string> $remediation ordered, actionable suggestions
     * @param string|null $requiresFramework framework identifier the rule needs, or null for any project
     */
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $description,
        public readonly Severity $defaultSeverity,
        public readonly RuleCategory $category,
        public readonly array $remediation,
        public readonly RuntimeTargetSet $runtimes,
        public readonly ?string $requiresFramework = null,
    ) {
    }
}
