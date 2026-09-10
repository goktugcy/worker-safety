<?php

declare(strict_types=1);

namespace WorkerSafety\Baseline;

use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\FindingCollection;

/**
 * Removes baselined findings while counting what was removed.
 */
final class BaselineFilter
{
    /**
     * @return array{0: FindingCollection, 1: int}
     */
    public function apply(FindingCollection $findings, Baseline $baseline): array
    {
        if ($baseline->isEmpty()) {
            return [$findings, 0];
        }

        $kept = $findings->filter(static fn (Finding $finding): bool => !$baseline->contains($finding));

        return [$kept, $findings->count() - $kept->count()];
    }
}
