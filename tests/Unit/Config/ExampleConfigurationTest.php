<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Config\ConfigurationTemplate;

/**
 * Keeps the committed example file identical to what `init` writes, so the
 * documentation can never drift away from the tool.
 */
#[CoversClass(ConfigurationTemplate::class)]
final class ExampleConfigurationTest extends TestCase
{
    private function examplePath(): string
    {
        return dirname(__DIR__, 3) . '/worker-safety.example.yaml';
    }

    public function test_the_committed_example_matches_the_template(): void
    {
        $committed = file_get_contents($this->examplePath());

        self::assertIsString($committed, 'worker-safety.example.yaml is missing');
        self::assertSame(
            ConfigurationTemplate::render(),
            $committed,
            'Regenerate worker-safety.example.yaml: php -r \'require "vendor/autoload.php";'
            . ' file_put_contents("worker-safety.example.yaml",'
            . ' WorkerSafety\\Config\\ConfigurationTemplate::render());\'',
        );
    }

    public function test_the_example_documents_the_binary_and_the_baseline_file(): void
    {
        $rendered = ConfigurationTemplate::render();

        self::assertStringContainsString(ApplicationInfo::BINARY . ' rules', $rendered);
        self::assertStringContainsString(ApplicationInfo::BASELINE_FILE, $rendered);
        self::assertStringContainsString(ApplicationInfo::IGNORE_MARKER, $rendered);
    }
}
