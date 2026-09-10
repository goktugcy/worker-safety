<?php

declare(strict_types=1);

namespace WorkerSafety\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use WorkerSafety\Application\ExitCode;
use WorkerSafety\Exception\ConfigurationException;
use WorkerSafety\Reporting\ConsoleStyles;
use WorkerSafety\Rule\Rule;
use WorkerSafety\Rule\RuleRegistry;
use WorkerSafety\Rule\RuleRegistryFactory;

#[AsCommand(
    name: 'rules',
    description: 'List the available rules, or show the details of one rule',
)]
final class RulesCommand extends AbstractCommand
{
    public function __construct(private readonly RuleRegistryFactory $factory = new RuleRegistryFactory())
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('rule', InputArgument::OPTIONAL, 'Show the details of a single rule, e.g. WS001')
            ->addOption('format', 'f', InputOption::VALUE_REQUIRED, 'Output format: table, json', 'table');
    }

    protected function handle(InputInterface $input, OutputInterface $output): ExitCode
    {
        $registry = $this->factory->create();
        $formatOption = $input->getOption('format');
        $format = strtolower(is_string($formatOption) ? $formatOption : 'table');

        if (!in_array($format, ['table', 'json'], true)) {
            throw ConfigurationException::invalidValue('format', sprintf('"%s" is not one of table, json.', $format));
        }

        $requested = $input->getArgument('rule');

        if (is_string($requested) && $requested !== '') {
            return $this->showOne($registry, $requested, $format, $output);
        }

        return $format === 'json'
            ? $this->listJson($registry, $output)
            : $this->listTable($registry, $output);
    }

    private function showOne(RuleRegistry $registry, string $ruleId, string $format, OutputInterface $output): ExitCode
    {
        $rule = $registry->get($ruleId);

        if (!$rule instanceof Rule) {
            throw ConfigurationException::invalidValue(
                'rule',
                sprintf('unknown rule "%s". Known rules: %s.', $ruleId, implode(', ', $registry->ids())),
            );
        }

        if ($format === 'json') {
            $output->writeln((string) json_encode(
                $this->describe($rule),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));

            return ExitCode::Success;
        }

        ConsoleStyles::register($output);
        $definition = $rule->definition();
        $style = $definition->defaultSeverity->consoleStyle();

        $output->writeln('');
        $output->writeln(sprintf(
            '<ws-rule>%s</ws-rule>  %s  <%s>%s</%s>',
            $definition->id,
            $definition->title,
            $style,
            $definition->defaultSeverity->label(),
            $style,
        ));
        $output->writeln('');
        $output->writeln(wordwrap($definition->description, 76));
        $output->writeln('');
        $output->writeln(sprintf('  Category   %s', $definition->category->label()));
        $output->writeln(sprintf('  Runtimes   %s', $definition->runtimes->describe()));
        $output->writeln(sprintf('  Framework  %s', $definition->requiresFramework ?? 'any'));
        $output->writeln('');

        if ($definition->remediation !== []) {
            $output->writeln('<ws-heading>How to fix</ws-heading>');
            $output->writeln('');

            foreach ($definition->remediation as $suggestion) {
                $output->writeln('  - ' . wordwrap($suggestion, 72, "\n    "));
            }

            $output->writeln('');
        }

        return ExitCode::Success;
    }

    private function listTable(RuleRegistry $registry, OutputInterface $output): ExitCode
    {
        ConsoleStyles::register($output);

        $table = new Table($output);
        $table->setHeaders(['ID', 'Severity', 'Category', 'Framework', 'Title']);

        $rules = $registry->all();
        $last = count($rules) - 1;

        foreach ($rules as $index => $rule) {
            $definition = $rule->definition();
            $style = $definition->defaultSeverity->consoleStyle();

            $table->addRow([
                $definition->id,
                sprintf('<%s>%s</%s>', $style, $definition->defaultSeverity->label(), $style),
                $definition->category->label(),
                $definition->requiresFramework ?? '-',
                $definition->title,
            ]);

            if ($index !== $last && $this->frameworkChanges($rules, $index)) {
                $table->addRow(new TableSeparator());
            }
        }

        $output->writeln('');
        $table->render();
        $output->writeln('');
        $output->writeln(sprintf(
            '<ws-muted>%d rules. Run `%s rules WS001` for the details of one rule.</ws-muted>',
            count($rules),
            'worker-safety',
        ));
        $output->writeln('');

        return ExitCode::Success;
    }

    private function listJson(RuleRegistry $registry, OutputInterface $output): ExitCode
    {
        $output->writeln((string) json_encode(
            [
                'rules' => array_map(fn (Rule $rule): array => $this->describe($rule), $registry->all()),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));

        return ExitCode::Success;
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(Rule $rule): array
    {
        $definition = $rule->definition();

        return [
            'id' => $definition->id,
            'title' => $definition->title,
            'description' => $definition->description,
            'severity' => $definition->defaultSeverity->value,
            'category' => $definition->category->value,
            'runtimes' => $definition->runtimes->values(),
            'framework' => $definition->requiresFramework,
            'remediation' => $definition->remediation,
        ];
    }

    /**
     * @param list<Rule> $rules
     */
    private function frameworkChanges(array $rules, int $index): bool
    {
        $current = $rules[$index]->definition()->requiresFramework;
        $next = $rules[$index + 1]->definition()->requiresFramework;

        return $current !== $next;
    }
}
