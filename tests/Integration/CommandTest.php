<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Tester\CommandTester;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Application\WorkerSafetyApplication;
use WorkerSafety\Support\Paths;
use WorkerSafety\Tests\Support\Fixtures;
use WorkerSafety\Tests\Support\JsonAccess;

/**
 * Drives the real console commands, including the documented exit codes.
 */
#[CoversNothing]
final class CommandTest extends TestCase
{
    use JsonAccess;

    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = Paths::normalize(sys_get_temp_dir() . '/ws-cmd-' . bin2hex(random_bytes(6)));
        @mkdir($this->workspace . '/src', 0o777, true);

        file_put_contents($this->workspace . '/src/Ctx.php', <<<'PHP'
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

    /**
     * @param array<string, mixed> $input
     */
    private function runCommand(string $command, array $input = []): CommandTester
    {
        $tester = new CommandTester((new WorkerSafetyApplication())->find($command));
        $tester->execute($input, ['decorated' => false, 'verbosity' => OutputInterface::VERBOSITY_NORMAL]);

        return $tester;
    }

    public function test_scan_reports_findings_and_exits_with_one(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $tester->getStatusCode());
        self::assertStringContainsString('WS001', $tester->getDisplay());
        self::assertStringContainsString('Result: FAILED', $tester->getDisplay());
    }

