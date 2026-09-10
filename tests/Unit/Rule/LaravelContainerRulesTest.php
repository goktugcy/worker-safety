<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\Laravel\LaravelAdapter;
use WorkerSafety\Framework\Laravel\Rule\ContainerSingletonMutableStateRule;
use WorkerSafety\Framework\Laravel\Rule\ScopedBindingCandidateRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;
use WorkerSafety\Tests\Support\Fixtures;

#[CoversClass(ContainerSingletonMutableStateRule::class)]
#[CoversClass(ScopedBindingCandidateRule::class)]
final class LaravelContainerRulesTest extends TestCase
{
    use FindingAssertions;

    private function analyze(): \WorkerSafety\Analyzer\AnalysisResult
    {
        $adapter = new LaravelAdapter();

        return AnalyzerHarness::analyze(
            Fixtures::laravelApp() . '/app',
            $adapter->rules(),
            new DetectedFramework(Framework::Laravel, '12', 'laravel/framework'),
            null,
            null,
            $adapter->bindingCollectors(),
            Fixtures::laravelApp(),
        );
    }

    public function test_a_singleton_with_request_specific_state_is_high(): void
    {
        $finding = $this->assertHasFinding(
            $this->analyze(),
            RuleId::LARAVEL_SINGLETON_MUTABLE_STATE,
            null,
            Severity::High,
        );

        self::assertStringContainsString('UserContext', $finding->message);
        self::assertStringContainsString('request-specific mutable state', $finding->message);
        self::assertStringContainsString('$user', $finding->message);
    }

    public function test_the_finding_is_reported_at_the_binding_not_at_the_class(): void
    {
        $finding = $this->assertHasFinding($this->analyze(), RuleId::LARAVEL_SINGLETON_MUTABLE_STATE);

        self::assertStringContainsString('AppServiceProvider.php', $finding->location->relativePath);
        self::assertStringContainsString('singleton(UserContext::class)', (string) $finding->snippet);
        self::assertStringContainsString('UserContext.php', (string) $finding->details);
    }

    public function test_it_suggests_a_scoped_binding(): void
    {
        $finding = $this->assertHasFinding(
            $this->analyze(),
            RuleId::LARAVEL_SCOPED_CANDIDATE,
            null,
            Severity::Medium,
        );

        self::assertStringContainsString('scoped', $finding->message);
        self::assertStringContainsString('scoped(UserContext::class', (string) $finding->details);
    }

    public function test_an_immutable_service_bound_as_a_singleton_is_not_reported(): void
    {
        foreach ($this->analyze()->findings as $finding) {
            self::assertStringNotContainsString('CurrencyFormatter', $finding->message);
        }
    }

    public function test_a_class_already_bound_as_scoped_is_not_reported(): void
    {
        foreach ($this->analyze()->findings as $finding) {
            self::assertStringNotContainsString('TenantRegistry', $finding->message);
        }
    }

    public function test_exactly_one_finding_per_container_rule(): void
    {
        $result = $this->analyze();

        self::assertCount(1, self::findingsFor($result, RuleId::LARAVEL_SINGLETON_MUTABLE_STATE));
        self::assertCount(1, self::findingsFor($result, RuleId::LARAVEL_SCOPED_CANDIDATE));
    }
}
