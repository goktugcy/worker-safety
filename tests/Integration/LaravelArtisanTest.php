<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Integration;

use Illuminate\Console\Application as Artisan;
use Illuminate\Contracts\Console\Kernel as KernelContract;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
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
        // Artisan keeps its starting callbacks in a static, so they have to be
        // cleared or one test's registration leaks into the next.
        Artisan::forgetBootstrappers();

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
        Artisan::forgetBootstrappers();

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
     * Register and boot the provider against an application that reports the
     * given console state, then ask a real Artisan application what it knows.
     *
     * Going through `Illuminate\Console\Application` matters: `commands()`
     * defers registration to an `Artisan::starting()` callback, so anything
     * that stops short of building Artisan would pass whether the guard is
     * there or not.
     *
     * @return list<string>
     */
    private function commandNamesAfterBoot(bool $runningInConsole): array
    {
        Artisan::forgetBootstrappers();

        $app = $runningInConsole
            ? new Application($this->basePath)
            : new class ($this->basePath) extends Application {
                public function runningInConsole(): bool
                {
                    return false;
                }
            };

        $app->singleton(KernelContract::class, Kernel::class);
        $app->singleton(ExceptionHandler::class, Handler::class);

        $provider = new WorkerSafetyServiceProvider($app);
        $provider->register();
        $provider->boot();

        // `resolve()` registers an #[AsCommand] class lazily into Artisan's
        // command map, so the container loader has to be attached before
        // `all()` can see it — this is what the console kernel does too.
        $artisan = (new Artisan($app, $app->make(Dispatcher::class), 'testing'))
            ->setContainerCommandLoader();

        return array_keys($artisan->all());
    }

    public function test_the_command_is_registered_for_a_console_run(): void
    {
        self::assertContains('worker-safety:scan', $this->commandNamesAfterBoot(true));
    }

    /**
     * Outside the console the provider still registers — a discovered provider
     * is loaded on every request — but it must not add the command.
     *
     * Removing the `runningInConsole()` guard from the provider has to make
     * this test fail; that is the whole point of it.
     */
    public function test_the_command_is_not_registered_outside_the_console(): void
    {
        $names = $this->commandNamesAfterBoot(false);

        self::assertNotContains('worker-safety:scan', $names);
        self::assertNotSame([], $names, 'Artisan itself should still have its built-in commands.');
    }

    public function test_the_container_binding_exists_either_way(): void
    {
        foreach ([true, false] as $console) {
            $app = $console
                ? new Application($this->basePath)
                : new class ($this->basePath) extends Application {
                    public function runningInConsole(): bool
                    {
                        return false;
                    }
                };

            (new WorkerSafetyServiceProvider($app))->register();

            self::assertTrue($app->bound(ArtisanScanCommand::class));
        }
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
