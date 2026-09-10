<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * WS003 — mutation of the process environment or of PHP settings.
 *
 * Reads (`getenv()`, `ini_get()`, `$_ENV['X']`) are never reported: only
 * mutations are, because they change the environment for every later request
 * handled by the same worker.
 *
 * Top-level statements are downgraded one severity level: code outside any
 * function normally runs once while the worker boots, which is exactly where
 * this kind of call belongs.
 */
final class RuntimeEnvironmentMutationRule extends AbstractRule
{
    /**
     * Function name => [severity, minimum argument count that makes it a write].
     *
     * @var array<string, array{0: Severity, 1: int}>
     */
    private const MUTATING_FUNCTIONS = [
        'putenv' => [Severity::High, 1],
        'ini_set' => [Severity::Medium, 2],
        'ini_alter' => [Severity::Medium, 2],
        'date_default_timezone_set' => [Severity::Medium, 1],
        'setlocale' => [Severity::Medium, 2],
        'mb_internal_encoding' => [Severity::Medium, 1],
        'set_time_limit' => [Severity::Low, 1],
    ];

    /**
     * @var array<string, Severity>
     */
    private const MUTABLE_SUPERGLOBALS = [
        '_ENV' => Severity::High,
        '_SERVER' => Severity::Medium,
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::RUNTIME_ENVIRONMENT_MUTATION,
            'Runtime environment mutation',
            'The process environment, the $_ENV/$_SERVER arrays and the ini settings belong to the worker process. Changing them while serving a request changes them for every request that follows.',
            Severity::Medium,
            RuleCategory::EnvironmentMutation,
            [
                'Read configuration instead of writing it: resolve values from a config object built at boot.',
                'If a value has to differ per request, pass it through the request-scoped service that needs it.',
                'When a temporary change is unavoidable, restore the previous value in a finally block.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function nodeTypes(): array
    {
        return [
            Expr\FuncCall::class,
            Expr\Assign::class,
            Expr\AssignRef::class,
            Expr\AssignOp::class,
            Stmt\Unset_::class,
        ];
    }

    public function enterNode(Node $node, RuleContext $context): iterable
    {
        if ($node instanceof Expr\FuncCall) {
            $finding = $this->inspectCall($node, $context);

            if ($finding instanceof Finding) {
                yield $finding;
            }

            return;
        }

        if ($node instanceof Stmt\Unset_) {
            foreach ($node->vars as $var) {
                $finding = $this->inspectTarget($var, $node, $context, true);

                if ($finding instanceof Finding) {
                    yield $finding;
                }
            }

            return;
        }

        if ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp) {
            $finding = $this->inspectTarget($node->var, $node, $context, false);

            if ($finding instanceof Finding) {
                yield $finding;
            }
        }
    }

    private function inspectCall(Expr\FuncCall $node, RuleContext $context): ?Finding
    {
        $name = AstHelper::functionName($node);

        if ($name === null || !isset(self::MUTATING_FUNCTIONS[$name])) {
            return null;
        }

        [$severity, $minimumArgs] = self::MUTATING_FUNCTIONS[$name];

        // Calls without arguments are reads (`mb_internal_encoding()`).
        if (count($node->args) < $minimumArgs) {
            return null;
        }

        return $this->finding(
            $context,
            $context->location($node),
            sprintf('%s() changes process-wide state at runtime.', $name),
            $this->explainCall($name, $context),
            $this->adjust($severity, $context),
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function inspectTarget(Expr $target, Node $node, RuleContext $context, bool $isUnset): ?Finding
    {
        $base = AstHelper::unwrapArrayDim($target);

        if (!$base instanceof Expr\Variable || !is_string($base->name)) {
            return null;
        }

        $severity = self::MUTABLE_SUPERGLOBALS[$base->name] ?? null;

        if ($severity === null) {
            return null;
        }

        $key = $target instanceof Expr\ArrayDimFetch && $target->dim !== null
            ? AstHelper::stringValue($target->dim)
            : null;

        $reference = $key !== null
            ? sprintf('$%s[\'%s\']', $base->name, $key)
            : '$' . $base->name;

        return $this->finding(
            $context,
            $context->location($node),
            $isUnset
                ? sprintf('%s is removed at runtime.', $reference)
                : sprintf('%s is assigned at runtime.', $reference),
            sprintf(
                '$%s is built once per worker process and is not rebuilt from scratch for every request under a persistent runtime. A value written here stays visible to later requests, and configuration readers such as env() will keep returning it.',
                $base->name,
            ),
            $this->adjust($severity, $context),
            new SymbolContext(
                $context->scope()->className(),
                $context->scope()->methodName(),
                null,
                $base->name,
            ),
            $context->snippet($node),
        );
    }

    private function explainCall(string $name, RuleContext $context): string
    {
        $base = match ($name) {
            'putenv' => 'putenv() writes into the environment of the whole PHP process. The value is not restored when the request ends, it is visible to every later request served by the worker, and under Swoole it can be read concurrently by other coroutines.',
            'ini_set', 'ini_alter' => 'An ini value changed here stays changed for the rest of the worker process, so later requests run with settings they never asked for.',
            'date_default_timezone_set' => 'The default timezone is process state. A request that switches it changes date formatting for every request the worker serves afterwards.',
            'setlocale' => 'The locale is process state (and not coroutine-safe). Later requests inherit the locale that the last request happened to set.',
            'mb_internal_encoding' => 'The mbstring internal encoding is process state and is inherited by later requests.',
            'set_time_limit' => 'The execution time limit applies to the worker process rather than to a single request, and persistent runtimes usually ignore or reinterpret it.',
            default => 'This call mutates state that outlives the current request.',
        };

        if ($context->scope()->isFileScope()) {
            return $base . ' This call is at file scope, which normally runs once while the worker boots, so the severity is reduced — confirm that this file is not re-included per request.';
        }

        return $base;
    }

    /**
     * Top-level code usually runs once per worker boot rather than per request.
     */
    private function adjust(Severity $severity, RuleContext $context): Severity
    {
        if (!$context->scope()->isFileScope()) {
            return $severity;
        }

        return match ($severity) {
            Severity::Critical => Severity::High,
            Severity::High => Severity::Medium,
            Severity::Medium => Severity::Low,
            default => Severity::Info,
        };
    }
}
