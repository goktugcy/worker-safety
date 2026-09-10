<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Rule\BuiltIn\PersistentListenerRegistrationRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(PersistentListenerRegistrationRule::class)]
final class PersistentListenerRegistrationRuleTest extends TestCase
{
    use FindingAssertions;

    private function laravel(): DetectedFramework
    {
        return new DetectedFramework(Framework::Laravel, '12', 'laravel/framework');
    }

    public function test_event_listen_on_a_request_path_is_reported_for_laravel(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/positive.php',
            [new PersistentListenerRegistrationRule()],
            $this->laravel(),
        );

        $this->assertHasFinding($result, RuleId::PERSISTENT_LISTENER_REGISTRATION, 16, Severity::Medium);
    }

    public function test_laravel_specific_patterns_are_not_reported_for_plain_php(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/positive.php',
            [new PersistentListenerRegistrationRule()],
            DetectedFramework::none(),
        );

        foreach (self::findingsFor($result, RuleId::PERSISTENT_LISTENER_REGISTRATION) as $finding) {
            self::assertNotSame(16, $finding->location->line);
        }
    }

    public function test_macro_registration_is_framework_independent(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/positive.php',
            [new PersistentListenerRegistrationRule()],
            DetectedFramework::none(),
        );

        $finding = $this->assertHasFinding($result, RuleId::PERSISTENT_LISTENER_REGISTRATION, 20);

        self::assertStringContainsString('macro', $finding->message);
    }

    public function test_appending_a_callback_to_a_listener_registry_is_reported(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/positive.php',
            [new PersistentListenerRegistrationRule()],
            DetectedFramework::none(),
        );

        $finding = $this->assertHasFinding($result, RuleId::PERSISTENT_LISTENER_REGISTRATION, 22);

        self::assertStringContainsString('$listeners', $finding->message);
    }

    public function test_native_registration_functions_are_reported(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/positive.php',
            [new PersistentListenerRegistrationRule()],
            DetectedFramework::none(),
        );

        $this->assertHasFinding($result, RuleId::PERSISTENT_LISTENER_REGISTRATION, 24, Severity::Medium);
    }

    public function test_registration_inside_a_service_provider_is_not_reported(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/safe.php',
            [new PersistentListenerRegistrationRule()],
            $this->laravel(),
        );

        $this->assertNoFindings($result);
    }

    public function test_bootstrap_level_registration_is_downgraded_to_low(): void
    {
        $result = AnalyzerHarness::analyze(
            'WS009/edge.php',
            [new PersistentListenerRegistrationRule()],
            DetectedFramework::none(),
        );

        $this->assertHasFinding($result, RuleId::PERSISTENT_LISTENER_REGISTRATION, 6, Severity::Low);
    }
}
