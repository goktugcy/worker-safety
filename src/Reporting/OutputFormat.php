<?php

declare(strict_types=1);

namespace WorkerSafety\Reporting;

use WorkerSafety\Exception\ConfigurationException;

enum OutputFormat: string
{
    case Console = 'console';
    case Json = 'json';
    case Sarif = 'sarif';

    /**
     * True when stdout must contain nothing but the report itself.
     */
    public function isMachineReadable(): bool
    {
        return $this !== self::Console;
    }

    public static function fromString(string $value): self
    {
        $format = self::tryFrom(strtolower(trim($value)));

        if (!$format instanceof self) {
            throw ConfigurationException::invalidValue(
                'format',
                sprintf('"%s" is not one of %s.', $value, implode(', ', self::names())),
            );
        }

        return $format;
    }

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
