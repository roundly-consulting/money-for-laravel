<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Tax;

use RoundingMode;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Percentage;
use RoundlyConsulting\Money\Ratio;

/**
 * A tax rate with exact net ⇄ gross math: one rational and one rounding per call, no float
 * divisor. Breakdowns derive the other side by subtraction, so `net + tax === gross` always.
 */
final readonly class TaxRate
{
    private function __construct(
        private Percentage $percentage,
        private ?string $label,
    ) {}

    public static function of(Percentage|int|string $percent, ?string $label = null): self
    {
        return new self($percent instanceof Percentage ? $percent : Percentage::of($percent), $label);
    }

    /** 1900 → 19 %, the unit shops stores rates in. */
    public static function fromBasisPoints(int $basisPoints, ?string $label = null): self
    {
        return new self(Percentage::fromBasisPoints($basisPoints), $label);
    }

    public static function zero(): self
    {
        return new self(Percentage::of(0), null);
    }

    public function percentage(): Percentage
    {
        return $this->percentage;
    }

    public function label(): ?string
    {
        return $this->label;
    }

    public function isZero(): bool
    {
        return $this->percentage->isZero();
    }

    /** round(net × rate). */
    public function taxOnNet(Money $net, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        return $net->percentage($this->percentage, $rounding);
    }

    public function grossFromNet(Money $net, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        return $net->add($this->taxOnNet($net, $rounding));
    }

    /** round(gross / (1 + rate)), computed as gross × 100 / (100 + rate) exactly. */
    public function netFromGross(Money $gross, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        $percent = $this->percentage->value();
        $hundred = Calculator::pow10(2 + DecimalString::scale($percent));

        return $gross->multiply(
            Ratio::fromIntegers($hundred, Calculator::add($hundred, DecimalString::unscaled($percent))),
            $rounding,
        );
    }

    public function taxInGross(Money $gross, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): Money
    {
        return $gross->subtract($this->netFromGross($gross, $rounding));
    }

    public function breakdownFromNet(Money $net, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): TaxBreakdown
    {
        $tax = $this->taxOnNet($net, $rounding);

        return new TaxBreakdown($net, $tax, $net->add($tax), $this);
    }

    public function breakdownFromGross(Money $gross, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): TaxBreakdown
    {
        $net = $this->netFromGross($gross, $rounding);

        return new TaxBreakdown($net, $gross->subtract($net), $gross, $this);
    }
}
