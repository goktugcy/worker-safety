<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\StaticRequestContextRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(StaticRequestContextRule::class)]
final class StaticRequestContextRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_a_public_written_request_context_is_critical(): void
    {
        $result = AnalyzerHarness::analyze('WS007/positive.php', [new StaticRequestContextRule()]);

        $finding = $this->assertHasFinding($result, RuleId::STATIC_REQUEST_CONTEXT, 13, Severity::Critical);

        self::assertStringContainsString('request-specific state', $finding->message);
        self::assertStringContainsString('user', (string) $finding->details);
    }

    public function test_a_private_written_request_context_is_high(): void
    {
        $result = AnalyzerHarness::analyze('WS007/positive.php', [new StaticRequestContextRule()]);

        $this->assertHasFinding($result, RuleId::STATIC_REQUEST_CONTEXT, 15, Severity::High);
    }

    public function test_configuration_names_are_neutralised(): void
    {
        $result = AnalyzerHarness::analyze('WS007/safe.php', [new StaticRequestContextRule()]);

        $this->assertNoFindings($result);
    }

    public function test_a_reset_path_lowers_the_severity(): void
    {
        $result = AnalyzerHarness::analyze('WS007/edge.php', [new StaticRequestContextRule()]);

        $this->assertHasFinding($result, RuleId::STATIC_REQUEST_CONTEXT, 9, Severity::Medium);
    }

    public function test_it_also_covers_function_scoped_statics(): void
    {
        $result = AnalyzerHarness::analyze('WS007/edge.php', [new StaticRequestContextRule()]);

        $finding = $this->assertHasFinding($result, RuleId::STATIC_REQUEST_CONTEXT, 27);

        self::assertSame('requestId', $finding->symbol->variable);
        self::assertStringContainsString('Function-scoped static', $finding->message);
    }
}
