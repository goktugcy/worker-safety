<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use PhpParser\Node;
use PhpParser\Node\Expr;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * WS010 — code that assumes the process dies when the request ends.
 *
 * The individual signals are weaker than the other rules', so the default
 * severity is low and each detection explains what specifically breaks.
 */
final class ShutdownLifecycleAssumptionRule extends AbstractRule
{
    /**
     * SAPI names that only exist in a per-request process model.
     *
     * @var list<string>
     */
    private const REQUEST_SCOPED_SAPIS = ['fpm-fcgi', 'apache2handler', 'cgi-fcgi', 'cgi', 'litespeed'];

    /**
     * Class name suffixes that legitimately terminate the process.
     *
     * @var list<string>
     */
    private const ENTRY_POINT_SUFFIXES = ['Command', 'Console', 'Kernel'];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::SHUTDOWN_LIFECYCLE_ASSUMPTION,
            'Shutdown/runtime lifecycle assumption',
            'Under PHP-FPM the process is torn down after every request, which makes shutdown hooks, exit() and SAPI checks behave in one specific way. A persistent worker keeps the process alive, so the same code means something different.',
            Severity::Low,
            RuleCategory::Lifecycle,
            [
                'Do the cleanup where the request ends (terminating middleware, a request-lifecycle listener) instead of at process shutdown.',
                'Return a response instead of calling exit()/die() so the worker can finish the request loop.',
                'Do not branch on the SAPI name: check for the capability you actually need.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function nodeTypes(): array
    {
        return [
            Expr\FuncCall::class,
            Expr\Exit_::class,
            Expr\BinaryOp\Identical::class,
            Expr\BinaryOp\Equal::class,
            Expr\BinaryOp\NotIdentical::class,
            Expr\BinaryOp\NotEqual::class,
        ];
    }

    public function enterNode(Node $node, RuleContext $context): iterable
    {
        $finding = match (true) {
            $node instanceof Expr\FuncCall => $this->inspectCall($node, $context),
            $node instanceof Expr\Exit_ => $this->inspectExit($node, $context),
            $node instanceof Expr\BinaryOp => $this->inspectSapiComparison($node, $context),
            default => null,
        };

        if ($finding instanceof Finding) {
            yield $finding;
        }
    }

    private function inspectCall(Expr\FuncCall $node, RuleContext $context): ?Finding
    {
        $name = AstHelper::functionName($node);

        return match ($name) {
            'register_shutdown_function' => $this->shutdownFunctionFinding($node, $context),
            'fastcgi_finish_request' => $this->fastCgiFinding($node, $context),
            'gc_disable' => $this->gcDisableFinding($node, $context),
            default => null,
        };
    }

    private function shutdownFunctionFinding(Expr\FuncCall $node, RuleContext $context): Finding
    {
        $perRequest = !$context->scope()->isFileScope();

        return $this->finding(
            $context,
            $context->location($node),
            'register_shutdown_function() defers work to process shutdown, not to the end of the request.',
            $perRequest
                ? sprintf(
                    'Registered from %s, this callback is added again for every request, and none of them run until the worker process itself exits. Cleanup that was written to happen "at the end of the request" therefore never happens, while the callback list grows for the life of the worker.',
                    $context->scope()->describeLocationScope(),
                )
                : 'At file scope this normally runs once per worker boot, so the callback list does not grow. It still only fires when the worker exits rather than at the end of each request.',
            $perRequest ? Severity::Medium : Severity::Low,
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function fastCgiFinding(Expr\FuncCall $node, RuleContext $context): Finding
    {
        $runtimes = $context->runtimes();
        $note = $runtimes->count() === 1
            ? ' ' . $runtimes->toArray()[0]->note()
            : '';

        return $this->finding(
            $context,
            $context->location($node),
            'fastcgi_finish_request() does not exist outside FPM/FastCGI.',
            sprintf(
                'The function is provided by the FPM and FastCGI SAPIs only. Under %s the call raises an undefined-function error, so any "flush the response and keep working" pattern built on it stops working.%s',
                $runtimes->isEmpty() ? 'a persistent worker' : $runtimes->describe(),
                $note,
            ),
            Severity::Medium,
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function gcDisableFinding(Expr\FuncCall $node, RuleContext $context): Finding
    {
        return $this->finding(
            $context,
            $context->location($node),
            'gc_disable() turns off cycle collection for the whole worker process.',
            'Disabling the cycle collector is a common per-request optimisation because the process is about to exit anyway. In a persistent worker the process does not exit, so reference cycles accumulate until the memory limit is reached.',
            Severity::Medium,
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function inspectExit(Expr\Exit_ $node, RuleContext $context): ?Finding
    {
        if ($this->isEntryPoint($context)) {
            return null;
        }

        $insideMethod = $context->scope()->methodName() !== null;

        return $this->finding(
            $context,
            $context->location($node),
            'exit()/die() ends the worker process, not just the request.',
            sprintf(
                'Called from %s. Under PHP-FPM this simply finishes the request; under a persistent worker it tears the process down mid-loop (Swoole converts it into an ExitException instead), so queued work is dropped and the runtime has to spawn a replacement worker.',
                $context->scope()->describeLocationScope(),
            ),
            $insideMethod ? Severity::Medium : Severity::Low,
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function inspectSapiComparison(Expr\BinaryOp $node, RuleContext $context): ?Finding
    {
        $sapiSide = $this->isSapiExpression($node->left) ? $node->right : ($this->isSapiExpression($node->right) ? $node->left : null);

        if ($sapiSide === null) {
            return null;
        }

        $value = AstHelper::stringValue($sapiSide);

        if ($value === null || !in_array(strtolower($value), self::REQUEST_SCOPED_SAPIS, true)) {
            return null;
        }

        return $this->finding(
            $context,
            $context->location($node),
            sprintf('Code branches on the SAPI being "%s".', $value),
            sprintf(
                'Persistent runtimes report a different SAPI name — FrankenPHP reports "frankenphp", RoadRunner, Octane and Swoole run under "cli". A branch that tests for "%s" therefore takes the other path once the application moves to a worker, silently changing behaviour.',
                $value,
            ),
            Severity::Low,
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function isSapiExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\ConstFetch) {
            return strtoupper($expr->name->toString()) === 'PHP_SAPI';
        }

        return $expr instanceof Expr\FuncCall && AstHelper::functionName($expr) === 'php_sapi_name';
    }

    /**
     * Console entry points are allowed to terminate the process.
     */
    private function isEntryPoint(RuleContext $context): bool
    {
        $className = $context->scope()->classShortName();

        if ($className === null) {
            return false;
        }

        foreach (self::ENTRY_POINT_SUFFIXES as $suffix) {
            if (str_ends_with($className, $suffix)) {
                return true;
            }
        }

        return false;
    }
}
