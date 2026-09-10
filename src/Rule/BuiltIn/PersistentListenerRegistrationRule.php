<?php

declare(strict_types=1);

namespace WorkerSafety\Rule\BuiltIn;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Identifier;
use WorkerSafety\Ast\AstHelper;
use WorkerSafety\Finding\Finding;
use WorkerSafety\Finding\RuleCategory;
use WorkerSafety\Finding\Severity;
use WorkerSafety\Rule\AbstractRule;
use WorkerSafety\Rule\RuleContext;
use WorkerSafety\Rule\RuleDefinition;
use WorkerSafety\Rule\RuleId;
use WorkerSafety\Runtime\RuntimeTargetSet;
use WorkerSafety\Support\NameHeuristics;

/**
 * WS009 — registration that is meant to happen once, done on a request path.
 *
 * Registrations inside a `*ServiceProvider` are skipped: that is where they
 * belong, and it is the single biggest source of false positives for this
 * pattern. What remains is registration from controllers, middleware, jobs,
 * models and helpers — code that runs again for every request while the
 * registry it appends to lives as long as the worker.
 */
final class PersistentListenerRegistrationRule extends AbstractRule
{
    /**
     * Native functions that append to a process-wide registry.
     *
     * @var array<string, string>
     */
    private const GLOBAL_REGISTRATION_FUNCTIONS = [
        'spl_autoload_register' => 'the autoloader stack',
        'stream_wrapper_register' => 'the stream wrapper registry',
        'stream_filter_register' => 'the stream filter registry',
        'register_tick_function' => 'the tick function list',
        'set_error_handler' => 'the error handler stack',
        'set_exception_handler' => 'the exception handler slot',
    ];

    /**
     * Laravel facades and their registering methods.
     *
     * @var array<string, list<string>>
     */
    private const LARAVEL_REGISTRATIONS = [
        'event' => ['listen', 'subscribe', 'push'],
        'db' => ['listen'],
        'queue' => ['before', 'after', 'looping', 'failing', 'stopping', 'createPayloadUsing'],
        'blade' => ['directive', 'component', 'componentNamespace', 'if', 'stringable', 'extend'],
        'validator' => ['extend', 'extendImplicit', 'replacer', 'extendDependent'],
        'gate' => ['define', 'policy', 'before', 'after'],
        'route' => ['macro', 'mixin'],
        'http' => ['globalMiddleware', 'globalRequestMiddleware', 'globalResponseMiddleware', 'macro'],
        'relation' => ['morphMap', 'enforceMorphMap'],
        'model' => ['observe', 'created', 'updated', 'saved', 'deleted', 'creating', 'updating', 'saving', 'deleting', 'retrieved', 'addGlobalScope', 'resolveRelationUsing'],
        'schema' => ['macro'],
    ];

    /**
     * Any class that offers `::macro()` extends a shared, process-wide object.
     */
    private const MACRO_METHOD = 'macro';

    /**
     * Property names that read like a listener registry.
     *
     * @var list<string>
     */
    private const LISTENER_TOKENS = [
        'listener',
        'listeners',
        'handler',
        'handlers',
        'callback',
        'callbacks',
        'hook',
        'hooks',
        'subscriber',
        'subscribers',
        'observer',
        'observers',
        'middleware',
        'extensions',
        'macros',
    ];

    public function definition(): RuleDefinition
    {
        return new RuleDefinition(
            RuleId::PERSISTENT_LISTENER_REGISTRATION,
            'Persistent event/listener registration',
            'Event listeners, macros and handler callbacks are registered into structures that live for the whole worker process. Registering them while serving a request means the registry grows on every request and listeners from earlier requests keep firing.',
            Severity::Medium,
            RuleCategory::EventRegistration,
            [
                'Register listeners, macros and handlers once, during boot: a service provider, or the worker bootstrap file.',
                'If the registration depends on request data, invoke the behaviour directly instead of registering a listener for it.',
                'Where a per-request listener is unavoidable, remove it again before the request ends.',
            ],
            RuntimeTargetSet::all(),
        );
    }

