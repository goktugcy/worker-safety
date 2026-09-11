<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Rule;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Ast\Index\WriteKind;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\BuiltIn\MutableStaticPropertyRule;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

/**
 * The `static::$password ??= Hash::make('password')` memoization that ships in
 * every new Laravel application.
 *
 * It is reported, and that is deliberate. Telling a deliberate memoization of
 * a fixed literal apart from a memoized request value would mean knowing what
 * the calls in the initializer return: `Hash::make('password')`,
 * `request('tenant')` and `auth()->user()` are the same shape to a parser, and
 * the last two freeze the first request's data into every later one. Guessing
 * from the class name, the `database/factories` path or the `??=` operator
 * would silence those too.
 *
 * So the analyzer reports the shape it can prove — write-once — says which
 * question decides it, and leaves the answer to the reader. These tests pin
 * both halves: the standard factory is still reported, and the documented
 * inline directive removes it.
 */
#[CoversClass(MutableStaticPropertyRule::class)]
#[CoversClass(WriteKind::class)]
final class FactoryMemoizationRegressionTest extends TestCase
{
    use FindingAssertions;

    private const FIXTURE = 'Regression/factory-memoization.php';

    private function analyze(string $fixture = self::FIXTURE): \WorkerSafety\Analyzer\AnalysisResult
    {
        return AnalyzerHarness::analyze($fixture, [new MutableStaticPropertyRule()]);
    }

    private function findingForProperty(string $property): Finding
    {
        foreach (self::findingsFor($this->analyze(), RuleId::MUTABLE_STATIC_PROPERTY) as $finding) {
            if ($finding->symbol->property === $property) {
                return $finding;
            }
        }

        self::fail(sprintf('No WS001 finding for $%s.', $property));
    }

    private function findingForClass(string $shortName): Finding
    {
        foreach (self::findingsFor($this->analyze(), RuleId::MUTABLE_STATIC_PROPERTY) as $finding) {
            if (str_ends_with((string) $finding->symbol->class, '\\' . $shortName)) {
                return $finding;
            }
        }

        self::fail(sprintf('No WS001 finding for %s.', $shortName));
    }

    public function test_the_stock_laravel_factory_is_still_reported(): void
    {
        $finding = $this->findingForProperty('password');

        self::assertSame(Severity::High, $finding->severity);
        self::assertStringContainsString('StandardUserFactory', (string) $finding->symbol->class);
    }

    public function test_it_names_the_conditional_shape_rather_than_a_generic_mutation(): void
    {
        $finding = $this->findingForProperty('password');

        self::assertStringContainsString('initialized with `??=`', $finding->message);
        self::assertStringContainsString('??=', (string) $finding->details);
    }

    /**
     * `??=` writes only into an empty slot. That is the whole of what it
     * proves, and the report must not stretch it into a claim that the
     * initializer runs once — both fixtures below disprove that at runtime.
     */
    public function test_it_does_not_claim_the_initializer_runs_only_once(): void
    {
        foreach (self::findingsFor($this->analyze(), RuleId::MUTABLE_STATIC_PROPERTY) as $finding) {
            $text = $finding->message . ' ' . (string) $finding->details;

            self::assertStringNotContainsString('never recomputed', $text);
            self::assertStringNotContainsString('initialized once', $text);
            self::assertStringNotContainsString('for the life of the worker', $text);
        }
    }

    /**
     * An initializer that yields null never fills the slot, so `??=` evaluates
     * it again on every pass. Running the shape gives three computations in
     * three calls.
     */
    public function test_a_null_initializer_is_described_without_a_lifetime_claim(): void
    {
        $details = (string) $this->findingForClass('NullReturningMemo')->details;

        self::assertStringContainsString('only while the slot is null or unset', $details);
        self::assertStringContainsString('returns null leaves the slot empty and runs again', $details);
    }

    /**
     * A reset re-opens the slot, so the value does not survive either. The
     * clearing write is excluded when the rule inspects the assignments, which
     * is exactly why the wording has to stay conditional.
     */
    public function test_a_reset_in_the_same_method_is_covered_by_the_wording(): void
    {
        $details = (string) $this->findingForClass('ResetBeforeMemo')->details;

        self::assertStringContainsString('until something resets or replaces it', $details);
        self::assertStringContainsString('How long that lasts is not determined here', $details);
    }

    /**
     * The finding has to state which question decides it, because the analyzer
     * cannot: a constant input makes it harmless, a request-derived one makes
     * it a leak of the first request.
     */
    public function test_it_states_both_outcomes_and_points_at_the_deciding_question(): void
    {
        $details = (string) $this->findingForProperty('password')->details;

        self::assertStringContainsString('where the value comes from', $details);
        self::assertStringContainsString('whichever request stored the value', $details);
        self::assertStringContainsString('worker-safety-ignore WS001', $details);
    }

    /**
     * Same syntax, request-derived values. If a heuristic ever starts
     * silencing the stock factory by shape, these go quiet with it — which is
     * exactly the regression this file exists to catch.
     */
    public function test_memoized_request_data_is_reported_just_as_loudly(): void
    {
        foreach (['user', 'tenant'] as $property) {
            $finding = $this->findingForProperty($property);

            self::assertSame(Severity::High, $finding->severity, $property);
            self::assertStringContainsString('initialized with `??=`', $finding->message, $property);
        }
    }

    public function test_a_caller_supplied_value_is_reported(): void
    {
        $finding = $this->findingForProperty('password');

        // Two classes in the fixture memoize into $password: the constant one
        // and the one taking a parameter. Both must be present.
        $all = array_values(array_filter(
            self::findingsFor($this->analyze(), RuleId::MUTABLE_STATIC_PROPERTY),
            static fn (Finding $f): bool => $f->symbol->property === 'password',
        ));

        self::assertCount(3, $all, 'Standard, variable-password and reassigned factories.');
        self::assertSame(Severity::High, $finding->severity);
    }

    /**
     * A plain `=` replaces the value on every call, so it is the previous
     * request that leaks, not the first. Different story, different text.
     */
    public function test_a_replacing_assignment_keeps_the_original_wording(): void
    {
        foreach (self::findingsFor($this->analyze(), RuleId::MUTABLE_STATIC_PROPERTY) as $finding) {
            if (str_contains((string) $finding->symbol->class, 'ReassignedFactory')) {
                self::assertStringContainsString('may persist between requests', $finding->message);
                self::assertStringNotContainsString('initialized with `??=`', $finding->message);

                return;
            }
        }

        self::fail('No finding for ReassignedFactory.');
    }

    /**
     * The documented escape hatch, verified on the exact shape it is
     * documented for.
     */
    public function test_the_documented_inline_directive_silences_the_stock_factory(): void
    {
        $result = $this->analyze('Regression/factory-memoization-ignored.php');

        $this->assertNoFindings($result);
        self::assertSame(1, $result->suppressedCount);
    }
}
