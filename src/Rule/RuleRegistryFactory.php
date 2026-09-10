<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use WorkerSafety\Framework\FrameworkAdapterRegistry;
use WorkerSafety\Rule\BuiltIn\MutableGlobalVariableRule;
use WorkerSafety\Rule\BuiltIn\MutableSingletonRule;
use WorkerSafety\Rule\BuiltIn\MutableStaticPropertyRule;
use WorkerSafety\Rule\BuiltIn\PersistentListenerRegistrationRule;
use WorkerSafety\Rule\BuiltIn\RuntimeEnvironmentMutationRule;
use WorkerSafety\Rule\BuiltIn\ShutdownLifecycleAssumptionRule;
use WorkerSafety\Rule\BuiltIn\StaticCollectionGrowthRule;
use WorkerSafety\Rule\BuiltIn\StaticRequestContextRule;

/**
 * Builds the rule registry from the built-in rules plus every framework
 * adapter's rules.
 *
 * Framework-specific rules are always registered; {@see RuleRegistry::enabledFor()}
 * is what decides whether they run for the project at hand. That way
 * `worker-safety rules` can list the complete rule set regardless of which
 * project it is invoked in.
 */
final class RuleRegistryFactory
{
    public function __construct(private readonly FrameworkAdapterRegistry $adapters = new FrameworkAdapterRegistry())
    {
    }

    public function create(): RuleRegistry
    {
        $registry = new RuleRegistry($this->builtIn());

        foreach ($this->adapters->all() as $adapter) {
            foreach ($adapter->rules() as $rule) {
                $registry->register($rule);
            }
        }

        return $registry;
    }

    /**
     * The framework-independent rules.
     *
     * @return list<Rule>
     */
    public function builtIn(): array
    {
        return [
            new MutableStaticPropertyRule(),
            new MutableGlobalVariableRule(),
            new RuntimeEnvironmentMutationRule(),
            new MutableSingletonRule(),
            new StaticRequestContextRule(),
            new StaticCollectionGrowthRule(),
            new PersistentListenerRegistrationRule(),
            new ShutdownLifecycleAssumptionRule(),
        ];
    }
}
