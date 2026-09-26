<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Discounts;

use RoundingMode;
use RoundlyConsulting\Money\Enums\DiscountTarget;
use RoundlyConsulting\Money\Enums\DiscountType;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidDiscount;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;

/**
 * One discount: a fixed amount or a percentage, on the subtotal or on shipping, optionally
 * capped, prioritised, exclusive and labelled (e.g. with a coupon code).
 *
 * {@see self::amountFor()} applies to whatever base it is given — the target is metadata
 * for {@see DiscountStack}. Route by {@see self::target()} yourself: never apply a
 * free-shipping discount to the price of the goods.
 */
final readonly class Discount
{
    private function __construct(
        private DiscountType $type,
        private DiscountTarget $target,
        private ?Money $fixedAmount = null,
        private ?Percentage $percent = null,
        private ?Money $cap = null,
        private int $priority = 0,
        private bool $exclusive = false,
        private ?string $label = null,
    ) {}

    /** A fixed amount off the subtotal; never more than the base. */
    public static function fixed(Money $amount): self
    {
        if ($amount->isNegative()) {
            throw InvalidDiscount::negative((string) $amount);
        }

        return new self(DiscountType::Fixed, DiscountTarget::Subtotal, fixedAmount: $amount);
    }

    /** 0..100 % off the subtotal (0 is a valid no-op). */
    public static function percentage(Percentage|int|string $percent): self
    {
        $percent = $percent instanceof Percentage ? $percent : Percentage::of($percent);

        if ($percent->compareTo(Percentage::of(100)) > 0) {
            throw InvalidDiscount::percentOutOfRange($percent->value());
        }

        return new self(DiscountType::Percentage, DiscountTarget::Subtotal, percent: $percent);
    }

    /** 100 % off shipping. */
    public static function freeShipping(): self
    {
        return self::percentage(100)->onShipping();
    }

    public function onShipping(): self
    {
        return $this->with(target: DiscountTarget::Shipping);
    }

    public function onSubtotal(): self
    {
        return $this->with(target: DiscountTarget::Subtotal);
    }

    /** The most this discount may remove. */
    public function cappedAt(Money $cap): self
    {
        if ($cap->isNegative()) {
            throw InvalidDiscount::negativeCap((string) $cap);
        }

        return $this->with(cap: $cap);
    }

    /** Higher priorities apply first. */
    public function withPriority(int $priority): self
    {
        return $this->with(priority: $priority);
    }

    public function exclusive(bool $exclusive = true): self
    {
        return $this->with(exclusive: $exclusive);
    }

    public function labelled(string $label): self
    {
        return $this->with(label: $label);
    }

    public function type(): DiscountType
    {
        return $this->type;
    }

    public function target(): DiscountTarget
    {
        return $this->target;
    }

    public function fixedAmount(): ?Money
    {
        return $this->fixedAmount;
    }

    public function percent(): ?Percentage
    {
        return $this->percent;
    }

    public function cap(): ?Money
    {
        return $this->cap;
    }

    public function priority(): int
    {
        return $this->priority;
    }

    public function isExclusive(): bool
    {
        return $this->exclusive;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    /**
     * What this discount removes from `$base`: between zero and the base, never above the
     * cap; a percentage rounds once. A zero or negative base yields zero.
     */
    public function amountFor(Money $base, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        foreach ([$this->fixedAmount, $this->cap] as $money) {
            if ($money !== null && ! $money->isSameCurrency($base)) {
                throw CurrencyMismatch::between($base->currency(), $money->currency());
            }
        }

        if (! $base->isPositive()) {
            return Money::zero($base->currency());
        }

        $amount = $this->fixedAmount ?? $base->percentage($this->percent ?? Percentage::of(0), $rounding);

        if ($this->cap !== null) {
            $amount = Money::min([$amount, $this->cap]);
        }

        return Money::min([$amount, $base]);
    }

    /** The base after this discount. */
    public function applyTo(Money $base, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        return $base->subtract($this->amountFor($base, $rounding));
    }

    private function with(
        ?DiscountTarget $target = null,
        ?Money $cap = null,
        ?int $priority = null,
        ?bool $exclusive = null,
        ?string $label = null,
    ): self {
        return new self(
            $this->type,
            $target ?? $this->target,
            $this->fixedAmount,
            $this->percent,
            $cap ?? $this->cap,
            $priority ?? $this->priority,
            $exclusive ?? $this->exclusive,
            $label ?? $this->label,
        );
    }
}