    public function nodeTypes(): array
    {
        return [
            Expr\FuncCall::class,
            Expr\StaticCall::class,
            Expr\MethodCall::class,
            Expr\Assign::class,
        ];
    }

    public function enterNode(Node $node, RuleContext $context): iterable
    {
        // Service providers are the correct home for registration.
        if ($this->isInsideServiceProvider($context)) {
            return [];
        }

        $finding = match (true) {
            $node instanceof Expr\FuncCall => $this->inspectFunctionCall($node, $context),
            $node instanceof Expr\StaticCall => $this->inspectStaticCall($node, $context),
            $node instanceof Expr\MethodCall => $this->inspectMethodCall($node, $context),
            $node instanceof Expr\Assign => $this->inspectListenerAppend($node, $context),
            default => null,
        };

        if ($finding instanceof Finding) {
            yield $finding;
        }
    }

    private function inspectFunctionCall(Expr\FuncCall $node, RuleContext $context): ?Finding
    {
        $name = AstHelper::functionName($node);

        if ($name === null || !isset(self::GLOBAL_REGISTRATION_FUNCTIONS[$name])) {
            return null;
        }

        return $this->finding(
            $context,
            $context->location($node),
            sprintf('%s() registers into %s from a request path.', $name, self::GLOBAL_REGISTRATION_FUNCTIONS[$name]),
            sprintf(
                '%s is process-wide state. Called from %s it runs again for every request the worker serves, so the registration either accumulates or silently replaces what an earlier request installed.',
                ucfirst(self::GLOBAL_REGISTRATION_FUNCTIONS[$name]),
                $context->scope()->describeLocationScope(),
            ),
            $this->adjust(Severity::Medium, $context),
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function inspectStaticCall(Expr\StaticCall $node, RuleContext $context): ?Finding
    {
        if (!$node->name instanceof Identifier || !$node->class instanceof Node\Name) {
            return null;
        }

        $method = $node->name->toString();
        $className = AstHelper::nameToString($node->class);
        $shortName = str_contains($className, '\\')
            ? substr($className, (int) strrpos($className, '\\') + 1)
            : $className;

        // `Anything::macro()` mutates a shared class-level registry.
        if (strtolower($method) === self::MACRO_METHOD) {
            return $this->registrationFinding($node, $context, $shortName, $method, 'macro');
        }

        if (!$context->framework()->isLaravel()) {
            return null;
        }

        $methods = self::LARAVEL_REGISTRATIONS[strtolower($shortName)] ?? null;

        if ($methods === null) {
            // Eloquent model hooks are registered on the model class itself.
            $methods = str_ends_with($shortName, 'Model') ? self::LARAVEL_REGISTRATIONS['model'] : null;
        }

        if ($methods === null || !in_array($method, $methods, true)) {
            return null;
        }

        return $this->registrationFinding($node, $context, $shortName, $method, 'listener');
    }

    private function inspectMethodCall(Expr\MethodCall $node, RuleContext $context): ?Finding
    {
        if (!$context->framework()->isLaravel() || !$node->name instanceof Identifier) {
            return null;
        }

        $method = $node->name->toString();

        if (!in_array($method, ['listen', 'subscribe', 'observe'], true)) {
            return null;
        }

        // Only when the receiver is recognisably the event dispatcher.
        if (!$this->isEventDispatcher($node->var)) {
            return null;
        }

        return $this->registrationFinding($node, $context, 'events', $method, 'listener');
    }

    /**
     * `self::$listeners[] = fn () => …` and friends.
     */
    private function inspectListenerAppend(Expr\Assign $node, RuleContext $context): ?Finding
    {
        if (!$node->var instanceof Expr\ArrayDimFetch) {
            return null;
        }

        if (!$this->isCallableExpression($node->expr)) {
            return null;
        }

        $base = AstHelper::unwrapArrayDim($node->var);
        $name = match (true) {
            $base instanceof Expr\StaticPropertyFetch && $base->name instanceof Node\VarLikeIdentifier => $base->name->toString(),
            $base instanceof Expr\PropertyFetch && $base->name instanceof Identifier => $base->name->toString(),
            default => null,
        };

        if ($name === null || !$this->looksLikeListenerRegistry($name)) {
            return null;
        }

        return $this->finding(
            $context,
            $context->location($node),
            sprintf('A callback is appended to the shared $%s registry.', $name),
            sprintf(
                '$%s is reached from %s and holds callables for the lifetime of the object. When that object outlives the request — a static property, or a singleton service — every request adds another callback and the earlier ones keep running.',
                $name,
                $context->scope()->describeLocationScope(),
            ),
            $this->adjust(Severity::Medium, $context),
            $context->scope()->symbol($name),
            $context->snippet($node),
        );
    }

    private function registrationFinding(
        Node $node,
        RuleContext $context,
        string $subject,
        string $method,
        string $kind,
    ): Finding {
        return $this->finding(
            $context,
            $context->location($node),
            $kind === 'macro'
                ? sprintf('%s::%s() extends a shared class from a request path.', $subject, $method)
                : sprintf('%s::%s() registers a listener from a request path.', $subject, $method),
            $kind === 'macro'
                ? sprintf(
                    'Macros are stored in a static registry on %s, so the registration survives the request. Running it from %s repeats the work for every request and lets one request change how the class behaves for all the others.',
                    $subject,
                    $context->scope()->describeLocationScope(),
                )
                : sprintf(
                    'The registration is performed from %s, which runs per request, while the dispatcher it registers with lives as long as the worker. Listeners therefore stack up and the ones registered by earlier requests keep firing — often with data captured from those requests.',
                    $context->scope()->describeLocationScope(),
                ),
            $this->adjust(Severity::Medium, $context),
            $context->scope()->symbol(),
            $context->snippet($node),
        );
    }

    private function isEventDispatcher(Expr $receiver): bool
    {
        // `$this->app['events']` / `$app['events']` / `$this->events` / `$dispatcher`
        if ($receiver instanceof Expr\ArrayDimFetch && $receiver->dim !== null) {
            return AstHelper::stringValue($receiver->dim) === 'events';
        }

        if ($receiver instanceof Expr\PropertyFetch && $receiver->name instanceof Identifier) {
            return in_array(strtolower($receiver->name->toString()), ['events', 'dispatcher', 'eventdispatcher'], true);
        }

        if ($receiver instanceof Expr\Variable && is_string($receiver->name)) {
            return in_array(strtolower($receiver->name), ['events', 'dispatcher', 'eventdispatcher'], true);
        }

        return false;
    }

    private function isCallableExpression(Expr $expr): bool
    {
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return true;
        }

        if ($expr instanceof Expr\FuncCall || $expr instanceof Expr\New_) {
            return false;
        }

        // `[$this, 'handle']` and `[self::class, 'handle']`
        if ($expr instanceof Expr\Array_ && count($expr->items) === 2) {
            return AstHelper::stringValue($expr->items[1]->value) !== null;
        }

        return false;
    }

    private function looksLikeListenerRegistry(string $name): bool
    {
        foreach (NameHeuristics::tokenize($name) as $token) {
            if (in_array($token, self::LISTENER_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    private function isInsideServiceProvider(RuleContext $context): bool
    {
        $className = $context->scope()->classShortName();

        if ($className !== null && str_ends_with($className, 'ServiceProvider')) {
            return true;
        }

        $parent = $context->scope()->parentClass();

        return $parent !== null && str_ends_with($parent, 'ServiceProvider');
    }

    /**
     * Registration in top-level bootstrap code runs once per worker boot.
     */
    private function adjust(Severity $severity, RuleContext $context): Severity
    {
        return $context->scope()->isFileScope() ? Severity::Low : $severity;
    }
}
