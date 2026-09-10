<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Analyzer\AnalysisResult;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\Laravel\LaravelAdapter;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Rule\RuleRegistryFactory;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

/**
 * Accuracy regressions found in the 0.1.0 release review.
 *
 * Each test pins one previously wrong answer — a missed finding or a false
 * positive — so the analysis cannot quietly drift back.
 */
#[CoversNothing]
final class AnalysisAccuracyRegressionTest extends TestCase
{
    use FindingAssertions;

    private function laravel(string $fixture): AnalysisResult
    {
        $adapter = new LaravelAdapter();

        return AnalyzerHarness::analyze(
            $fixture,
            $adapter->rules(),
            new DetectedFramework(Framework::Laravel, '12', 'laravel/framework'),
            null,
            null,
            $adapter->bindingCollectors(),
        );
    }

    private function builtIn(string $fixture): AnalysisResult
    {
        return AnalyzerHarness::analyze($fixture, (new RuleRegistryFactory())->builtIn());
    }

    /**
     * @return list<string>
     */
    private function abstractsReported(AnalysisResult $result): array
    {
        return array_map(
            static fn (Finding $finding): string => (string) $finding->snippet,
            self::findingsFor($result, RuleId::LARAVEL_SINGLETON_MUTABLE_STATE),
        );
    }

    public function test_a_scoped_binding_under_another_key_does_not_hide_the_singleton(): void
    {
        $snippets = $this->abstractsReported($this->laravel('Regression/container-keys.php'));

        self::assertContains(
            "\$app->singleton('shared', RequestContext::class);",
            $snippets,
            'Flushing the scoped `request` key never flushes the `shared` singleton.',
        );
    }

    public function test_a_free_form_service_id_is_still_a_binding(): void
    {
        $snippets = $this->abstractsReported($this->laravel('Regression/container-keys.php'));

        self::assertContains("\$app->singleton('auth.context', RequestContext::class);", $snippets);
    }

    public function test_an_already_built_instance_names_its_concrete_type(): void
    {
        $snippets = $this->abstractsReported($this->laravel('Regression/container-keys.php'));

        self::assertContains("\$app->instance('ctx', new RequestContext());", $snippets);
    }

    public function test_a_factory_resolves_to_the_returned_type_not_the_first_allocation(): void
    {
        $result = $this->laravel('Regression/container-keys.php');

        $factory = array_values(array_filter(
            self::findingsFor($result, RuleId::LARAVEL_SINGLETON_MUTABLE_STATE),
            static fn (Finding $f): bool => str_contains((string) $f->snippet, 'function ()'),
        ));

        self::assertCount(1, $factory, 'The closure builds a RequestContext, not the SafeDep it allocates first.');
        self::assertStringContainsString('RequestContext', $factory[0]->message);
        self::assertStringNotContainsString('SafeDep', $factory[0]->message);
    }

    public function test_a_class_bound_only_as_scoped_is_not_reported(): void
    {
        $this->assertNoFindings($this->laravel('Regression/scoped-only.php'));
    }

    public function test_a_qualified_name_is_never_matched_against_a_local_short_name(): void
    {
        $this->assertNoFindings($this->laravel('Regression/external-class.php'));
    }

    public function test_a_readonly_class_has_no_mutable_state(): void
    {
        $this->assertNoFindings($this->laravel('Regression/readonly-class.php'));
        $this->assertNoFindings($this->builtIn('Regression/readonly-class.php'));
    }

    public function test_array_slice_is_not_a_release_path(): void
    {
        $result = $this->builtIn('Regression/collection-bounds.php');

        $finding = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 9, Severity::High);

        self::assertStringContainsString('without any release path', $finding->message);
    }

    public function test_a_bound_in_one_method_does_not_cover_another_method(): void
    {
        $result = $this->builtIn('Regression/collection-bounds.php');

        // Reported at the declaration; `unbounded()` appends with no eviction.
        $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, 22, Severity::High);
    }

    public function test_a_literal_key_bounds_the_collection(): void
    {
        $result = $this->builtIn('Regression/collection-bounds.php');

        foreach (self::findingsFor($result, RuleId::STATIC_COLLECTION_GROWTH) as $finding) {
            self::assertNotSame(
                38,
                $finding->location->line,
                'A literal key writes one slot, so the entry count cannot run away.',
            );
        }
    }

    public function test_unsetting_a_static_local_entry_stays_a_clearing_write(): void
    {
        $result = $this->builtIn('Regression/collection-bounds.php');

        foreach (self::findingsFor($result, RuleId::STATIC_COLLECTION_GROWTH) as $finding) {
            self::assertNotSame('items', $finding->symbol->variable);
        }
    }

    public function test_an_aliased_import_is_still_the_builtin_function(): void
    {
        $result = $this->builtIn('Regression/call-resolution.php');

        $this->assertHasFinding($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION, 14, Severity::High);
    }

    public function test_a_locale_query_is_not_a_mutation(): void
    {
        $result = $this->builtIn('Regression/call-resolution.php');

        foreach (self::findingsFor($result, RuleId::RUNTIME_ENVIRONMENT_MUTATION) as $finding) {
            self::assertStringNotContainsString('setlocale', $finding->message);
        }
    }

    public function test_an_instance_registry_is_not_assumed_to_outlive_the_request(): void
    {
        $this->assertNoFindings($this->builtIn('Regression/instance-registry.php'));
    }
}
