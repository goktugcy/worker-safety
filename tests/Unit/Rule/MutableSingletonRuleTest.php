<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\MutableSingletonRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(MutableSingletonRule::class)]
final class MutableSingletonRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_a_singleton_with_mutable_instance_state_is_high(): void
    {
        $result = AnalyzerHarness::analyze('WS004/positive.php', [new MutableSingletonRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_SINGLETON, 9, Severity::High);

        self::assertStringContainsString('$userId', $finding->message);
        self::assertStringContainsString('$attributes', $finding->message);
    }

    public function test_a_plain_service_is_not_a_singleton(): void
    {
        $result = AnalyzerHarness::analyze('WS004/safe.php', [new MutableSingletonRule()]);

        $this->assertNoFindings($result);
    }

    public function test_an_immutable_singleton_is_only_low(): void
    {
        $result = AnalyzerHarness::analyze('WS004/edge.php', [new MutableSingletonRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_SINGLETON, 12, Severity::Low);

        self::assertStringContainsString('shared by every request', $finding->message);
    }
}
