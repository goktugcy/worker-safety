<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Baseline;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Baseline\Baseline;
use WorkerSafety\Baseline\BaselineFilter;
use WorkerSafety\Baseline\BaselineRepository;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Finding\Location;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Tests\Support\JsonAccess;

#[CoversClass(Baseline::class)]
#[CoversClass(BaselineFilter::class)]
#[CoversClass(BaselineRepository::class)]
final class BaselineTest extends TestCase
{
    use JsonAccess;

    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/ws-baseline-' . bin2hex(random_bytes(6)) . '/baseline.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @rmdir(dirname($this->path));
    }

    private function finding(
        string $ruleId = 'WS001',
        int $line = 10,
        string $file = 'app/A.php',
        string $property = 'user',
        string $snippet = 'public static $user;',
    ): Finding {
        return new Finding(
            $ruleId,
            'Title',
            Severity::High,
            RuleCategory::StaticState,
            new Location('/project/' . $file, $file, $line),
            'message',
            null,
            [],
            new SymbolContext('App\\A', null, $property),
            $snippet,
        );
    }

    public function test_an_empty_baseline_filters_nothing(): void
    {
        $findings = new FindingCollection([$this->finding()]);

        [$kept, $removed] = (new BaselineFilter())->apply($findings, Baseline::empty());

        self::assertCount(1, $kept);
        self::assertSame(0, $removed);
    }

    public function test_a_baselined_finding_is_removed(): void
    {
        $findings = new FindingCollection([$this->finding()]);
        $baseline = Baseline::fromFindings($findings);

        [$kept, $removed] = (new BaselineFilter())->apply($findings, $baseline);

        self::assertCount(0, $kept);
        self::assertSame(1, $removed);
    }

    public function test_moving_a_finding_to_another_line_keeps_it_baselined(): void
    {
        $baseline = Baseline::fromFindings(new FindingCollection([$this->finding(line: 10)]));

        [$kept] = (new BaselineFilter())->apply(
            new FindingCollection([$this->finding(line: 250)]),
            $baseline,
        );

        self::assertCount(0, $kept);
    }

    public function test_a_new_finding_is_not_baselined(): void
    {
        $baseline = Baseline::fromFindings(new FindingCollection([$this->finding()]));

        [$kept, $removed] = (new BaselineFilter())->apply(
            new FindingCollection([
                $this->finding(),
                $this->finding(line: 20, property: 'tenant', snippet: 'public static $tenant;'),
            ]),
            $baseline,
        );

        self::assertCount(1, $kept);
        self::assertSame(1, $removed);
        self::assertSame('tenant', $kept->toArray()[0]->symbol->property);
    }

    public function test_moving_a_finding_to_another_file_un_baselines_it(): void
    {
        $baseline = Baseline::fromFindings(new FindingCollection([$this->finding(file: 'app/A.php')]));

        [$kept] = (new BaselineFilter())->apply(
            new FindingCollection([$this->finding(file: 'app/B.php')]),
            $baseline,
        );

        self::assertCount(1, $kept);
    }

    public function test_a_different_rule_on_the_same_line_is_not_baselined(): void
    {
        $baseline = Baseline::fromFindings(new FindingCollection([$this->finding('WS001')]));

        [$kept] = (new BaselineFilter())->apply(
            new FindingCollection([$this->finding('WS007')]),
            $baseline,
        );

        self::assertCount(1, $kept);
    }

    public function test_a_baseline_round_trips_through_the_repository(): void
    {
        $repository = new BaselineRepository();
        $original = Baseline::fromFindings(new FindingCollection([$this->finding(), $this->finding('WS007')]));

        self::assertFalse($repository->exists($this->path));

        $repository->save($this->path, $original);

        self::assertTrue($repository->exists($this->path));

        $loaded = $repository->load($this->path);

        self::assertSame($original->count(), $loaded->count());
        self::assertTrue($loaded->contains($this->finding()));
    }

    public function test_the_saved_file_is_readable_json_with_metadata(): void
    {
        (new BaselineRepository())->save($this->path, Baseline::fromFindings(
            new FindingCollection([$this->finding()]),
        ));

        $decoded = self::decodeJsonFile($this->path);

        self::assertSame('1', self::stringAt($decoded, 'version'));
        self::assertSame(1, self::intAt($decoded, 'count'));
        self::assertNotSame('', self::stringAt($decoded, 'generated_at'));
        self::assertStringContainsString('Worker Safety', self::stringAt($decoded, 'generated_by'));
        self::assertSame('WS001', self::stringAt($decoded, 'findings', 0, 'rule'));
        self::assertSame('app/A.php', self::stringAt($decoded, 'findings', 0, 'file'));
        self::assertSame('App\\A::$user', self::stringAt($decoded, 'findings', 0, 'symbol'));
    }

    public function test_loading_a_missing_file_is_a_configuration_error(): void
    {
        $this->expectException(ConfigurationException::class);

        (new BaselineRepository())->load($this->path);
    }

    public function test_loading_invalid_json_is_a_configuration_error(): void
    {
        @mkdir(dirname($this->path), 0o777, true);
        file_put_contents($this->path, '{not json');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/not valid JSON/');

        (new BaselineRepository())->load($this->path);
    }

    public function test_an_entry_without_a_fingerprint_is_rejected(): void
    {
        @mkdir(dirname($this->path), 0o777, true);
        file_put_contents($this->path, '{"findings":[{"rule":"WS001"}]}');

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/needs a "fingerprint"/');

        (new BaselineRepository())->load($this->path);
    }

    public function test_duplicate_findings_are_recorded_once(): void
    {
        $baseline = Baseline::fromFindings(new FindingCollection([$this->finding(), $this->finding(line: 40)]));

        self::assertSame(1, $baseline->count());
    }
}
