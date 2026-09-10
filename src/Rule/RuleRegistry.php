<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use WorkerSafety\Config\Configuration;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * Holds the available rules and decides which apply to a given scan.
 */
final class RuleRegistry
{
    /**
     * @var array<string, Rule>
     */
    private array $rules = [];

    /**
     * @param iterable<Rule> $rules
     */
    public function __construct(iterable $rules = [])
    {
        foreach ($rules as $rule) {
            $this->register($rule);
        }
    }

    public function register(Rule $rule): void
    {
        $this->rules[$rule->definition()->id] = $rule;
    }

    public function has(string $ruleId): bool
    {
        return isset($this->rules[strtoupper($ruleId)]);
    }

    public function get(string $ruleId): ?Rule
    {
        return $this->rules[strtoupper($ruleId)] ?? null;
    }

    /**
     * All registered rules ordered by id.
     *
     * @return list<Rule>
     */
    public function all(): array
    {
        $rules = $this->rules;
        ksort($rules);

        return array_values($rules);
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = array_keys($this->rules);
        sort($ids);

        return $ids;
    }

    /**
     * Rules that are enabled, match the detected framework and target at least
     * one of the selected runtimes.
     *
     * @return list<Rule>
     */
    public function enabledFor(
        Configuration $configuration,
        DetectedFramework $framework,
        RuntimeTargetSet $runtimes,
    ): array {
        $enabled = [];

        foreach ($this->all() as $rule) {
            $definition = $rule->definition();

            if (!$configuration->isRuleEnabled($definition->id)) {
                continue;
            }

            if ($definition->requiresFramework !== null
                && $definition->requiresFramework !== $framework->identifier()
            ) {
                continue;
            }

            if ($runtimes->intersect($definition->runtimes)->isEmpty()) {
                continue;
            }

            $enabled[] = $rule;
        }

        return $enabled;
    }

    public function count(): int
    {
        return count($this->rules);
    }
}
