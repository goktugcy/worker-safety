<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Stmt;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Ast\Visitor\VariableWriteFinder;
use WorkerSafety\Finding\Location;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Finding\SymbolContext;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;

/**
 * WS002 — developer-controlled global state.
 *
 * Reading a superglobal is perfectly normal and never reported. What the rule
 * looks for is state the application itself puts into the global scope, plus
 * writes to the request superglobals, which persist for the rest of the worker
 * loop under every supported runtime.
 */
final class MutableGlobalVariableRule extends AbstractRule
{
    /**
     * Superglobals whose *mutation* changes what later requests observe.
     *
     * `$_ENV` and `$_SERVER` are handled by WS003, `$_SESSION` is excluded
     * because writing to it is the documented way to use PHP sessions.
     *
     * @var list<string>
     */
    private const REQUEST_SUPERGLOBALS = ['_GET', '_POST', '_REQUEST', '_COOKIE', '_FILES'];

    /**
     * @var array<string, array{location: Location, severity: Severity, message: string, details: string, symbol: SymbolContext, snippet: string|null, excerpt: string|null, count: int}>
     */
    private array $pending = [];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::MUTABLE_GLOBAL_VARIABLE,
            'Mutable global variable',
            'Variables in the global scope, including entries the application writes into $GLOBALS or into the request superglobals, are owned by the PHP process rather than by the request.',
            Severity::High,
            RuleCategory::GlobalState,
            [
                'Pass the value explicitly, or resolve it from a request-scoped service.',
                'If a process-wide value is genuinely needed, expose it through an immutable object built once at boot.',
                'Never write application state into $GLOBALS or into the request superglobals.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function nodeTypes(): array
    {
        return [
            Stmt\Global_::class,
            Expr\Assign::class,
            Expr\AssignRef::class,
            Expr\AssignOp::class,
            Expr\PreInc::class,
            Expr\PostInc::class,
            Expr\PreDec::class,
            Expr\PostDec::class,
        ];
    }

    public function beginFile(RuleContext $context): void
    {
        $this->pending = [];
    }

    public function enterNode(Node $node, RuleContext $context): iterable
    {
        if ($node instanceof Stmt\Global_) {
            $this->collectGlobalDeclaration($node, $context);

            return [];
        }

        $target = match (true) {
            $node instanceof Expr\Assign,
            $node instanceof Expr\AssignRef,
            $node instanceof Expr\AssignOp => $node->var,
            $node instanceof Expr\PreInc,
            $node instanceof Expr\PostInc,
            $node instanceof Expr\PreDec,
            $node instanceof Expr\PostDec => $node->var,
            default => null,
        };

        if ($target !== null) {
            $this->collectWrite($target, $node, $context);
        }

        return [];
    }

    public function finishFile(RuleContext $context): iterable
    {
        foreach ($this->pending as $entry) {
            $details = $entry['details'];

            if ($entry['count'] > 1) {
                $details .= sprintf(' Found %d times in this file; only the first occurrence is reported.', $entry['count']);
            }

            yield $this->finding(
                $context,
                $entry['location'],
                $entry['message'],
                $details,
                $entry['severity'],
                $entry['symbol'],
                $entry['snippet'],
                null,
                $entry['excerpt'],
            );
        }

        $this->pending = [];
    }

    private function collectGlobalDeclaration(Stmt\Global_ $node, RuleContext $context): void
    {
        $names = [];

        foreach ($node->vars as $var) {
            if ($var instanceof Expr\Variable && is_string($var->name)) {
                $names[] = $var->name;
            }
        }

        if ($names === []) {
            return;
        }

        $functionNode = $context->scope()->functionNode();
        $body = $functionNode?->getStmts();
        $writesLocally = $body !== null && VariableWriteFinder::writesAny(array_values($body), $names);

        foreach ($names as $name) {
            $this->remember(
                'global:' . $name,
                $context->location($node),
                $writesLocally ? Severity::High : Severity::Medium,
                sprintf('Global variable $%s is imported with `global` in %s.', $name, $context->scope()->describeLocationScope()),
                $writesLocally
                    ? sprintf('$%s lives in the global scope, which is created once per PHP process. Because %s also assigns to it, a value produced while serving one request is still there when the worker picks up the next one.', $name, $context->scope()->describeLocationScope())
                    : sprintf('$%s lives in the global scope, which is created once per PHP process. Reading it means the behaviour of this request depends on whatever an earlier request left behind.', $name),
                new SymbolContext($context->scope()->className(), $context->scope()->methodName(), null, $name),
                $context->snippet($node),
                $context->excerpt($node),
            );
        }
    }

    private function collectWrite(Expr $target, Node $node, RuleContext $context): void
    {
        $base = AstHelper::unwrapArrayDim($target);

        if (!$base instanceof Expr\Variable || !is_string($base->name)) {
            return;
        }

        if ($base->name === 'GLOBALS') {
            $key = $this->arrayKeyOf($target);

            $this->remember(
                'globals:' . ($key ?? '*'),
                $context->location($node),
                Severity::High,
                $key !== null
                    ? sprintf('Application state is written to $GLOBALS[\'%s\'].', $key)
                    : 'Application state is written to $GLOBALS.',
                sprintf(
                    '$GLOBALS is the symbol table of the PHP process. Under a persistent worker the entry written here is still set for every later request handled by the same process, so state and lifetime leak between unrelated requests.%s',
                    $key !== null ? '' : ' The key could not be resolved statically, so the whole array is treated as written.',
                ),
                new SymbolContext($context->scope()->className(), $context->scope()->methodName(), null, 'GLOBALS'),
                $context->snippet($node),
                $context->excerpt($node),
            );

            return;
        }

        if (in_array($base->name, self::REQUEST_SUPERGLOBALS, true)) {
            $this->remember(
                'superglobal:' . $base->name,
                $context->location($node),
                Severity::Low,
                sprintf('Request superglobal $%s is rewritten at runtime.', $base->name),
                sprintf(
                    'The supported runtimes do rebuild $%s for every request — FrankenPHP documents $_GET, $_POST, $_COOKIE, $_FILES, $_SERVER and $_REQUEST as reset, with $_ENV as the exception — so this is not by itself a cross-request leak. It is reported because rewriting request input in place makes later reads indistinguishable from real input, and because any code that then caches the value in a static or a global does turn it into one.',
                    $base->name,
                ),
                new SymbolContext($context->scope()->className(), $context->scope()->methodName(), null, $base->name),
                $context->snippet($node),
                $context->excerpt($node),
            );
        }
    }

    private function arrayKeyOf(Expr $target): ?string
    {
        if (!$target instanceof Expr\ArrayDimFetch || $target->dim === null) {
            return null;
        }

        return AstHelper::stringValue($target->dim);
    }

    /**
     * Keep the first occurrence per file and count the rest.
     */
    private function remember(
        string $key,
        Location $location,
        Severity $severity,
        string $message,
        string $details,
        SymbolContext $symbol,
        ?string $snippet,
        ?string $excerpt,
    ): void {
        if (isset($this->pending[$key])) {
            ++$this->pending[$key]['count'];

            // A local assignment discovered later upgrades the severity.
            if ($severity->rank() > $this->pending[$key]['severity']->rank()) {
                $this->pending[$key]['severity'] = $severity;
            }

            return;
        }

        $this->pending[$key] = [
            'location' => $location,
            'severity' => $severity,
            'message' => $message,
            'details' => $details,
            'symbol' => $symbol,
            'snippet' => $snippet,
            'excerpt' => $excerpt,
            'count' => 1,
        ];
    }
}
