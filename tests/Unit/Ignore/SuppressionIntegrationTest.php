<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Ignore;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Ignore\FindingSuppressor;
use WorkerSafety\Ignore\IgnoreAttributeVisitor;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Rule\RuleRegistryFactory;
use WorkerSafety\Tests\Support\AnalyzerHarness;
use WorkerSafety\Tests\Support\FindingAssertions;

#[CoversClass(FindingSuppressor::class)]
#[CoversClass(IgnoreAttributeVisitor::class)]
final class SuppressionIntegrationTest extends TestCase
{
    use FindingAssertions;

    /**
     * @return list<\WorkerSafety\Rule\Rule>
     */
    private function rules(): array
    {
        return (new RuleRegistryFactory())->builtIn();
    }

    public function test_comment_directives_suppress_the_intended_findings(): void
    {
        $result = AnalyzerHarness::analyze('Ignore/suppressed.php', $this->rules());

        // `$handle`, `$accepted` and `$currentUser` are fully suppressed.
        foreach ($result->findings as $finding) {
            self::assertNotSame('handle', $finding->symbol->property);
            self::assertNotSame('accepted', $finding->symbol->property);
            self::assertNotSame('currentUser', $finding->symbol->property);
        }

        self::assertGreaterThan(0, $result->suppressedCount);
    }

    public function test_a_rule_specific_directive_stays_precise(): void
    {
        $result = AnalyzerHarness::analyze('Ignore/suppressed.php', $this->rules());

        // For $tenant the directive names WS001 only, so WS007 still reports it.
        $tenantFindings = array_map(
            static fn ($finding): string => $finding->ruleId,
            array_values(array_filter(
                $result->findings->toArray(),
                static fn ($finding): bool => $finding->symbol->property === 'tenant',
            )),
        );

        self::assertSame([RuleId::STATIC_REQUEST_CONTEXT], $tenantFindings);
    }

    public function test_an_undirected_property_is_still_reported(): void
    {
        $result = AnalyzerHarness::analyze('Ignore/suppressed.php', $this->rules());

        $reported = array_values(array_filter(
            $result->findings->toArray(),
            static fn ($finding): bool => $finding->symbol->property === 'reported',
        ));

        self::assertNotSame([], $reported);
    }

    public function test_the_ignore_attribute_suppresses_the_declaration(): void
    {
        $result = AnalyzerHarness::analyze('Ignore/suppressed.php', $this->rules());

        foreach ($result->findings as $finding) {
            self::assertNotSame('currentUser', $finding->symbol->property);
        }
    }

    public function test_a_file_wide_directive_silences_the_whole_file(): void
    {
        $result = AnalyzerHarness::analyze('Ignore/file-wide.php', $this->rules());

        $this->assertNoFindings($result);
        self::assertGreaterThan(0, $result->suppressedCount);
    }

    public function test_config_ignore_patterns_suppress_by_path(): void
    {
        $configuration = new Configuration(
            ignore: [RuleId::MUTABLE_STATIC_PROPERTY => ['**/WS001/*']],
        );

        $result = AnalyzerHarness::analyze('WS001/positive.php', $this->rules(), null, $configuration);

        $this->assertNoFinding($result, RuleId::MUTABLE_STATIC_PROPERTY);
        $this->assertHasFinding($result, RuleId::STATIC_REQUEST_CONTEXT);
        self::assertSame(2, $result->suppressedCount);
    }
}
