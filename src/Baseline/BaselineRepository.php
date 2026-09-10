<?php

declare(strict_types=1);

namespace WorkerSafety\Baseline;

use WorkerSafety\Exception\ConfigurationException;

/**
 * Reads and writes the baseline file.
 */
final class BaselineRepository
{
    public function exists(string $path): bool
    {
        return is_file($path);
    }

    public function load(string $path): Baseline
    {
        if (!is_file($path) || !is_readable($path)) {
            throw ConfigurationException::unreadable($path);
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw ConfigurationException::unreadable($path);
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ConfigurationException(
                sprintf('Baseline file "%s" is not valid JSON: %s', $path, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            throw ConfigurationException::invalidValue($path, 'the baseline file must contain a JSON object.');
        }

        $findings = $decoded['findings'] ?? [];

        if (!is_array($findings)) {
            throw ConfigurationException::invalidValue($path, '"findings" must be a list.');
        }

        $fingerprints = [];
        $entries = [];

        foreach ($findings as $entry) {
            if (!is_array($entry)) {
                throw ConfigurationException::invalidValue($path, 'every baseline entry must be an object.');
            }

            $fingerprint = $entry['fingerprint'] ?? null;

            if (!is_string($fingerprint) || $fingerprint === '') {
                throw ConfigurationException::invalidValue($path, 'every baseline entry needs a "fingerprint" string.');
            }

            $fingerprints[] = $fingerprint;
            $entries[] = self::withStringKeys($entry);
        }

        $generatedAt = $decoded['generated_at'] ?? null;
        $generatedBy = $decoded['generated_by'] ?? null;

        return new Baseline(
            $fingerprints,
            $entries,
            is_string($generatedAt) ? $generatedAt : null,
            is_string($generatedBy) ? $generatedBy : null,
        );
    }

    /**
     * Drop non-string keys so the entry matches the documented shape.
     *
     * @param array<mixed, mixed> $entry
     *
     * @return array<string, mixed>
     */
    private static function withStringKeys(array $entry): array
    {
        $result = [];

        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function save(string $path, Baseline $baseline): void
    {
        $json = json_encode(
            $baseline->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $directory = dirname($path);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new ConfigurationException(sprintf('Could not create directory "%s" for the baseline file.', $directory));
        }

        if (@file_put_contents($path, $json . "\n") === false) {
            throw new ConfigurationException(sprintf('Could not write the baseline file "%s".', $path));
        }
    }
}
