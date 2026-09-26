<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tax;

use RoundlyConsulting\Money\Money;

/** Net, tax and gross of one amount at one rate; `net + tax === gross`. */
final readonly class TaxBreakdown
{
    public function __construct(
        public Money $net,
        public Money $tax,
        public Money $gross,
        public TaxRate $rate,
    ) {}
}
