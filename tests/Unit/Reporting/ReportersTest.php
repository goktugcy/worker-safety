<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Reporting;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Ast\ParseFailure;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;
use WorkerSafety\Finding\Location;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Reporting\ConsoleReporter;
use WorkerSafety\Reporting\JsonReporter;
use WorkerSafety\Reporting\OutputFormat;
use WorkerSafety\Reporting\ReporterFactory;
use WorkerSafety\Reporting\SarifReporter;
use WorkerSafety\Reporting\ScanReport;
use WorkerSafety\Rule\RuleRegistryFactory;
use WorkerSafety\Runtime\RuntimeTarget;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Tests\Support\JsonAccess;

#[CoversClass(ConsoleReporter::class)]
#[CoversClass(JsonReporter::class)]
#[CoversClass(SarifReporter::class)]
#[CoversClass(ScanReport::class)]
#[CoversClass(ReporterFactory::class)]
final class ReportersTest extends TestCase
{
    use JsonAccess;

    /**
     * @param list<ParseFailure> $parseFailures
     */
    private function report(
        ?FindingCollection $findings = null,
        ?Severity $failOn = Severity::High,
        array $parseFailures = [],
    ): ScanReport {
        return new ScanReport(
            '/project',
            new DetectedFramework(Framework::Laravel, '12', 'laravel/framework'),
            new RuntimeTargetSet([RuntimeTarget::FrankenPhp, RuntimeTarget::Octane]),
            $findings ?? new FindingCollection([$this->finding()]),
            284,
            $parseFailures,
            2,
            1,
            0.5,
            $failOn,
            'worker-safety.yaml',
            null,
            ['app'],
            '8.4.2',
            '0.1.0',
        );
    }

    private function finding(Severity $severity = Severity::High): Finding
    {
        return new Finding(
            'WS001',
            'Mutable static property',
            $severity,
            RuleCategory::StaticState,
            new Location('/project/app/Services/UserContext.php', 'app/Services/UserContext.php', 14, 5),
            'Mutable static property $currentUser may persist between requests.',
            'Detailed explanation of why <this> is risky.',
            ['Avoid request-specific state in static properties.'],
            new SymbolContext('App\\Services\\UserContext', null, 'currentUser'),
            'public static ?User $currentUser = null;',
            new RuntimeTargetSet([RuntimeTarget::FrankenPhp, RuntimeTarget::Octane]),
            'Laravel',
        );
    }

    private function render(\WorkerSafety\Reporting\Reporter $reporter, ScanReport $report, bool $decorated = false): string
    {
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL, $decorated);
        $reporter->report($report, $output);

