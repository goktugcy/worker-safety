<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\ShutdownLifecycleAssumptionRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTarget;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(ShutdownLifecycleAssumptionRule::class)]
final class ShutdownLifecycleAssumptionRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_shutdown_function_on_a_request_path_is_medium(): void
    {
        $result = AnalyzerHarness::analyze('WS010/positive.php', [new ShutdownLifecycleAssumptionRule()]);

        $finding = $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 11, Severity::Medium);

        self::assertStringContainsString('process shutdown', $finding->message);
    }

    public function test_fastcgi_finish_request_is_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS010/positive.php', [new ShutdownLifecycleAssumptionRule()]);

        $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 16, Severity::Medium);
    }

    public function test_the_message_carries_a_runtime_specific_note_for_a_single_target(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS010/positive.php',
            [new ShutdownLifecycleAssumptionRule()],
            null,
            null,
            new RuntimeTargetSet([RuntimeTarget::FrankenPhp]),
        );

        $finding = $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 16);

        self::assertStringContainsString('FrankenPHP', (string) $finding->details);
        self::assertStringContainsString('worker loop', (string) $finding->details);
    }

    public function test_gc_disable_and_exit_are_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS010/positive.php', [new ShutdownLifecycleAssumptionRule()]);

        $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 19, Severity::Medium);
        $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 21, Severity::Medium);
    }

    public function test_a_request_scoped_sapi_check_is_low(): void
    {
        $result = AnalyzerHarness::analyze('WS010/positive.php', [new ShutdownLifecycleAssumptionRule()]);

        $finding = $this->assertHasFinding($result, RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION, 15, Severity::Low);

        self::assertStringContainsString('fpm-fcgi', $finding->message);
    }

    public function test_a_persistent_friendly_sapi_check_is_not_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS010/safe.php', [new ShutdownLifecycleAssumptionRule()]);

        $this->assertNoFindings($result);
    }

    public function test_a_console_entry_point_may_exit(): void
    {
        $result = AnalyzerHarness::analyze('WS010/edge.php', [new ShutdownLifecycleAssumptionRule()]);

        $this->assertNoFindings($result);
    }
}
