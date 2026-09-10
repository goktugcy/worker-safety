<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use Symfony\Component\Console\Formatter\OutputFormatterStyle;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Finding\Severity;

/**
 * Registers the severity colours used by the console reporter.
 *
 * With `--no-ansi` Symfony strips the tags, so the same code produces plain
 * text without any branching here.
 */
final class ConsoleStyles
{
    private function __construct()
    {
    }

    public static function register(OutputInterface $output): void
    {
        $formatter = $output->getFormatter();

        $styles = [
            Severity::Critical->consoleStyle() => new OutputFormatterStyle('white', 'red', ['bold']),
            Severity::High->consoleStyle() => new OutputFormatterStyle('red', null, ['bold']),
            Severity::Medium->consoleStyle() => new OutputFormatterStyle('yellow'),
            Severity::Low->consoleStyle() => new OutputFormatterStyle('cyan'),
            Severity::Info->consoleStyle() => new OutputFormatterStyle('gray'),
            'ws-heading' => new OutputFormatterStyle(null, null, ['bold']),
            'ws-muted' => new OutputFormatterStyle('gray'),
            'ws-rule' => new OutputFormatterStyle('blue'),
            'ws-pass' => new OutputFormatterStyle('green', null, ['bold']),
            'ws-fail' => new OutputFormatterStyle('red', null, ['bold']),
            'ws-code' => new OutputFormatterStyle('white'),
        ];

        foreach ($styles as $name => $style) {
            $formatter->setStyle($name, $style);
        }
    }
}
