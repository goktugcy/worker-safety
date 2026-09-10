<?php

declare(strict_types=1);

namespace WorkerSafety\Attribute;

use Attribute;

/**
 * Suppresses the given rules for the annotated declaration.
 *
 * The scanner recognises the attribute by its short name, so importing it is
 * optional — but shipping the class means editors and static analysers do not
 * complain about an undefined attribute.
 *
 * ```php
 * #[WorkerSafetyIgnore('WS001', 'WS008')]
 * private static array $cache = [];
 * ```
 */
#[\Attribute(\Attribute::TARGET_ALL | \Attribute::IS_REPEATABLE)]
final class WorkerSafetyIgnore
{
    /**
     * @var list<string>
     */
    public readonly array $ruleIds;

    public function __construct(string ...$ruleIds)
    {
        $this->ruleIds = array_values($ruleIds);
    }
}
