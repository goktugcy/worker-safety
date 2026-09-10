<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\RuntimeEnvironmentMutationRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(RuntimeEnvironmentMutationRule::class)]
final class RuntimeEnvironmentMutationRuleTest extends TestCase
{
    use FindingAssertions;

    public function test_putenv_and_env_writes_are_high(): void
    {
        $result = AnalyzerHarness::analyze('WS003/positive.php', [new RuntimeEnvironmentMutationRule()]);

        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 11, Severity::High);
        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 12, Severity::High);
    }

    public function test_ini_and_timezone_changes_are_medium(): void
    {
        $result = AnalyzerHarness::analyze('WS003/positive.php', [new RuntimeEnvironmentMutationRule()]);

        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 14, Severity::Medium);
        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 15, Severity::Medium);
    }

    /**
     * $_SERVER is rebuilt for every request by the supported runtimes, unlike
     * $_ENV, which FrankenPHP documents as the exception.
     */
    public function test_server_writes_rank_below_env_writes(): void
    {
        $result = AnalyzerHarness::analyze('WS003/positive.php', [new RuntimeEnvironmentMutationRule()]);

        $env = $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 12, Severity::High);
        $server = $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 13, Severity::Low);

        self::assertStringContainsString('documented exception', (string) $env->details);
        self::assertStringContainsString('rebuilt for every request', (string) $server->details);
    }

    public function test_a_locale_query_is_not_a_mutation(): void
    {
        $result = AnalyzerHarness::analyze('WS003/safe.php', [new RuntimeEnvironmentMutationRule()]);

        $this->assertNoFindings($result);
    }

    public function test_reads_are_never_reported(): void
    {
        $result = AnalyzerHarness::analyze('WS003/safe.php', [new RuntimeEnvironmentMutationRule()]);

        $this->assertNoFindings($result);
    }

    public function test_file_scope_bootstrap_calls_are_downgraded(): void
    {
        $result = AnalyzerHarness::analyze('WS003/edge.php', [new RuntimeEnvironmentMutationRule()]);

        $putenv = $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 7, Severity::Medium);
        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 8, Severity::Low);

        self::assertStringContainsString('file scope', (string) $putenv->details);
    }
}
