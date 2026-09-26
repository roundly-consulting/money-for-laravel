<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use RoundlyConsulting\Money\Money;

/** A converted amount together with the rate that produced it. */
final readonly class Conversion
{
    public function __construct(
        public Money $original,
        public Money $converted,
        public ExchangeRate $rate,
    ) {}
}
