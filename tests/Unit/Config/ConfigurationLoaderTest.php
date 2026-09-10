<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Config;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Config\Configuration;
use WorkerSafety\Config\ConfigurationLoader;
use WorkerSafety\Config\ConfigurationTemplate;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Support\Paths;

#[CoversClass(ConfigurationLoader::class)]
#[CoversClass(Configuration::class)]
final class ConfigurationLoaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = Paths::normalize(sys_get_temp_dir() . '/ws-config-' . bin2hex(random_bytes(6)));
        @mkdir($this->root, 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->root);
    }

    private function writeConfig(string $yaml, string $name = 'worker-safety.yaml'): string
    {
        $path = $this->root . '/' . $name;
        file_put_contents($path, $yaml);

        return $path;
    }

    private function load(?string $path = null): Configuration
    {
        return (new ConfigurationLoader())->load($path, $this->root, RuleId::all());
    }

    public function test_sensible_defaults_when_no_file_exists(): void
    {
        $configuration = $this->load();

        self::assertSame([], $configuration->paths);
        self::assertSame(Configuration::DEFAULT_EXCLUDES, $configuration->exclude);
        self::assertSame(['php'], $configuration->extensions);
        self::assertTrue($configuration->runtimes->isAll());
        self::assertSame(Severity::High, $configuration->failOn);
        self::assertNull($configuration->sourcePath);
        self::assertNull($configuration->baseline);
    }

    public function test_it_discovers_the_config_file_in_the_project_root(): void
    {
        $this->writeConfig("paths:\n  - app\n");

        $configuration = $this->load();

        self::assertSame(['app'], $configuration->paths);
        self::assertNotNull($configuration->sourcePath);
    }

    public function test_a_full_configuration_is_parsed(): void
    {
        $this->writeConfig(<<<'YAML'
            paths:
              - app
              - src
            exclude:
              - vendor
              - storage
            extensions:
              - php
              - inc
            runtime:
              - frankenphp
              - octane
            rules:
              WS001:
                enabled: true
                severity: critical
              WS009: false
            ignore:
              WS008:
                - app/Legacy
            fail_on: medium
            baseline: custom-baseline.json
            YAML);

        $configuration = $this->load();

        self::assertSame(['app', 'src'], $configuration->paths);
        self::assertSame(['vendor', 'storage'], $configuration->exclude);
        self::assertSame(['php', 'inc'], $configuration->extensions);
        self::assertSame(['frankenphp', 'octane'], $configuration->runtimes->values());
        self::assertSame(Severity::Critical, $configuration->ruleSetting('WS001')->severity);
        self::assertTrue($configuration->isRuleEnabled('WS001'));
        self::assertFalse($configuration->isRuleEnabled('WS009'));
        self::assertSame(Severity::Medium, $configuration->failOn);
        self::assertStringEndsWith('custom-baseline.json', (string) $configuration->baseline);
    }

    public function test_configured_severity_overrides_the_reported_one(): void
    {
        $this->writeConfig("rules:\n  WS001:\n    severity: low\n");

        $configuration = $this->load();

        self::assertSame(Severity::Low, $configuration->severityFor('WS001', Severity::Critical));
        self::assertSame(Severity::Critical, $configuration->severityFor('WS002', Severity::Critical));
    }

    public function test_ignore_patterns_become_a_matcher(): void
    {
        $this->writeConfig("ignore:\n  WS008:\n    - app/Legacy\n");

        $matcher = $this->load()->ignoreMatcherFor('WS008');

        self::assertNotNull($matcher);
        self::assertTrue($matcher->matches('app/Legacy/Registry.php'));
        self::assertFalse($matcher->matches('app/Http/Controller.php'));
        self::assertNull($this->load()->ignoreMatcherFor('WS001'));
    }

    public function test_runtime_all_expands_to_every_target(): void
    {
        $this->writeConfig("runtime: all\n");

        self::assertTrue($this->load()->runtimes->isAll());
    }

    public function test_fail_on_never_disables_the_threshold(): void
    {
        $this->writeConfig("fail_on: never\n");

        self::assertNull($this->load()->failOn);
    }

    public function test_baseline_false_disables_the_baseline(): void
    {
        $this->writeConfig("baseline: false\n");

        self::assertNull($this->load()->baseline);
    }

    public function test_an_existing_baseline_file_is_picked_up_automatically(): void
    {
        file_put_contents($this->root . '/worker-safety-baseline.json', '{"findings":[]}');

        self::assertNotNull($this->load()->baseline);
    }

    public function test_an_empty_file_falls_back_to_defaults(): void
    {
        $this->writeConfig('');

        self::assertSame(Severity::High, $this->load()->failOn);
    }

    public function test_an_unknown_top_level_key_is_rejected(): void
    {
        $this->writeConfig("pathz:\n  - app\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown configuration key "pathz"/');

        $this->load();
    }

    public function test_an_unknown_rule_key_is_rejected(): void
    {
        $this->writeConfig("rules:\n  WS001:\n    enabledd: true\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Unknown configuration key "enabledd"/');

        $this->load();
    }

    public function test_an_invalid_severity_is_rejected(): void
    {
        $this->writeConfig("rules:\n  WS001:\n    severity: extreme\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/is not one of/');

        $this->load();
    }

    public function test_an_invalid_fail_on_is_rejected(): void
    {
        $this->writeConfig("fail_on: extreme\n");

        $this->expectException(ConfigurationException::class);

        $this->load();
    }

    public function test_an_unknown_rule_id_is_rejected(): void
    {
        $this->writeConfig("rules:\n  WS999:\n    enabled: false\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/unknown rule/');

        $this->load();
    }

    public function test_a_malformed_rule_id_is_rejected(): void
    {
        $this->writeConfig("rules:\n  not-a-rule:\n    enabled: false\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must look like/');

        $this->load();
    }

    public function test_an_unknown_runtime_is_rejected(): void
    {
        $this->writeConfig("runtime:\n  - fpm\n");

        $this->expectException(ConfigurationException::class);

        $this->load();
    }

    public function test_broken_yaml_is_rejected(): void
    {
        // Tabs are never valid YAML indentation.
        $this->writeConfig("paths:\n\t- app\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/Could not parse configuration file/');

        $this->load();
    }

    public function test_a_non_mapping_document_is_rejected(): void
    {
        $this->writeConfig("- app\n- src\n");

        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/must contain a YAML mapping/');

        $this->load();
    }

    public function test_a_missing_explicit_config_file_is_rejected(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches('/does not exist or is not readable/');

        $this->load('nope.yaml');
    }

    public function test_the_generated_template_is_accepted_by_the_loader(): void
    {
        $this->writeConfig(ConfigurationTemplate::render());

        $configuration = $this->load();

        self::assertSame(['src'], $configuration->paths);
        self::assertSame(Severity::High, $configuration->failOn);
        self::assertSame(['frankenphp', 'octane'], $configuration->runtimes->values());
        self::assertNotNull($configuration->ignoreMatcherFor('WS008'));
    }
}
