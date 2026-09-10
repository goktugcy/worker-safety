<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Finding;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Finding\Location;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;

#[CoversClass(FindingCollection::class)]
#[CoversClass(Finding::class)]
final class FindingCollectionTest extends TestCase
{
    private function finding(
        string $ruleId,
        Severity $severity,
        string $file = 'src/A.php',
        int $line = 1,
        string $message = 'message',
    ): Finding {
        return new Finding(
            $ruleId,
            'Title',
            $severity,
            RuleCategory::StaticState,
            new Location('/project/' . $file, $file, $line),
            $message,
        );
    }

    public function test_sorting_is_severity_then_file_then_line(): void
    {
        $collection = new FindingCollection([
            $this->finding('WS002', Severity::Low, 'src/B.php', 5),
            $this->finding('WS001', Severity::Critical, 'src/Z.php', 9),
            $this->finding('WS003', Severity::Low, 'src/A.php', 2),
            $this->finding('WS004', Severity::High, 'src/M.php', 1),
        ]);

        $order = array_map(
            static fn (Finding $f): string => $f->ruleId,
            $collection->sorted()->toArray(),
        );

        self::assertSame(['WS001', 'WS004', 'WS003', 'WS002'], $order);
    }

    public function test_identical_findings_are_deduplicated(): void
    {
        $collection = new FindingCollection([
            $this->finding('WS001', Severity::High),
            $this->finding('WS001', Severity::High),
        ]);

        self::assertCount(1, $collection);
    }

    public function test_findings_differing_only_in_message_are_both_kept(): void
    {
        $collection = new FindingCollection([
            $this->finding('WS001', Severity::High, 'src/A.php', 1, 'first'),
            $this->finding('WS001', Severity::High, 'src/A.php', 1, 'second'),
        ]);

        self::assertCount(2, $collection);
    }

    public function test_counts_by_severity_always_lists_every_severity(): void
    {
        $counts = (new FindingCollection([$this->finding('WS001', Severity::High)]))->countsBySeverity();

        self::assertSame(
            ['critical' => 0, 'high' => 1, 'medium' => 0, 'low' => 0, 'info' => 0],
            $counts,
        );
    }

    public function test_counts_by_rule_is_sorted(): void
    {
        $collection = new FindingCollection([
            $this->finding('WS008', Severity::High, 'src/A.php', 1),
            $this->finding('WS001', Severity::High, 'src/A.php', 2),
            $this->finding('WS001', Severity::High, 'src/A.php', 3),
        ]);

        self::assertSame(['WS001' => 2, 'WS008' => 1], $collection->countsByRule());
    }

    public function test_severity_threshold_filtering(): void
    {
        $collection = new FindingCollection([
            $this->finding('WS001', Severity::Critical, 'src/A.php', 1),
            $this->finding('WS002', Severity::Medium, 'src/A.php', 2),
        ]);

        self::assertTrue($collection->hasSeverityAtLeast(Severity::High));
        self::assertCount(1, $collection->withSeverityAtLeast(Severity::High));
        self::assertCount(2, $collection->withSeverityAtLeast(Severity::Medium));

        $mediumOnly = new FindingCollection([$this->finding('WS002', Severity::Medium)]);

        self::assertFalse($mediumOnly->hasSeverityAtLeast(Severity::High));
    }

    public function test_with_severity_returns_the_same_instance_when_unchanged(): void
    {
        $finding = $this->finding('WS001', Severity::High);

        self::assertSame($finding, $finding->withSeverity(Severity::High));
        self::assertNotSame($finding, $finding->withSeverity(Severity::Low));
    }

    public function test_fingerprint_ignores_the_line_number(): void
    {
        $a = $this->finding('WS001', Severity::High, 'src/A.php', 10);
        $b = $this->finding('WS001', Severity::High, 'src/A.php', 99);

        self::assertSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_fingerprint_changes_with_the_file(): void
    {
        $a = $this->finding('WS001', Severity::High, 'src/A.php');
        $b = $this->finding('WS001', Severity::High, 'src/B.php');

        self::assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_symbol_context_describes_properties_and_methods(): void
    {
        self::assertSame('App\\Ctx::$user', (new SymbolContext('App\\Ctx', null, 'user'))->describe());
        self::assertSame('App\\Ctx::set()', (new SymbolContext('App\\Ctx', 'set'))->describe());
        self::assertSame('$user', (new SymbolContext(null, null, null, 'user'))->describe());
        self::assertNull(SymbolContext::empty()->describe());
    }
}
