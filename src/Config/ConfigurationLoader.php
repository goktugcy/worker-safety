<?php

declare(strict_types=1);

namespace WorkerSafety\Config;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\Paths;

/**
 * Loads and strictly validates the YAML configuration.
 *
 * Unknown keys and malformed values are hard errors rather than silently
 * ignored typos, and the YAML is parsed without object or custom-tag support
 * so a configuration file can never execute code.
 */
final class ConfigurationLoader
{
    /**
     * @var list<string>
     */
    private const TOP_LEVEL_KEYS = [
        'paths',
        'exclude',
        'extensions',
        'runtime',
        'rules',
        'fail_on',
        'fail_on_parse_error',
        'baseline',
        'ignore',
    ];

    /**
     * @var list<string>
     */
    private const RULE_KEYS = ['enabled', 'severity'];

    /**
     * Candidate file names, in priority order.
     *
     * @var list<string>
     */
    private const CANDIDATES = [
        'worker-safety.yaml',
        'worker-safety.yml',
        'worker-safety.dist.yaml',
        'worker-safety.dist.yml',
        '.worker-safety.yaml',
    ];

    /**
     * @param list<string> $knownRuleIds when non-empty, unknown rule ids are rejected
     */
    public function load(?string $explicitPath, string $projectRoot, array $knownRuleIds = []): Configuration
    {
        $path = $explicitPath !== null
            ? Paths::makeAbsolute($explicitPath, $projectRoot)
            : $this->discover($projectRoot);

        if ($path === null) {
            return $this->applyBaselineDefault(Configuration::defaults(), $projectRoot);
        }

        if (!is_file($path) || !is_readable($path)) {
            throw ConfigurationException::unreadable($explicitPath ?? $path);
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new ConfigurationException(sprintf(
                'Could not parse configuration file "%s": %s',
                $path,
                $exception->getMessage(),
            ), 0, $exception);
        }

        if ($parsed === null) {
            return $this->applyBaselineDefault(Configuration::defaults()->withSourcePath($path), $projectRoot);
        }

        // A scalar or a sequence at the root is a mapping mistake, not a set of
        // unknown keys, so say so plainly.
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw ConfigurationException::invalidValue('<root>', 'the configuration file must contain a YAML mapping.');
        }

