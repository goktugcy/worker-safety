<?php

declare(strict_types=1);

namespace WorkerSafety\Rule;

/**
 * Canonical rule identifiers, referenced by configuration and tests.
 */
final class RuleId
{
    public const MUTABLE_STATIC_PROPERTY = 'WS001';
    public const MUTABLE_GLOBAL_VARIABLE = 'WS002';
    public const RUNTIME_ENVIRONMENT_MUTATION = 'WS003';
    public const MUTABLE_SINGLETON = 'WS004';
    public const LARAVEL_SINGLETON_MUTABLE_STATE = 'WS005';
    public const LARAVEL_SCOPED_CANDIDATE = 'WS006';
    public const STATIC_REQUEST_CONTEXT = 'WS007';
    public const STATIC_COLLECTION_GROWTH = 'WS008';
    public const PERSISTENT_LISTENER_REGISTRATION = 'WS009';
    public const SHUTDOWN_LIFECYCLE_ASSUMPTION = 'WS010';

    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::MUTABLE_STATIC_PROPERTY,
            self::MUTABLE_GLOBAL_VARIABLE,
            self::RUNTIME_ENVIRONMENT_MUTATION,
            self::MUTABLE_SINGLETON,
            self::LARAVEL_SINGLETON_MUTABLE_STATE,
            self::LARAVEL_SCOPED_CANDIDATE,
            self::STATIC_REQUEST_CONTEXT,
            self::STATIC_COLLECTION_GROWTH,
            self::PERSISTENT_LISTENER_REGISTRATION,
            self::SHUTDOWN_LIFECYCLE_ASSUMPTION,
        ];
    }
}
