<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money;

use RoundlyConsulting\Money\Exceptions\InvalidPercentage;
use RoundlyConsulting\Money\Exceptions\RoundingNecessary;
use RoundlyConsulting\Money\Math\DecimalString;
use RoundlyConsulting\Money\Math\IntegerString;

/**
 * An exact, non-negative decimal percentage with at most six fraction digits ("8.5" = 8.5 %).
 */
final readonly class Percentage
{
    public const int MAX_SCALE = 6;

    private function __construct(private string $value) {}

    public static function of(int|string $percent): self
    {
        $value = DecimalString::normalize($percent);

        if (IntegerString::isNegative($value)) {
            throw InvalidPercentage::negative($value);
        }

        if (DecimalString::scale($value) > self::MAX_SCALE) {
            throw InvalidPercentage::precision($value, self::MAX_SCALE);
        }

        return new self($value);
    }

    /** 850 basis points → 8.5 %. */
    public static function fromBasisPoints(int $basisPoints): self
    {
        if ($basisPoints < 0) {
            throw InvalidPercentage::negative((string) $basisPoints);
        }

        return new self(DecimalString::normalize(IntegerString::toDecimal((string) $basisPoints, 2)));
    }

    /** A fraction (17/200) as a percentage (8.5); refuses anything finer than six digits. */
    public static function fromRatio(Ratio $fraction): self
    {
        $percent = $fraction->multiply(Ratio::of(100));
        $decimal = $percent->toExactDecimal(self::MAX_SCALE);

        if ($decimal === null) {
            throw RoundingNecessary::for((string) $percent, self::MAX_SCALE);
        }

        return self::of($decimal);
    }

    public function value(): string
    {
        return $this->value;
    }

    /** 8.5 % → 850; refuses a percentage finer than 0.01 %. */
    public function basisPoints(): int
    {
        return IntegerString::toInt(DecimalString::toScaledInteger($this->value, 2));
    }

    /** 8.5 % → 17/200. */
    public function toRatio(): Ratio
    {
        return Ratio::of($this->value, 100);
    }

    public function isZero(): bool
    {
        return $this->value === '0';
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function compareTo(self $other): int
    {
        $scale = max(DecimalString::scale($this->value), DecimalString::scale($other->value));

        return IntegerString::compare(
            DecimalString::toScaledInteger($this->value, $scale),
            DecimalString::toScaledInteger($other->value, $scale),
        );
    }
}
