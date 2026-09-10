<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\MutableStaticPropertyRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(MutableStaticPropertyRule::class)]
final class MutableStaticPropertyRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_it_reports_a_static_property_that_is_assigned_at_runtime(): void
    {
        $result = AnalyzerHarness::analyze('WS001/positive.php', [new MutableStaticPropertyRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_STATIC_PROPERTY, 9, Severity::High);

        self::assertSame('currentUser', $finding->symbol->property);
        self::assertStringContainsString('may persist between requests', $finding->message);
        self::assertNotSame([], $finding->remediation);
        self::assertNotNull($finding->details);
    }

    public function test_it_downgrades_an_append_only_collection_because_ws008_owns_the_memory_story(): void
    {
        $result = AnalyzerHarness::analyze('WS001/positive.php', [new MutableStaticPropertyRule()]);

        $this->assertHasFinding($result, RuleId::MUTABLE_STATIC_PROPERTY, 11, Severity::Medium);
    }

    public function test_it_stays_quiet_about_constants_and_unwritten_scalar_configuration(): void
    {
        $result = AnalyzerHarness::analyze('WS001/safe.php', [new MutableStaticPropertyRule()]);

        $this->assertNoFindings($result);
    }

    public function test_an_explicit_reset_path_lowers_the_severity(): void
    {
        $result = AnalyzerHarness::analyze('WS001/edge.php', [new MutableStaticPropertyRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_STATIC_PROPERTY, 12, Severity::Medium);

        self::assertStringContainsString('reset path', (string) $finding->details);
    }

    public function test_a_scalar_static_initialised_from_a_constant_is_not_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS001/edge.php', [new MutableStaticPropertyRule()]);

        foreach ($result->findings as $finding) {
            self::assertNotSame('configuredMax', $finding->symbol->property);
        }
    }

    public function test_it_does_not_report_the_singleton_instance_holder(): void
    {
        $result = AnalyzerHarness::analyze('WS004/positive.php', [new MutableStaticPropertyRule()]);

        $this->assertNoFinding($result, RuleId::MUTABLE_STATIC_PROPERTY);
    }
}
