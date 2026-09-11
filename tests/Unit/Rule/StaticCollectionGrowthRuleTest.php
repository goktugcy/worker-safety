<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\StaticCollectionGrowthRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(StaticCollectionGrowthRule::class)]
final class StaticCollectionGrowthRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_growth_without_any_release_path_is_high(): void
    {
        $result = AnalyzerHarness::analyze('WS008/positive.php', [new StaticCollectionGrowthRule()]);

        $append = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 9, Severity::High);
        $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 11, Severity::High);

        self::assertStringContainsString('without any release path', $append->message);
    }

    /**
     * The eviction idiom is recognised as intent, not as proof: the finding
     * drops to MEDIUM instead of disappearing, because nothing in the code
     * shows the removal actually bounds the array.
     */
    public function test_an_eviction_in_the_same_method_lowers_the_severity(): void
    {
        $result = AnalyzerHarness::analyze('WS008/safe.php', [new StaticCollectionGrowthRule()]);

        $finding = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 11, Severity::Medium);

        self::assertStringContainsString('not a provable bound', $finding->message);
        self::assertStringContainsString('worker-safety-ignore WS008', (string) $finding->details);
    }

    public function test_a_reset_only_release_path_is_medium(): void
    {
        $result = AnalyzerHarness::analyze('WS008/edge.php', [new StaticCollectionGrowthRule()]);

        $finding = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 9, Severity::Medium);

        self::assertStringContainsString('flush()', (string) $finding->details);
    }

    public function test_function_scoped_memoisation_is_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS008/edge.php', [new StaticCollectionGrowthRule()]);

        $finding = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 27, Severity::High);

        self::assertSame('memo', $finding->symbol->variable);
    }
}
