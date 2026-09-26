<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\InvalidRatio;
use RoundlyConsulting\Money\Math\Calculator;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Math\IntegerString;
use RoundlyConsulting\Money\Math\Rounder;
use Stringable;

/**
 * An exact rational number: numerator/denominator as integer strings, reduced by their gcd,
 * with a positive denominator. Exchange rates, percentages and fractional factors are
 * ratios so that chained math (cross rates, inversions) never rounds until the very end.
 */
final readonly class Ratio implements Stringable
{
    /** Digit cap per term after reduction — bounds the cost of chained cross-rate math. */
    public const int MAX_DIGITS = 80;

    private function __construct(
        private string $numerator,
        private string $denominator,
    ) {}

    /**
     * Both terms may be integers or plain decimals: `Ratio::of('1.0854')`, `Ratio::of(17, 200)`.
     */
    public static function of(int|string $numerator, int|string $denominator = 1): self
    {
        $numerator = DecimalString::normalize($numerator);
        $denominator = DecimalString::normalize($denominator);

        $numeratorScale = DecimalString::scale($numerator);
        $denominatorScale = DecimalString::scale($denominator);

        // a/10^s1 ÷ b/10^s2 = (a·10^s2) / (b·10^s1)
        return self::fromIntegers(
            Calculator::mul(DecimalString::unscaled($numerator), Calculator::pow10($denominatorScale)),
            Calculator::mul(DecimalString::unscaled($denominator), Calculator::pow10($numeratorScale)),
        );
    }

    public static function one(): self
    {
        return new self('1', '1');
    }

    public static function zero(): self
    {
        return new self('0', '1');
    }

    /**
     * @internal build from canonical integer terms; reduces and validates.
     */
    public static function fromIntegers(string $numerator, string $denominator): self
    {
        if ($denominator === '0') {
            throw InvalidRatio::zeroDenominator();
        }

        if (IntegerString::isNegative($denominator)) {
            $numerator = IntegerString::negate($numerator);
            $denominator = IntegerString::negate($denominator);
        }

        if ($numerator === '0') {
            return self::zero();
        }

        $gcd = Calculator::gcd($numerator, $denominator);

        if ($gcd !== '1') {
            [$numerator] = Calculator::divmod($numerator, $gcd);
            [$denominator] = Calculator::divmod($denominator, $gcd);
        }

        if (IntegerString::digits($numerator) > self::MAX_DIGITS || IntegerString::digits($denominator) > self::MAX_DIGITS) {
            throw InvalidRatio::tooLarge(self::MAX_DIGITS);
        }

        return new self($numerator, $denominator);
    }

    public function numerator(): string
    {
        return $this->numerator;
    }

    public function denominator(): string
    {
        return $this->denominator;
    }

    public function multiply(self $other): self
    {
        return self::fromIntegers(
            Calculator::mul($this->numerator, $other->numerator),
            Calculator::mul($this->denominator, $other->denominator),
        );
    }

    public function divide(self $other): self
    {
        return $this->multiply($other->invert());
    }

    public function invert(): self
    {
        if ($this->numerator === '0') {
            throw InvalidRatio::zeroDenominator();
        }

        return self::fromIntegers($this->denominator, $this->numerator);
    }

    public function compareTo(self $other): int
    {
        return IntegerString::compare(
            Calculator::mul($this->numerator, $other->denominator),
            Calculator::mul($other->numerator, $this->denominator),
        );
    }

    public function equals(self $other): bool
    {
        return $this->numerator === $other->numerator && $this->denominator === $other->denominator;
    }

    public function isZero(): bool
    {
        return $this->numerator === '0';
    }

    public function isNegative(): bool
    {
        return IntegerString::isNegative($this->numerator);
    }

    /** The ratio as a decimal with exactly `$scale` fraction digits, rounded once. */
    public function toDecimal(int $scale, RoundingMode $rounding = RoundingMode::HalfAwayFromZero): string
    {
        $scaled = Rounder::divide(Calculator::mul($this->numerator, Calculator::pow10($scale)), $this->denominator, $rounding);

        return IntegerString::toDecimal($scaled, $scale);
    }

    /**
     * The exact terminating decimal, or null when the expansion does not terminate within
     * `$maxScale` fraction digits (e.g. 1/3).
     */
    public function toExactDecimal(int $maxScale = 20): ?string
    {
        for ($scale = 0; $scale <= $maxScale; $scale++) {
            [$quotient, $remainder] = Calculator::divmod(Calculator::mul($this->numerator, Calculator::pow10($scale)), $this->denominator);

            if ($remainder === '0') {
                return IntegerString::toDecimal($quotient, $scale);
            }
        }

        return null;
    }

    public function __toString(): string
    {
        return $this->numerator.'/'.$this->denominator;
    }
}
