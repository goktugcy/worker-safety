<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Framework;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Framework\DetectedFramework;
use WorkerSafety\Framework\Framework;
use WorkerSafety\Framework\FrameworkAdapterRegistry;
use WorkerSafety\Framework\FrameworkDetector;
use WorkerSafety\Framework\Laravel\LaravelAdapter;
use WorkerSafety\Framework\PlainPhpAdapter;
use WorkerSafety\Framework\Symfony\SymfonyAdapter;
use WorkerSafety\Support\Paths;
use WorkerSafety\Tests\Support\Fixtures;

#[CoversClass(FrameworkDetector::class)]
#[CoversClass(DetectedFramework::class)]
#[CoversClass(FrameworkAdapterRegistry::class)]
final class FrameworkDetectorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Paths::normalize(sys_get_temp_dir() . '/ws-fw-' . bin2hex(random_bytes(6)));
        @mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function write(string $name, array $data): void
    {
        file_put_contents($this->root . '/' . $name, (string) json_encode($data));
    }

    public function test_no_composer_json_means_no_framework(): void
    {
        $detected = (new FrameworkDetector())->detect($this->root);

        self::assertSame(Framework::None, $detected->framework);
        self::assertFalse($detected->isKnown());
        self::assertSame('plain PHP', $detected->describe());
        self::assertNull($detected->findingLabel());
    }

    public function test_laravel_is_detected_from_composer_json(): void
    {
        $this->write('composer.json', ['require' => ['laravel/framework' => '^11.0']]);

        $detected = (new FrameworkDetector())->detect($this->root);

        self::assertTrue($detected->isLaravel());
        self::assertSame('11', $detected->version);
        self::assertSame('Laravel 11', $detected->describe());
        self::assertSame('laravel', $detected->identifier());
    }

    public function test_the_lock_file_version_wins_over_the_constraint(): void
    {
        $this->write('composer.json', ['require' => ['laravel/framework' => '^11.0']]);
        $this->write('composer.lock', ['packages' => [['name' => 'laravel/framework', 'version' => 'v12.4.1']]]);

        self::assertSame('12', (new FrameworkDetector())->detect($this->root)->version);
    }

    public function test_symfony_is_detected(): void
    {
        $this->write('composer.json', ['require' => ['symfony/framework-bundle' => '^7.1']]);

        $detected = (new FrameworkDetector())->detect($this->root);

        self::assertTrue($detected->isSymfony());
        self::assertSame('Symfony 7', $detected->describe());
    }

    public function test_lumen_and_illuminate_support_count_as_laravel(): void
    {
        $this->write('composer.json', ['require' => ['illuminate/support' => '^11.0']]);

        self::assertTrue((new FrameworkDetector())->detect($this->root)->isLaravel());
    }

    public function test_dev_requirements_are_considered(): void
    {
        $this->write('composer.json', ['require-dev' => ['laravel/framework' => '^11.0']]);

        self::assertTrue((new FrameworkDetector())->detect($this->root)->isLaravel());
    }

    public function test_a_plain_project_is_not_a_framework(): void
    {
        $this->write('composer.json', ['require' => ['nikic/php-parser' => '^5.0']]);

        self::assertFalse((new FrameworkDetector())->detect($this->root)->isKnown());
    }

    public function test_malformed_composer_json_is_tolerated(): void
    {
        file_put_contents($this->root . '/composer.json', '{not json');

        self::assertFalse((new FrameworkDetector())->detect($this->root)->isKnown());
    }

    public function test_the_laravel_fixture_project_is_detected(): void
    {
        $detected = (new FrameworkDetector())->detect(Fixtures::laravelApp());

        self::assertSame('Laravel 12', $detected->describe());
    }

    public function test_the_registry_picks_the_matching_adapter(): void
    {
        $registry = new FrameworkAdapterRegistry();

        self::assertInstanceOf(
            LaravelAdapter::class,
            $registry->for(new DetectedFramework(Framework::Laravel)),
        );
        self::assertInstanceOf(
            SymfonyAdapter::class,
            $registry->for(new DetectedFramework(Framework::Symfony)),
        );
        self::assertInstanceOf(
            PlainPhpAdapter::class,
            $registry->for(DetectedFramework::none()),
        );
    }

    public function test_the_laravel_adapter_contributes_rules_and_collectors(): void
    {
        $adapter = new LaravelAdapter();

        self::assertCount(2, $adapter->rules());
        self::assertCount(1, $adapter->bindingCollectors());
        self::assertContains('app', $adapter->defaultPaths());
        self::assertContains('*.blade.php', $adapter->defaultExcludes());
    }

    public function test_the_symfony_adapter_is_a_ready_extension_point_without_rules(): void
    {
        $adapter = new SymfonyAdapter();

        self::assertSame([], $adapter->rules());
        self::assertSame([], $adapter->bindingCollectors());
        self::assertSame(['src', 'config'], $adapter->defaultPaths());
    }
}
