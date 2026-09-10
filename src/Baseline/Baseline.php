<?php

declare(strict_types=1);

namespace WorkerSafety\Baseline;

use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;

/**
 * A snapshot of accepted findings.
 *
 * Matching is by {@see Finding::fingerprint()}, which deliberately excludes the
 * line number: editing code above a baselined finding must not resurrect it,
 * while changing the finding itself must.
 */
final class Baseline
{
    public const SCHEMA_VERSION = '1';

    /**
     * @var array<string, true>
     */
    private readonly array $fingerprints;

    /**
     * @param list<string> $fingerprints
     * @param list<array<string, mixed>> $entries human readable records
     */
    public function __construct(
        array $fingerprints,
        public readonly array $entries = [],
        public readonly ?string $generatedAt = null,
        public readonly ?string $generatedBy = null,
    ) {
        $index = [];

        foreach ($fingerprints as $fingerprint) {
            $index[$fingerprint] = true;
        }

        $this->fingerprints = $index;
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public static function fromFindings(FindingCollection $findings): self
    {
        $fingerprints = [];
        $entries = [];

        foreach ($findings->sorted() as $finding) {
            $fingerprint = $finding->fingerprint();

            if (isset($entries[$fingerprint])) {
                continue;
            }

            $fingerprints[] = $fingerprint;
            $entries[$fingerprint] = [
                'rule' => $finding->ruleId,
                'severity' => $finding->severity->value,
                'file' => $finding->location->relativePath,
                'line' => $finding->location->line,
                'symbol' => $finding->symbol->describe(),
                'fingerprint' => $fingerprint,
            ];
        }

        return new self(
            $fingerprints,
            array_values($entries),
            (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            ApplicationInfo::NAME . ' ' . ApplicationInfo::VERSION,
        );
    }

    public function contains(Finding $finding): bool
    {
        return isset($this->fingerprints[$finding->fingerprint()]);
    }

    public function count(): int
    {
        return count($this->fingerprints);
    }

    public function isEmpty(): bool
    {
        return $this->fingerprints === [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => self::SCHEMA_VERSION,
            'generated_at' => $this->generatedAt,
            'generated_by' => $this->generatedBy,
            'count' => $this->count(),
            'findings' => $this->entries,
        ];
    }
}
