<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Analyzer\ScanOptions;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Support\Paths;
use WorkerSafety\Tests\Support\FindingAssertions;
use WorkerSafety\Tests\Support\Fixtures;

/**
 * The acceptance criteria from the specification, run through the real
 * ScanService: configuration, discovery, framework detection, rules, baseline.
 */
#[CoversNothing]
final class AcceptanceTest extends TestCase
{
    use FindingAssertions;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Paths::normalize(sys_get_temp_dir() . '/ws-accept-' . bin2hex(random_bytes(6)));
        @mkdir($this->workspace . '/src', 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->remove($this->workspace);
    }

    private function remove(string $path): void
    {
        if (is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }

        @rmdir($path);
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->workspace . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);
    }

    public function test_the_plain_php_acceptance_file_is_flagged(): void
    {
        $this->write('src/UserContext.php', <<<'PHP'
            <?php

            final class UserContext
            {
                public static ?object $currentUser = null;

                private static array $cache = [];

                public static function setUser(object $user): void
                {
                    self::$currentUser = $user;
                    self::$cache[] = $user;
                }
            }
            PHP);

        $outcome = (new ScanService())->scan(new ScanOptions($this->workspace));
        $ids = self::ruleIds($outcome->report->findings);

        self::assertContains(RuleId::MUTABLE_STATIC_PROPERTY, $ids, 'WS001 must fire');
        self::assertContains(RuleId::STATIC_REQUEST_CONTEXT, $ids, 'WS007 must fire');
        self::assertContains(RuleId::STATIC_COLLECTION_GROWTH, $ids, 'WS008 must fire');
    }

    public function test_an_immutable_constant_holder_produces_no_warning(): void
    {
        $this->write('src/Version.php', <<<'PHP'
            <?php

            final class Version
            {
                public const VERSION = '1.0.0';
            }
            PHP);

        $outcome = (new ScanService())->scan(new ScanOptions($this->workspace));

        $this->assertNoFindings($outcome->report->findings);
        self::assertSame(ExitCode::Success, $outcome->report->exitCode());
    }

    public function test_the_laravel_acceptance_case_reports_a_high_container_finding(): void
    {
        $outcome = (new ScanService())->scan(new ScanOptions(Fixtures::laravelApp()));
        $report = $outcome->report;

        self::assertSame('Laravel 12', $report->framework->describe());
        self::assertSame(['frankenphp', 'octane'], $report->runtimes->values());

        $finding = $this->assertHasFinding(
            $report->findings,
            RuleId::LARAVEL_SINGLETON_MUTABLE_STATE,
            null,
            Severity::High,
        );

        self::assertStringContainsString('request-specific mutable state', $finding->message);
        self::assertStringContainsString('scoped', implode(' ', $finding->remediation));

        $this->assertHasFinding($report->findings, RuleId::LARAVEL_SCOPED_CANDIDATE);
    }

    public function test_the_scan_never_bootstraps_the_analyzed_application(): void
    {
        // A file that would fail loudly if it were ever included or evaluated.
        $this->write('src/Explosive.php', <<<'PHP'
            <?php

            throw new \RuntimeException('the scanner executed analyzed code');

            final class Explosive
            {
                public static ?object $user = null;
            }
            PHP);

        $outcome = (new ScanService())->scan(new ScanOptions($this->workspace));

        self::assertSame(1, $outcome->report->filesScanned);
        self::assertFalse($outcome->report->findings->isEmpty());
    }

    public function test_vendor_is_never_scanned_even_when_the_config_omits_it(): void
    {
        $this->write('worker-safety.yaml', "paths:\n  - .\nexclude:\n  - storage\n");
        $this->write('vendor/acme/Leaky.php', <<<'PHP'
            <?php

            final class VendorLeak
            {
                public static ?object $currentUser = null;

                public static function set(object $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP);
        $this->write('src/Own.php', <<<'PHP'
            <?php

            final class OwnLeak
            {
                public static ?object $currentUser = null;

                public static function set(object $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP);

        $outcome = (new ScanService())->scan(new ScanOptions($this->workspace));

        foreach ($outcome->report->findings as $finding) {
            self::assertStringNotContainsString('vendor/', $finding->location->relativePath);
        }

        self::assertSame(1, $outcome->report->filesScanned);
    }

    public function test_a_syntax_error_does_not_abort_the_scan(): void
    {
        $this->write('src/Broken.php', "<?php\nfinal class Broken { public static \$x = 1 \n");
        $this->write('src/Healthy.php', <<<'PHP'
            <?php

            final class Healthy
            {
                public static ?object $currentUser = null;

                public static function set(object $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP);

        $report = (new ScanService())->scan(new ScanOptions($this->workspace))->report;

        self::assertGreaterThan(0, $report->parseFailureCount());
        $this->assertHasFinding($report->findings, RuleId::MUTABLE_STATIC_PROPERTY, null, null, 'Healthy.php');
    }

    public function test_the_runtime_option_narrows_the_reported_runtimes(): void
    {
        $this->write('src/Ctx.php', <<<'PHP'
            <?php

            final class Ctx
            {
                public static ?object $currentUser = null;

                public static function set(object $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP);

        $outcome = (new ScanService())->scan(new ScanOptions(
            $this->workspace,
            runtimes: \WorkerSafety\Runtime\RuntimeTargetSet::fromStrings(['frankenphp']),
        ));

        self::assertSame(['frankenphp'], $outcome->report->runtimes->values());

        foreach ($outcome->report->findings as $finding) {
            self::assertSame(['frankenphp'], $finding->runtimes->values());
        }
    }

    public function test_the_fail_on_threshold_controls_the_exit_code(): void
    {
        // `$handle` is deliberately not request-scoped vocabulary, so HIGH
        // (WS001) is the most severe finding this file can produce.
        $this->write('src/Ctx.php', <<<'PHP'
            <?php

            final class Ctx
            {
                public static ?object $handle = null;

                public static function set(object $value): void
                {
                    self::$handle = $value;
                }
            }
            PHP);

        $service = new ScanService();

        self::assertSame(
            ExitCode::FindingsAboveThreshold,
            $service->scan(new ScanOptions($this->workspace, failOn: 'high'))->report->exitCode(),
        );
        self::assertSame(
            ExitCode::Success,
            $service->scan(new ScanOptions($this->workspace, failOn: 'never'))->report->exitCode(),
        );
        self::assertSame(
            ExitCode::Success,
            $service->scan(new ScanOptions($this->workspace, failOn: 'critical'))->report->exitCode(),
        );
    }

    public function test_the_configured_severity_overrides_the_reported_one(): void
    {
        $this->write('worker-safety.yaml', "paths:\n  - src\nrules:\n  WS001:\n    severity: info\n");
        $this->write('src/Ctx.php', <<<'PHP'
            <?php

            final class Ctx
            {
                public static ?object $handle = null;

                public static function set(object $value): void
                {
                    self::$handle = $value;
                }
            }
            PHP);

        $report = (new ScanService())->scan(new ScanOptions($this->workspace))->report;

        $this->assertHasFinding($report->findings, RuleId::MUTABLE_STATIC_PROPERTY, null, Severity::Info);
        self::assertSame(ExitCode::Success, $report->exitCode());
    }
}
