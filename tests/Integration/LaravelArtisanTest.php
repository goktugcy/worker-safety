<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Console\Kernel;
use Illuminate\Foundation\Exceptions\Handler;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Integration\Laravel\ArtisanScanCommand;
use WorkerSafety\Integration\Laravel\WorkerSafetyServiceProvider;
use WorkerSafety\Support\Paths;
use WorkerSafety\Tests\Support\JsonAccess;

/**
 * Drives `worker-safety:scan` through a real booted Laravel application.
 *
 * The point is that the Artisan command is the CLI command: same options, same
 * formats, same baseline handling, same exit codes — only the default project
 * root differs.
 */
#[CoversNothing]
final class LaravelArtisanTest extends TestCase
{
    use JsonAccess;

    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = Paths::normalize(sys_get_temp_dir() . '/ws-artisan-' . bin2hex(random_bytes(6)));

        @mkdir($this->basePath . '/app/Services', 0o777, true);
        @mkdir($this->basePath . '/bootstrap/cache', 0o777, true);
        @mkdir($this->basePath . '/elsewhere/src', 0o777, true);

        file_put_contents($this->basePath . '/app/Services/UserContext.php', <<<'PHP'
            <?php

            namespace App\Services;

            final class UserContext
            {
                public static ?object $currentUser = null;

                public static function set(object $user): void
                {
                    self::$currentUser = $user;
                }
            }
            PHP);

