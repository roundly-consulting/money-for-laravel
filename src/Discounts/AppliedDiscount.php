<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Discounts;

use RoundlyConsulting\Money\Enums\DiscountTarget;
use RoundlyConsulting\Money\Money;

/** One row of a {@see DiscountBreakdown}: which discount removed how much from which base. */
final readonly class AppliedDiscount
{
    public function __construct(
        public Discount $discount,
        public DiscountTarget $target,
        public Money $amount,
    ) {}
}
