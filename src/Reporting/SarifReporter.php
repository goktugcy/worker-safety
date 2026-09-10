<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Rule\RuleRegistry;

/**
 * SARIF 2.1.0 output for GitHub code scanning.
 *
 * Every rule the scan could have produced is described in `tool.driver.rules`,
 * so GitHub can show the rule help even for rules with no results, and each
 * result carries a line-independent `partialFingerprints` entry so that code
 * scanning can track a finding across commits.
 */
final class SarifReporter implements Reporter
{
    private const SCHEMA = 'https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json';

    public function __construct(private readonly ?RuleRegistry $registry = null)
    {
    }

    public function report(ScanReport $report, OutputInterface $output): void
    {
        $output->writeln($this->encode($report));
    }

    public function encode(ScanReport $report): string
    {
        return (string) json_encode(
            $this->toArray($report),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(ScanReport $report): array
    {
        $rules = $this->rules();
        $ruleIndex = [];

        foreach ($rules as $index => $rule) {
            $id = $rule['id'];

            if (is_string($id)) {
                $ruleIndex[$id] = $index;
            }
        }

        return [
            '$schema' => self::SCHEMA,
            'version' => '2.1.0',
            'runs' => [
                [
                    'tool' => [
                        'driver' => [
                            'name' => ApplicationInfo::NAME,
                            'semanticVersion' => $report->toolVersion,
                            'version' => $report->toolVersion,
                            'informationUri' => ApplicationInfo::HOMEPAGE,
                            'rules' => $rules,
                        ],
                    ],
                    'originalUriBaseIds' => [
                        'SRCROOT' => ['uri' => $this->directoryUri($report->projectRoot)],
                    ],
                    'invocations' => [
                        [
                            // False whenever a file could not be analyzed: a
                            // consumer must be able to tell a complete run from
                            // a partial one.
                            'executionSuccessful' => !$report->isIncomplete(),
                            'toolExecutionNotifications' => $this->notifications($report),
                        ],
                    ],
                    'results' => array_map(
                        fn (Finding $finding): array => $this->result($finding, $ruleIndex),
                        $report->findings->toArray(),
                    ),
                ],
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rules(): array
    {
        if (!$this->registry instanceof RuleRegistry) {
            return [];
        }

        return array_map(
            static function (Rule $rule): array {
                $definition = $rule->definition();

                $help = $definition->description;

                if ($definition->remediation !== []) {
                    $help .= "\n\nHow to fix:\n- " . implode("\n- ", $definition->remediation);
                }

                $tags = ['worker-safety', $definition->category->value];

                foreach ($definition->runtimes as $runtime) {
                    $tags[] = 'runtime:' . $runtime->value;
                }

                if ($definition->requiresFramework !== null) {
                    $tags[] = 'framework:' . $definition->requiresFramework;
                }

                return [
                    'id' => $definition->id,
                    'name' => str_replace(' ', '', ucwords($definition->title)),
                    'shortDescription' => ['text' => $definition->title],
                    'fullDescription' => ['text' => $definition->description],
                    'help' => ['text' => $help, 'markdown' => $help],
                    'defaultConfiguration' => ['level' => $definition->defaultSeverity->sarifLevel()],
                    'properties' => [
                        'tags' => $tags,
                        'category' => $definition->category->value,
                        'defaultSeverity' => $definition->defaultSeverity->value,
                        'problem' => ['severity' => $definition->defaultSeverity->value],
                    ],
                ];
            },
            $this->registry->all(),
        );
    }

    /**
     * @param array<string, int> $ruleIndex
     *
     * @return array<string, mixed>
     */
    private function result(Finding $finding, array $ruleIndex): array
    {
        $region = ['startLine' => max(1, $finding->location->line)];

        if ($finding->location->column !== null) {
            $region['startColumn'] = max(1, $finding->location->column);
        }

        if ($finding->snippet !== null) {
            $region['snippet'] = ['text' => $finding->snippet];
        }

        $result = [
            'ruleId' => $finding->ruleId,
            'level' => $finding->severity->sarifLevel(),
            'message' => ['text' => $this->message($finding)],
            'locations' => [
                [
                    'physicalLocation' => [
                        'artifactLocation' => [
                            'uri' => self::encodePath($finding->location->relativePath),
                            'uriBaseId' => 'SRCROOT',
                        ],
                        'region' => $region,
                    ],
                ],
            ],
            'partialFingerprints' => [
                'workerSafetyFingerprint/v1' => $finding->fingerprint(),
            ],
            'properties' => [
                'severity' => $finding->severity->value,
                'category' => $finding->category->value,
                'runtimes' => $finding->runtimes->values(),
            ],
        ];

        if (isset($ruleIndex[$finding->ruleId])) {
            $result['ruleIndex'] = $ruleIndex[$finding->ruleId];
        }

        return $result;
    }

    private function message(Finding $finding): string
    {
        $message = $finding->message;

        if ($finding->details !== null) {
            $message .= ' ' . $finding->details;
        }

        if ($finding->remediation !== []) {
            $message .= ' Fix: ' . $finding->remediation[0];
        }

        return $message;
    }

    /**
     * Parse failures are reported as tool notifications rather than results,
     * which is what SARIF consumers expect for "could not analyze this file".
     *
     * @return list<array<string, mixed>>
     */
    private function notifications(ScanReport $report): array
    {
        return array_map(
            static fn (\WorkerSafety\Ast\ParseFailure $failure): array => [
                'level' => $failure->fatal ? 'error' : 'warning',
                'message' => ['text' => sprintf('Could not parse %s: %s', $failure->relativePath, $failure->message)],
                'locations' => [
                    [
                        'physicalLocation' => [
                            'artifactLocation' => [
                                'uri' => self::encodePath($failure->relativePath),
                                'uriBaseId' => 'SRCROOT',
                            ],
                            'region' => ['startLine' => max(1, $failure->line)],
                        ],
                    ],
                ],
            ],
            $report->parseFailures,
        );
    }

    /**
     * Percent-encode a relative path for use as a SARIF artifact URI.
     *
     * Path separators stay literal; everything else is escaped, so a file named
     * `path #1.php` cannot turn its `#` into a URI fragment delimiter.
     */
    private static function encodePath(string $relative): string
    {
        $segments = explode('/', str_replace('\\', '/', $relative));

        return implode('/', array_map(static fn (string $segment): string => rawurlencode($segment), $segments));
    }

    private function directoryUri(string $path): string
    {
        $normalized = rtrim(str_replace('\\', '/', $path), '/');
        $prefix = '';

        // Keep a Windows drive letter readable: `C:` must not become `C%3A`.
        if (preg_match('#^([a-zA-Z]:)(/.*)?$#', $normalized, $matches) === 1) {
            $prefix = '/' . $matches[1];
            $normalized = $matches[2] ?? '';
        }

        return 'file://' . $prefix . self::encodePath($normalized) . '/';
    }
}
