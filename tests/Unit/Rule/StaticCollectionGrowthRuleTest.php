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

    public function test_a_collection_bounded_in_the_same_method_is_not_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS008/safe.php', [new StaticCollectionGrowthRule()]);

        $this->assertNoFindings($result);
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
