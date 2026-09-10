<?php

declare(strict_types=1);

namespace WorkerSafety\Ast\Index;

/**
 * Recognises the self-instantiating singleton shape.
 *
 * Shared by WS004, which reports the pattern, and by WS001, which stays quiet
 * about the instance holder so that one line never carries two contradictory
 * severities.
 */
final class SingletonAnalyzer
{
    private function __construct()
    {
    }

    /**
     * The static property holding the single instance, if the class has one.
     */
    public static function instanceHolder(ClassShape $class): ?PropertyShape
    {
        foreach ($class->staticProperties as $property) {
            if (!self::canHoldInstance($property, $class)) {
                continue;
            }

            foreach ($class->methods as $method) {
                if (self::assignsInstance($method, $property)) {
                    return $property;
                }
            }
        }

        return null;
    }

    public static function isInstanceHolder(ClassShape $class, PropertyShape $property): bool
    {
        $holder = self::instanceHolder($class);

        return $holder instanceof PropertyShape && $holder->name === $property->name;
    }

    private static function canHoldInstance(PropertyShape $property, ClassShape $class): bool
    {
        $type = $property->typeString;

        if ($type !== null) {
            $normalized = strtolower(ltrim($type, '?'));

            if ($normalized === 'self' || $normalized === 'static') {
                return true;
            }

            foreach ($property->typeClassNames as $className) {
                if (strtolower($className) === strtolower($class->name)) {
                    return true;
                }
            }

            // A property typed as something else cannot be the instance holder.
            return false;
        }

        // Untyped holders are only accepted when they start out empty, which is
        // what `private static $instance;` and `= null` look like.
        return $property->default === DefaultValueKind::None || $property->default === DefaultValueKind::Null;
    }

    private static function assignsInstance(MethodShape $method, PropertyShape $property): bool
    {
        return $method->isStatic
            && $method->instantiatesSelf
            && in_array($property->name, $method->assignedStaticProperties, true);
    }
}
