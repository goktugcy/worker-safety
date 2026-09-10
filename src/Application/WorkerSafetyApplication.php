<?php

declare(strict_types=1);

namespace WorkerSafety\Application;

use Symfony\Component\Console\Application;
use WorkerSafety\Command\BaselineCommand;
use WorkerSafety\Command\InitCommand;
use WorkerSafety\Command\RulesCommand;
use WorkerSafety\Command\ScanCommand;

/**
 * The console application.
 */
final class WorkerSafetyApplication extends Application
{
    public function __construct()
    {
        parent::__construct(ApplicationInfo::NAME, ApplicationInfo::VERSION);

        $this->addCommands([
            new ScanCommand(),
            new RulesCommand(),
            new InitCommand(),
            new BaselineCommand(),
        ]);
    }

    public function getLongVersion(): string
    {
        return sprintf(
            '<info>%s</info> version <comment>%s</comment> (%s)',
            ApplicationInfo::NAME,
            ApplicationInfo::VERSION,
            ApplicationInfo::PACKAGE,
        );
    }
}
