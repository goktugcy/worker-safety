<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

/**
 * Shape of a property's declared default value.
 *
 * Used to tell configuration-ish state (`private static string $version = '1.0'`)
 * apart from request-ish state (`public static ?User $user = null`).
 */
enum DefaultValueKind: string
{
    case None = 'none';
    case Null = 'null';
    case Scalar = 'scalar';
    case EmptyArray = 'empty-array';
    case NonEmptyArray = 'non-empty-array';
    case ConstantExpression = 'constant-expression';
    case NewObject = 'new-object';
    case Other = 'other';

    /**
     * True when the default is a compile-time constant scalar, i.e. the sort of
     * value that reads as configuration rather than as runtime state.
     */
    public function isConstantScalar(): bool
    {
        return $this === self::Scalar || $this === self::ConstantExpression;
    }
}
