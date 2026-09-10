<?php

declare(strict_types=1);

namespace WorkerSafety\Finding;

/**
 * Coarse grouping used for reporting and SARIF tags.
 */
enum RuleCategory: string
{
    case StaticState = 'static-state';
    case GlobalState = 'global-state';
    case EnvironmentMutation = 'environment-mutation';
    case Singleton = 'singleton';
    case ContainerBinding = 'container-binding';
    case MemoryRetention = 'memory-retention';
    case EventRegistration = 'event-registration';
    case Lifecycle = 'lifecycle';

    public function label(): string
    {
        return match ($this) {
            self::StaticState => 'Static state',
            self::GlobalState => 'Global state',
            self::EnvironmentMutation => 'Environment mutation',
            self::Singleton => 'Singleton',
            self::ContainerBinding => 'Container binding',
            self::MemoryRetention => 'Memory retention',
            self::EventRegistration => 'Event registration',
            self::Lifecycle => 'Process lifecycle',
        };
    }
}
