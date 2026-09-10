<?php

declare(strict_types=1);

namespace App\Support;

/**
 * An immutable service: a singleton binding for this is correct and must not
 * be reported.
 */
final class CurrencyFormatter
{
    public function __construct(
        private readonly string $currency = 'EUR',
        private readonly int $precision = 2,
    ) {
    }

    public function format(float $amount): string
    {
        return number_format($amount, $this->precision) . ' ' . $this->currency;
    }
}
