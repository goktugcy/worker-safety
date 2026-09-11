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

    /**
     * `scoped()` changes how long the instance lives, so the finding has to
     * ask for a review rather than present a rename. The trap it must name is
     * a longer-lived consumer that captured the old instance: rebinding does
     * not reach into an object that already holds a reference.
     */
    public function test_the_scoped_suggestion_asks_for_a_lifetime_review_rather_than_a_swap(): void
    {
        $finding = $this->assertHasFinding($this->analyze(), RuleId::LARAVEL_SCOPED_CANDIDATE);

        self::assertStringContainsString('candidate', $finding->message);
        self::assertStringNotContainsString('should use a scoped binding', $finding->message);

        $details = (string) $finding->details;
        self::assertStringContainsString('change of lifetime', $details);
        self::assertStringContainsString('review', $details);

        $advice = implode(' ', $finding->remediation);
        self::assertStringContainsString('outlive a request', $advice);
        self::assertStringNotContainsString('the change is safe', $advice);
    }

    /**
     * Softening WS006 must not quiet WS005: a mutable singleton is the finding
     * that carries the actual persistent-worker risk here.
     */
    public function test_the_mutable_singleton_finding_survives_alongside_it(): void
    {
        $result = $this->analyze();

        $singleton = $this->assertHasFinding($result, RuleId::LARAVEL_SINGLETON_MUTABLE_STATE, null, Severity::High);
        $scoped = $this->assertHasFinding($result, RuleId::LARAVEL_SCOPED_CANDIDATE, null, Severity::Medium);

        self::assertSame($singleton->symbol->class, $scoped->symbol->class);
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
