<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use WorkerSafety\Analyzer\ScanOptions;
use WorkerSafety\Analyzer\ScanService;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Application\WorkerSafetyApplication;
use WorkerSafety\Reporting\ScanReport;
use WorkerSafety\Support\Paths;
use WorkerSafety\Tests\Support\JsonAccess;

/**
 * A scan that skipped files is not evidence of safety.
 *
 * These pin the release-review findings about scan completeness, the baseline
 * opt-out and finding identity — every one of which previously let an
 * incomplete or changed risk pass as clean.
 */
#[CoversNothing]
final class ScanIntegrityTest extends TestCase
{
    use JsonAccess;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Paths::normalize(sys_get_temp_dir() . '/ws-integrity-' . bin2hex(random_bytes(6)));
        @mkdir($this->workspace . '/src', 0o777, true);
    }

    protected function tearDown(): void
    {
        // Restore any permissions the test removed, or the cleanup fails.
        foreach (glob($this->workspace . '/src/*') ?: [] as $file) {
            @chmod($file, 0o644);
        }

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

    private function write(string $relative, string $contents): string
    {
        $path = $this->workspace . '/' . $relative;
        @mkdir(dirname($path), 0o777, true);
        file_put_contents($path, $contents);

        return $path;
    }

    private function scan(?ScanOptions $options = null): ScanReport
    {
        return (new ScanService())->scan($options ?? new ScanOptions($this->workspace))->report;
    }

    private function riskyFile(): string
    {
        return <<<'PHP'
            <?php

            final class Ctx
            {
                public function boot(): void
                {
                    putenv('TENANT=acme');
                }
            }
            PHP;
    }

    public function test_an_unreadable_file_is_not_counted_as_analyzed(): void
    {
        $path = $this->write('src/Unreadable.php', $this->riskyFile());

        if (@chmod($path, 0o000) !== true || is_readable($path)) {
            self::markTestSkipped('Cannot make a file unreadable in this environment (running as root?).');
        }

        $report = $this->scan();

        self::assertSame(0, $report->filesScanned, 'A file nobody could read was not analyzed.');
        self::assertTrue($report->isIncomplete());
        self::assertSame(['src/Unreadable.php'], $report->unanalyzedFiles());
        self::assertStringContainsString('could not be read', $report->parseFailures[0]->message);
        self::assertSame(ExitCode::FindingsAboveThreshold, $report->exitCode());
    }

    public function test_a_wholly_unparseable_file_fails_the_scan(): void
    {
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $report = $this->scan();

        self::assertSame(0, $report->filesScanned);
        self::assertTrue($report->isIncomplete());
        self::assertTrue($report->failed());
        self::assertTrue($report->failedOnIncompleteAnalysis());
        self::assertFalse($report->failedOnSeverity());
        self::assertSame(ExitCode::FindingsAboveThreshold, $report->exitCode());
    }

    public function test_a_recoverable_syntax_error_keeps_the_scan_complete(): void
    {
        $this->write('src/Recoverable.php', "<?php\nfinal class R { public static \$x = 1 \n}\n");

        $report = $this->scan();

        self::assertGreaterThan(0, $report->parseFailureCount());
        self::assertFalse($report->isIncomplete(), 'php-parser recovered, so the file was analyzed.');
        self::assertFalse($report->failedOnIncompleteAnalysis());
    }

    public function test_incomplete_analysis_can_be_accepted_explicitly(): void
    {
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $report = $this->scan(new ScanOptions($this->workspace, allowParseErrors: true));

        self::assertTrue($report->isIncomplete());
        self::assertFalse($report->failed());
        self::assertSame(ExitCode::Success, $report->exitCode());
    }

    public function test_fail_on_parse_error_can_be_disabled_in_configuration(): void
    {
        $this->write('worker-safety.yaml', "paths:\n  - src\nfail_on_parse_error: false\n");
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $report = $this->scan();

        self::assertTrue($report->isIncomplete());
        self::assertFalse($report->failed());
    }

    public function test_sarif_does_not_claim_success_for_an_incomplete_scan(): void
    {
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--format' => 'sarif']);
        $sarif = self::decodeJson($tester->getDisplay());

        self::assertFalse(self::boolAt($sarif, 'runs', 0, 'invocations', 0, 'executionSuccessful'));
    }

    public function test_json_reports_completeness(): void
    {
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--format' => 'json']);
        $json = self::decodeJson($tester->getDisplay());

        self::assertTrue(self::boolAt($json, 'summary', 'incomplete'));
        self::assertSame(1, self::intAt($json, 'summary', 'files_not_analyzed'));
        self::assertTrue(self::boolAt($json, 'summary', 'failed_on_incomplete_analysis'));
        self::assertFalse(self::boolAt($json, 'summary', 'failed_on_severity'));
    }

    public function test_baseline_false_in_configuration_really_disables_the_baseline(): void
    {
        $this->write('src/Ctx.php', $this->riskyFile());

        $baseline = $this->runCommand('baseline', ['--project-dir' => $this->workspace]);
        self::assertSame(ExitCode::Success->value, $baseline->getStatusCode());

        // With the baseline in place the scan passes.
        self::assertSame(ExitCode::Success, $this->scan()->exitCode());

        // Turning it off must report the findings again.
        $this->write('worker-safety.yaml', "paths:\n  - src\nbaseline: false\n");
        $report = $this->scan();

        self::assertSame(0, $report->baselineFilteredCount);
        self::assertNull($report->baselinePath);
        self::assertSame(ExitCode::FindingsAboveThreshold, $report->exitCode());
    }

    public function test_a_baseline_is_not_written_from_an_incomplete_scan(): void
    {
        $this->write('src/Ctx.php', $this->riskyFile());
        $this->write('src/Broken.php', '<?php class Broken { public function run(');

        $tester = $this->runCommand('baseline', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::InternalError->value, $tester->getStatusCode());
        self::assertStringContainsString('Refusing to write a baseline', $tester->getDisplay());
        self::assertFileDoesNotExist($this->workspace . '/worker-safety-baseline.json');

        $forced = $this->runCommand('baseline', [
            '--project-dir' => $this->workspace,
            '--allow-parse-errors' => true,
        ]);

        self::assertSame(ExitCode::Success->value, $forced->getStatusCode());
        self::assertFileExists($this->workspace . '/worker-safety-baseline.json');
    }

    public function test_a_changed_multiline_argument_changes_the_finding_identity(): void
    {
        $template = "<?php\nfunction run(): void { putenv(\n    '%s'\n); }\n";

        $this->write('src/Env.php', sprintf($template, 'TOKEN=a'));
        $first = $this->scan()->findings->toArray()[0]->fingerprint();

        $this->write('src/Env.php', sprintf($template, 'OTHER=b'));
        $second = $this->scan()->findings->toArray()[0]->fingerprint();

        self::assertNotSame($first, $second, 'A baseline must not accept a changed argument unreviewed.');
    }

    public function test_moving_a_finding_down_the_file_keeps_its_identity(): void
    {
        $template = "<?php\n%sfunction run(): void { putenv(\n    'TOKEN=a'\n); }\n";

        $this->write('src/Env.php', sprintf($template, ''));
        $first = $this->scan()->findings->toArray()[0]->fingerprint();

        $this->write('src/Env.php', sprintf($template, "// a comment\n// and another\n"));
        $second = $this->scan()->findings->toArray()[0]->fingerprint();

        self::assertSame($first, $second, 'Edits above a finding must not resurrect it.');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new WorkerSafetyApplication())->find($command));
        $tester->execute($input, ['decorated' => false]);

        return $tester;
    }
}
