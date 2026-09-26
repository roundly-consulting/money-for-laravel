<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Exchange;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RoundingMode;
use RoundlyConsulting\Money\Currency;
use RoundlyConsulting\Money\Exceptions\CurrencyMismatch;
use RoundlyConsulting\Money\Exceptions\InvalidExchangeRate;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Math\Rounder;
use RoundlyConsulting\Money\Money;
use RoundlyConsulting\Money\Ratio;

/**
 * An exact exchange rate: `1 from = rate × to` (major units), as a {@see Ratio} — never a
 * float and never a pre-rounded decimal, so inversions and cross rates stay exact and only
 * the final converted Money rounds.
 */
final readonly class ExchangeRate
{
    public CarbonImmutable $date;

    public function __construct(
        public Currency $from,
        public Currency $to,
        public Ratio $rate,
        CarbonInterface $date,
        public string $source,
    ) {
        if ($rate->isZero() || $rate->isNegative()) {
            throw InvalidExchangeRate::nonPositive((string) $rate);
        }

        if ($from->equals($to) && ! $rate->equals(Ratio::one())) {
            throw InvalidExchangeRate::sameCurrency($from->code);
        }

        $this->date = CarbonImmutable::instance($date)->startOfDay();
    }

    /** `fromDecimal('EUR', 'USD', '1.0854', $date)`. */
    public static function fromDecimal(Currency|string $from, Currency|string $to, string $rate, CarbonInterface $date, string $source = 'manual'): self
    {
        return new self(
            $from instanceof Currency ? $from : Currency::of($from),
            $to instanceof Currency ? $to : Currency::of($to),
            Ratio::of($rate),
            $date,
            $source,
        );
    }

    /** The exact rate `to → from`. */
    public function invert(): self
    {
        return new self($this->to, $this->from, $this->rate->invert(), $this->date, $this->source);
    }

    /**
     * The exact cross rate A→B→C. Carries the older leg's date and both sources.
     */
    public function through(self $next): self
    {
        if (! $this->to->equals($next->from)) {
            throw CurrencyMismatch::between($this->to, $next->from);
        }

        return new self(
            $this->from,
            $next->to,
            $this->rate->multiply($next->rate),
            $this->date->lessThan($next->date) ? $this->date : $next->date,
            $this->source === $next->source ? $this->source : $this->source.'+'.$next->source,
        );
    }

    /**
     * minor_to = round(minor_from × num × 10^exp_to / (den × 10^exp_from)) — one rational,
     * one rounding.
     */
    public function convert(Money $money, RoundingMode $rounding = RoundingMode::HalfEven): Money
    {
        if (! $money->currency()->equals($this->from)) {
            throw CurrencyMismatch::between($money->currency(), $this->from);
        }

        $numerator = Calculator::mul(Calculator::mul($money->minor(), $this->rate->numerator()), Calculator::pow10($this->to->exponent));
        $denominator = Calculator::mul($this->rate->denominator(), Calculator::pow10($this->from->exponent));

        return Money::ofMinor(IntegerString::capped(Rounder::divide($numerator, $denominator, $rounding), 'convert'), $this->to);
    }

    /** The rate as a decimal with `$scale` fraction digits (for display). */
    public function decimal(int $scale = 10, RoundingMode $rounding = RoundingMode::HalfEven): string
    {
        return $this->rate->toDecimal($scale, $rounding);
    }
}
