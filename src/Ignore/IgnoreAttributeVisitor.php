<?php

declare(strict_types=1);

namespace WorkerSafety\Ignore;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use WorkerSafety\Application\ApplicationInfo;
use WorkerSafety\Ast\AstHelper;

/**
 * Collects `#[WorkerSafetyIgnore('WS001')]` attributes.
 *
 * The attribute suppresses the annotated declaration's whole line range, which
 * makes it the right tool for a class or method and the comment directive the
 * right tool for a single statement.
 */
final class IgnoreAttributeVisitor extends NodeVisitorAbstract
{
    public function __construct(private readonly SuppressionIndex $index)
    {
    }

    public function enterNode(Node $node): null
    {
        if (!property_exists($node, 'attrGroups')) {
            return null;
        }

        /** @var list<Node\AttributeGroup> $attrGroups */
        $attrGroups = $node->attrGroups;

        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->isIgnoreAttribute($attribute)) {
                    continue;
                }

                $start = max(1, $node->getStartLine());
                $end = max($start, $node->getEndLine());

                $this->index->suppressRange($start, $end, $this->ruleIds($attribute));
            }
        }

        return null;
    }

    private function isIgnoreAttribute(Node\Attribute $attribute): bool
    {
        $name = AstHelper::nameToString($attribute->name);
        $short = str_contains($name, '\\')
            ? substr($name, (int) strrpos($name, '\\') + 1)
            : $name;

        return strtolower($short) === strtolower(ApplicationInfo::IGNORE_ATTRIBUTE);
    }

    /**
     * @return list<string>
     */
    private function ruleIds(Node\Attribute $attribute): array
    {
        $ids = [];

        foreach ($attribute->args as $arg) {
            foreach ($this->stringValues($arg->value) as $value) {
                if (preg_match('/^[A-Za-z]{2}\d{3}$/', $value) === 1) {
                    $ids[] = strtoupper($value);
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return list<string>
     */
    private function stringValues(Node\Expr $expr): array
    {
        $literal = AstHelper::stringValue($expr);

        if ($literal !== null) {
            return [$literal];
        }

        if (!$expr instanceof Node\Expr\Array_) {
            return [];
        }

        $values = [];

        foreach ($expr->items as $item) {
            $value = AstHelper::stringValue($item->value);

            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
