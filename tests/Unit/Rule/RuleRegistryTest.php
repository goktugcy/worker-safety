<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Config\RuleSetting;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Rule\RuleRegistry;
use WorkerSafety\Rule\RuleRegistryFactory;
use WorkerSafety\Runtime\RuntimeTarget;
use WorkerSafety\Runtime\RuntimeTargetSet;

#[CoversClass(RuleRegistry::class)]
#[CoversClass(RuleRegistryFactory::class)]
final class RuleRegistryTest extends TestCase
{
    private function registry(): RuleRegistry
    {
        return (new RuleRegistryFactory())->create();
    }

    public function test_every_documented_rule_is_registered(): void
    {
        self::assertSame(RuleId::all(), $this->registry()->ids());
    }

    public function test_rule_ids_are_unique_and_metadata_is_complete(): void
    {
        foreach ($this->registry()->all() as $rule) {
            $definition = $rule->definition();

            self::assertMatchesRegularExpression('/^WS\d{3}$/', $definition->id);
            self::assertNotSame('', $definition->title);
            self::assertNotSame('', $definition->description);
            self::assertNotSame([], $definition->remediation, $definition->id . ' needs remediation advice');
            self::assertFalse($definition->runtimes->isEmpty(), $definition->id . ' needs runtime targets');
        }
    }

    public function test_lookup_is_case_insensitive(): void
    {
        self::assertTrue($this->registry()->has('ws001'));
        self::assertNotNull($this->registry()->get('ws001'));
        self::assertNull($this->registry()->get('WS999'));
    }

    public function test_disabled_rules_are_filtered_out(): void
    {
        $configuration = new Configuration(rules: ['WS001' => new RuleSetting(false)]);

        $ids = $this->idsOf($this->registry()->enabledFor(
            $configuration,
            DetectedFramework::none(),
            RuntimeTargetSet::all(),
        ));

        self::assertNotContains(RuleId::MUTABLE_STATIC_PROPERTY, $ids);
        self::assertContains(RuleId::MUTABLE_GLOBAL_VARIABLE, $ids);
    }

    public function test_framework_rules_only_run_for_their_framework(): void
    {
        $plain = $this->idsOf($this->registry()->enabledFor(
            Configuration::defaults(),
            DetectedFramework::none(),
            RuntimeTargetSet::all(),
        ));

        self::assertNotContains(RuleId::LARAVEL_SINGLETON_MUTABLE_STATE, $plain);
        self::assertNotContains(RuleId::LARAVEL_SCOPED_CANDIDATE, $plain);

        $laravel = $this->idsOf($this->registry()->enabledFor(
            Configuration::defaults(),
            new DetectedFramework(Framework::Laravel, '12'),
            RuntimeTargetSet::all(),
        ));

        self::assertContains(RuleId::LARAVEL_SINGLETON_MUTABLE_STATE, $laravel);
        self::assertContains(RuleId::LARAVEL_SCOPED_CANDIDATE, $laravel);
    }

    public function test_selecting_a_single_runtime_keeps_the_rules_that_target_it(): void
    {
        $ids = $this->idsOf($this->registry()->enabledFor(
            Configuration::defaults(),
            DetectedFramework::none(),
            new RuntimeTargetSet([RuntimeTarget::Swoole]),
        ));

        self::assertContains(RuleId::MUTABLE_STATIC_PROPERTY, $ids);
    }

    public function test_the_rules_command_can_list_framework_rules_in_any_project(): void
    {
        self::assertTrue($this->registry()->has(RuleId::LARAVEL_SINGLETON_MUTABLE_STATE));
        self::assertSame(10, $this->registry()->count());
    }

    /**
     * @param list<Rule> $rules
     *
     * @return list<string>
     */
    private function idsOf(array $rules): array
    {
        return array_map(static fn (Rule $rule): string => $rule->definition()->id, $rules);
    }
}
