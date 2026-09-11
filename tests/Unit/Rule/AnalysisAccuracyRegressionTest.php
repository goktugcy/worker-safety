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

        // Reported at the declaration. `bounded()` removes what it adds, but
        // `unbounded()` has no removal at all, and the worst growing scope
        // decides — so this is HIGH regardless of the order the methods are
        // declared in.
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

    /**
     * Add-then-remove of the same key really is bounded, but proving it needs
     * the order of the two writes and the runtime value of the key. The rule
     * reports it at MEDIUM rather than claiming a bound it cannot show.
     */
    public function test_an_unprovable_add_remove_pair_is_reported_at_medium(): void
    {
        $result = $this->builtIn('Regression/collection-bounds.php');

        $local = array_values(array_filter(
            self::findingsFor($result, RuleId::STATIC_COLLECTION_GROWTH),
            static fn (Finding $f): bool => $f->symbol->variable === 'items',
        ));

        self::assertCount(1, $local);
        self::assertSame(Severity::Medium, $local[0]->severity);
        self::assertStringContainsString('not a provable bound', $local[0]->message);
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

    /**
     * @return list<string>
     */
    private function grownClasses(string $fixture): array
    {
        $classes = array_values(array_unique(array_map(
            static fn (Finding $f): string => (string) $f->symbol->class,
            self::findingsFor($this->builtIn($fixture), RuleId::STATIC_COLLECTION_GROWTH),
        )));

        sort($classes);

        return array_map(
            static fn (string $class): string => substr($class, (int) strrpos($class, '\\') + 1),
            $classes,
        );
    }

    public function test_a_fixed_outer_key_does_not_bound_a_nested_collection(): void
    {
        $classes = $this->grownClasses('Regression/nested-growth.php');

        self::assertContains('NestedBucket', $classes, 'The nested [] append grows without bound.');
        self::assertContains('NestedKeyed', $classes, 'The nested dynamic key grows without bound.');
        self::assertNotContains('FixedPath', $classes, 'Every dimension is fixed, so this is one slot.');
    }

    public function test_a_nested_append_in_a_function_static_is_reported(): void
    {
        $result = $this->builtIn('Regression/nested-growth.php');

        $finding = $this->assertHasFinding($result, RuleId::STATIC_COLLECTION_GROWTH, null, Severity::High);

        self::assertContains(
            'items',
            array_map(
                static fn (Finding $f): string => $f->symbol->property ?? (string) $f->symbol->variable,
                self::findingsFor($result, RuleId::STATIC_COLLECTION_GROWTH),
            ),
        );
        self::assertNotSame('', $finding->message);
    }

    /**
     * A bound has to be provable. Counting appends against removals is only
     * sound when each one is guaranteed to run exactly once.
     */
    public function test_only_a_provable_bound_silences_the_growth_warning(): void
    {
        self::assertSame(
            [
                'AddThenRemove',
                'ClearsOneKey',
                'ConditionalRemoval',
                'EvictionBehindASecondCondition',
                'GrowthInLoop',
                'ImpossibleCountGuard',
                'InfiniteLimit',
                'NestedPushUnderFixedKey',
                'NetGrowth',
                'NullsOneKey',
                'PushesTwoPopsOne',
                'ReassignedKey',
                'RemovalAfterEarlyReturn',
                'RemovalBeforeAddition',
                'RemovalDeclaredFirst',
                'RemovalDeclaredSecond',
                'ResetInsideAnUncalledClosure',
                'ResetInsideCoalesceAssignment',
                'ResetInsideNullsafeArguments',
                'ResetPastANullsafeCall',
                'ResetPastANullsafeDimension',
                'ResetPastANullsafeProperty',
                'ResetPastANullsafeStaticCall',
                'ResetPastAPlainChain',
                'ResetSkippedByGoto',
                'ShortCircuitRemoval',
                'SizeGuarded',
                'UnrelatedCountGuard',
                'UnsetsAnotherKey',
                'UnsetsMissingKeyUnderGuard',
            ],
            $this->grownClasses('Regression/growth-bounds.php'),
        );
    }

    /**
     * Severity of one finding, by the class it was reported on.
     */
    private function severityOf(string $fixture, string $shortName): Severity
    {
        $matches = array_values(array_filter(
            self::findingsFor($this->builtIn($fixture), RuleId::STATIC_COLLECTION_GROWTH),
            static fn (Finding $f): bool => str_ends_with((string) $f->symbol->class, '\\' . $shortName),
        ));

        self::assertCount(1, $matches, sprintf('Expected exactly one WS008 finding on %s.', $shortName));

        return $matches[0]->severity;
    }

    /**
     * `self::$items['last'] = []` assigns an empty array into a key; it does
     * not empty the collection, and can even add an entry.
     */
    public function test_clearing_one_key_is_not_a_full_reset(): void
    {
        self::assertSame(Severity::High, $this->severityOf('Regression/growth-bounds.php', 'ClearsOneKey'));
        self::assertSame(Severity::High, $this->severityOf('Regression/growth-bounds.php', 'NullsOneKey'));
    }

    /**
     * A closure body is not executed by the function that declares it, so a
     * reset written inside one proves nothing about the enclosing method.
     */
    public function test_a_reset_inside_an_uncalled_closure_is_not_a_bound(): void
    {
        self::assertSame(
            Severity::Medium,
            $this->severityOf('Regression/growth-bounds.php', 'ResetInsideAnUncalledClosure'),
        );
    }

    public function test_a_reset_skipped_by_goto_is_not_a_bound(): void
    {
        self::assertSame(
            Severity::Medium,
            $this->severityOf('Regression/growth-bounds.php', 'ResetSkippedByGoto'),
        );
    }

    /**
     * A nullsafe call evaluates none of its arguments when the receiver is
     * null, so a reset written there is not unconditional.
     */
    public function test_a_reset_inside_nullsafe_arguments_is_not_a_bound(): void
    {
        self::assertSame(
            Severity::Medium,
            $this->severityOf('Regression/growth-bounds.php', 'ResetInsideNullsafeArguments'),
        );
    }

    public function test_a_reset_on_the_right_of_a_coalesce_assignment_is_not_a_bound(): void
    {
        self::assertSame(
            Severity::Medium,
            $this->severityOf('Regression/growth-bounds.php', 'ResetInsideCoalesceAssignment'),
        );
    }

    /**
     * `array_push(self::$x['bucket'], $v)` grows the nested array. The fixed
     * outer key limits the number of keys, not the size of what is under one.
     */
    public function test_a_fixed_outer_key_does_not_bound_a_nested_push(): void
    {
        self::assertSame(
            Severity::High,
            $this->severityOf('Regression/growth-bounds.php', 'NestedPushUnderFixedKey'),
        );
    }

    /**
     * `?->` short-circuits the whole chain, not only its own link: when the
     * receiver is null, a reset written further along it never runs.
     */
    public function test_a_reset_past_a_nullsafe_link_is_not_a_bound(): void
    {
        foreach (
            [
                'ResetPastANullsafeCall',
                'ResetPastANullsafeProperty',
                'ResetPastANullsafeDimension',
                'ResetPastANullsafeStaticCall',
            ] as $class
        ) {
            self::assertSame(
                Severity::Medium,
                $this->severityOf('Regression/growth-bounds.php', $class),
                $class . ' must still be reported.',
            );
        }
    }

    /**
     * Proof stops at the statement boundary.
     *
     * A reset nested inside another expression is not accepted as proof even
     * when it does run, because whether a sub-expression is evaluated depends
     * on its surroundings — a nullsafe link anywhere in the chain, a
     * short-circuit operator, a call that never happens. Drawing the line at
     * "is this a statement" closes that whole class of question instead of
     * enumerating its members, at the cost of over-reporting this shape.
     */
    public function test_a_reset_nested_in_another_expression_is_not_accepted_as_proof(): void
    {
        self::assertSame(
            Severity::Medium,
            $this->severityOf('Regression/growth-bounds.php', 'ResetPastAPlainChain'),
        );
    }

    /**
     * A reset that is a statement in its own right still counts.
     */
    public function test_a_statement_level_reset_is_still_a_bound(): void
    {
        self::assertNotContains('FullReset', $this->grownClasses('Regression/growth-bounds.php'));
    }

    /**
     * The worst growing function decides, so moving a method within its class
     * cannot change the severity or the exit code.
     */
    public function test_severity_does_not_depend_on_method_declaration_order(): void
    {
        $first = $this->severityOf('Regression/growth-bounds.php', 'RemovalDeclaredFirst');
        $second = $this->severityOf('Regression/growth-bounds.php', 'RemovalDeclaredSecond');

        self::assertSame($first, $second);
        self::assertSame(Severity::High, $first, 'One method never removes anything.');
    }

    /**
     * A `count()` somewhere in the condition is not a bound. The guard has to
     * measure this collection and compare it against a finite limit in the
     * direction that makes the eviction run when the limit is exceeded.
     */
    public function test_a_count_call_is_not_by_itself_a_size_guard(): void
    {
        $reported = $this->grownClasses('Regression/growth-bounds.php');

        self::assertContains('UnrelatedCountGuard', $reported, 'count([]) measures nothing.');
        self::assertContains('ImpossibleCountGuard', $reported, 'count(x) < 0 can never evict.');
    }

    public function test_removing_a_different_key_is_not_a_bound(): void
    {
        self::assertContains('UnsetsAnotherKey', $this->grownClasses('Regression/growth-bounds.php'));
    }

    public function test_write_sites_are_not_counted_as_element_counts(): void
    {
        self::assertContains(
            'PushesTwoPopsOne',
            $this->grownClasses('Regression/growth-bounds.php'),
            'array_push() with two values adds two entries; array_pop() removes one.',
        );
    }

    public function test_a_short_circuit_operand_is_not_guaranteed_to_run(): void
    {
        self::assertContains('ShortCircuitRemoval', $this->grownClasses('Regression/growth-bounds.php'));
    }

    /**
     * A size-guarded eviction is the idiom a bounded cache uses, but the code
     * alone does not show that the removal runs on every path, removes as much
     * as was added, or that the limit is finite. It is reported at MEDIUM.
     */
    public function test_a_size_guarded_eviction_is_reported_at_medium(): void
    {
        $reported = self::findingsFor(
            $this->builtIn('Regression/growth-bounds.php'),
            RuleId::STATIC_COLLECTION_GROWTH,
        );

        $guarded = array_values(array_filter(
            $reported,
            static fn (Finding $f): bool => str_ends_with((string) $f->symbol->class, 'SizeGuarded'),
        ));

        self::assertCount(1, $guarded);
        self::assertSame(Severity::Medium, $guarded[0]->severity);
    }

    public function test_an_unconditional_full_reset_is_a_bound(): void
    {
        self::assertNotContains('FullReset', $this->grownClasses('Regression/growth-bounds.php'));
    }

    public function test_only_a_full_reset_is_treated_as_a_proof(): void
    {
        $reported = $this->grownClasses('Regression/growth-bounds.php');

        self::assertNotContains('FullReset', $reported, 'An unconditional full reset is provable.');
        self::assertContains('AddThenRemove', $reported, 'Everything else is reported.');
        self::assertContains('SizeGuarded', $reported);
    }

    public function test_container_keys_are_matched_byte_for_byte(): void
    {
        $reported = array_map(
            static fn (Finding $f): string => trim((string) $f->snippet),
            self::findingsFor($this->laravel('Regression/container-key-identity.php'), RuleId::LARAVEL_SINGLETON_MUTABLE_STATE),
        );

        self::assertContains(
            "\$app->singleton('Shared', CaseSensitiveState::class);",
            $reported,
            "A scoped 'shared' does not flush a singleton 'Shared'.",
        );
    }

    /**
     * `forgetScopedInstances()` unsets `$instances[$scoped]` with no alias
     * resolution, and `bind()` deletes `$aliases[$abstract]` for the key it
     * registers. Scoping an alias therefore never flushes the binding it
     * aliased, so the singleton still has to be reported.
     */
    public function test_a_scoped_alias_does_not_flush_the_binding_it_aliases(): void
    {
        $reported = array_map(
            static fn (Finding $f): string => trim((string) $f->snippet),
            self::findingsFor(
                $this->laravel('Regression/container-key-identity.php'),
                RuleId::LARAVEL_SINGLETON_MUTABLE_STATE,
            ),
        );

        self::assertContains('$app->singleton(AliasedState::class);', $reported);
    }
}