        return $output->fetch();
    }

    public function test_console_report_contains_the_essentials(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report());

        self::assertStringContainsString('Worker Safety 0.1.0', $text);
        self::assertStringContainsString('/project', $text);
        self::assertStringContainsString('PHP        8.4.2', $text);
        self::assertStringContainsString('Framework  Laravel 12', $text);
        self::assertStringContainsString('Runtime    FrankenPHP, Octane', $text);
        self::assertStringContainsString('284 PHP files analyzed', $text);
        self::assertStringContainsString('HIGH', $text);
        self::assertStringContainsString('WS001', $text);
        self::assertStringContainsString('app/Services/UserContext.php:14', $text);
        self::assertStringContainsString('public static ?User $currentUser = null;', $text);
        self::assertStringContainsString('Affected runtimes:', $text);
        self::assertStringContainsString('Recommendation:', $text);
        self::assertStringContainsString('Result: FAILED', $text);
    }

    public function test_console_report_mentions_suppressed_and_baselined_counts(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report());

        self::assertStringContainsString('2 finding(s) suppressed', $text);
        self::assertStringContainsString('1 finding(s) ignored by the baseline', $text);
    }

    public function test_console_report_passes_when_nothing_reaches_the_threshold(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(new FindingCollection()));

        self::assertStringContainsString('No worker-safety risks found', $text);
        self::assertStringContainsString('Result: PASSED', $text);
    }

    public function test_console_report_explains_a_disabled_threshold(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(null, null));

        self::assertStringContainsString('Result: PASSED', $text);
        self::assertStringContainsString('No failure threshold configured', $text);
    }

    public function test_console_report_lists_parse_warnings(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(null, Severity::High, [
            new ParseFailure('app/Broken.php', '/project/app/Broken.php', 18, 'Unexpected token', false),
        ]));

        self::assertStringContainsString('Parse warnings', $text);
        self::assertStringContainsString('app/Broken.php:18', $text);
        self::assertStringContainsString('Unexpected token', $text);
        // Recoverable: the file was still analyzed, so the scan stays complete.
        self::assertStringContainsString('1 file(s) were analyzed despite a syntax error', $text);
        self::assertStringNotContainsString('incomplete', $text);
    }

    public function test_console_report_is_plain_text_without_decoration(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(), false);

        self::assertStringNotContainsString("\033[", $text);
        self::assertStringNotContainsString('<ws-', $text);
    }

    public function test_console_report_colours_when_decorated(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(), true);

        self::assertStringContainsString("\033[", $text);
    }

    public function test_console_report_escapes_angle_brackets_from_source(): void
    {
        $text = $this->render(new ConsoleReporter(), $this->report(), false);

        self::assertStringContainsString('<this>', $text);
    }

    public function test_console_report_adds_a_runtime_note_for_a_single_target(): void
    {
        $report = new ScanReport(
            '/project',
            DetectedFramework::none(),
            new RuntimeTargetSet([RuntimeTarget::Octane]),
            new FindingCollection([
                $this->finding()->withRuntimes(new RuntimeTargetSet([RuntimeTarget::Octane])),
            ]),
            1,
            [],
            0,
            0,
            0.1,
            Severity::High,
            null,
            null,
        );

        self::assertStringContainsString('octane.flush', $this->render(new ConsoleReporter(), $report));
    }

    public function test_json_output_is_valid_and_stable(): void
    {
        $decoded = self::decodeJson($this->render(new JsonReporter(), $this->report()));

        self::assertSame('1', self::stringAt($decoded, 'version'));
        self::assertSame(
            ['version', 'tool', 'project', 'summary', 'findings', 'parse_errors'],
            array_keys($decoded),
        );
        self::assertSame(284, self::intAt($decoded, 'summary', 'files'));
        self::assertSame(1, self::intAt($decoded, 'summary', 'high'));
        self::assertSame(0, self::intAt($decoded, 'summary', 'critical'));
        self::assertSame(2, self::intAt($decoded, 'summary', 'suppressed'));
        self::assertSame(1, self::intAt($decoded, 'summary', 'baseline_filtered'));
        self::assertSame('high', self::stringAt($decoded, 'summary', 'fail_on'));
        self::assertTrue(self::boolAt($decoded, 'summary', 'failed'));
        self::assertSame(['WS001' => 1], self::arrayAt($decoded, 'summary', 'by_rule'));
        self::assertSame('laravel', self::stringAt($decoded, 'project', 'framework', 'name'));
        self::assertSame(['frankenphp', 'octane'], self::arrayAt($decoded, 'project', 'runtimes'));
    }

    public function test_json_finding_fields(): void
    {
        $decoded = self::decodeJson($this->render(new JsonReporter(), $this->report()));

        self::assertSame('WS001', self::stringAt($decoded, 'findings', 0, 'rule'));
        self::assertSame('high', self::stringAt($decoded, 'findings', 0, 'severity'));
        self::assertSame('static-state', self::stringAt($decoded, 'findings', 0, 'category'));
        self::assertSame('app/Services/UserContext.php', self::stringAt($decoded, 'findings', 0, 'file'));
        self::assertSame(14, self::intAt($decoded, 'findings', 0, 'line'));
        self::assertSame(5, self::intAt($decoded, 'findings', 0, 'column'));
        self::assertSame('currentUser', self::stringAt($decoded, 'findings', 0, 'symbol', 'property'));
        self::assertSame(['frankenphp', 'octane'], self::arrayAt($decoded, 'findings', 0, 'runtimes'));
        self::assertNotSame('', self::stringAt($decoded, 'findings', 0, 'fingerprint'));
        self::assertNotSame([], self::arrayAt($decoded, 'findings', 0, 'remediation'));
    }

    public function test_json_output_contains_no_console_decoration(): void
    {
        $json = $this->render(new JsonReporter(), $this->report(), true);

        self::assertStringNotContainsString("\033[", $json);
        self::assertStringStartsWith('{', trim($json));
    }

    public function test_sarif_output_matches_the_2_1_0_shape(): void
    {
        $registry = (new RuleRegistryFactory())->create();
        $sarif = self::decodeJson($this->render(new SarifReporter($registry), $this->report()));

        self::assertSame('2.1.0', self::stringAt($sarif, 'version'));
        self::assertArrayHasKey('$schema', $sarif);
        self::assertCount(1, self::arrayAt($sarif, 'runs'));

        self::assertSame('Worker Safety', self::stringAt($sarif, 'runs', 0, 'tool', 'driver', 'name'));
        self::assertSame('0.1.0', self::stringAt($sarif, 'runs', 0, 'tool', 'driver', 'semanticVersion'));
        self::assertCount(10, self::arrayAt($sarif, 'runs', 0, 'tool', 'driver', 'rules'));
        self::assertArrayHasKey('SRCROOT', self::arrayAt($sarif, 'runs', 0, 'originalUriBaseIds'));

        self::assertSame('WS001', self::stringAt($sarif, 'runs', 0, 'results', 0, 'ruleId'));
        self::assertSame('error', self::stringAt($sarif, 'runs', 0, 'results', 0, 'level'));
        self::assertSame(0, self::intAt($sarif, 'runs', 0, 'results', 0, 'ruleIndex'));
        self::assertStringContainsString(
            'may persist between requests',
            self::stringAt($sarif, 'runs', 0, 'results', 0, 'message', 'text'),
        );

        $location = ['runs', 0, 'results', 0, 'locations', 0, 'physicalLocation'];

        self::assertSame(
            'app/Services/UserContext.php',
            self::stringAt($sarif, ...[...$location, 'artifactLocation', 'uri']),
        );
        self::assertSame('SRCROOT', self::stringAt($sarif, ...[...$location, 'artifactLocation', 'uriBaseId']));
        self::assertSame(14, self::intAt($sarif, ...[...$location, 'region', 'startLine']));
        self::assertSame(5, self::intAt($sarif, ...[...$location, 'region', 'startColumn']));
        self::assertNotSame([], self::arrayAt($sarif, 'runs', 0, 'results', 0, 'partialFingerprints'));
    }

    public function test_sarif_reports_parse_failures_as_notifications(): void
    {
        $sarif = self::decodeJson($this->render(new SarifReporter(), $this->report(null, Severity::High, [
            new ParseFailure('app/Broken.php', '/project/app/Broken.php', 18, 'Unexpected token', true),
        ])));

        $path = ['runs', 0, 'invocations', 0, 'toolExecutionNotifications'];

        self::assertCount(1, self::arrayAt($sarif, ...$path));
        self::assertSame('error', self::stringAt($sarif, ...[...$path, 0, 'level']));
        self::assertStringContainsString(
            'Could not parse app/Broken.php',
            self::stringAt($sarif, ...[...$path, 0, 'message', 'text']),
        );
    }

    public function test_sarif_percent_encodes_paths(): void
    {
        $report = new ScanReport(
            '/pro ject',
            DetectedFramework::none(),
            RuntimeTargetSet::all(),
            new FindingCollection([
                new Finding(
                    'WS001',
                    'Mutable static property',
                    Severity::High,
                    RuleCategory::StaticState,
                    new Location('/pro ject/src/path #1.php', 'src/path #1.php', 3),
                    'message',
                ),
            ]),
            1,
            [],
            0,
            0,
            0.1,
            Severity::High,
            null,
            null,
        );

        $sarif = self::decodeJson($this->render(new SarifReporter(), $report));

        $uri = self::stringAt(
            $sarif,
            'runs',
            0,
            'results',
            0,
            'locations',
            0,
            'physicalLocation',
            'artifactLocation',
            'uri',
        );

        // A literal `#` would be read as a fragment delimiter, a literal space
        // is not valid in a URI at all.
        self::assertSame('src/path%20%231.php', $uri);
        self::assertSame('src/path #1.php', rawurldecode($uri));

        $base = self::stringAt($sarif, 'runs', 0, 'originalUriBaseIds', 'SRCROOT', 'uri');

        self::assertSame('file:///pro%20ject/', $base);
    }

    public function test_the_factory_maps_formats_to_reporters(): void
    {
        $factory = new ReporterFactory();

        self::assertInstanceOf(ConsoleReporter::class, $factory->create(OutputFormat::Console));
        self::assertInstanceOf(JsonReporter::class, $factory->create(OutputFormat::Json));
        self::assertInstanceOf(SarifReporter::class, $factory->create(OutputFormat::Sarif));
    }

    public function test_report_exit_code_and_failing_count(): void
    {
        $report = $this->report();

        self::assertTrue($report->failed());
        self::assertSame(ExitCode::FindingsAboveThreshold, $report->exitCode());
        self::assertSame(1, $report->failingCount());

        $passing = $this->report(new FindingCollection([$this->finding(Severity::Low)]));

        self::assertFalse($passing->failed());
        self::assertSame(ExitCode::Success, $passing->exitCode());
        self::assertSame(0, $passing->failingCount());
    }

    public function test_output_format_parsing(): void
    {
        self::assertSame(OutputFormat::Json, OutputFormat::fromString('JSON'));
        self::assertTrue(OutputFormat::Sarif->isMachineReadable());
        self::assertFalse(OutputFormat::Console->isMachineReadable());

        $this->expectException(\WorkerSafety\Exception\ConfigurationException::class);
        OutputFormat::fromString('xml');
    }
}
