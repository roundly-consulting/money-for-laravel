<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Discounts;

use RoundingMode;
use RoundlyConsulting\Money\Enums\DiscountTarget;
use RoundlyConsulting\Money\Enums\StackingStrategy;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Money;

/**
 * Several discounts over a subtotal and an optional shipping amount:
 *
 * 1. Application order: subtotal discounts (priority desc, then insertion order), then
 *    shipping discounts (same ordering). Shipping discounts drop out without shipping.
 * 2. Exclusivity is decided across both targets: the best exclusive discount alone
 *    competes with all non-exclusive ones combined; the larger total wins (tie: combined).
 * 3. The strategy combines the chosen set: Sequential (each on the running remainder of
 *    its target — percentages compound), Additive (each on its original base) or BestOf
 *    (the single largest discount, either target).
 * 4. Each target is clamped to its base, then the stack-wide cap applies; both trim in
 *    reverse application order, and zero rows are dropped.
 */
final readonly class DiscountStack
{
    /**
     * @param  list<Discount>  $discounts
     */
    private function __construct(
        private array $discounts,
        private StackingStrategy $strategy = StackingStrategy::Sequential,
        private ?Money $cap = null,
    ) {}

    public static function of(Discount ...$discounts): self
    {
        return new self(array_values($discounts));
    }

    public function using(StackingStrategy $strategy): self
    {
        return new self($this->discounts, $strategy, $this->cap);
    }

    /** A combined cap across both targets (same currency as the subtotal). */
    public function capTotalAt(Money $maximum): self
    {
        return new self($this->discounts, $this->strategy, $maximum);
    }

    public function apply(Money $subtotal, ?Money $shipping = null, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): DiscountBreakdown
    {
        if ($shipping !== null && ! $shipping->isSameCurrency($subtotal)) {
            throw CurrencyMismatch::between($subtotal->currency(), $shipping->currency());
        }

        if ($this->cap !== null && ! $this->cap->isSameCurrency($subtotal)) {
            throw CurrencyMismatch::between($subtotal->currency(), $this->cap->currency());
        }

        $bases = [
            DiscountTarget::Subtotal->value => $subtotal,
            DiscountTarget::Shipping->value => $shipping ?? Money::zero($subtotal->currency()),
        ];

        $ordered = $this->ordered($shipping !== null);

        $combined = $this->finalize(
            $this->combine(array_values(array_filter($ordered, static fn (Discount $discount): bool => ! $discount->isExclusive())), $bases, $rounding),
            $bases,
        );

        $exclusives = array_values(array_filter($ordered, static fn (Discount $discount): bool => $discount->isExclusive()));
        $exclusive = $exclusives === [] ? null : $this->finalize(self::best($exclusives, $bases, $rounding), $bases);

        $applied = $exclusive !== null && self::total($exclusive, $subtotal)->isGreaterThan(self::total($combined, $subtotal))
            ? $exclusive
            : $combined;

        return new DiscountBreakdown(
            $subtotal,
            self::targetTotal($applied, DiscountTarget::Subtotal, $subtotal),
            $bases[DiscountTarget::Shipping->value],
            self::targetTotal($applied, DiscountTarget::Shipping, $subtotal),
            $applied,
        );
    }

    /**
     * @return list<Discount>
     */
    private function ordered(bool $withShipping): array
    {
        $ordered = [];

        foreach ([DiscountTarget::Subtotal, DiscountTarget::Shipping] as $target) {
            if ($target === DiscountTarget::Shipping && ! $withShipping) {
                continue;
            }

            $group = array_filter($this->discounts, static fn (Discount $discount): bool => $discount->target() === $target);

            // Stable: equal priorities keep insertion order.
            uasort($group, static fn (Discount $a, Discount $b): int => $b->priority() <=> $a->priority());

            array_push($ordered, ...array_values($group));
        }

        return $ordered;
    }

    /**
     * @param  list<Discount>  $discounts
     * @param  array<string, Money>  $bases
     * @return list<AppliedDiscount>
     */
    private function combine(array $discounts, array $bases, RoundingMode $rounding): array
    {
        if ($this->strategy === StackingStrategy::BestOf) {
            return self::best($discounts, $bases, $rounding);
        }

        $remaining = $bases;
        $applied = [];

        foreach ($discounts as $discount) {
            $target = $discount->target()->value;
            $base = $this->strategy === StackingStrategy::Sequential ? $remaining[$target] : $bases[$target];
            $amount = $discount->amountFor($base, $rounding);

            $remaining[$target] = $remaining[$target]->subtract($amount);
            $applied[] = new AppliedDiscount($discount, $discount->target(), $amount);
        }

        return $applied;
    }

    /**
     * @param  list<Discount>  $discounts
     * @param  array<string, Money>  $bases
     * @return list<AppliedDiscount>
     */
    private static function best(array $discounts, array $bases, RoundingMode $rounding): array
    {
        $best = null;

        foreach ($discounts as $discount) {
            $row = new AppliedDiscount($discount, $discount->target(), $discount->amountFor($bases[$discount->target()->value], $rounding));

            if ($best === null || $row->amount->isGreaterThan($best->amount)) {
                $best = $row;
            }
        }

        return $best === null ? [] : [$best];
    }

    /**
     * Clamp each target to its base, apply the stack-wide cap, drop zero rows.
     *
     * @param  list<AppliedDiscount>  $rows
     * @param  array<string, Money>  $bases
     * @return list<AppliedDiscount>
     */
    private function finalize(array $rows, array $bases): array
    {
        foreach ($bases as $target => $base) {
            $rows = self::trim($rows, $base, static fn (AppliedDiscount $row): bool => $row->target->value === $target);
        }

        if ($this->cap !== null) {
            $rows = self::trim($rows, $this->cap, static fn (AppliedDiscount $row): bool => true);
        }

        return array_values(array_filter($rows, static fn (AppliedDiscount $row): bool => ! $row->amount->isZero()));
    }

    /**
     * Reduce the matching rows, last-applied first, until they sum to at most `$limit`.
     *
     * @param  list<AppliedDiscount>  $rows
     * @param  callable(AppliedDiscount): bool  $matches
     * @return list<AppliedDiscount>
     */
    private static function trim(array $rows, Money $limit, callable $matches): array
    {
        $total = Money::zero($limit->currency());

        foreach ($rows as $row) {
            if ($matches($row)) {
                $total = $total->add($row->amount);
            }
        }

        $excess = $total->subtract(Money::max([$limit, Money::zero($limit->currency())]));

        for ($index = count($rows) - 1; $index >= 0 && $excess->isPositive(); $index--) {
            if (! $matches($rows[$index])) {
                continue;
            }

            $cut = Money::min([$rows[$index]->amount, $excess]);
            $rows[$index] = new AppliedDiscount($rows[$index]->discount, $rows[$index]->target, $rows[$index]->amount->subtract($cut));
            $excess = $excess->subtract($cut);
        }

        return array_values($rows);
    }

    /**
     * @param  list<AppliedDiscount>  $rows
     */
    private static function total(array $rows, Money $subtotal): Money
    {
        return Money::sum(array_map(static fn (AppliedDiscount $row): Money => $row->amount, $rows), $subtotal->currency());
    }

    /**
     * @param  list<AppliedDiscount>  $rows
     */
    private static function targetTotal(array $rows, DiscountTarget $target, Money $subtotal): Money
    {
        return self::total(array_values(array_filter($rows, static fn (AppliedDiscount $row): bool => $row->target === $target)), $subtotal);
    }
}
