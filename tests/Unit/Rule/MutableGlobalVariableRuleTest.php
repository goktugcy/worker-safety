<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\MutableGlobalVariableRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(MutableGlobalVariableRule::class)]
final class MutableGlobalVariableRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_an_assigned_global_declaration_is_high(): void
    {
        $result = AnalyzerHarness::analyze('WS002/positive.php', [new MutableGlobalVariableRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_GLOBAL_VARIABLE, 9, Severity::High);

        self::assertSame('currentUser', $finding->symbol->variable);
    }

    public function test_writing_to_globals_is_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS002/positive.php', [new MutableGlobalVariableRule()]);

        $finding = $this->assertHasFinding($result, RuleId::MUTABLE_GLOBAL_VARIABLE, 16, Severity::High);

        self::assertStringContainsString("\$GLOBALS['tenant']", $finding->message);
    }

    /**
     * FrankenPHP documents $_GET/$_POST/$_COOKIE/$_FILES/$_SERVER/$_REQUEST as
     * reset between requests, so rewriting one is an input-integrity smell
     * rather than the cross-request retention that $GLOBALS and $_ENV cause.
     */
    public function test_writing_to_a_request_superglobal_is_low(): void
    {
        $result = AnalyzerHarness::analyze('WS002/positive.php', [new MutableGlobalVariableRule()]);

        $get = $this->assertHasFinding($result, RuleId::MUTABLE_GLOBAL_VARIABLE, 21, Severity::Low);
        $this->assertHasFinding($result, RuleId::MUTABLE_GLOBAL_VARIABLE, 22, Severity::Low);

        self::assertStringContainsString('not by itself a cross-request leak', (string) $get->details);
    }

    public function test_reading_superglobals_is_never_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS002/safe.php', [new MutableGlobalVariableRule()]);

        $this->assertNoFindings($result);
    }

    public function test_a_read_only_global_declaration_is_medium(): void
    {
        $result = AnalyzerHarness::analyze('WS002/edge.php', [new MutableGlobalVariableRule()]);

        $this->assertHasFinding($result, RuleId::MUTABLE_GLOBAL_VARIABLE, 9, Severity::Medium);
    }

    public function test_repeated_writes_to_the_same_global_collapse_into_one_finding(): void
    {
        $result = AnalyzerHarness::analyze('WS002/edge.php', [new MutableGlobalVariableRule()]);

        $globals = array_values(array_filter(
            self::findingsFor($result, RuleId::MUTABLE_GLOBAL_VARIABLE),
            static fn ($finding): bool => str_contains($finding->message, 'counter'),
        ));

        self::assertCount(1, $globals);
        self::assertStringContainsString('Found 3 times', (string) $globals[0]->details);
    }
}