        file_put_contents($this->basePath . '/elsewhere/src/Clean.php', <<<'PHP'
            <?php

            final class Clean
            {
                public const VERSION = '1.0.0';
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->remove($this->basePath);
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
     * A minimal but genuine Laravel application with the provider registered
     * the way package discovery would register it.
     */
    private function laravel(): Application
    {
        $app = new Application($this->basePath);

        $app->singleton(KernelContract::class, Kernel::class);
        $app->singleton(ExceptionHandler::class, Handler::class);

        $app->register(WorkerSafetyServiceProvider::class);

        return $app;
    }

    /**
     * @param array<string, mixed> $parameters
     *
     * @return array{0: int, 1: string}
     */
    private function artisan(array $parameters): array
    {
        $app = $this->laravel();

        /** @var Kernel $kernel */
        $kernel = $app->make(KernelContract::class);
        $kernel->bootstrap();

        $output = new BufferedOutput();

        try {
            $status = $kernel->handle(new ArrayInput(['command' => 'worker-safety:scan'] + $parameters), $output);
        } finally {
            // Laravel's HandleExceptions bootstrapper installs global error and
            // exception handlers; leaving them in place leaks into other tests.
            restore_error_handler();
            restore_exception_handler();
        }

        return [$status, $output->fetch()];
    }

    public function test_the_command_is_registered_with_artisan(): void
    {
        $app = $this->laravel();

        /** @var Kernel $kernel */
        $kernel = $app->make(KernelContract::class);
        $kernel->bootstrap();

        try {
            $names = array_keys($kernel->all());
        } finally {
            restore_error_handler();
            restore_exception_handler();
        }

        self::assertContains('worker-safety:scan', $names);
    }

    /**
     * The provider only wires the command up for console runs.
     */
    public function test_nothing_is_registered_outside_the_console(): void
    {
        $app = new Application($this->basePath);
        $app->singleton(KernelContract::class, Kernel::class);
        $app->singleton(ExceptionHandler::class, Handler::class);

        // Pretend this is an HTTP request.
        $app->bind('request', static fn (): \Illuminate\Http\Request => new \Illuminate\Http\Request());
        $registered = [];
        Artisan::starting(static function (Artisan $artisan) use (&$registered): void {
            $registered = array_keys($artisan->all());
        });

        $provider = new WorkerSafetyServiceProvider($app);
        $provider->register();

        self::assertTrue($app->bound(ArtisanScanCommand::class), 'The binding is always available…');
        self::assertNotContains('worker-safety:scan', $registered, '…but nothing is registered yet.');
    }

    public function test_it_defaults_to_the_application_base_path(): void
    {
        [$status, $display] = $this->artisan(['--fail-on' => 'never', '--no-ansi' => true]);

        self::assertSame(ExitCode::Success->value, $status);
        self::assertStringContainsString($this->basePath, $display);
        self::assertStringContainsString('app/Services/UserContext.php', $display);
    }

    public function test_an_explicit_project_dir_wins_over_the_base_path(): void
    {
        [$status, $display] = $this->artisan([
            '--project-dir' => $this->basePath . '/elsewhere',
            '--fail-on' => 'never',
            '--no-ansi' => true,
        ]);

        self::assertSame(ExitCode::Success->value, $status);
        self::assertStringContainsString('No worker-safety risks found', $display);
        self::assertStringNotContainsString('UserContext', $display);
    }

    public function test_a_relative_project_dir_resolves_against_the_application_root(): void
    {
        [, $display] = $this->artisan([
            '--project-dir' => 'elsewhere',
            '--fail-on' => 'never',
            '--no-ansi' => true,
        ]);

        self::assertStringContainsString('No worker-safety risks found', $display);
    }

    public function test_path_arguments_are_passed_through(): void
    {
        [, $display] = $this->artisan([
            'paths' => ['app'],
            '--fail-on' => 'never',
            '--no-ansi' => true,
        ]);

        self::assertStringContainsString('UserContext', $display);
    }

    public function test_findings_produce_the_documented_exit_code(): void
    {
        [$status] = $this->artisan(['--fail-on' => 'high', '--no-ansi' => true]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $status);
    }

    public function test_an_invalid_option_exits_with_two(): void
    {
        [$status] = $this->artisan(['--format' => 'xml']);

        self::assertSame(ExitCode::InvalidConfiguration->value, $status);
    }

    public function test_a_missing_path_exits_with_three(): void
    {
        [$status] = $this->artisan(['paths' => ['nope']]);

        self::assertSame(ExitCode::InternalError->value, $status);
    }

    public function test_json_output_is_pure_json(): void
    {
        [, $display] = $this->artisan(['--format' => 'json', '--fail-on' => 'never']);

        $decoded = self::decodeJson($display);

        self::assertSame('1', self::stringAt($decoded, 'version'));
        self::assertNotSame([], self::arrayAt($decoded, 'findings'));
    }

    public function test_sarif_output_is_pure_json(): void
    {
        [, $display] = $this->artisan(['--format' => 'sarif', '--fail-on' => 'never']);

        $decoded = self::decodeJson($display);

        self::assertSame('2.1.0', self::stringAt($decoded, 'version'));
    }

    public function test_the_runtime_option_is_passed_through(): void
    {
        [, $display] = $this->artisan([
            '--runtime' => ['octane'],
            '--format' => 'json',
            '--fail-on' => 'never',
        ]);

        self::assertSame(['octane'], self::arrayAt(self::decodeJson($display), 'project', 'runtimes'));
    }

    public function test_the_baseline_round_trip_works_through_artisan(): void
    {
        [$generated] = $this->artisan(['--generate-baseline' => true]);

        self::assertSame(ExitCode::Success->value, $generated);
        self::assertFileExists($this->basePath . '/worker-safety-baseline.json');

        [$scanned] = $this->artisan([]);

        self::assertSame(ExitCode::Success->value, $scanned, 'Baselined findings must stop failing the build.');

        [$ignored] = $this->artisan(['--no-baseline' => true]);

        self::assertSame(ExitCode::FindingsAboveThreshold->value, $ignored);
    }

    public function test_the_artisan_command_reuses_the_cli_option_definitions(): void
    {
        $cli = (new \WorkerSafety\Command\ScanCommand())->getDefinition();
        $artisan = (new ArtisanScanCommand($this->basePath))->getDefinition();

        self::assertSame(
            array_keys($cli->getOptions()),
            array_keys($artisan->getOptions()),
            'The Artisan command must not define its own options.',
        );
        self::assertSame(array_keys($cli->getArguments()), array_keys($artisan->getArguments()));
    }
}