    public function test_scan_accepts_explicit_paths(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, 'paths' => ['src']]);

        self::assertStringContainsString('1 PHP file analyzed', $tester->getDisplay());
    }

    public function test_scan_with_fail_on_never_exits_zero(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--fail-on' => 'never']);

        self::assertSame(ExitCode::Success->value, $tester->getStatusCode());
        self::assertStringContainsString('Result: PASSED', $tester->getDisplay());
    }

    public function test_scan_json_output_is_pure_json(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--format' => 'json']);

        $decoded = self::decodeJson($tester->getDisplay());

        self::assertSame('1', self::stringAt($decoded, 'version'));
        self::assertNotSame([], self::arrayAt($decoded, 'findings'));
    }

    public function test_scan_sarif_output_is_pure_json(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--format' => 'sarif']);

        $decoded = self::decodeJson($tester->getDisplay());

        self::assertSame('2.1.0', self::stringAt($decoded, 'version'));
        self::assertNotSame([], self::arrayAt($decoded, 'runs', 0, 'results'));
    }

    public function test_an_unknown_format_exits_with_two(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--format' => 'xml']);

        self::assertSame(ExitCode::InvalidConfiguration->value, $tester->getStatusCode());
    }

    public function test_an_unknown_runtime_exits_with_two(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--runtime' => ['fpm']]);

        self::assertSame(ExitCode::InvalidConfiguration->value, $tester->getStatusCode());
    }

    public function test_a_missing_path_exits_with_three(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, 'paths' => ['nope']]);

        self::assertSame(ExitCode::InternalError->value, $tester->getStatusCode());
    }

    public function test_a_missing_config_file_exits_with_two(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--config' => 'nope.yaml']);

        self::assertSame(ExitCode::InvalidConfiguration->value, $tester->getStatusCode());
    }

    public function test_runtime_option_accepts_a_comma_separated_list(): void
    {
        $tester = $this->runCommand('scan', [
            '--project-dir' => $this->workspace,
            '--runtime' => ['frankenphp,octane'],
            '--fail-on' => 'never',
        ]);

        self::assertStringContainsString('Runtime    FrankenPHP, Octane', $tester->getDisplay());
    }

    public function test_rules_lists_every_rule(): void
    {
        $tester = $this->runCommand('rules');
        $display = $tester->getDisplay();

        self::assertSame(ExitCode::Success->value, $tester->getStatusCode());

        foreach (\WorkerSafety\Rule\RuleId::all() as $ruleId) {
            self::assertStringContainsString($ruleId, $display);
        }

        self::assertStringContainsString('10 rules', $display);
    }

    public function test_rules_can_show_one_rule(): void
    {
        $tester = $this->runCommand('rules', ['rule' => 'WS001']);
        $display = $tester->getDisplay();

        self::assertStringContainsString('Mutable static property', $display);
        self::assertStringContainsString('How to fix', $display);
        self::assertStringContainsString('Static state', $display);
    }

    public function test_rules_json_output(): void
    {
        $tester = $this->runCommand('rules', ['--format' => 'json']);
        $decoded = self::decodeJson($tester->getDisplay());

        self::assertCount(10, self::arrayAt($decoded, 'rules'));
        self::assertSame('WS001', self::stringAt($decoded, 'rules', 0, 'id'));
        self::assertNotSame([], self::arrayAt($decoded, 'rules', 0, 'remediation'));
    }

    public function test_rules_rejects_an_unknown_rule(): void
    {
        $tester = $this->runCommand('rules', ['rule' => 'WS999']);

        self::assertSame(ExitCode::InvalidConfiguration->value, $tester->getStatusCode());
    }

    public function test_init_creates_a_config_file(): void
    {
        $tester = $this->runCommand('init', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::Success->value, $tester->getStatusCode());
        self::assertStringContainsString('Created ' . ApplicationInfo::CONFIG_FILE, $tester->getDisplay());
        self::assertFileExists($this->workspace . '/' . ApplicationInfo::CONFIG_FILE);
    }

    public function test_init_refuses_to_overwrite(): void
    {
        $this->runCommand('init', ['--project-dir' => $this->workspace]);
        $tester = $this->runCommand('init', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::InvalidConfiguration->value, $tester->getStatusCode());
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    public function test_init_overwrites_with_force(): void
    {
        $this->runCommand('init', ['--project-dir' => $this->workspace]);
        $tester = $this->runCommand('init', ['--project-dir' => $this->workspace, '--force' => true]);

        self::assertSame(ExitCode::Success->value, $tester->getStatusCode());
    }

    public function test_init_uses_laravel_defaults_for_a_laravel_project(): void
    {
        $tester = new CommandTester((new WorkerSafetyApplication())->find('init'));
        $target = $this->workspace . '/laravel';
        @mkdir($target, 0o777, true);
        copy(Fixtures::laravelApp() . '/composer.json', $target . '/composer.json');
        copy(Fixtures::laravelApp() . '/composer.lock', $target . '/composer.lock');

        $tester->execute(['--project-dir' => $target], ['decorated' => false]);

        self::assertStringContainsString('Detected Laravel 12', $tester->getDisplay());
        $config = file_get_contents($target . '/worker-safety.yaml');

        self::assertIsString($config);
        self::assertStringContainsString('- app', $config);
    }

    public function test_baseline_records_findings_then_the_scan_passes(): void
    {
        $baseline = $this->runCommand('baseline', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::Success->value, $baseline->getStatusCode());
        self::assertStringContainsString('finding(s) recorded', $baseline->getDisplay());
        self::assertFileExists($this->workspace . '/' . ApplicationInfo::BASELINE_FILE);

        $scan = $this->runCommand('scan', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::Success->value, $scan->getStatusCode());
        self::assertStringContainsString('ignored by the baseline', $scan->getDisplay());
    }

    public function test_no_baseline_re_reports_baselined_findings(): void
    {
        $this->runCommand('baseline', ['--project-dir' => $this->workspace]);

        $scan = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--no-baseline' => true]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $scan->getStatusCode());
    }

    public function test_generate_baseline_from_the_scan_command(): void
    {
        $tester = $this->runCommand('scan', ['--project-dir' => $this->workspace, '--generate-baseline' => true]);

        self::assertSame(ExitCode::Success->value, $tester->getStatusCode());
        self::assertFileExists($this->workspace . '/' . ApplicationInfo::BASELINE_FILE);
    }

    public function test_a_new_finding_still_fails_after_baselining(): void
    {
        $this->runCommand('baseline', ['--project-dir' => $this->workspace]);

        file_put_contents($this->workspace . '/src/More.php', <<<'PHP'
            <?php

            final class More
            {
                public static ?object $currentTenant = null;

                public static function set(object $tenant): void
                {
                    self::$currentTenant = $tenant;
                }
            }
            PHP);

        $scan = $this->runCommand('scan', ['--project-dir' => $this->workspace]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $scan->getStatusCode());
        self::assertStringContainsString('More.php', $scan->getDisplay());
    }

    public function test_the_application_exposes_name_and_version(): void
    {
        $application = new WorkerSafetyApplication();

        self::assertSame(ApplicationInfo::NAME, $application->getName());
        self::assertSame(ApplicationInfo::VERSION, $application->getVersion());
        self::assertStringContainsString(ApplicationInfo::PACKAGE, $application->getLongVersion());
    }

    public function test_the_documented_commands_exist(): void
    {
        $application = new WorkerSafetyApplication();

        foreach (['scan', 'rules', 'init', 'baseline'] as $name) {
            self::assertTrue($application->has($name), $name . ' should be registered');
        }
    }
}
