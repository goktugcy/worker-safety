<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a scan report.
 */
interface Reporter
{
    public function report(ScanReport $report, OutputInterface $output): void;
}
