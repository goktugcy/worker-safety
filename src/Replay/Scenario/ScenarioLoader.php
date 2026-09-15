<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Scenario;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Support\Paths;

/**
 * Loads and strictly validates a replay scenario.
 *
 * Same policy as the analyzer's configuration loader: unknown keys are errors
 * rather than silently ignored typos, and the YAML is parsed without object or
 * custom-tag support so a scenario file can never execute code. A scenario is
 * data the tool reads, never something it runs.
 */
final class ScenarioLoader
{
    /**
     * @var list<string>
     */
    private const TOP_LEVEL_KEYS = ['version', 'name', 'base_url', 'steps'];

    /**
     * @var list<string>
     */
    private const STEP_KEYS = ['id', 'request', 'expect'];

    /**
     * @var list<string>
     */
    private const REQUEST_KEYS = ['method', 'path', 'headers', 'json', 'body'];

    /**
     * @var list<string>
     */
    private const EXPECT_KEYS = ['status', 'json', 'headers', 'body_contains', 'body_not_contains'];

    public function load(string $path, ?string $baseUrlOverride = null, ?string $workingDirectory = null): ReplayScenario
    {
        $absolute = Paths::makeAbsolute($path, $workingDirectory ?? $this->cwd());

        if (!is_file($absolute) || !is_readable($absolute)) {
            throw ConfigurationException::unreadable($path);
        }

        try {
            /** @var mixed $parsed */
            $parsed = Yaml::parseFile($absolute);

            // Parsed a second time with mappings as objects. Symfony's default
            // turns both `{}` and `[]` into an empty PHP array, so an
            // expectation of "an empty object" would silently match an empty
            // list. The structural validation below stays on the array form —
            // request JSON and expectation values are lifted from this one, where
            // JSON's object/array distinction survives.
            /** @var mixed $typed */
            $typed = Yaml::parseFile($absolute, Yaml::PARSE_OBJECT_FOR_MAP);
        } catch (ParseException $exception) {
            throw new ConfigurationException(sprintf(
                'Could not parse scenario file "%s": %s',
                $path,
                $exception->getMessage(),
            ), 0, $exception);
        }

        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            throw ConfigurationException::invalidValue(
                'scenario',
                sprintf('"%s" must contain a YAML mapping at the root.', $path),
            );
        }

