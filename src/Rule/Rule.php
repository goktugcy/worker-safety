<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

use PhpParser\Node;
use WorkerSafety\Finding\Finding;

/**
 * One worker-safety check.
 *
 * The lifecycle is aligned with how php-parser traverses code, and with the
 * fact that some risks are only visible once the whole project is known:
 *
 *  1. `beginFile()`   — reset per-file state
 *  2. `enterNode()`   — called for every node matching `nodeTypes()`
 *  3. `finishFile()`  — emit findings that needed the whole file
 *  4. `finishProject()` — emit findings that needed the whole project
 *
 * A rule may implement any subset; `AbstractRule` no-ops the rest.
 */
interface Rule
{
    public function definition(): RuleDefinition;

    /**
     * Node classes (or parent classes / interfaces) this rule wants to see.
     *
     * Returning an empty list means the rule is driven purely by the project
     * index and never inspects individual nodes.
     *
     * @return list<class-string<Node>>
     */
    public function nodeTypes(): array;

    public function beginFile(RuleContext $context): void;

    /**
     * @return iterable<Finding>
     */
    public function enterNode(Node $node, RuleContext $context): iterable;

    /**
     * @return iterable<Finding>
     */
    public function finishFile(RuleContext $context): iterable;

    /**
     * @return iterable<Finding>
     */
    public function finishProject(ProjectContext $context): iterable;
}