        return $this->applyBaselineDefault(
            $this->build($parsed, $path, $projectRoot, $knownRuleIds),
            $projectRoot,
        );
    }

    public function discover(string $projectRoot): ?string
    {
        foreach (self::CANDIDATES as $candidate) {
            $path = Paths::normalize($projectRoot . '/' . $candidate);

            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string> $knownRuleIds
     */
    private function build(array $data, string $path, string $projectRoot, array $knownRuleIds): Configuration
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, self::TOP_LEVEL_KEYS, true)) {
                throw ConfigurationException::unknownKey(
                    is_string($key) ? $key : (string) json_encode($key),
                    'the configuration root',
                    self::TOP_LEVEL_KEYS,
                );
            }
        }

        $paths = $this->stringList($data, 'paths');
        $exclude = array_key_exists('exclude', $data)
            ? $this->stringList($data, 'exclude')
            : Configuration::DEFAULT_EXCLUDES;
        $extensions = array_key_exists('extensions', $data)
            ? $this->extensions($this->stringList($data, 'extensions'))
            : Configuration::DEFAULT_EXTENSIONS;

        return new Configuration(
            $paths,
            $exclude,
            $extensions,
            $this->runtimes($data),
            $this->rules($data, $knownRuleIds),
            $this->failOn($data),
            $this->baseline($data, $projectRoot),
            $this->ignore($data, $knownRuleIds),
            $path,
            $this->failOnParseError($data),
            $this->baselineDisabled($data),
        );
    }

    /**
     * @param array<mixed, mixed> $data
     *
     * @return list<string>
     */
    private function stringList(array $data, string $key): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }

        $value = $data[$key];

        if (is_string($value)) {
            $value = [$value];
        }

        if (!is_array($value)) {
            throw ConfigurationException::invalidValue($key, 'expected a list of strings.');
        }

        $result = [];

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw ConfigurationException::invalidValue($key, 'every entry must be a string.');
            }

            $trimmed = trim($item);

            if ($trimmed !== '') {
                $result[] = $trimmed;
            }
        }

        return $result;
    }

    /**
     * @param list<string> $extensions
     *
     * @return list<string>
     */
    private function extensions(array $extensions): array
    {
        $normalized = [];

        foreach ($extensions as $extension) {
            $normalized[] = strtolower(ltrim($extension, '.'));
        }

        if ($normalized === []) {
            throw ConfigurationException::invalidValue('extensions', 'at least one file extension is required.');
        }

        return $normalized;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function runtimes(array $data): RuntimeTargetSet
    {
        if (!array_key_exists('runtime', $data)) {
            return RuntimeTargetSet::all();
        }

        $values = $this->stringList($data, 'runtime');

        if ($values === []) {
            return RuntimeTargetSet::all();
        }

        $set = RuntimeTargetSet::fromStrings($values);

        if ($set->isEmpty()) {
            throw ConfigurationException::invalidValue('runtime', 'at least one runtime target is required.');
        }

        return $set;
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string> $knownRuleIds
     *
     * @return array<string, RuleSetting>
     */
    private function rules(array $data, array $knownRuleIds): array
    {
        if (!array_key_exists('rules', $data)) {
            return [];
        }

        $rules = $data['rules'];

        if (!is_array($rules)) {
            throw ConfigurationException::invalidValue('rules', 'expected a mapping of rule id to settings.');
        }

        $result = [];

        foreach ($rules as $ruleId => $settings) {
            $id = $this->normalizeRuleId($ruleId, 'rules', $knownRuleIds);

            if (is_bool($settings)) {
                $result[$id] = new RuleSetting($settings);

                continue;
            }

            if (!is_array($settings)) {
                throw ConfigurationException::invalidValue(
                    'rules.' . $id,
                    'expected a boolean or a mapping with "enabled" and/or "severity".',
                );
            }

            foreach (array_keys($settings) as $key) {
                if (!is_string($key) || !in_array($key, self::RULE_KEYS, true)) {
                    throw ConfigurationException::unknownKey(
                        is_string($key) ? $key : (string) json_encode($key),
                        'rules.' . $id,
                        self::RULE_KEYS,
                    );
                }
            }

            $enabled = $settings['enabled'] ?? true;

            if (!is_bool($enabled)) {
                throw ConfigurationException::invalidValue('rules.' . $id . '.enabled', 'expected a boolean.');
            }

            $severity = null;

            if (array_key_exists('severity', $settings) && $settings['severity'] !== null) {
                if (!is_string($settings['severity'])) {
                    throw ConfigurationException::invalidValue('rules.' . $id . '.severity', 'expected a string.');
                }

                $severity = Severity::tryFromString($settings['severity']);

                if (!$severity instanceof Severity) {
                    throw ConfigurationException::invalidValue(
                        'rules.' . $id . '.severity',
                        sprintf('"%s" is not one of %s.', $settings['severity'], implode(', ', Severity::names())),
                    );
                }
            }

            $result[$id] = new RuleSetting($enabled, $severity);
        }

        return $result;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function failOn(array $data): ?Severity
    {
        if (!array_key_exists('fail_on', $data)) {
            return Severity::High;
        }

        $value = $data['fail_on'];

        if ($value === null || $value === false) {
            return null;
        }

        if (!is_string($value)) {
            throw ConfigurationException::invalidValue('fail_on', 'expected a severity name or "never".');
        }

        $normalized = strtolower(trim($value));

        if ($normalized === 'never' || $normalized === 'none') {
            return null;
        }

        $severity = Severity::tryFromString($normalized);

        if (!$severity instanceof Severity) {
            throw ConfigurationException::invalidValue(
                'fail_on',
                sprintf('"%s" is not one of %s, never.', $value, implode(', ', Severity::names())),
            );
        }

        return $severity;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function failOnParseError(array $data): bool
    {
        if (!array_key_exists('fail_on_parse_error', $data)) {
            return true;
        }

        $value = $data['fail_on_parse_error'];

        if (!is_bool($value)) {
            throw ConfigurationException::invalidValue('fail_on_parse_error', 'expected a boolean.');
        }

        return $value;
    }

    /**
     * `baseline: false` means "explicitly off", which is different from an
     * absent setting: only the latter may pick up a baseline file automatically.
     *
     * @param array<mixed, mixed> $data
     */
    private function baselineDisabled(array $data): bool
    {
        if (!array_key_exists('baseline', $data)) {
            return false;
        }

        $value = $data['baseline'];

        return $value === false || $value === null;
    }

    /**
     * @param array<mixed, mixed> $data
     */
    private function baseline(array $data, string $projectRoot): ?string
    {
        if (!array_key_exists('baseline', $data)) {
            return null;
        }

        $value = $data['baseline'];

        if ($value === null || $value === false) {
            return null;
        }

        if (!is_string($value) || trim($value) === '') {
            throw ConfigurationException::invalidValue('baseline', 'expected a file path or false.');
        }

        return Paths::makeAbsolute(trim($value), $projectRoot);
    }

    /**
     * @param array<mixed, mixed> $data
     * @param list<string> $knownRuleIds
     *
     * @return array<string, list<string>>
     */
    private function ignore(array $data, array $knownRuleIds): array
    {
        if (!array_key_exists('ignore', $data)) {
            return [];
        }

        $ignore = $data['ignore'];

        if (!is_array($ignore)) {
            throw ConfigurationException::invalidValue('ignore', 'expected a mapping of rule id to path patterns.');
        }

        $result = [];

        foreach ($ignore as $ruleId => $patterns) {
            $id = $this->normalizeRuleId($ruleId, 'ignore', $knownRuleIds);

            if (is_string($patterns)) {
                $patterns = [$patterns];
            }

            if (!is_array($patterns)) {
                throw ConfigurationException::invalidValue('ignore.' . $id, 'expected a list of path patterns.');
            }

            $list = [];

            foreach ($patterns as $pattern) {
                if (!is_string($pattern)) {
                    throw ConfigurationException::invalidValue('ignore.' . $id, 'every pattern must be a string.');
                }

                $list[] = trim($pattern);
            }

            $result[$id] = $list;
        }

        return $result;
    }

    /**
     * @param list<string> $knownRuleIds
     */
    private function normalizeRuleId(mixed $ruleId, string $section, array $knownRuleIds): string
    {
        if (!is_string($ruleId) && !is_int($ruleId)) {
            throw ConfigurationException::invalidValue($section, 'rule ids must be strings such as "WS001".');
        }

        $id = strtoupper(trim((string) $ruleId));

        if (preg_match('/^WS\d{3}$/', $id) !== 1) {
            throw ConfigurationException::invalidValue(
                $section . '.' . $id,
                'rule ids must look like "WS001".',
            );
        }

        if ($knownRuleIds !== [] && !in_array($id, $knownRuleIds, true)) {
            throw ConfigurationException::invalidValue(
                $section . '.' . $id,
                sprintf('unknown rule. Run `%s rules` to list the available rules.', ApplicationInfo::BINARY),
            );
        }

        return $id;
    }

    /**
     * Pick up an existing baseline file automatically when none is configured.
     */
    private function applyBaselineDefault(Configuration $configuration, string $projectRoot): Configuration
    {
        if ($configuration->baseline !== null || $configuration->baselineDisabled) {
            return $configuration;
        }

        $default = Paths::normalize($projectRoot . '/' . ApplicationInfo::BASELINE_FILE);

        return is_file($default) ? $configuration->withBaseline($default) : $configuration;
    }
}