        return $this->build($parsed, $typed, $absolute, $baseUrlOverride);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function build(array $data, mixed $typed, string $path, ?string $baseUrlOverride): ReplayScenario
    {
        $this->rejectUnknown($data, self::TOP_LEVEL_KEYS, 'scenario');

        $this->assertVersion($data);

        $name = $data['name'] ?? null;

        if (!is_string($name) || trim($name) === '') {
            throw ConfigurationException::invalidValue('name', 'A scenario needs a non-empty name.');
        }

        $baseUrl = $this->baseUrl($data, $baseUrlOverride);
        $steps = $this->steps($data, $this->typedSteps($typed));

        return new ReplayScenario(trim($name), $baseUrl, $steps, $path);
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function assertVersion(array $data): void
    {
        if (!array_key_exists('version', $data)) {
            throw ConfigurationException::invalidValue(
                'version',
                sprintf('A scenario must declare "version: %d".', ReplayScenario::VERSION),
            );
        }

        if ($data['version'] !== ReplayScenario::VERSION) {
            throw ConfigurationException::invalidValue('version', sprintf(
                'Unsupported scenario version %s; this release of Worker Safety understands version %d.',
                is_scalar($data['version']) ? var_export($data['version'], true) : gettype($data['version']),
                ReplayScenario::VERSION,
            ));
        }
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function baseUrl(array $data, ?string $override): string
    {
        $value = $override ?? ($data['base_url'] ?? null);

        if (!is_string($value) || trim($value) === '') {
            throw ConfigurationException::invalidValue(
                'base_url',
                'A scenario needs a base_url, or a --base-url option to supply one.',
            );
        }

        $value = trim($value);
        $scheme = parse_url($value, PHP_URL_SCHEME);

        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw ConfigurationException::invalidValue(
                'base_url',
                sprintf('"%s" must be an absolute http:// or https:// URL.', $value),
            );
        }

        return rtrim($value, '/');
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<mixed> $typedSteps
     *
     * @return list<ReplayStep>
     */
    private function steps(array $data, array $typedSteps): array
    {
        $raw = $data['steps'] ?? null;

        if (!is_array($raw) || $raw === [] || !array_is_list($raw)) {
            throw ConfigurationException::invalidValue('steps', 'A scenario needs a non-empty list of steps.');
        }

        $steps = [];
        $seen = [];

        foreach ($raw as $index => $entry) {
            if (!is_array($entry) || array_is_list($entry)) {
                throw ConfigurationException::invalidValue(
                    sprintf('steps.%d', $index),
                    'Each step must be a mapping with a request.',
                );
            }

            $step = $this->step($entry, $typedSteps[$index] ?? null, $index);

            if (isset($seen[$step->id])) {
                throw ConfigurationException::invalidValue(
                    sprintf('steps.%d.id', $index),
                    sprintf('Step id "%s" is used more than once; ids identify steps in the report.', $step->id),
                );
            }

            $seen[$step->id] = true;
            $steps[] = $step;
        }

        return $steps;
    }

    /**
     * @param array<array-key, mixed> $entry
     */
    private function step(array $entry, mixed $typedEntry, int $index): ReplayStep
    {
        $this->rejectUnknown($entry, self::STEP_KEYS, sprintf('steps.%d', $index));

        $id = $entry['id'] ?? null;

        if ($id !== null && (!is_string($id) || trim($id) === '')) {
            throw ConfigurationException::invalidValue(sprintf('steps.%d.id', $index), 'A step id must be a non-empty string.');
        }

        $request = $entry['request'] ?? null;

        if (!is_array($request) || array_is_list($request)) {
            throw ConfigurationException::invalidValue(
                sprintf('steps.%d.request', $index),
                'Each step needs a request mapping.',
            );
        }

        $expect = $entry['expect'] ?? [];

        if (!is_array($expect) || ($expect !== [] && array_is_list($expect))) {
            throw ConfigurationException::invalidValue(
                sprintf('steps.%d.expect', $index),
                'expect must be a mapping.',
            );
        }

        return new ReplayStep(
            is_string($id) ? trim($id) : sprintf('step-%d', $index + 1),
            $this->request($request, $typedEntry instanceof \stdClass ? ($typedEntry->request ?? null) : null, $index),
            $this->expectation($expect, $this->typedExpectJson($typedEntry), $index),
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function request(array $data, mixed $typedRequest, int $index): ReplayRequest
    {
        $context = sprintf('steps.%d.request', $index);
        $this->rejectUnknown($data, self::REQUEST_KEYS, $context);

        $method = $data['method'] ?? 'GET';

        if (!is_string($method) || !in_array(strtoupper($method), ReplayRequest::METHODS, true)) {
            throw ConfigurationException::invalidValue($context . '.method', sprintf(
                'Method must be one of %s.',
                implode(', ', ReplayRequest::METHODS),
            ));
        }

        $path = $data['path'] ?? null;

        if (!is_string($path) || $path === '') {
            throw ConfigurationException::invalidValue($context . '.path', 'A request needs a path, for example "/context".');
        }

        // Both would leave the body ambiguous, so it is rejected rather than
        // resolved by a precedence rule nobody would remember.
        $hasJson = array_key_exists('json', $data);
        $hasBody = array_key_exists('body', $data);

        if ($hasJson && $hasBody) {
            throw ConfigurationException::invalidValue(
                $context,
                'A request may set either json or body, not both.',
            );
        }

        $body = null;

        if ($hasBody) {
            $raw = $data['body'];

            if (!is_string($raw)) {
                throw ConfigurationException::invalidValue($context . '.body', 'body must be a string.');
            }

            $body = $raw;
        }

        return new ReplayRequest(
            strtoupper($method),
            $path,
            $this->headerMap($data['headers'] ?? [], $context . '.headers'),
            $hasJson && $typedRequest instanceof \stdClass ? ($typedRequest->json ?? null) : null,
            $body,
            $hasJson,
        );
    }

    /**
     * @param array<array-key, mixed> $data
     */
    private function expectation(array $data, ?\stdClass $typedJson, int $index): ReplayExpectation
    {
        $context = sprintf('steps.%d.expect', $index);
        $this->rejectUnknown($data, self::EXPECT_KEYS, $context);

        $status = null;

        if (array_key_exists('status', $data)) {
            $raw = $data['status'];

            if (!is_int($raw) || $raw < 100 || $raw > 599) {
                throw ConfigurationException::invalidValue($context . '.status', 'status must be an integer between 100 and 599.');
            }

            $status = $raw;
        }

        $json = [];

        if (array_key_exists('json', $data)) {
            $raw = $data['json'];

            if (!is_array($raw) || array_is_list($raw)) {
                throw ConfigurationException::invalidValue(
                    $context . '.json',
                    'json must be a mapping of dot-paths to expected values, for example "user: null".',
                );
            }

            $typedValues = $typedJson instanceof \stdClass ? get_object_vars($typedJson) : [];

            foreach ($raw as $path => $value) {
                if (!is_string($path) || trim($path) === '') {
                    throw ConfigurationException::invalidValue($context . '.json', 'Every JSON path must be a non-empty string.');
                }

                // Prefer the type-preserving value; fall back to the array form
                // if the two parses ever disagree about shape.
                $json[trim($path)] = array_key_exists($path, $typedValues) ? $typedValues[$path] : $value;
            }
        }

        return new ReplayExpectation(
            $status,
            $json,
            $this->headerMap($data['headers'] ?? [], $context . '.headers'),
            $this->stringList($data, 'body_contains', $context),
            $this->stringList($data, 'body_not_contains', $context),
        );
    }

    /**
     * The steps of the type-preserving parse, positionally aligned with the
     * array parse because both come from the same file.
     *
     * @return list<mixed>
     */
    private function typedSteps(mixed $typed): array
    {
        if (!$typed instanceof \stdClass) {
            return [];
        }

        $steps = get_object_vars($typed)['steps'] ?? null;

        return is_array($steps) && array_is_list($steps) ? $steps : [];
    }

    private function typedExpectJson(mixed $typedEntry): ?\stdClass
    {
        if (!$typedEntry instanceof \stdClass) {
            return null;
        }

        $expect = get_object_vars($typedEntry)['expect'] ?? null;

        if (!$expect instanceof \stdClass) {
            return null;
        }

        $json = get_object_vars($expect)['json'] ?? null;

        return $json instanceof \stdClass ? $json : null;
    }

    /**
     * @return array<string, string>
     */
    private function headerMap(mixed $raw, string $context): array
    {
        if ($raw === [] || $raw === null) {
            return [];
        }

        if (!is_array($raw) || array_is_list($raw)) {
            throw ConfigurationException::invalidValue($context, 'headers must be a mapping of header names to values.');
        }

        $headers = [];

        foreach ($raw as $name => $value) {
            if (!is_string($name) || trim($name) === '') {
                throw ConfigurationException::invalidValue($context, 'Every header name must be a non-empty string.');
            }

            if (is_bool($value) || $value === null || is_array($value)) {
                throw ConfigurationException::invalidValue(
                    $context . '.' . $name,
                    'A header value must be a string or a number.',
                );
            }

            /** @var string|int|float $value */
            $headers[trim($name)] = (string) $value;
        }

        return $headers;
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>
     */
    private function stringList(array $data, string $key, string $context): array
    {
        if (!array_key_exists($key, $data)) {
            return [];
        }

        $raw = $data[$key];

        if (!is_array($raw) || !array_is_list($raw)) {
            throw ConfigurationException::invalidValue($context . '.' . $key, sprintf('%s must be a list of strings.', $key));
        }

        $values = [];

        foreach ($raw as $value) {
            if (!is_string($value) || $value === '') {
                throw ConfigurationException::invalidValue(
                    $context . '.' . $key,
                    sprintf('%s must contain non-empty strings.', $key),
                );
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * @param array<array-key, mixed> $data
     * @param list<string> $allowed
     */
    private function rejectUnknown(array $data, array $allowed, string $context): void
    {
        foreach (array_keys($data) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw ConfigurationException::unknownKey((string) $key, $context, $allowed);
            }
        }
    }

    private function cwd(): string
    {
        $cwd = getcwd();

        return Paths::normalize($cwd === false ? '.' : $cwd);
    }
}
