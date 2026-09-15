<?php

declare(strict_types=1);

namespace WorkerSafety\Replay\Result;

/**
 * "There was no value here" — as a type, not as data.
 *
 * Absence used to be a magic string, which meant a response that legitimately
 * contained that string was indistinguishable from one that was missing the
 * field entirely. Any other reserved string has the same flaw, so absence is
 * represented by a value that JSON simply cannot produce: an enum case, unique
 * by identity and impossible to decode a response into.
 */
enum MissingValue
{
    case Instance;
}
