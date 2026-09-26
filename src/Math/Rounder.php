<?php

declare(strict_types=1);

namespace RoundlyConsulting\Money\Math;

use RoundingMode;
use RoundlyConsulting\Money\Exceptions\DivisionByZero;

/**
 * Exact N/D → integer under a native `\RoundingMode`, on canonical integer strings. The one
 * rounding step every lossy operation ends with.
 *
 * @internal
 */
final class Rounder
{
    public static function divide(string $numerator, string $denominator, RoundingMode $mode): string
    {
        if ($denominator === '0') {
            throw DivisionByZero::create();
        }

        // Normalise to a positive denominator so the remainder's sign is the value's sign.
        if (IntegerString::isNegative($denominator)) {
            $numerator = IntegerString::negate($numerator);
            $denominator = IntegerString::negate($denominator);
        }

        [$quotient, $remainder] = Calculator::divmod($numerator, $denominator);

        if ($remainder === '0') {
            return $quotient;
        }

        $sign = IntegerString::sign($remainder);
        $half = IntegerString::compare(Calculator::add(IntegerString::abs($remainder), IntegerString::abs($remainder)), $denominator);
        $odd = in_array(substr($quotient, -1), ['1', '3', '5', '7', '9'], true);

        $increment = match ($mode) {
            RoundingMode::TowardsZero => false,
            RoundingMode::AwayFromZero => true,
            RoundingMode::PositiveInfinity => $sign > 0,
            RoundingMode::NegativeInfinity => $sign < 0,
            RoundingMode::HalfAwayFromZero => $half >= 0,
            RoundingMode::HalfTowardsZero => $half > 0,
            RoundingMode::HalfEven => $half > 0 || ($half === 0 && $odd),
            RoundingMode::HalfOdd => $half > 0 || ($half === 0 && ! $odd),
        };

        if (! $increment) {
            return $quotient;
        }

        return Calculator::add($quotient, $sign > 0 ? '1' : '-1');
    }
}
