<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Discounts;

use RoundlyConsulting\Money\Money;

/**
 * The result of {@see DiscountStack::apply()}. Invariant: the applied amounts sum to
 * `subtotalDiscount + shippingDiscount`, and neither discount exceeds its base.
 */
final readonly class DiscountBreakdown
{
    /**
     * @param  list<AppliedDiscount>  $applied
     */
    public function __construct(
        public Money $subtotal,
        public Money $subtotalDiscount,
        public Money $shipping,
        public Money $shippingDiscount,
        public array $applied,
    ) {}

    public function discountedSubtotal(): Money
    {
        return $this->subtotal->subtract($this->subtotalDiscount);
    }

    public function discountedShipping(): Money
    {
        return $this->shipping->subtract($this->shippingDiscount);
    }

    public function totalDiscount(): Money
    {
        return $this->subtotalDiscount->add($this->shippingDiscount);
    }

    public function total(): Money
    {
        return $this->discountedSubtotal()->add($this->discountedShipping());
    }
}
