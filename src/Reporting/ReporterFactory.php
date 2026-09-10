<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use WorkerSafety\Rule\RuleRegistry;

/**
 * Maps an output format to its reporter.
 */
final class ReporterFactory
{
    public function __construct(private readonly ?RuleRegistry $registry = null)
    {
    }

    public function create(OutputFormat $format): Reporter
    {
        return match ($format) {
            OutputFormat::Console => new ConsoleReporter(),
            OutputFormat::Json => new JsonReporter(),
            OutputFormat::Sarif => new SarifReporter($this->registry),
        };
    }
}
